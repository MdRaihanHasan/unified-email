<?php

namespace Tests\Feature;

use App\Mail\Data\Address;
use App\Mail\Data\RemoteMessage;
use App\Mail\Support\ThreadResolver;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\Thread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThreadResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): ThreadResolver
    {
        return new ThreadResolver;
    }

    public function test_a_message_with_no_relatives_starts_its_own_thread(): void
    {
        $account = MailAccount::factory()->create();

        $thread = $this->resolver()->resolve($account, new RemoteMessage(
            providerMessageId: 'm1',
            rfc822MessageId: '<root@example.com>',
            from: new Address('sender@example.com'),
            subject: 'Invoice 42',
        ));

        $this->assertSame('invoice 42', $thread->subject_normalized);
        $this->assertSame(1, Thread::count());
    }

    public function test_in_reply_to_attaches_to_the_parents_thread(): void
    {
        $account = MailAccount::factory()->create();
        $parent = Message::factory()->for($account, 'mailAccount')
            ->create(['rfc822_message_id' => '<root@example.com>']);

        $thread = $this->resolver()->resolve($account, new RemoteMessage(
            providerMessageId: 'm2',
            rfc822MessageId: '<reply@example.com>',
            inReplyTo: '<root@example.com>',
            subject: 'Re: Invoice 42',
        ));

        $this->assertSame($parent->thread_id, $thread->id);
        $this->assertSame(1, Thread::count());
    }

    public function test_references_headers_merge_threads_across_accounts(): void
    {
        // The whole point of a unified inbox: a reply arriving on the Workspace
        // account joins the conversation that started in Outlook. Message-IDs are
        // globally unique, so this tier is allowed to cross the account boundary.
        $outlook = MailAccount::factory()->create(['email' => 'me@outlook.com']);
        $workspace = MailAccount::factory()->gmailApi()->create(['email' => 'me@company.com']);

        $original = Message::factory()->for($outlook, 'mailAccount')
            ->create(['rfc822_message_id' => '<original@example.com>']);

        $thread = $this->resolver()->resolve($workspace, new RemoteMessage(
            providerMessageId: 'g1',
            rfc822MessageId: '<reply@example.com>',
            references: ['<original@example.com>'],
            subject: 'Re: Invoice 42',
        ));

        $this->assertSame($original->thread_id, $thread->id);
    }

    public function test_a_reply_synced_before_its_parent_still_lands_together(): void
    {
        // Two accounts poll independently, so the reply can arrive first.
        $account = MailAccount::factory()->create();
        $reply = Message::factory()->for($account, 'mailAccount')->create([
            'rfc822_message_id' => '<reply@example.com>',
            'in_reply_to' => '<root@example.com>',
            'references_ids' => ['<root@example.com>'],
        ]);

        $thread = $this->resolver()->resolve($account, new RemoteMessage(
            providerMessageId: 'm0',
            rfc822MessageId: '<root@example.com>',
            subject: 'Invoice 42',
        ));

        $this->assertSame($reply->thread_id, $thread->id);
    }

    public function test_provider_thread_id_groups_messages_within_one_account(): void
    {
        $account = MailAccount::factory()->gmailApi()->create();
        $existing = Message::factory()->for($account, 'mailAccount')->create([
            'provider_thread_id' => 'gmail-thread-1',
            'rfc822_message_id' => '<a@example.com>',
        ]);

        $thread = $this->resolver()->resolve($account, new RemoteMessage(
            providerMessageId: 'm9',
            providerThreadId: 'gmail-thread-1',
            rfc822MessageId: '<b@example.com>',
            subject: 'Something else entirely',
        ));

        $this->assertSame($existing->thread_id, $thread->id);
    }

    public function test_provider_thread_id_is_ignored_for_imap_accounts(): void
    {
        // IMAP has no trustworthy thread identity, so a stray value must not group.
        $account = MailAccount::factory()->imap()->create();
        Message::factory()->for($account, 'mailAccount')->create([
            'provider_thread_id' => 'not-trustworthy',
            'rfc822_message_id' => '<a@example.com>',
            'subject' => 'Totally unrelated',
        ]);

        $thread = $this->resolver()->resolve($account, new RemoteMessage(
            providerMessageId: 'm9',
            providerThreadId: 'not-trustworthy',
            rfc822MessageId: '<b@example.com>',
            from: new Address('other@example.com'),
            subject: 'Something else entirely',
        ));

        $this->assertSame(2, Thread::count());
    }

    public function test_subject_and_participants_group_a_header_less_reply(): void
    {
        $account = MailAccount::factory()->imap()->create();

        $first = $this->resolver()->resolve($account, new RemoteMessage(
            providerMessageId: 'm1',
            rfc822MessageId: '<a@example.com>',
            from: new Address('client@example.com'),
            to: [new Address('me@gmail.com')],
            subject: 'Invoice 42',
            sentAt: new \DateTimeImmutable('2026-08-01 10:00:00'),
        ));
        Message::factory()->for($account, 'mailAccount')->create(['thread_id' => $first->id]);

        $second = $this->resolver()->resolve($account, new RemoteMessage(
            providerMessageId: 'm2',
            rfc822MessageId: '<b@example.com>',
            from: new Address('client@example.com'),
            to: [new Address('me@gmail.com')],
            subject: 'Re: Invoice 42',
            sentAt: new \DateTimeImmutable('2026-08-02 10:00:00'),
        ));

        $this->assertSame($first->id, $second->id);
    }

    public function test_the_subject_heuristic_never_merges_across_accounts(): void
    {
        // Two unrelated people mailing "Invoice" to two different mailboxes is the
        // exact false merge this restriction exists to prevent.
        $outlook = MailAccount::factory()->create(['email' => 'me@outlook.com']);
        $gmail = MailAccount::factory()->imap()->create(['email' => 'me@gmail.com']);

        $first = $this->resolver()->resolve($outlook, new RemoteMessage(
            providerMessageId: 'o1',
            rfc822MessageId: '<a@example.com>',
            from: new Address('shared@example.com'),
            subject: 'Invoice',
            sentAt: new \DateTimeImmutable('2026-08-01 10:00:00'),
        ));
        Message::factory()->for($outlook, 'mailAccount')->create(['thread_id' => $first->id]);

        $second = $this->resolver()->resolve($gmail, new RemoteMessage(
            providerMessageId: 'g1',
            rfc822MessageId: '<b@example.com>',
            from: new Address('shared@example.com'),
            subject: 'Invoice',
            sentAt: new \DateTimeImmutable('2026-08-02 10:00:00'),
        ));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Thread::count());
    }

    public function test_the_subject_heuristic_respects_its_time_window(): void
    {
        $account = MailAccount::factory()->imap()->create();

        $first = $this->resolver()->resolve($account, new RemoteMessage(
            providerMessageId: 'm1',
            rfc822MessageId: '<a@example.com>',
            from: new Address('client@example.com'),
            subject: 'Monthly report',
            sentAt: new \DateTimeImmutable('2026-01-01 10:00:00'),
        ));
        Message::factory()->for($account, 'mailAccount')->create(['thread_id' => $first->id]);

        $second = $this->resolver()->resolve($account, new RemoteMessage(
            providerMessageId: 'm2',
            rfc822MessageId: '<b@example.com>',
            from: new Address('client@example.com'),
            subject: 'Monthly report',
            sentAt: new \DateTimeImmutable('2026-08-01 10:00:00'),
        ));

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_an_empty_subject_does_not_collapse_unrelated_mail(): void
    {
        $account = MailAccount::factory()->imap()->create();

        $first = $this->resolver()->resolve($account, new RemoteMessage(
            providerMessageId: 'm1',
            rfc822MessageId: '<a@example.com>',
            from: new Address('one@example.com'),
            subject: '',
        ));
        Message::factory()->for($account, 'mailAccount')->create(['thread_id' => $first->id]);

        $second = $this->resolver()->resolve($account, new RemoteMessage(
            providerMessageId: 'm2',
            rfc822MessageId: '<b@example.com>',
            from: new Address('two@example.com'),
            subject: '',
        ));

        $this->assertNotSame($first->id, $second->id);
    }
}

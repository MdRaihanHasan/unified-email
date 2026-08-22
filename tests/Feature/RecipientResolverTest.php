<?php

namespace Tests\Feature;

use App\Enums\OutboundType;
use App\Mail\Support\RecipientResolver;
use App\Models\MailAccount;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipientResolverTest extends TestCase
{
    use RefreshDatabase;

    private RecipientResolver $resolver;

    private MailAccount $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(RecipientResolver::class);
        $this->workspace = MailAccount::factory()->gmailApi()->create(['email' => 'me@company.com']);
        MailAccount::factory()->imap()->create(['email' => 'me@gmail.com']);
    }

    private function incoming(array $overrides = []): Message
    {
        return Message::factory()->for($this->workspace, 'mailAccount')->create([
            'from_addr' => ['address' => 'anna@client.test', 'name' => 'Anna'],
            'to_addrs' => [['address' => 'me@company.com', 'name' => null]],
            'cc_addrs' => [],
            'reply_to' => null,
            ...$overrides,
        ]);
    }

    /** @return list<string> */
    private function addresses(array $list): array
    {
        return array_map(fn ($a) => $a->address, $list);
    }

    public function test_a_reply_goes_back_to_the_sender(): void
    {
        $result = $this->resolver->for($this->incoming(), OutboundType::Reply);

        $this->assertSame(['anna@client.test'], $this->addresses($result['to']));
        $this->assertSame([], $result['cc']);
    }

    public function test_reply_to_beats_from(): void
    {
        // That header exists to redirect replies, and mailing lists depend on it.
        $message = $this->incoming([
            'reply_to' => [['address' => 'list@lists.test', 'name' => 'The List']],
        ]);

        $result = $this->resolver->for($message, OutboundType::Reply);

        $this->assertSame(['list@lists.test'], $this->addresses($result['to']));
    }

    public function test_reply_all_ccs_the_rest_of_the_thread(): void
    {
        $message = $this->incoming([
            'to_addrs' => [
                ['address' => 'me@company.com', 'name' => null],
                ['address' => 'bob@client.test', 'name' => 'Bob'],
            ],
            'cc_addrs' => [['address' => 'carol@client.test', 'name' => 'Carol']],
        ]);

        $result = $this->resolver->for($message, OutboundType::ReplyAll);

        $this->assertSame(['anna@client.test'], $this->addresses($result['to']));
        $this->assertSame(['bob@client.test', 'carol@client.test'], $this->addresses($result['cc']));
    }

    public function test_our_own_addresses_are_never_recipients(): void
    {
        // Every connected account counts, not just the sending one — otherwise
        // replying on Workspace to mail that also reached the personal account
        // silently CCs yourself.
        $message = $this->incoming([
            'to_addrs' => [
                ['address' => 'me@company.com', 'name' => null],
                ['address' => 'me@gmail.com', 'name' => null],
                ['address' => 'bob@client.test', 'name' => null],
            ],
        ]);

        $result = $this->resolver->for($message, OutboundType::ReplyAll);

        $all = [...$this->addresses($result['to']), ...$this->addresses($result['cc'])];

        $this->assertNotContains('me@company.com', $all);
        $this->assertNotContains('me@gmail.com', $all);
        $this->assertContains('bob@client.test', $all);
    }

    public function test_a_reply_all_does_not_cc_whoever_is_already_in_to(): void
    {
        $message = $this->incoming([
            'to_addrs' => [
                ['address' => 'me@company.com', 'name' => null],
                ['address' => 'anna@client.test', 'name' => 'Anna'],
            ],
        ]);

        $result = $this->resolver->for($message, OutboundType::ReplyAll);

        $this->assertSame(['anna@client.test'], $this->addresses($result['to']));
        $this->assertSame([], $result['cc'], 'anna is already the To recipient');
    }

    public function test_bcc_is_never_carried_into_a_reply(): void
    {
        // We only see Bcc on mail we sent; copying it forward exposes recipients the
        // sender deliberately hid.
        $message = $this->incoming([
            'bcc_addrs' => [['address' => 'secret@client.test', 'name' => null]],
        ]);

        $result = $this->resolver->for($message, OutboundType::ReplyAll);

        $all = [...$this->addresses($result['to']), ...$this->addresses($result['cc'])];
        $this->assertNotContains('secret@client.test', $all);
    }

    public function test_replying_to_your_own_sent_message_targets_the_original_recipients(): void
    {
        // Removing our own addresses would otherwise leave nobody to reply to.
        $message = $this->incoming([
            'from_addr' => ['address' => 'me@company.com', 'name' => 'Me'],
            'to_addrs' => [['address' => 'anna@client.test', 'name' => 'Anna']],
        ]);

        $result = $this->resolver->for($message, OutboundType::Reply);

        $this->assertSame(['anna@client.test'], $this->addresses($result['to']));
    }

    public function test_duplicate_addresses_collapse_regardless_of_case(): void
    {
        $message = $this->incoming([
            'to_addrs' => [
                ['address' => 'Bob@Client.test', 'name' => 'Bob'],
                ['address' => 'bob@client.test', 'name' => 'bob again'],
            ],
        ]);

        $result = $this->resolver->for($message, OutboundType::ReplyAll);

        $this->assertCount(1, $result['cc']);
        $this->assertSame('Bob@Client.test', $result['cc'][0]->address, 'the first spelling is kept');
    }

    public function test_a_forward_starts_with_no_recipients(): void
    {
        $result = $this->resolver->for($this->incoming(), OutboundType::Forward);

        $this->assertSame([], $result['to']);
        $this->assertSame([], $result['cc']);
    }

    public function test_a_message_with_no_sender_yields_no_recipients(): void
    {
        $message = $this->incoming(['from_addr' => null, 'to_addrs' => []]);

        $this->assertSame([], $this->resolver->for($message, OutboundType::Reply)['to']);
    }
}

<?php

namespace Database\Seeders;

use App\Enums\FolderRole;
use App\Enums\Provider;
use App\Mail\Data\Address;
use App\Mail\Data\RemoteAttachment;
use App\Mail\Data\RemoteFolder;
use App\Mail\Data\RemoteMessage;
use App\Mail\Support\MessageWriter;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Sample mail so the inbox can be developed and reviewed before any real mailbox
 * is connected. Goes through MessageWriter rather than inserting rows directly, so
 * it exercises the same threading and folder logic a real sync does.
 *
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $writer = app(MessageWriter::class);

        User::updateOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo', 'password' => Hash::make('password')],
        );

        $workspace = MailAccount::updateOrCreate(
            ['email' => 'me@company.com', 'provider' => Provider::GmailApi],
            [
                'label' => 'Work',
                'display_name' => 'Me',
                'credentials' => ['refresh_token' => 'demo'],
                'status' => 'active',
                'backfill_done_at' => now(),
                'last_synced_at' => now(),
            ],
        );

        $outlook = MailAccount::updateOrCreate(
            ['email' => 'me@outlook.com', 'provider' => Provider::Graph],
            [
                'label' => 'Personal',
                'display_name' => 'Me',
                'credentials' => ['refresh_token' => 'demo'],
                'status' => 'active',
                'backfill_done_at' => now(),
                'last_synced_at' => now()->subMinutes(2),
            ],
        );

        $gmail = MailAccount::updateOrCreate(
            ['email' => 'me@gmail.com', 'provider' => Provider::Imap],
            [
                'label' => 'Old Gmail',
                'credentials' => ['password' => 'demo'],
                'status' => 'active',
                'backfill_done_at' => now(),
                // Deliberately behind, so the staleness banner is visible in the UI.
                'last_synced_at' => now()->subHours(4),
            ],
        );

        foreach ([$workspace, $outlook, $gmail] as $account) {
            $writer->storeFolders($account, [
                new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox),
                new RemoteFolder('SENT', 'Sent', FolderRole::Sent),
                new RemoteFolder('ARCHIVE', 'Archive', FolderRole::Archive),
            ]);
        }

        // A thread that spans two accounts: the original landed in Outlook, the reply
        // came back on Workspace. Stitched by References, which is the whole point.
        $writer->store($outlook, new RemoteMessage(
            providerMessageId: 'demo-cross-1',
            rfc822MessageId: '<cross-original@client.test>',
            from: new Address('anna@client.test', 'Anna Bergström'),
            to: [new Address('me@outlook.com')],
            subject: 'Invoice 2418 — payment terms',
            snippet: 'Could we move to net-30 for the next quarter?',
            bodyHtml: '<p>Hi,</p><p>Could we move to <strong>net-30</strong> for the next quarter? '
                .'Our finance team is reworking the schedule.</p>'
                .'<p>Attached is the current statement.</p><p>Thanks,<br>Anna</p>'
                .'<img src="https://tracker.example.test/open.gif" width="1" height="1">',
            bodyText: 'Could we move to net-30 for the next quarter?',
            sentAt: now()->subDays(2)->toDateTimeImmutable(),
            receivedAt: now()->subDays(2)->toDateTimeImmutable(),
            isRead: true,
            sizeBytes: 48213,
            folderRemoteIds: ['INBOX'],
            attachments: [new RemoteAttachment('statement-2418.pdf', 'att-1', 'application/pdf', 184320)],
        ));

        $writer->store($workspace, new RemoteMessage(
            providerMessageId: 'demo-cross-2',
            rfc822MessageId: '<cross-reply@company.com>',
            inReplyTo: '<cross-original@client.test>',
            references: ['<cross-original@client.test>'],
            from: new Address('anna@client.test', 'Anna Bergström'),
            to: [new Address('me@company.com')],
            subject: 'Re: Invoice 2418 — payment terms',
            snippet: 'Forwarding to your work address as well.',
            bodyHtml: '<p>Forwarding to your work address as well, in case that is easier.</p>'
                .'<blockquote><p>Could we move to <strong>net-30</strong> for the next quarter?</p></blockquote>',
            bodyText: 'Forwarding to your work address as well.',
            sentAt: now()->subDay()->toDateTimeImmutable(),
            receivedAt: now()->subDay()->toDateTimeImmutable(),
            isRead: false,
            isStarred: true,
            sizeBytes: 12044,
            folderRemoteIds: ['INBOX'],
        ));

        $samples = [
            [$workspace, 'Deploy window moved to Thursday', 'ops@company.com', 'Ops Team', false, false, 3],
            [$workspace, 'Q3 roadmap review — agenda', 'priya@company.com', 'Priya Raman', true, false, 8],
            [$gmail, 'Your order has shipped', 'no-reply@shop.test', 'Shop', true, false, 26],
            [$gmail, 'চালান ৪২ — ধন্যবাদ', 'hisab@bikroy.test', 'হিসাব বিভাগ', false, false, 30],
            [$outlook, 'Renewal reminder: domain bixcel.com.au', 'billing@registrar.test', 'Registrar', true, true, 52],
            [$outlook, 'Re: Weekend plans', 'sam@friends.test', 'Sam', true, false, 74],
        ];

        foreach ($samples as $i => [$account, $subject, $from, $fromName, $isRead, $isStarred, $hoursAgo]) {
            $writer->store($account, new RemoteMessage(
                providerMessageId: 'demo-'.$i,
                rfc822MessageId: "<demo-{$i}@example.test>",
                from: new Address($from, $fromName),
                to: [new Address($account->email)],
                subject: $subject,
                snippet: 'This is sample mail created by DemoSeeder.',
                bodyHtml: "<p>This is sample mail created by <code>DemoSeeder</code>.</p><p>{$subject}</p>",
                bodyText: 'This is sample mail created by DemoSeeder.',
                sentAt: now()->subHours($hoursAgo)->toDateTimeImmutable(),
                receivedAt: now()->subHours($hoursAgo)->toDateTimeImmutable(),
                isRead: $isRead,
                isStarred: $isStarred,
                sizeBytes: 4096 + $i * 512,
                folderRemoteIds: ['INBOX'],
            ));
        }

        $this->command?->info('Demo data seeded. Sign in as demo@example.com / password');
    }
}

<?php

namespace App\Http\Middleware;

use App\Enums\OutboundStatus;
use App\Models\MailAccount;
use App\Models\OutboundMessage;
use App\Models\Thread;
use App\Support\AccountColor;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'email'),
            ],

            // The account strip is on every screen, and so is the staleness warning.
            // Every failure mode in this app is silent — an expired cursor, a dead
            // IDLE daemon, a rotated app password — so "last synced" belongs in front
            // of the user rather than only in a log.
            'accounts' => fn () => $request->user() === null ? [] : MailAccount::query()
                ->withCount('syncFailures')
                ->orderBy('id')
                ->get()
                ->map(fn (MailAccount $account) => [
                    'id' => $account->id,
                    'label' => $account->label,
                    'email' => $account->email,
                    'provider' => $account->provider->value,
                    'provider_label' => $account->provider->label(),
                    // One hue per mailbox, the same everywhere it appears.
                    'color' => AccountColor::for($account),
                    'status' => $account->status->value,
                    'last_synced_at' => $account->last_synced_at?->toIso8601String(),
                    'last_synced_for_humans' => $account->last_synced_at?->diffForHumans(),
                    'is_stale' => $account->isStale((int) config('mail_providers.sync.stale_after_minutes')),
                    'backfilling' => ! $account->hasFinishedBackfill(),
                    // Connected but nothing has run — almost always a queue worker
                    // that is not up, which nothing else in the UI would reveal.
                    'import_stalled' => $account->importStalled(),
                    // Only while importing: two extra queries per account, and
                    // pointless once the mailbox is filled.
                    'import_progress' => $account->hasFinishedBackfill() ? null : $account->importProgress(),
                    'last_error' => $account->last_error,
                    // Messages the sync could not store. Quarantine without a
                    // visible count is silent data loss with extra steps.
                    'sync_failures' => $account->sync_failures_count,
                    'display_name' => $account->display_name,
                    'signature_html' => $account->signature_html,
                    'removing' => $account->removal_requested_at !== null,
                    'full_history' => $account->backfill_days === 0,
                ])->values(),

            // The sidebar shows these on every screen, so they are computed lazily
            // and skipped entirely on Inertia partial reloads that do not ask for them.
            'counts' => fn () => $request->user() === null ? null : [
                'inbox' => Thread::query()->inView('inbox')->where('unread_count', '>', 0)->count(),
                'unread' => Thread::query()->where('unread_count', '>', 0)->count(),
                'starred' => Thread::query()->where('is_starred', true)->count(),
                // Mail trying to leave. `failed` is separate so the sidebar can
                // shout about it — a failed send is the one state that must never
                // be quiet.
                'outbox' => OutboundMessage::query()->whereIn('status', [
                    OutboundStatus::Queued, OutboundStatus::Sending, OutboundStatus::Failed,
                ])->count(),
                'outbox_failed' => OutboundMessage::query()
                    ->where('status', OutboundStatus::Failed)->count(),
                'drafts' => OutboundMessage::query()
                    ->where('status', OutboundStatus::Draft)->count(),
            ],

            'flash' => [
                'message' => fn () => $request->session()->get('message'),
            ],
        ];
    }
}

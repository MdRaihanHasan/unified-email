<?php

namespace App\Http\Controllers;

use App\Enums\OutboundStatus;
use App\Jobs\SendMessageJob;
use App\Models\OutboundMessage;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The place a send is never invisible.
 *
 * A new message has no thread, so before this page existed a stuck or failed
 * send was unreachable from every screen: the only surface for outbound state
 * was the banner inside an open thread. Drafts share the page because they
 * have the same problem — a draft without a thread was reachable only by
 * knowing its /compose/{id} URL.
 */
class OutboxController
{
    public function index(): Response
    {
        $shape = fn (OutboundMessage $outbound) => [
            'id' => $outbound->id,
            'status' => $outbound->status->value,
            'subject' => $outbound->subject,
            'to' => $outbound->to_addrs ?? [],
            'account' => $outbound->mailAccount?->label,
            'attempts' => $outbound->attempts,
            'error' => $outbound->error,
            'thread_id' => $outbound->thread_id,
            'updated_at' => $outbound->updated_at?->toIso8601String(),
            'updated_for_humans' => $outbound->updated_at?->diffForHumans(),
        ];

        return Inertia::render('Outbox/Index', [
            'undelivered' => OutboundMessage::query()
                ->with('mailAccount')
                ->whereIn('status', [OutboundStatus::Queued, OutboundStatus::Sending, OutboundStatus::Failed])
                ->orderByDesc('updated_at')
                ->get()
                ->map($shape)
                ->values(),
            'drafts' => OutboundMessage::query()
                ->with('mailAccount')
                ->where('status', OutboundStatus::Draft)
                ->orderByDesc('updated_at')
                ->get()
                ->map($shape)
                ->values(),
        ]);
    }

    /** Put a failed or stuck send back on the queue. Message-ID reuse makes this safe. */
    public function retry(OutboundMessage $outbound): RedirectResponse
    {
        if (! in_array($outbound->status, [OutboundStatus::Failed, OutboundStatus::Queued], true)) {
            return back()->with('message', 'That message is not waiting to be retried.');
        }

        $outbound->update(['status' => OutboundStatus::Queued, 'error' => null]);

        SendMessageJob::dispatch($outbound);

        return back()->with('message', 'Queued again.');
    }

    /** Delete an unsent message, staged uploads included. */
    public function discard(OutboundMessage $outbound): RedirectResponse
    {
        if ($outbound->status === OutboundStatus::Sent) {
            return back()->with('message', 'That message has already been sent.');
        }

        $outbound->discard();

        return back()->with('message', 'Discarded.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Jobs\PushFlagsJob;
use App\Mail\Data\FlagChange;
use App\Mail\Support\MessageWriter;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bulk flag changes from the list's selection bar.
 *
 * Only read/unread and star/unstar for now: those go through PushFlagsJob, which
 * is implemented. Archive, delete and move need provider->move(), which lands with
 * the provider adapters — so they are deliberately absent rather than shipped as
 * buttons that fail.
 */
class ThreadActionController
{
    public function __construct(private readonly MessageWriter $writer) {}

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'thread_ids' => ['required', 'array', 'min:1', 'max:200'],
            'thread_ids.*' => ['integer', 'exists:threads,id'],
            'action' => ['required', Rule::in(['read', 'unread', 'star', 'unstar'])],
            // Implicit actions (opening a thread marks it read) skip the flash:
            // "Marked 1 conversation read." on every open is noise, not feedback.
            'quiet' => ['sometimes', 'boolean'],
        ]);

        $change = match ($data['action']) {
            'read' => new FlagChange(isRead: true),
            'unread' => new FlagChange(isRead: false),
            'star' => new FlagChange(isStarred: true),
            'unstar' => new FlagChange(isStarred: false),
        };

        $messages = Message::query()
            ->with('mailAccount')
            ->whereIn('thread_id', $data['thread_ids'])
            ->get();

        if ($messages->isEmpty()) {
            return back();
        }

        // Capture the values before the local write, so a failed push can put each
        // message back to what it actually was rather than to an assumed opposite.
        $previous = $messages->mapWithKeys(fn (Message $m) => [
            $m->provider_message_id => ['is_read' => $m->is_read, 'is_starred' => $m->is_starred],
        ])->all();

        $attributes = array_filter([
            'is_read' => $change->isRead,
            'is_starred' => $change->isStarred,
        ], fn ($v) => $v !== null);

        Message::whereIn('id', $messages->pluck('id'))->update($attributes);

        foreach ($messages->pluck('thread_id')->unique() as $threadId) {
            $this->writer->recountThread($threadId);
        }

        // One job per account: each provider is a separate connection and a separate
        // set of ids, and a failure on one must not revert the others.
        foreach ($messages->groupBy('mail_account_id') as $group) {
            PushFlagsJob::dispatch(
                $group->first()->mailAccount,
                $group->pluck('provider_message_id')->unique()->values()->all(),
                $change,
                $previous,
            );
        }

        if ($request->boolean('quiet')) {
            return back();
        }

        return back()->with('message', $this->confirmation($data['action'], count($data['thread_ids'])));
    }

    private function confirmation(string $action, int $count): string
    {
        $noun = $count === 1 ? 'conversation' : 'conversations';

        return match ($action) {
            'read' => "Marked {$count} {$noun} read.",
            'unread' => "Marked {$count} {$noun} unread.",
            'star' => "Starred {$count} {$noun}.",
            'unstar' => "Unstarred {$count} {$noun}.",
        };
    }
}

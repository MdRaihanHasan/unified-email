<?php

namespace App\Http\Controllers;

use App\Enums\MoveAction;
use App\Jobs\PushFlagsJob;
use App\Jobs\PushMoveJob;
use App\Mail\Data\FlagChange;
use App\Mail\Support\MessageWriter;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Bulk actions from the list's selection bar, the row hover controls, and the
 * keyboard: read/star toggles and the triage verbs (archive, trash, spam,
 * restore). Every one writes locally first and pushes to the provider through a
 * job that reverts on failure, so the UI is instant and never silently wrong.
 */
class ThreadActionController
{
    public function __construct(private readonly MessageWriter $writer) {}

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'thread_ids' => ['required', 'array', 'min:1', 'max:200'],
            'thread_ids.*' => ['integer', 'exists:threads,id'],
            'action' => ['required', Rule::in([
                'read', 'unread', 'star', 'unstar',
                'archive', 'trash', 'spam', 'restore',
            ])],
            // Implicit actions (opening a thread marks it read) skip the flash:
            // "Marked 1 conversation read." on every open is noise, not feedback.
            'quiet' => ['sometimes', 'boolean'],
        ]);

        $messages = Message::query()
            ->with('mailAccount')
            ->whereIn('thread_id', $data['thread_ids'])
            ->get();

        if ($messages->isEmpty()) {
            return back();
        }

        $move = MoveAction::tryFrom($data['action']);

        $move === null
            ? $this->applyFlags($data['action'], $messages)
            : $this->applyMove($move, $messages);

        if ($request->boolean('quiet')) {
            return back();
        }

        return back()->with('message', $this->confirmation($data['action'], count($data['thread_ids'])));
    }

    /** @param  Collection<int, Message>  $messages */
    private function applyFlags(string $action, Collection $messages): void
    {
        $change = match ($action) {
            'read' => new FlagChange(isRead: true),
            'unread' => new FlagChange(isRead: false),
            'star' => new FlagChange(isStarred: true),
            'unstar' => new FlagChange(isStarred: false),
        };

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
    }

    /** @param  Collection<int, Message>  $messages */
    private function applyMove(MoveAction $action, Collection $messages): void
    {
        $messages->load('folders:id');

        foreach ($messages->groupBy('mail_account_id') as $group) {
            $account = $group->first()->mailAccount;

            // The pivot rows as they are now, so a refused push restores exactly
            // what was, not an assumed inverse.
            $previous = $group->mapWithKeys(fn (Message $m) => [
                $m->provider_message_id => $m->folders->pluck('id')->all(),
            ])->all();

            $this->writer->applyMoveLocally($account, $group, $action);

            PushMoveJob::dispatch(
                $account,
                $group->pluck('provider_message_id')->unique()->values()->all(),
                $action,
                $previous,
            );
        }
    }

    private function confirmation(string $action, int $count): string
    {
        $noun = $count === 1 ? 'conversation' : 'conversations';

        return match ($action) {
            'read' => "Marked {$count} {$noun} read.",
            'unread' => "Marked {$count} {$noun} unread.",
            'star' => "Starred {$count} {$noun}.",
            'unstar' => "Unstarred {$count} {$noun}.",
            default => MoveAction::from($action)->pastTense()." — {$count} {$noun}.",
        };
    }
}

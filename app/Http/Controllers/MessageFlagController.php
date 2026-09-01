<?php

namespace App\Http\Controllers;

use App\Jobs\PushFlagsJob;
use App\Mail\Data\FlagChange;
use App\Mail\Support\MessageWriter;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MessageFlagController
{
    public function __construct(private readonly MessageWriter $writer) {}

    /**
     * Flip a flag locally, then queue the push.
     *
     * Local first so the UI never waits on a provider round trip. PushFlagsJob puts
     * the flag back if the push ultimately fails, which is why the previous value is
     * handed to it rather than inferred later.
     */
    public function update(Request $request, Message $message): RedirectResponse
    {
        $data = $request->validate([
            'is_read' => ['nullable', 'boolean'],
            'is_starred' => ['nullable', 'boolean'],
        ]);

        $changes = array_filter($data, fn ($value) => $value !== null);

        if ($changes === []) {
            return back();
        }

        // A cross-account thread holds one copy of this message per mailbox, and
        // the UI shows them as ONE message — so a flag flip has to reach every
        // copy, or the other mailbox re-asserts its old state on the next poll.
        $copies = $message->rfc822_message_id === null
            ? collect([$message->load('mailAccount')])
            : Message::query()
                ->with('mailAccount')
                ->where('thread_id', $message->thread_id)
                ->where('rfc822_message_id', $message->rfc822_message_id)
                ->get();

        $previous = $copies->mapWithKeys(fn (Message $copy) => [
            $copy->provider_message_id => [
                'is_read' => $copy->is_read,
                'is_starred' => $copy->is_starred,
            ],
        ])->all();

        Message::whereIn('id', $copies->pluck('id'))->update($changes);
        $this->writer->recountThread($message->thread_id);

        $change = new FlagChange(
            isRead: $data['is_read'] ?? null,
            isStarred: $data['is_starred'] ?? null,
        );

        foreach ($copies->groupBy('mail_account_id') as $group) {
            PushFlagsJob::dispatch(
                $group->first()->mailAccount,
                $group->pluck('provider_message_id')->all(),
                $change,
                $previous,
            );
        }

        return back();
    }
}

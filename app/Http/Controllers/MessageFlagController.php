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

        $previous = [
            $message->provider_message_id => [
                'is_read' => $message->is_read,
                'is_starred' => $message->is_starred,
            ],
        ];

        $changes = array_filter($data, fn ($value) => $value !== null);

        if ($changes === []) {
            return back();
        }

        $message->update($changes);
        $this->writer->recountThread($message->thread_id);

        PushFlagsJob::dispatch(
            $message->mailAccount,
            [$message->provider_message_id],
            new FlagChange(
                isRead: $data['is_read'] ?? null,
                isStarred: $data['is_starred'] ?? null,
            ),
            $previous,
        );

        return back();
    }
}

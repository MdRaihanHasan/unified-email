<?php

namespace App\Mail\Support;

use App\Enums\OutboundType;
use App\Mail\Data\Address;
use App\Models\MailAccount;
use App\Models\Message;

/**
 * Works out who a reply actually goes to.
 *
 * Easy to get subtly wrong, and the failure modes are embarrassing rather than
 * loud: mailing yourself, dropping half the thread off a reply-all, or replying to
 * a no-reply address when the sender asked for something else. The rules:
 *
 *   - Reply-To wins over From when the sender set one. That header exists
 *     precisely to redirect replies, and mailing lists rely on it.
 *   - Our own addresses never appear as recipients. Every connected account counts,
 *     not just the one being replied from — otherwise replying on the Workspace
 *     account to mail that also reached the personal one CCs yourself.
 *   - Reply-all keeps the original To and Cc as Cc, minus whoever is already in To.
 *   - Bcc is never carried forward. We only see it on mail we sent, and copying it
 *     into a reply exposes recipients the sender deliberately hid.
 */
class RecipientResolver
{
    /**
     * @return array{to: list<Address>, cc: list<Address>}
     */
    public function for(Message $parent, OutboundType $type, ?MailAccount $sendingFrom = null): array
    {
        // Forwards start empty: the user picks who it goes to.
        if ($type === OutboundType::Forward) {
            return ['to' => [], 'cc' => []];
        }

        $ownAddresses = $this->ownAddresses();

        $replyTo = Address::listFromArray($parent->reply_to);
        $from = $parent->from_addr === null ? [] : [Address::fromArray($parent->from_addr)];

        $to = $this->clean($replyTo !== [] ? $replyTo : $from, $ownAddresses);

        // Replying to your own sent message: the useful target is who you sent it
        // to, not the empty set left after removing yourself.
        if ($to === []) {
            $to = $this->clean(Address::listFromArray($parent->to_addrs), $ownAddresses);
        }

        if ($type !== OutboundType::ReplyAll) {
            return ['to' => $to, 'cc' => []];
        }

        $alreadyAddressed = array_map(
            fn (Address $address) => mb_strtolower($address->address),
            $to,
        );

        $cc = $this->clean(
            [
                ...Address::listFromArray($parent->to_addrs),
                ...Address::listFromArray($parent->cc_addrs),
            ],
            [...$ownAddresses, ...$alreadyAddressed],
        );

        return ['to' => $to, 'cc' => $cc];
    }

    /** @return list<string> lowercased addresses of every connected account */
    public function ownAddresses(): array
    {
        return MailAccount::query()
            ->pluck('email')
            ->map(fn (string $email) => mb_strtolower($email))
            ->all();
    }

    /**
     * Drop excluded addresses and duplicates, keeping the first spelling of each.
     *
     * @param  list<Address>  $addresses
     * @param  list<string>  $exclude  lowercased
     * @return list<Address>
     */
    private function clean(array $addresses, array $exclude): array
    {
        $seen = [];
        $result = [];

        foreach ($addresses as $address) {
            $key = mb_strtolower(trim($address->address));

            if ($key === '' || isset($seen[$key]) || in_array($key, $exclude, true)) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $address;
        }

        return $result;
    }
}

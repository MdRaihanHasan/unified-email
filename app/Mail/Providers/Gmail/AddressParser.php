<?php

namespace App\Mail\Providers\Gmail;

use App\Mail\Data\Address;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Throwable;

/**
 * Parses an RFC 5322 address-list header into addresses.
 *
 * Splitting a header on commas is the obvious approach and the wrong one: a display
 * name may legally contain a comma ("Doe, Jane" <j@x>), and so may a comment or a
 * group. So the split walks the string tracking quotes and angle brackets, and each
 * token then goes through Symfony's parser with a regex fallback — real mail is full
 * of headers no strict parser accepts, and losing the sender of a message is worse
 * than accepting a slightly malformed address.
 */
class AddressParser
{
    /** @return list<Address> */
    public static function list(?string $header): array
    {
        $header = trim((string) $header);

        if ($header === '') {
            return [];
        }

        $addresses = [];

        foreach (self::split($header) as $token) {
            $address = self::one($token);

            if ($address !== null) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    public static function first(?string $header): ?Address
    {
        return self::list($header)[0] ?? null;
    }

    public static function one(string $token): ?Address
    {
        $token = trim($token, " \t\r\n,;");

        if ($token === '') {
            return null;
        }

        try {
            $parsed = SymfonyAddress::create($token);

            return new Address($parsed->getAddress(), $parsed->getName() !== '' ? $parsed->getName() : null);
        } catch (Throwable) {
            // Any parse failure falls through, not just an RFC one: Address::create()
            // raises InvalidArgumentException for a string it cannot read at all.
            // A header we cannot parse strictly usually still has a recognisable
            // address in it, and losing the sender is the worse outcome.
        }

        if (preg_match('/<([^>]+)>/', $token, $matches) === 1) {
            $name = trim(str_replace($matches[0], '', $token), " \t\"'");

            return new Address(trim($matches[1]), $name !== '' ? $name : null);
        }

        if (preg_match('/[^\s<>,;"]+@[^\s<>,;"]+/', $token, $matches) === 1) {
            return new Address($matches[0]);
        }

        return null;
    }

    /**
     * Split on commas that are not inside quotes or angle brackets.
     *
     * @return list<string>
     */
    private static function split(string $header): array
    {
        $tokens = [];
        $current = '';
        $inQuotes = false;
        $depth = 0;

        foreach (str_split($header) as $index => $character) {
            if ($character === '"' && ($index === 0 || $header[$index - 1] !== '\\')) {
                $inQuotes = ! $inQuotes;
            }

            if (! $inQuotes) {
                if ($character === '<') {
                    $depth++;
                } elseif ($character === '>') {
                    $depth = max(0, $depth - 1);
                } elseif (($character === ',' || $character === ';') && $depth === 0) {
                    $tokens[] = $current;
                    $current = '';

                    continue;
                }
            }

            $current .= $character;
        }

        $tokens[] = $current;

        return array_values(array_filter(array_map('trim', $tokens), fn (string $t) => $t !== ''));
    }
}

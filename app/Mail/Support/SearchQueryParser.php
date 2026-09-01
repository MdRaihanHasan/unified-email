<?php

namespace App\Mail\Support;

/**
 * The Gmail search grammar people type on reflex, reduced to a plain array.
 *
 * Supported: from: to: subject: has:attachment is:unread|read|starred
 * in:inbox|sent|archive|trash|spam|junk account: before:/after:YYYY-MM-DD
 * (Gmail's Y/m/d spelling accepted too). Everything else stays free text, so a
 * malformed operator degrades to searching for its literal words rather than
 * erroring — and quoted phrases plus -exclusions ride through to websearch.
 */
class SearchQueryParser
{
    private const KEYS = ['from', 'to', 'subject', 'has', 'is', 'in', 'account', 'before', 'after'];

    /**
     * @return array{
     *     text: string,
     *     from: list<string>,
     *     to: list<string>,
     *     subject: list<string>,
     *     has_attachment: bool,
     *     is: list<string>,
     *     in: ?string,
     *     account: ?string,
     *     before: ?\DateTimeImmutable,
     *     after: ?\DateTimeImmutable,
     * }
     */
    public function parse(string $query): array
    {
        $result = [
            'text' => '',
            'from' => [],
            'to' => [],
            'subject' => [],
            'has_attachment' => false,
            'is' => [],
            'in' => null,
            'account' => null,
            'before' => null,
            'after' => null,
        ];

        $free = [];

        // key:value, key:"quoted value", bare "quoted phrase", -exclusion, word.
        preg_match_all('/(?:(\w+):)?("[^"]*"|\S+)/u', trim($query), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = strtolower($match[1]);
            $value = trim($match[2], '"');

            if ($key === '' || ! in_array($key, self::KEYS, true) || $value === '') {
                // Not an operator we know: keep the token verbatim (quotes and
                // minus signs included) so websearch still sees phrases/exclusions.
                $free[] = $match[1] === '' ? $match[2] : $match[0];

                continue;
            }

            match ($key) {
                'from' => $result['from'][] = $value,
                'to' => $result['to'][] = $value,
                'subject' => $result['subject'][] = $value,
                'has' => $result['has_attachment'] = $result['has_attachment'] || strtolower($value) === 'attachment',
                'is' => $result['is'][] = strtolower($value),
                'in' => $result['in'] = $this->view($value),
                'account' => $result['account'] = $value,
                'before' => $result['before'] = $this->date($value),
                'after' => $result['after'] = $this->date($value),
            };
        }

        $result['text'] = implode(' ', $free);

        return $result;
    }

    private function view(string $value): ?string
    {
        return match (strtolower($value)) {
            'inbox' => 'inbox',
            'sent' => 'sent',
            'archive', 'all' => 'all',
            'trash', 'bin' => 'trash',
            'spam', 'junk' => 'junk',
            default => null,
        };
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        // Gmail spells dates 2026/09/01; people also type 2026-09-01.
        $normalized = str_replace('/', '-', $value);

        try {
            return new \DateTimeImmutable($normalized);
        } catch (\Exception) {
            return null;
        }
    }
}

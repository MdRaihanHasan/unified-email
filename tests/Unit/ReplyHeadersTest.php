<?php

namespace Tests\Unit;

use App\Mail\Support\ReplyHeaders;
use App\Models\Thread;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReplyHeadersTest extends TestCase
{
    #[DataProvider('replySubjects')]
    public function test_reply_subject_does_not_stack_prefixes(string $input, string $expected): void
    {
        $this->assertSame($expected, ReplyHeaders::replySubject($input));
    }

    public static function replySubjects(): array
    {
        return [
            'plain' => ['Invoice 42', 'Re: Invoice 42'],
            'already prefixed' => ['Re: Invoice 42', 'Re: Invoice 42'],
            'lowercase prefix' => ['re: Invoice 42', 're: Invoice 42'],
            'counted prefix' => ['Re[2]: Invoice 42', 'Re[2]: Invoice 42'],
            'no space' => ['Re:Invoice 42', 'Re:Invoice 42'],
            'forward is not a reply prefix' => ['Fwd: Invoice 42', 'Re: Fwd: Invoice 42'],
            'empty' => ['', 'Re:'],
        ];
    }

    #[DataProvider('forwardSubjects')]
    public function test_forward_subject_does_not_stack_prefixes(string $input, string $expected): void
    {
        $this->assertSame($expected, ReplyHeaders::forwardSubject($input));
    }

    public static function forwardSubjects(): array
    {
        return [
            'plain' => ['Invoice 42', 'Fwd: Invoice 42'],
            'already fwd' => ['Fwd: Invoice 42', 'Fwd: Invoice 42'],
            'short form' => ['Fw: Invoice 42', 'Fw: Invoice 42'],
            'reply is not a forward prefix' => ['Re: Invoice 42', 'Fwd: Re: Invoice 42'],
        ];
    }

    public function test_short_reference_chains_are_left_alone(): void
    {
        $references = ['<a@x>', '<b@x>', '<c@x>'];

        $this->assertSame($references, ReplyHeaders::trim($references));
    }

    public function test_long_reference_chains_keep_the_root_and_the_tail(): void
    {
        // Threading walks References to find the immediate parent, and clients key
        // the thread off the root, so an over-long chain must lose its middle.
        $references = array_map(fn (int $i) => '<'.str_repeat('x', 200)."-{$i}@example.com>", range(1, 200));

        $trimmed = ReplyHeaders::trim($references);

        $this->assertLessThan(count($references), count($trimmed));
        $this->assertSame($references[0], $trimmed[0], 'the root must survive');
        $this->assertSame(end($references), end($trimmed), 'the immediate parent must survive');
        $this->assertLessThanOrEqual(16384, strlen(implode(' ', $trimmed)));
    }

    #[DataProvider('subjects')]
    public function test_subject_normalisation_strips_reply_and_forward_prefixes(string $input, string $expected): void
    {
        $this->assertSame($expected, Thread::normalizeSubject($input));
    }

    public static function subjects(): array
    {
        return [
            ['Re: Invoice 42', 'invoice 42'],
            ['RE: FWD: Invoice 42', 'invoice 42'],
            ['Re[2]: Invoice 42', 'invoice 42'],
            ['Fwd: Re: Invoice   42', 'invoice 42'],
            ['  Invoice 42  ', 'invoice 42'],
            ['Aw: Invoice 42', 'invoice 42'],
            ['', ''],
        ];
    }
}

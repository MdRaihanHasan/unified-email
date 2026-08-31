<?php

namespace Tests\Unit;

use App\Mail\Providers\Gmail\AddressParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AddressParserTest extends TestCase
{
    public function test_a_bare_address_parses(): void
    {
        $addresses = AddressParser::list('anna@client.test');

        $this->assertCount(1, $addresses);
        $this->assertSame('anna@client.test', $addresses[0]->address);
        $this->assertNull($addresses[0]->name);
    }

    public function test_a_display_name_is_kept(): void
    {
        $address = AddressParser::first('Anna Bergström <anna@client.test>');

        $this->assertSame('anna@client.test', $address->address);
        $this->assertSame('Anna Bergström', $address->name);
    }

    public function test_a_comma_inside_a_quoted_name_does_not_split_the_list(): void
    {
        // Splitting on commas is the obvious approach and the wrong one: a display
        // name may legally contain one.
        $addresses = AddressParser::list('"Doe, Jane" <jane@x.test>, bob@y.test');

        $this->assertCount(2, $addresses);
        $this->assertSame('jane@x.test', $addresses[0]->address);
        $this->assertSame('Doe, Jane', $addresses[0]->name);
        $this->assertSame('bob@y.test', $addresses[1]->address);
    }

    public function test_a_semicolon_separated_list_parses(): void
    {
        $addresses = AddressParser::list('a@x.test; b@y.test');

        $this->assertSame(['a@x.test', 'b@y.test'], array_map(fn ($a) => $a->address, $addresses));
    }

    public function test_a_long_list_keeps_its_order(): void
    {
        $addresses = AddressParser::list('One <a@x.test>, Two <b@x.test>, Three <c@x.test>');

        $this->assertSame(['One', 'Two', 'Three'], array_map(fn ($a) => $a->name, $addresses));
    }

    public function test_a_malformed_header_still_yields_the_address(): void
    {
        // Real mail is full of headers no strict parser accepts, and losing the
        // sender is worse than accepting something slightly malformed.
        $address = AddressParser::first('Anna (Client Services) <anna@client.test');

        $this->assertNotNull($address);
        $this->assertSame('anna@client.test', $address->address);
    }

    public function test_an_unquoted_name_with_punctuation_survives(): void
    {
        $address = AddressParser::first('Support Team - Billing <billing@shop.test>');

        $this->assertSame('billing@shop.test', $address->address);
    }

    public function test_a_unicode_display_name_survives(): void
    {
        $address = AddressParser::first('হিসাব বিভাগ <hisab@bikroy.test>');

        $this->assertSame('hisab@bikroy.test', $address->address);
        $this->assertSame('হিসাব বিভাগ', $address->name);
    }

    #[DataProvider('emptyHeaders')]
    public function test_nothing_in_means_nothing_out(?string $header): void
    {
        $this->assertSame([], AddressParser::list($header));
        $this->assertNull(AddressParser::first($header));
    }

    public static function emptyHeaders(): array
    {
        return [[null], [''], ['   '], [','], ['; ,']];
    }

    public function test_a_header_with_no_address_at_all_is_dropped(): void
    {
        $this->assertSame([], AddressParser::list('undisclosed recipients'));
    }

    public function test_duplicate_separators_do_not_produce_empty_entries(): void
    {
        $addresses = AddressParser::list('a@x.test,, ,b@y.test');

        $this->assertCount(2, $addresses);
    }

    public function test_an_rfc2047_encoded_display_name_decodes(): void
    {
        // The Gmail API returns headers verbatim, so any non-ASCII name arrives as
        // an encoded-word — this is what every Bangla sender looks like undecoded.
        $encoded = '=?UTF-8?B?'.base64_encode('রায়হান হাসান').'?= <raihan@example.com>';

        $address = AddressParser::first($encoded);

        $this->assertSame('raihan@example.com', $address->address);
        $this->assertSame('রায়হান হাসান', $address->name);
    }

    public function test_a_q_encoded_latin1_name_decodes(): void
    {
        $address = AddressParser::first('=?ISO-8859-1?Q?Andr=E9?= <andre@example.com>');

        $this->assertSame('andre@example.com', $address->address);
        $this->assertSame('André', $address->name);
    }
}

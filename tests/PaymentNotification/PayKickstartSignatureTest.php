<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartSignature;

/**
 * PayKickstartSignature against hashes computed without it: by PayKickstart's
 * published PHP validator, run verbatim (on PHP 8.3 and on PHP 7.4, the same
 * hashes), and by a Python reading of the same steps. The payloads are the
 * docs' own example (with its two typos mended: `billing_state` and
 * `campaign_name` lost their `=`), the same fields in reverse order, "0" and
 * empty values, non-ASCII text (Latin-1 and not), whitespace around values, a
 * "|" inside a value, and a refund under another key.
 *
 * A list value (`licenses` when a sale has several) is the one input the
 * validator cannot say anything about on PHP 8, where its trim() of an array
 * throws. On PHP 7.4 it warns, answers null, and array_filter() drops it:
 * the verbatim validator gave d0c2d4e2… for a sale with two licences, the
 * same hash as for that sale without the field. That is the reading taken.
 */
final class PayKickstartSignatureTest extends TestCase
{
    private const KEY = 'kq9X-2mT_7vR.pL4';

    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function vectors(): array
    {
        $docs = [
            'event' => 'subscription-payment', 'mode' => 'live', 'payment_processor' => 'stripe', 'is_rebill' => '1',
            'amount' => '9', 'quantity' => '1', 'buyer_ip' => '196.215.215.215', 'buyer_first_name' => 'Ruggero',
            'buyer_last_name' => 'Sandri-Boriani', 'buyer_email' => 'ruggero@sandri.com',
            'vendor_first_name' => 'Digital',
            'vendor_last_name' => 'Kickstart', 'vendor_email' => 'support@digitalkickstart.com',
            'billing_address_1' => '',
            'billing_address_2' => '', 'billing_city' => '', 'billing_state' => '', 'billing_zip' => '',
            'billing_country' => '',
            'shipping_address_1' => '', 'shipping_address_2' => '', 'shipping_city' => '', 'shipping_state' => '',
            'shipping_zip' => '', 'shipping_country' => '', 'transaction_id' => 'PK-TN0LNO7XWR',
            'invoice_id' => 'PK-PZ1WK636WR',
            'tracking_id' => '216', 'transaction_time' => '1469014598', 'product_id' => '2354',
            'product_name' => 'SEO Snapshot - Main', 'campaign_id' => '215', 'campaign_name' => 'SEO Snapshot',
            'affiliate_first_name' => 'Bob', 'affiliate_last_name' => 'Jones', 'affiliate_email' => 'bob@jones.com',
            'affiliate_commission_amount' => '4', 'affiliate_commission_percent' => '50', 'coupon_code' => '',
            'coupon_type' => '', 'coupon_rate' => '', 'custom_var1' => '123', 'custom_var2' => 'email@user.com',
            'licenses' => '',
            'verification_code' => 'e2288202ad23b877c3498a6db6214b5a417b75a4',
        ];

        return [
            'the docs\' example' => ['3A0GTRFIJHYE', $docs, 'db5d44ab630df0be7ddade1fb120c038311f3355'],
            'the same fields reversed' => [
                '3A0GTRFIJHYE',
                array_reverse($docs, true),
                'db5d44ab630df0be7ddade1fb120c038311f3355',
            ],
            '"0" and empty values' => [self::KEY, [
                'event' => 'sales', 'mode' => 'live', 'is_rebill' => '0', 'amount' => '47.00', 'quantity' => '1',
                'transaction_id' => 'PK-SALE0001', 'invoice_id' => 'PK-INV0001', 'affiliate_commission_amount' => '0',
                'affiliate_commission_percent' => '0', 'coupon_rate' => '0', 'coupon_code' => '',
                'campaign_id' => '215',
                'product_id' => '2354', 'custom_subid' => '42', 'transaction_time' => '1700000000',
            ], '8c64afd98719d22a062f0d5e711dcdb55c6d8c7e'],
            'non-ASCII text' => [self::KEY, [
                'event' => 'sales', 'mode' => 'live', 'amount' => '19.99', 'transaction_id' => 'PK-NONASCII1',
                'buyer_first_name' => 'José', 'buyer_last_name' => 'Müller-李', 'product_name' => 'Café ☕ Course',
                'shipping_city' => '東京', 'custom_subid' => '7',
            ], '2dd9e040dd0809d1f2843e5fe0999f6b230dfba1'],
            'whitespace around values' => [self::KEY, [
                'event' => ' sales ', 'mode' => "live\n", 'amount' => "\t5.5 ", 'transaction_id' => ' PK-WS1 ',
                'custom_subid' => ' 9 ', 'coupon_rate' => ' 0 ', 'coupon_code' => '   ',
            ], '2517aa08a16c8803cc0faba1a2f37b8ff221b990'],
            'a "|" in a value' => [self::KEY, [
                'event' => 'sales', 'mode' => 'test', 'amount' => '1', 'transaction_id' => 'PK-PIPE',
                'product_name' => 'Course | Bonus', 'custom_subid' => '3',
            ], 'f5636fae5d4ba60f6291c78132c4135170167eff'],
            'a refund under another campaign\'s key' => ['another-campaign-key-0001', [
                'event' => 'refund', 'mode' => 'live', 'amount' => '47.00', 'transaction_id' => 'PK-SALE0001',
                'invoice_id' => 'PK-INV0001', 'custom_subid' => '42', 'campaign_id' => '216',
            ], 'f144972dc2bf5f59a1eb1fdd6266772783f31f00'],
            'a list value, left out (the validator on PHP 7.4)' => [self::KEY, [
                'event' => 'sales', 'mode' => 'live', 'is_rebill' => '0', 'amount' => '97.00',
                'transaction_id' => 'PK-LIC0001',
                'custom_subid' => '42', 'licenses' => ['LIC-AAAA-1111', 'LIC-BBBB-2222'],
                'product_name' => 'Two-seat licence',
            ], 'd0c2d4e23aa1843abebdd2a2fca4b17c76f9d5e3'],
            'one licence, a plain value' => [self::KEY, [
                'event' => 'sales', 'mode' => 'live', 'is_rebill' => '0', 'amount' => '97.00',
                'transaction_id' => 'PK-LIC0002',
                'custom_subid' => '42', 'licenses' => 'LIC-CCCC-3333', 'product_name' => 'One-seat licence',
            ], '752d1561193b067b268a4cc1842328df8af3a6a6'],
        ];
    }

    /**
     * @dataProvider vectors
     * @param array<string, mixed> $fields
     */
    public function testTheHashIsThePublishedValidatorsHash(string $key, array $fields, string $expected): void
    {
        self::assertSame($expected, PayKickstartSignature::sign(PayKickstartSignature::message($fields), $key));
        // The hash a notification carries is not part of what it signs.
        $fields['hash'] = $expected;
        self::assertSame($expected, PayKickstartSignature::sign(PayKickstartSignature::message($fields), $key));
    }

    public function testTheMessageIsTheTrimmedNonEmptyValuesInFieldOrder(): void
    {
        self::assertSame("b|c\xE9|1", PayKickstartSignature::message([
            'z' => '1', 'b' => 'b', 'c' => ' cé ', 'a' => '0', 'd' => '', 'e' => ' ', 'hash' => 'x',
            'verification_code' => 'y',
            'list' => ['q', 'r'],
        ]));
    }

    public function testAnotherKeyOrAnotherValueIsAnotherHash(): void
    {
        $fields = ['event' => 'sales', 'amount' => '47.00', 'custom_subid' => '42'];
        $hash = PayKickstartSignature::sign(PayKickstartSignature::message($fields), self::KEY);

        $sign = static fn (array $f, string $key): string => PayKickstartSignature::sign(
            PayKickstartSignature::message($f),
            $key
        );

        self::assertNotSame($hash, $sign($fields, self::KEY . 'x'));
        self::assertNotSame($hash, $sign(['amount' => '470.0'] + $fields, self::KEY));
        self::assertNotSame($hash, $sign(['custom_subid' => '43'] + $fields, self::KEY));
    }

    /**
     * The conversion is written out rather than left to iconv(), whose
     * //IGNORE differs between C libraries. For valid UTF-8 it is glibc's
     * iconv exactly; for bytes that are not UTF-8 it drops them, where
     * iconv() sometimes fails (a value cut inside a character) and the
     * validator then joins an empty value: a string PayKickstart does not send.
     */
    public function testTheConversionIsGlibcIconvForEveryValidString(): void
    {
        if (!function_exists('iconv') || ICONV_IMPL !== 'glibc') {
            self::markTestSkipped(
                'glibc\'s iconv is the reference, and this PHP has ' . (function_exists('iconv') ? ICONV_IMPL : 'none')
            );
        }
        mt_srand(202);
        $points = [0x41, 0x7E, 0x80, 0xA0, 0xE9, 0xFF, 0x100, 0x7FF, 0x800, 0x4E2D, 0xFFFD, 0x10000, 0x1F600, 0x10FFFF];
        $compared = 0;
        for ($n = 0; $n < 3000; $n++) {
            $text = '';
            for ($k = mt_rand(0, 8); $k > 0; $k--) {
                $point = mt_rand(0, 3) === 0 ? $points[array_rand($points)] : mt_rand(0x20, 0x2FFFF);
                $text .= mb_chr($point >= 0xD800 && $point <= 0xDFFF ? 0x41 : $point, 'UTF-8');
            }
            $reference = iconv('UTF-8', 'ISO-8859-1//IGNORE', $text);
            self::assertSame($reference, PayKickstartSignature::latin1($text), bin2hex($text));
            $compared++;
        }
        for ($n = 0; $n < 3000; $n++) {
            $bytes = '';
            for ($k = mt_rand(1, 6); $k > 0; $k--) {
                $bytes .= chr(mt_rand(0, 255));
            }
            $reference = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $bytes);
            if ($reference !== false) {
                self::assertSame($reference, PayKickstartSignature::latin1($bytes), bin2hex($bytes));
                $compared++;
            }
        }
        self::assertGreaterThan(4000, $compared);
        self::assertSame("Jos\xE9 M\xFCller- caf\xE9", PayKickstartSignature::latin1('José Müller-李 café'));
        self::assertSame(
            'ab',
            PayKickstartSignature::latin1("a\xC0\xAF\xED\xA0\x80b"),
            'an overlong and a surrogate are dropped'
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prosper202\Integration\PayKickstartIpn;

final class PayKickstartIpnTest extends TestCase
{
    private const SECRET = 'pk-campaign-secret';

    /**
     * PayKickstart's published PHP is_valid_ipn(), returning the hash it
     * compares instead of the comparison.
     *
     * @param array<string, string> $data
     */
    private static function official(array $data, string $secret_key): string
    {
        unset($data['hash'], $data['verification_code']);
        $data = array_filter(array_map('trim', $data));
        foreach ($data as $key => $item) {
            $data[$key] = iconv("UTF-8", "ISO-8859-1//IGNORE", $item);
        }
        ksort($data, SORT_STRING);

        return hash_hmac('sha1', implode("|", $data), $secret_key);
    }

    /** @return array<string, string> */
    private static function sale(string $firstName = 'Ruggero', string $lastName = 'Sandri-Boriani'): array
    {
        return [
            'event' => 'sales',
            'mode' => 'live',
            'amount' => '47',
            'buyer_first_name' => $firstName,
            'buyer_last_name' => $lastName,
            'transaction_id' => 'PK-TN0LNO7XWR',
            'invoice_id' => 'PK-PZ1WK636WR',
            'custom_subid' => '1001',
            'affiliate_commission_amount' => '0',
            'coupon_code' => '',
            'verification_code' => 'e2288202ad23b877c3498a6db6214b5a417b75a4',
        ];
    }

    public function testAnAsciiNotificationSignedAsThePhpSampleSignsItVerifies(): void
    {
        $post = self::sale();
        $post['hash'] = self::official($post, self::SECRET);
        self::assertTrue(PayKickstartIpn::verify($post, self::SECRET));
    }

    public function testALatin1NameSignedAsThePhpSampleSignsItVerifies(): void
    {
        $post = self::sale('José', 'Müller');
        $post['hash'] = self::official($post, self::SECRET);
        self::assertTrue(PayKickstartIpn::verify($post, self::SECRET));
    }

    public function testTheSameNameSignedAsTheJavaScriptSampleSignsItVerifies(): void
    {
        // PayKickstart's JavaScript is_valid_ipn (node's HMAC standing in for
        // its Crypto helper), run on this payload and secret: UTF-8 bytes.
        $post = self::sale('José', 'Müller');
        $post['hash'] = '73af8ac156951656fd09e73d0e94f7a926e8ffee';
        self::assertTrue(PayKickstartIpn::verify($post, self::SECRET));
        self::assertNotSame($post['hash'], self::official($post, self::SECRET), 'the two samples differ here');
    }

    /** @dataProvider tampered */
    public function testEverySignedValueIsCovered(string $field, string $value): void
    {
        $post = self::sale();
        $post['hash'] = self::official($post, self::SECRET);
        $post[$field] = $value;
        self::assertFalse(PayKickstartIpn::verify($post, self::SECRET), "$field changed after signing");
    }

    /** @return iterable<string, array{string, string}> */
    public static function tampered(): iterable
    {
        yield 'the click id' => ['custom_subid', '1002'];
        yield 'the amount' => ['amount', '470'];
        yield 'the event' => ['event', 'refund'];
        yield 'test mode' => ['mode', 'test'];
        yield 'an added field' => ['custom_extra', 'x'];
    }

    public function testAnotherSecretOrNoneDoesNotVerify(): void
    {
        $post = self::sale();
        $post['hash'] = self::official($post, self::SECRET);
        self::assertFalse(PayKickstartIpn::verify($post, 'another'));
        self::assertFalse(PayKickstartIpn::verify($post, ''));
        unset($post['hash']);
        self::assertFalse(PayKickstartIpn::verify($post, self::SECRET));
    }

    public function testSeveralCampaignSecretsAreSavedTogether(): void
    {
        self::assertSame(['a1', 'b2', 'c3'], PayKickstartIpn::secrets(" a1  b2,c3\n"));
        self::assertSame([], PayKickstartIpn::secrets(''));
    }
}

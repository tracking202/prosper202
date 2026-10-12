<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prosper202\Integration\JvzooIpn;

final class JvzooIpnTest extends TestCase
{
    private const SECRET = 'MyJVZIPNSecretKey';

    /**
     * JVZoo's own jvzipnVerification() from "JVZIPN - How to create your own
     * integrated script", reading $post where it reads $_POST: the spec the
     * implementation is held to.
     *
     * @param array<string, string> $post
     */
    private static function official(array $post, string $secretKey): string
    {
        $pop = "";
        $ipnFields = array();
        foreach ($post as $key => $value) {
            if ($key == "cverify") {
                continue;
            }
            $ipnFields[] = $key;
        }
        sort($ipnFields);
        foreach ($ipnFields as $field) {
            $pop = $pop . $post[$field] . "|";
        }
        $pop = $pop . $secretKey;
        if ('UTF-8' != mb_detect_encoding($pop)) {
            $pop = mb_convert_encoding($pop, "UTF-8");
        }
        $calcedVerify = sha1($pop);

        return strtoupper(substr($calcedVerify, 0, 8));
    }

    /** @return array<string, string> JVZoo's v1 sample, in an order a post may arrive in (the signature sorts) */
    private static function sale(): array
    {
        return [
            'ctransaction' => 'SALE',
            'ctransreceipt' => '1R402129DY123456V',
            'caffitid' => '1001',
            'ctransamount' => '1.00',
            'ccustname' => 'Joe Smith',
            'ccustemail' => 'joe@test.com',
            'ccustcc' => 'US',
            'ccuststate' => '',
            'cproditem' => '45',
            'cprodtitle' => 'Test Product',
            'cprodtype' => 'STANDARD',
            'ctransaffiliate' => '1',
            'ctranspaymentmethod' => 'PYPL',
            'ctranstime' => '1613419768',
            'ctransvendor' => '4',
            'cupsellreceipt' => '',
            'cvendthru' => '',
        ];
    }

    /** @return array<string, string> */
    private static function signed(array $post): array
    {
        $post['cverify'] = self::official($post, self::SECRET);

        return $post;
    }

    public function testANotificationSignedAsJvzooSignsItVerifies(): void
    {
        self::assertTrue(JvzooIpn::verify(self::signed(self::sale()), self::SECRET));

        $accented = self::sale();
        $accented['ccustname'] = 'José Müller';
        self::assertTrue(JvzooIpn::verify(self::signed($accented), self::SECRET));
    }

    /** @dataProvider tampered */
    public function testEveryFieldIsCovered(string $field, string $value): void
    {
        $post = self::signed(self::sale());
        $post[$field] = $value;
        self::assertFalse(JvzooIpn::verify($post, self::SECRET), "$field changed after signing");
    }

    /** @return iterable<string, array{string, string}> */
    public static function tampered(): iterable
    {
        yield 'the click id' => ['caffitid', '1002'];
        yield 'the amount' => ['ctransamount', '100.00'];
        yield 'the type' => ['ctransaction', 'RFND'];
        yield 'the receipt' => ['ctransreceipt', '1R402129DY123456W'];
        yield 'an added field' => ['cextra', 'x'];
    }

    public function testAnotherSecretOrNoneDoesNotVerify(): void
    {
        $post = self::signed(self::sale());
        self::assertFalse(JvzooIpn::verify($post, 'another'));
        self::assertFalse(JvzooIpn::verify($post, ''));
        unset($post['cverify']);
        self::assertFalse(JvzooIpn::verify($post, self::SECRET));
    }

    public function testAnArrayValueIsRefusedRatherThanSignedAsTheWordArray(): void
    {
        $post = self::sale();
        $post['ccustname'] = ['Joe'];
        $post['cverify'] = self::official(['ccustname' => 'Array'] + self::sale(), self::SECRET);
        self::assertFalse(JvzooIpn::verify($post, self::SECRET));
    }

    public function testAV2NotificationIsRecognised(): void
    {
        self::assertTrue(JvzooIpn::isV2(['transaction_type' => 'SALE', 'paykey' => 'PT-1', 'cverify' => 'ABCD1234']));
        self::assertFalse(JvzooIpn::isV2(self::sale()));
    }

    /** @dataProvider amounts */
    public function testTheAmountReadsPenniesAndDollars(mixed $raw, ?string $expected): void
    {
        self::assertSame($expected, JvzooIpn::amount($raw));
    }

    /** @return iterable<string, array{mixed, string|null}> */
    public static function amounts(): iterable
    {
        yield 'pennies, as documented' => ['1000', '10.00'];
        yield 'one penny' => ['1', '0.01'];
        yield 'dollars, as the sample shows' => ['1.00', '1.00'];
        yield 'dollars and cents' => ['47.5', '47.5'];
        yield 'negative' => ['-1000', null];
        yield 'a word' => ['ten', null];
        yield 'empty' => ['', null];
        yield 'too large' => ['1234567.00', null];
        yield 'not a string' => [1000, null];
    }
}

<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\PaymentNotification\JVZoo\JVZooSignature;

/**
 * JVZooSignature against cverify values computed without it.
 *
 * The first two are other people's: niteoweb.ipn.jvzoo's and pyramid_bimt's
 * mocked JVZoo requests, each published with its cverify for the key
 * "secret". The rest were computed for this test by JVZoo's published PHP
 * validator (support.jvzoo.com, "JVZIPN - How to create your own integrated
 * script"), run verbatim on PHP 8.3, and by a separate Python reading of the
 * article's steps; the two agreed on every one. The payloads are the
 * article's sample POST, the same fields reversed, an affiliate sale, a
 * vendor sale with buy-link variables, a rebill, non-ASCII text, whitespace
 * around values, a "|" inside a value, and a refund under another key.
 */
final class JVZooSignatureTest extends TestCase
{
    private const KEY = 'kq9X2mT7vRpL4wZ8nB3';

    /** @return array<string, array{string, array<string, string>, string}> */
    public static function vectors(): array
    {
        return [
            'niteoweb.ipn.jvzoo\'s mocked request' => ['secret', [
                'ccustname' => 'JohnSmith', 'ccuststate' => '', 'ccustcc' => '', 'ccustemail' => 'test@email.com',
                'cproditem' => '1', 'cprodtitle' => 'TestProduct', 'cprodtype' => 'STANDARD',
                'ctransaction' => 'SALE', 'ctransaffiliate' => 'affiliate@email.com', 'ctransamount' => '1000',
                'ctranspaymentmethod' => '', 'ctransvendor' => '', 'ctransreceipt' => '1', 'cupsellreceipt' => '',
                'caffitid' => '', 'cvendthru' => '', 'ctranstime' => '1350388651',
            ], 'AACDD10E'],
            'pyramid_bimt\'s mocked request' => ['secret', [
                'ccustname' => 'JohnSmith', 'ccuststate' => '', 'ccustcc' => '', 'ccustemail' => 'jvzoo@bar.com',
                'cproditem' => '1', 'cprodtitle' => 'TestProduct', 'cprodtype' => 'STANDARD',
                'ctransaction' => 'SALE', 'ctransaffiliate' => 'aff@bar.com', 'ctransamount' => '1234',
                'ctranspaymentmethod' => '', 'ctransvendor' => '', 'ctransreceipt' => '1', 'cupsellreceipt' => '',
                'caffitid' => '', 'cvendthru' => '', 'ctranstime' => '1350388651',
            ], 'D1EA7E5A'],
            'the docs\' sample POST' => [self::KEY, [
                'caffitid' => 'potd', 'ccustcc' => 'US', 'ccustemail' => 'joe@test.com', 'ccustname' => 'Joe Smith',
                'ccuststate' => '', 'cproditem' => '45', 'cprodtitle' => 'Test Product', 'cprodtype' => 'STANDARD',
                'ctransaction' => 'SALE', 'ctransaffiliate' => '1', 'ctransamount' => '1.00',
                'ctranspaymentmethod' => 'PYPL', 'ctransreceipt' => '1R402129DY123456V',
                'ctranstime' => '1613419768', 'ctransvendor' => '4', 'cupsellreceipt' => '', 'cvendthru' => '',
            ], 'AAE9CC2F'],
            'the same fields reversed' => [self::KEY, [
                'cvendthru' => '', 'cupsellreceipt' => '', 'ctransvendor' => '4', 'ctranstime' => '1613419768',
                'ctransreceipt' => '1R402129DY123456V', 'ctranspaymentmethod' => 'PYPL', 'ctransamount' => '1.00',
                'ctransaffiliate' => '1', 'ctransaction' => 'SALE', 'cprodtype' => 'STANDARD',
                'cprodtitle' => 'Test Product', 'cproditem' => '45', 'ccuststate' => '', 'ccustname' => 'Joe Smith',
                'ccustemail' => 'joe@test.com', 'ccustcc' => 'US', 'caffitid' => 'potd',
            ], 'AAE9CC2F'],
            'an affiliate sale on click 42' => [self::KEY, [
                'caffitid' => '42', 'ccustcc' => 'US', 'ccustemail' => 'joe@test.com', 'ccustname' => 'Joe Smith',
                'ccuststate' => '', 'cproditem' => '45', 'cprodtitle' => 'Test Product', 'cprodtype' => 'STANDARD',
                'ctransaction' => 'SALE', 'ctransaffiliate' => '1', 'ctransamount' => '47.00',
                'ctranspaymentmethod' => 'PYPL', 'ctransreceipt' => '5CA16795AA047184W',
                'ctranstime' => '1613419768', 'ctransvendor' => '4', 'cupsellreceipt' => '', 'cvendthru' => '',
            ], 'FC3BF755'],
            'a vendor sale with subid=42' => [self::KEY, [
                'caffitid' => '', 'ccustcc' => 'US', 'ccustemail' => 'joe@test.com', 'ccustname' => 'Joe Smith',
                'ccuststate' => '', 'cproditem' => '45', 'cprodtitle' => 'Test Product', 'cprodtype' => 'STANDARD',
                'ctransaction' => 'SALE', 'ctransaffiliate' => '1', 'ctransamount' => '97.00',
                'ctranspaymentmethod' => 'PYPL', 'ctransreceipt' => '0GF15564W1339831T',
                'ctranstime' => '1613419768', 'ctransvendor' => '4', 'cupsellreceipt' => '',
                'cvendthru' => 'subid=42&utm=fb',
            ], '389B5934'],
            'a rebill' => [self::KEY, [
                'caffitid' => '42', 'ccustcc' => 'US', 'ccustemail' => 'joe@test.com', 'ccustname' => 'Joe Smith',
                'ccuststate' => '', 'cproditem' => '45', 'cprodtitle' => 'Test Product', 'cprodtype' => 'RECURRING',
                'ctransaction' => 'BILL', 'ctransaffiliate' => '1', 'ctransamount' => '10.00',
                'ctranspaymentmethod' => 'PYPL', 'ctransreceipt' => '4W609755AY3796749-B002',
                'ctranstime' => '1613419768', 'ctransvendor' => '4', 'cupsellreceipt' => '', 'cvendthru' => '',
            ], '5F95EB35'],
            'non-ASCII text' => [self::KEY, [
                'caffitid' => '42', 'ccustcc' => 'US', 'ccustemail' => 'joe@test.com',
                'ccustname' => 'José Müller-李', 'ccuststate' => '', 'cproditem' => '45',
                'cprodtitle' => 'Café ☕ Course', 'cprodtype' => 'STANDARD', 'ctransaction' => 'SALE',
                'ctransaffiliate' => '1', 'ctransamount' => '1.00', 'ctranspaymentmethod' => 'PYPL',
                'ctransreceipt' => '1R402129DY123456V', 'ctranstime' => '1613419768', 'ctransvendor' => '4',
                'cupsellreceipt' => '', 'cvendthru' => '',
            ], '012CB079'],
            'whitespace kept, not trimmed' => [self::KEY, [
                'caffitid' => '42', 'ccustcc' => 'US', 'ccustemail' => 'joe@test.com',
                'ccustname' => ' Joe  Smith ', 'ccuststate' => '', 'cproditem' => '45',
                'cprodtitle' => "Course\n", 'cprodtype' => 'STANDARD', 'ctransaction' => 'SALE',
                'ctransaffiliate' => '1', 'ctransamount' => '1.00', 'ctranspaymentmethod' => 'PYPL',
                'ctransreceipt' => '1R402129DY123456V', 'ctranstime' => '1613419768', 'ctransvendor' => '4',
                'cupsellreceipt' => '', 'cvendthru' => '',
            ], 'B5D8CF72'],
            'a "|" in a value' => [self::KEY, [
                'caffitid' => '42', 'ccustcc' => 'US', 'ccustemail' => 'joe@test.com', 'ccustname' => 'Joe Smith',
                'ccuststate' => '', 'cproditem' => '45', 'cprodtitle' => 'Course | Bonus',
                'cprodtype' => 'STANDARD', 'ctransaction' => 'SALE', 'ctransaffiliate' => '1',
                'ctransamount' => '1.00', 'ctranspaymentmethod' => 'PYPL', 'ctransreceipt' => '1R402129DY123456V',
                'ctranstime' => '1613419768', 'ctransvendor' => '4', 'cupsellreceipt' => '', 'cvendthru' => '',
            ], 'D1B28023'],
            'a refund under another key' => ['another-account-JVZIPN-9', [
                'caffitid' => '42', 'ccustcc' => 'US', 'ccustemail' => 'joe@test.com', 'ccustname' => 'Joe Smith',
                'ccuststate' => '', 'cproditem' => '45', 'cprodtitle' => 'Test Product', 'cprodtype' => 'STANDARD',
                'ctransaction' => 'RFND', 'ctransaffiliate' => '1', 'ctransamount' => '47.00',
                'ctranspaymentmethod' => 'PYPL', 'ctransreceipt' => '5CA16795AA047184W',
                'ctranstime' => '1613419768', 'ctransvendor' => '4', 'cupsellreceipt' => '', 'cvendthru' => '',
            ], 'DB690253'],
        ];
    }

    /**
     * @dataProvider vectors
     * @param array<string, string> $fields
     */
    public function testTheCverifyIsThePublishedValidatorsCverify(string $key, array $fields, string $expected): void
    {
        $message = JVZooSignature::message($fields);
        self::assertNotNull($message);
        self::assertSame($expected, JVZooSignature::sign($message, $key));
        // The cverify a notification carries is not part of what it signs.
        $fields['cverify'] = $expected;
        self::assertSame($message, JVZooSignature::message($fields));
    }

    public function testTheMessageIsEveryValueAsSentInFieldNameOrder(): void
    {
        self::assertSame("|b| c ||1|", JVZooSignature::message([
            'z' => '1', 'b' => 'b', 'c' => ' c ', 'a' => '', 'd' => '', 'cverify' => 'ABCD1234',
        ]));
    }

    public function testAListOrTextThatIsNotUtf8IsNotSigned(): void
    {
        self::assertNull(JVZooSignature::message(['caffitid' => ['42'], 'ctransaction' => 'SALE']));
        self::assertNull(JVZooSignature::message(['ccustname' => "Jos\xE9", 'ctransaction' => 'SALE']));
        self::assertNotNull(JVZooSignature::message(['ccustname' => 'José', 'ctransaction' => 'SALE']));
    }

    public function testAnotherKeyOrAnotherValueIsAnotherCverify(): void
    {
        $fields = self::vectors()['an affiliate sale on click 42'][1];
        $message = (string) JVZooSignature::message($fields);

        self::assertNotSame(JVZooSignature::sign($message, self::KEY), JVZooSignature::sign($message, self::KEY . 'x'));
        foreach (['caffitid' => '43', 'ctransamount' => '470.00', 'ctransreceipt' => 'X'] as $name => $value) {
            $changed = (string) JVZooSignature::message([$name => $value] + $fields);
            self::assertNotSame(
                JVZooSignature::sign($message, self::KEY),
                JVZooSignature::sign($changed, self::KEY),
                $name
            );
        }
    }
}

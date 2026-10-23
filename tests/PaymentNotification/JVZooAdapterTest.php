<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\JVZoo\JVZooAdapter;
use Prosper202\PaymentNotification\JVZoo\JVZooRole;
use Prosper202\PaymentNotification\JVZoo\JVZooSignature;
use Prosper202\PaymentNotification\Notification;
use Prosper202\PaymentNotification\NotificationKind;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\Refusal;

/**
 * How JVZooAdapter verifies a JVZIPN and reads it, for each role. The
 * database half (the stored keys, what a notification records) is
 * JVZooIpnIntegrationTest's.
 */
final class JVZooAdapterTest extends TestCase
{
    private const KEY_A = 'account-three-JVZIPN-1';
    private const KEY_OTHER = 'account-seven-JVZIPN-1';

    private const AMOUNT_RULE = '; an amount is an exact decimal such as 47.00, not negative, at most 999999.99999.';

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function signed(array $fields, string $key): array
    {
        return $fields + ['cverify' => JVZooSignature::sign((string) JVZooSignature::message($fields), $key)];
    }

    /**
     * Every field JVZIPN v1 posts, as an affiliate's sale on click 42.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function sale(array $overrides = []): array
    {
        return $overrides + [
            'caffitid' => '42', 'ccustcc' => 'US', 'ccustemail' => 'buyer@example.com', 'ccustname' => 'Joe Buyer',
            'ccuststate' => 'CA', 'cproditem' => '414851', 'cprodtitle' => 'Test Product', 'cprodtype' => 'STANDARD',
            'ctransaction' => 'SALE', 'ctransaffiliate' => '17', 'ctransamount' => '47.00',
            'ctranspaymentmethod' => 'PYPL', 'ctransreceipt' => '5CA16795AA047184W', 'ctranstime' => '1700000000',
            'ctransvendor' => '873381', 'cupsellreceipt' => '', 'cvendthru' => '',
        ];
    }

    /** @param array<array-key, mixed> $fields */
    private static function request(array $fields, string $type = 'application/x-www-form-urlencoded'): InboundRequest
    {
        return new InboundRequest('POST', $type, $fields, '203.0.113.9', '203.0.113.9', 'JVZoo');
    }

    /** @param array<string, mixed> $overrides */
    private static function parsed(array $overrides, ?JVZooRole $role = JVZooRole::AFFILIATE): Notification
    {
        return (new JVZooAdapter($role))->parse(self::request(self::sale($overrides)));
    }

    private static function refusal(callable $call): Refusal
    {
        try {
            $call();
        } catch (Refusal $refusal) {
            return $refusal;
        }
        self::fail('no refusal');
    }

    /**
     * @param array<int, list<string>> $secrets
     * @return array<int, int>|null
     */
    private static function verified(array $fields, array $secrets): ?array
    {
        return (new JVZooAdapter(JVZooRole::AFFILIATE))->verify(self::request($fields), $secrets)?->secretByAccount;
    }

    public function testTheAccountIsTheOneWhoseKeyVerifies(): void
    {
        $secrets = [3 => [self::KEY_A], 7 => [self::KEY_OTHER]];

        self::assertSame([3 => 0], self::verified(self::signed(self::sale(), self::KEY_A), $secrets));
        self::assertSame([7 => 0], self::verified(self::signed(self::sale(), self::KEY_OTHER), $secrets));
        // Two accounts holding one JVZoo account's key: both, and the click decides.
        $shared = [3 => [self::KEY_A], 7 => [self::KEY_A]];
        self::assertSame([3 => 0, 7 => 0], self::verified(self::signed(self::sale(), self::KEY_A), $shared));
    }

    public function testNothingVerifiesAChangedValueAnotherKeyOrALooseCverify(): void
    {
        $secrets = [3 => [self::KEY_A]];
        $signed = self::signed(self::sale(), self::KEY_A);

        $changes = ['ctransamount' => '470.00', 'caffitid' => '43', 'ctransreceipt' => 'X1', 'ccustname' => ''];
        foreach ($changes as $name => $value) {
            self::assertNull(self::verified([$name => $value] + $signed, $secrets), $name);
        }
        self::assertNull(self::verified(self::signed(self::sale(), 'not-a-stored-key'), $secrets));
        self::assertNull(self::verified($signed, []));
        // An empty value is still a "|": adding a field changes the cverify.
        self::assertNull(self::verified($signed + ['cextra' => ''], $secrets));
        self::assertNull(self::verified(['cverify' => strtolower($signed['cverify'])] + $signed, $secrets));
        // JVZoo's sample compares with ==, which reads numeric strings as numbers.
        foreach (['0E123456', ' ' . $signed['cverify'], $signed['cverify'] . ' '] as $loose) {
            self::assertNull(self::verified(['cverify' => $loose] + $signed, $secrets), $loose);
        }
    }

    /**
     * JVZoo's sample compares with ==, under which two numeric strings are
     * equal as numbers: for a cverify that is all digits, "+12345678" and
     * "12345678.0" would verify. hash_equals() takes only the value itself.
     */
    public function testACverifyOfDigitsIsComparedAsTextNotAsANumber(): void
    {
        $message = (string) JVZooSignature::message(self::sale());
        for ($i = 0, $key = ''; $i < 5000; $i++) {
            $key = 'digits-key-' . $i;
            if (preg_match('/^\d{8}$/D', JVZooSignature::sign($message, $key)) === 1) {
                break;
            }
        }
        $cverify = JVZooSignature::sign($message, $key);
        self::assertMatchesRegularExpression('/^\d{8}$/D', $cverify, 'a key whose cverify is all digits');
        $secrets = [3 => [$key]];

        self::assertSame([3 => 0], self::verified(self::sale() + ['cverify' => $cverify], $secrets));
        foreach (['+' . $cverify, $cverify . '.0', $cverify . 'e0', ' ' . $cverify] as $loose) {
            self::assertTrue($loose == $cverify, 'the sample\'s == would take ' . $loose);
            self::assertNull(self::verified(self::sale() + ['cverify' => $loose], $secrets), $loose);
        }
    }

    public function testARequestThatIsNotAJvzipnIsRefusedBeforeAnyKey(): void
    {
        $adapter = new JVZooAdapter(JVZooRole::AFFILIATE);
        $secrets = [3 => [self::KEY_A]];
        $refused = static fn (array $fields, string $type = 'application/x-www-form-urlencoded'): Refusal
            => self::refusal(static fn () => $adapter->verify(self::request($fields, $type), $secrets));

        self::assertSame(OutcomeKind::UNSUPPORTED_BODY, $refused([], 'application/json')->outcome->kind);
        self::assertSame(OutcomeKind::MALFORMED, $refused([])->outcome->kind);
        $v2 = $refused(['transaction_type' => 'SALE', 'paykey' => 'AP-1', 'total' => '47.00', 'cverify' => 'A1B2C3D4']);
        self::assertSame(OutcomeKind::UNSUPPORTED_BODY, $v2->outcome->kind);
        self::assertStringContainsString('JVZIPN v2', $v2->outcome->message);
        self::assertSame(OutcomeKind::UNVERIFIED, $refused(self::sale())->outcome->kind);
        self::assertSame(OutcomeKind::UNVERIFIED, $refused(['cverify' => ['A']] + self::sale())->outcome->kind);
        $list = $refused(['ccustcc' => ['US']] + self::signed(self::sale(), self::KEY_A));
        self::assertSame(OutcomeKind::MALFORMED, $list->outcome->kind);
        $latin1 = $refused(['ccustname' => "Jos\xE9"] + self::signed(self::sale(), self::KEY_A));
        self::assertSame(OutcomeKind::MALFORMED, $latin1->outcome->kind);
    }

    public function testEachTransactionTypeForAnAffiliate(): void
    {
        self::assertEquals(
            new Notification(NotificationKind::SALE, 'SALE', '5CA16795AA047184W', null, '47.00', null, 42, 1700000000),
            self::parsed([])
        );
        $rebill = '4W609755AY3796749-B002';
        $bill = self::parsed(['ctransaction' => 'BILL', 'ctransreceipt' => $rebill, 'ctransamount' => '10.00']);
        self::assertEquals(
            new Notification(NotificationKind::REBILL, 'BILL', $rebill, null, '10.00', null, 42, 1700000000),
            $bill
        );
        $reversals = [
            'RFND' => NotificationKind::REFUND, 'CGBK' => NotificationKind::CHARGEBACK,
            'INSF' => NotificationKind::CHARGEBACK,
        ];
        foreach ($reversals as $type => $kind) {
            // The whole payment, whatever amount the reversal states.
            self::assertEquals(
                new Notification($kind, $type, '5CA16795AA047184W', '5CA16795AA047184W', null, null, 42, 1700000000),
                self::parsed(['ctransaction' => $type, 'ctransamount' => '-47.00']),
                $type
            );
        }
        foreach (['CANCEL-REBILL', 'UNCANCEL-REBILL'] as $type) {
            self::assertEquals(
                new Notification(NotificationKind::IGNORED, $type),
                self::parsed(['ctransaction' => $type])
            );
        }
        self::assertEquals(new Notification(NotificationKind::TEST, 'TEST'), self::parsed(['ctransaction' => 'TEST']));
        self::assertSame('0.00', self::parsed(['ctransamount' => '0.00'])->amount, 'a free sale is a sale at 0');
        self::assertNull(self::parsed(['ctranstime' => 'yesterday'])->occurredAt, 'logged only, never refused');
    }

    public function testTheVendorsClickIsTheBuyLinksSubidAndTheTidIsNotRead(): void
    {
        $vendor = static fn (array $overrides): Notification => self::parsed($overrides, JVZooRole::VENDOR);

        self::assertSame(77, $vendor(['caffitid' => '42', 'cvendthru' => 'subid=77'])->clickId);
        self::assertSame(77, $vendor(['cvendthru' => '?utm=fb&subid=77&x=1'])->clickId);
        self::assertSame(77, $vendor(['cvendthru' => 'subid=77&subid=77'])->clickId, 'the same value twice is one');
        self::assertSame(77, $vendor(['cvendthru' => 'sub%69d=7%37'])->clickId, 'decoded as a query string');
        // The affiliate reads only the tid, whatever the vendor's variables say.
        self::assertSame(42, self::parsed(['cvendthru' => 'subid=77'])->clickId);
    }

    /** @return array<string, array{array<string, mixed>, JVZooRole, string}> */
    public static function noClick(): array
    {
        return [
            'an affiliate sale with no tid' => [['caffitid' => ''], JVZooRole::AFFILIATE, 'carries no tid'],
            'a tid that is not a click id' => [['caffitid' => 'fbweek1'], JVZooRole::AFFILIATE, 'tid "fbweek1"'],
            'a tid of 0' => [['caffitid' => '0'], JVZooRole::AFFILIATE, 'tid "0"'],
            'no tid field at all' => [['caffitid' => null], JVZooRole::AFFILIATE, 'carries no tid'],
            'a vendor sale with no variables' => [['cvendthru' => ''], JVZooRole::VENDOR, 'carries no subid'],
            'variables without subid' => [['cvendthru' => 'utm=fb&subid2=5'], JVZooRole::VENDOR, 'carries no subid'],
            'a refund with no tid' => [['ctransaction' => 'RFND', 'caffitid' => ''], JVZooRole::AFFILIATE, 'RFND'],
        ];
    }

    /**
     * @dataProvider noClick
     * @param array<string, mixed> $overrides
     */
    public function testANotificationNamingNoClickIsAnsweredAsSuch(
        array $overrides,
        JVZooRole $role,
        string $says
    ): void {
        $fields = array_filter(self::sale($overrides), static fn (mixed $v): bool => $v !== null);
        $refusal = self::refusal(static fn () => (new JVZooAdapter($role))->parse(self::request($fields)));

        self::assertSame(OutcomeKind::NO_CLICK, $refusal->outcome->kind);
        self::assertStringStartsWith('Verified. The ', $refusal->outcome->message);
        self::assertStringContainsString($says, $refusal->outcome->message);
        self::assertStringContainsString('5CA16795AA047184W', $refusal->outcome->message);
    }

    /** @return array<string, array{array<string, mixed>, ?JVZooRole, string}> */
    public static function malformed(): array
    {
        $vendor = JVZooRole::VENDOR;
        $affiliate = JVZooRole::AFFILIATE;

        return [
            'a URL with no role' => [[], null, 'This URL names no role.'],
            'a field JVZIPN does not post' => [['ctid' => '9'], $affiliate, 'a field JVZIPN v1 does not post (ctid)'],
            'no ctransaction' => [['ctransaction' => ''], $affiliate, 'The notification carries no ctransaction.'],
            'an unknown type' => [['ctransaction' => 'sale'], $affiliate, 'ctransaction is "sale", which JVZIPN'],
            'a ClickBank type' => [['ctransaction' => 'TEST_SALE'], $affiliate, 'ctransaction is "TEST_SALE"'],
            'no receipt' => [['ctransreceipt' => ''], $affiliate, 'The SALE notification carries no ctransreceipt.'],
            'a receipt with a "|"' => [['ctransreceipt' => 'PYPL|5CA1'], $affiliate, 'ctransreceipt is "PYPL|5CA1"'],
            'a receipt with a space' => [['ctransreceipt' => '5CA1 6795'], $affiliate, 'ctransreceipt is "5CA1 6795"'],
            'no amount' => [['ctransamount' => ''], $affiliate, 'The sale carries no ctransamount.'],
            'pennies or dollars' => [['ctransamount' => '1000'], $affiliate, 'ctransamount is "1000", a whole number.'],
            'a comma' => [['ctransamount' => '1,000.00'], $affiliate, 'ctransamount is "1,000.00"' . self::AMOUNT_RULE],
            'a negative sale' => [
                ['ctransamount' => '-5.00'],
                $affiliate,
                'ctransamount is "-5.00"' . self::AMOUNT_RULE,
            ],
            'an exponent' => [['ctransamount' => '1e3'], $affiliate, 'ctransamount is "1e3"' . self::AMOUNT_RULE],
            'more than the column' => [
                ['ctransamount' => '1000000.00'],
                $affiliate,
                'ctransamount is "1000000.00"' . self::AMOUNT_RULE,
            ],
            'six decimals' => [
                ['ctransamount' => '1.000001'],
                $affiliate,
                'ctransamount is "1.000001"' . self::AMOUNT_RULE,
            ],
            'an amount moved into it' => [['ctransamount' => '17|47.00'], $affiliate, 'ctransamount is "17|47.00"'],
            'a list where a value belongs' => [
                ['ctransaction' => ['SALE']],
                $affiliate,
                'ctransaction arrived as a list',
            ],
            'a subid that is not a click id' => [
                ['cvendthru' => 'subid=[[subid]]'],
                $vendor,
                'cvendthru carries subid "[[subid]]", which is not a click id.',
            ],
            'two subids' => [
                ['cvendthru' => 'subid=5&subid=6'],
                $vendor,
                'cvendthru carries subid more than once, with different values.',
            ],
        ];
    }

    /**
     * @dataProvider malformed
     * @param array<string, mixed> $overrides
     */
    public function testAFieldTheNotificationNeedsIsReadExactlyOrRefusedByName(
        array $overrides,
        ?JVZooRole $role,
        string $says
    ): void {
        $refusal = self::refusal(static fn () => self::parsed($overrides, $role));

        self::assertSame(OutcomeKind::MALFORMED, $refusal->outcome->kind);
        self::assertStringContainsString($says, $refusal->outcome->message);
    }

    /**
     * The cverify joins values without names, so the same signature holds
     * for the values moved between names. Every such move verifies, and is
     * then refused or names no click.
     */
    public function testValuesMovedBetweenFieldsUnderOneCverifyAreNotRead(): void
    {
        $adapter = new JVZooAdapter(JVZooRole::AFFILIATE);
        $secrets = [3 => [self::KEY_A]];
        $signed = self::signed(self::sale(['caffitid' => '', 'ctransaffiliate' => '42']), self::KEY_A);
        $moved = static function (array $remove, array $set) use ($signed): array {
            return $set + array_diff_key($signed, array_flip($remove));
        };
        // A field sorting first, and cupsellreceipt dropped: every field
        // between takes the value of the one after it.
        $names = array_keys(array_diff_key($signed, ['cverify' => 1]));
        sort($names, SORT_STRING);
        $values = array_map(static fn (string $n): string => $signed[$n], $names);
        $shifted = ['caaa' => array_shift($values)];
        foreach (array_values(array_diff($names, ['cupsellreceipt'])) as $i => $name) {
            $shifted[$name] = $values[$i];
        }
        // Added after the amount: the receipt becomes the time, a second sale on
        // the same click from one notification, but for the unknown field.
        $sale = self::signed(self::sale(), self::KEY_A);
        $second = [
            'ctransamountx' => 'PYPL', 'ctranspaymentmethod' => $sale['ctransreceipt'],
            'ctransreceipt' => $sale['ctranstime'], 'ctranstime' => $sale['ctransvendor'], 'ctransvendor' => '',
        ] + array_diff_key($sale, ['cupsellreceipt' => 1]);
        self::assertSame(JVZooSignature::message($sale), JVZooSignature::message($second));
        self::assertNotNull($adapter->verify(self::request($second), $secrets));
        $refused = self::refusal(static fn () => $adapter->parse(self::request($second)));
        self::assertSame(OutcomeKind::MALFORMED, $refused->outcome->kind);
        self::assertStringContainsString('(ctransamountx)', $refused->outcome->message);

        $variants = [
            'a field added before the tid' => [$shifted + ['cverify' => $signed['cverify']], OutcomeKind::MALFORMED],
            // The affiliate id (digits) moved into the amount, joined by a "|".
            'a field removed into the amount' => [
                $moved(['ctransaffiliate'], ['ctransamount' => '42|47.00']),
                OutcomeKind::MALFORMED,
            ],
            'a field removed into the receipt' => [
                $moved(['ctranspaymentmethod'], ['ctransreceipt' => 'PYPL|5CA16795AA047184W']),
                OutcomeKind::MALFORMED,
            ],
            'a field removed into the type' => [
                $moved(['cprodtype'], ['ctransaction' => 'STANDARD|SALE']),
                OutcomeKind::MALFORMED,
            ],
            'the next value moved into the tid' => [$moved(['ccustcc'], ['caffitid' => '|US']), OutcomeKind::NO_CLICK],
        ];
        foreach ($variants as $name => [$fields, $kind]) {
            self::assertSame(
                JVZooSignature::message($signed),
                JVZooSignature::message($fields),
                $name . ': the move keeps the signed string'
            );
            self::assertNotNull($adapter->verify(self::request($fields), $secrets), $name);
            $refusal = self::refusal(static fn () => $adapter->parse(self::request($fields)));
            self::assertSame($kind, $refusal->outcome->kind, $name);
        }
    }

    public function testTheRoleIsReadFromTheQueryExactly(): void
    {
        self::assertSame(JVZooRole::AFFILIATE, JVZooRole::fromQuery('affiliate'));
        self::assertSame(JVZooRole::VENDOR, JVZooRole::fromQuery('vendor'));
        foreach ([null, '', 'Vendor', 'vendor ', ['vendor'], 'seller'] as $other) {
            self::assertNull(JVZooRole::fromQuery($other));
        }
        self::assertSame(
            'https://t.example/tracking202/static/jvzoo.php?role=vendor',
            JVZooRole::VENDOR->url('https://t.example/')
        );
    }

    public function testEveryFieldTheArticleListsIsKnownAndNoOther(): void
    {
        // JVZoo's table of post parameters, in its order.
        $documented = ['ccustname', 'ccuststate', 'ccustcc', 'ccustemail', 'cproditem', 'cprodtitle', 'cprodtype',
            'ctransaction', 'ctransaffiliate', 'ctransamount', 'ctranspaymentmethod', 'ctransvendor', 'ctransreceipt',
            'cupsellreceipt', 'caffitid', 'cvendthru', 'cverify', 'ctranstime'];
        $known = JVZooAdapter::FIELDS;
        sort($documented);
        sort($known);
        self::assertSame($documented, $known);
        // caffitid, the field shaped like a click id, is first in name order.
        $order = JVZooAdapter::FIELDS;
        sort($order, SORT_STRING);
        self::assertSame('caffitid', $order[0]);
    }
}

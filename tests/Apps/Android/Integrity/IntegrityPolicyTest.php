<?php

declare(strict_types=1);

namespace Tests\Apps\Android\Integrity;

use Api\V3\Apps\Android\Integrity\IntegrityMode;
use Api\V3\Apps\Android\Integrity\IntegrityPolicy;
use Api\V3\Apps\Android\Integrity\IntegrityState;
use Api\V3\Apps\AppPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The verdict policy (plan §5.11), one check at a time: each case starts
 * from a verdict that passes and breaks exactly one thing, so a check that
 * stopped running would show as that case passing.
 */
final class IntegrityPolicyTest extends TestCase
{
    private const PACKAGE = 'com.example.summit';
    private const RECEIVED = 1_727_200_200;
    private const HASH = '24e659a4f8d70b46db15a679b91d44d5cb6d19e42fd3208568a96d0552dbd106';

    /** @return array<string, mixed> */
    public static function passing(): array
    {
        return [
            'requestDetails' => ['requestPackageName' => self::PACKAGE, 'requestHash' => self::HASH, 'timestampMillis' => (string) ((self::RECEIVED - 30) * 1000)],
            'appIntegrity' => ['appRecognitionVerdict' => 'PLAY_RECOGNIZED', 'packageName' => self::PACKAGE, 'certificateSha256Digest' => ['abc'], 'versionCode' => '42'],
            'deviceIntegrity' => ['deviceRecognitionVerdict' => ['MEETS_DEVICE_INTEGRITY']],
            'accountDetails' => ['appLicensingVerdict' => 'LICENSED'],
        ];
    }

    public function testAPassingVerdictIsValid(): void
    {
        $j = IntegrityPolicy::judge(self::passing(), self::PACKAGE, self::HASH, self::RECEIVED);
        self::assertTrue($j->valid, $j->reason);
        self::assertSame('valid', $j->code);
        self::assertSame(['code' => 'valid', 'package' => self::PACKAGE, 'app_recognition' => 'PLAY_RECOGNIZED', 'device' => ['MEETS_DEVICE_INTEGRITY'],
            'licensing' => 'LICENSED', 'issued_at' => self::RECEIVED - 30, 'version_code' => '42'], $j->summary);
        self::assertStringNotContainsString('token', strtolower($j->summaryJson()), 'the summary never carries the token');
    }

    /**
     * @dataProvider oneThingWrong
     * @param callable(array<string, mixed>): array<string, mixed> $break
     */
    public function testEachCheckRefutesOnItsOwn(callable $break, string $code, string $says): void
    {
        $j = IntegrityPolicy::judge($break(self::passing()), self::PACKAGE, self::HASH, self::RECEIVED);
        self::assertFalse($j->valid, $code);
        self::assertSame($code, $j->code, $j->reason);
        self::assertStringContainsString($says, $j->reason);
        self::assertSame($code, $j->summary['code']);
    }

    /** @return array<string, array{callable, string, string}> */
    public static function oneThingWrong(): array
    {
        $set = static fn (string $path, mixed $value): callable => static function (array $v) use ($path, $value): array {
            $keys = explode('.', $path);
            $ref = &$v;
            foreach ($keys as $k) {
                $ref = &$ref[$k];
            }
            $ref = $value;

            return $v;
        };
        $unset = static fn (string $a, string $b): callable => static function (array $v) use ($a, $b): array {
            unset($v[$a][$b]);

            return $v;
        };

        return [
            'no requestDetails' => [static function (array $v): array {
                unset($v['requestDetails']);

                return $v;
            }, 'malformed', 'no requestDetails'],
            'another app requested it' => [$set('requestDetails.requestPackageName', 'com.evil.app'), 'wrong_package', '"com.evil.app"'],
            'a case-folded package' => [$set('requestDetails.requestPackageName', 'com.example.Summit'), 'wrong_package', 'not by com.example.summit'],
            'Google recognised another binary' => [$set('appIntegrity.packageName', 'com.evil.app'), 'wrong_package', 'recognised the calling app'],
            'bound to another install' => [$set('requestDetails.requestHash', str_repeat('0', 64)), 'request_hash', 'another install body'],
            'a hash in upper case' => [$set('requestDetails.requestHash', strtoupper(self::HASH)), 'request_hash', 'another install body'],
            'a classic nonce request' => [static function (array $v): array {
                unset($v['requestDetails']['requestHash']);
                $v['requestDetails']['nonce'] = 'abc';

                return $v;
            }, 'request_hash', 'classic request'],
            'no timestamp' => [$unset('requestDetails', 'timestampMillis'), 'malformed', 'timestampMillis'],
            'a float timestamp' => [$set('requestDetails.timestampMillis', 1727200170000.5), 'malformed', 'timestampMillis'],
            'eleven minutes old' => [$set('requestDetails.timestampMillis', (string) ((self::RECEIVED - 601) * 1000)), 'stale', '601 s before'],
            'from the future' => [$set('requestDetails.timestampMillis', (string) ((self::RECEIVED + 121) * 1000)), 'future', '121 s after'],
            'an unrecognized version' => [$set('appIntegrity.appRecognitionVerdict', 'UNRECOGNIZED_VERSION'), 'app_not_recognized', 'UNRECOGNIZED_VERSION'],
            'unevaluated app' => [$set('appIntegrity.appRecognitionVerdict', 'UNEVALUATED'), 'app_not_recognized', 'UNEVALUATED'],
            'no app verdict' => [static function (array $v): array {
                unset($v['appIntegrity']);

                return $v;
            }, 'app_not_recognized', 'nothing'],
            'basic integrity only (rooted)' => [$set('deviceIntegrity.deviceRecognitionVerdict', ['MEETS_BASIC_INTEGRITY']), 'device_integrity', 'MEETS_BASIC_INTEGRITY'],
            'an emulator' => [$set('deviceIntegrity.deviceRecognitionVerdict', ['MEETS_VIRTUAL_INTEGRITY']), 'device_integrity', 'MEETS_VIRTUAL_INTEGRITY'],
            'no device labels' => [$set('deviceIntegrity.deviceRecognitionVerdict', []), 'device_integrity', 'no labels'],
            'a label as a string, not a list' => [$set('deviceIntegrity.deviceRecognitionVerdict', 'MEETS_DEVICE_INTEGRITY_NOT'), 'device_integrity', 'MEETS_DEVICE_INTEGRITY_NOT'],
            'unlicensed' => [$set('accountDetails.appLicensingVerdict', 'UNLICENSED'), 'unlicensed', 'UNLICENSED'],
        ];
    }

    public function testWhatStillPasses(): void
    {
        foreach ([
            'strong integrity' => ['deviceIntegrity', 'deviceRecognitionVerdict', ['MEETS_BASIC_INTEGRITY', 'MEETS_DEVICE_INTEGRITY', 'MEETS_STRONG_INTEGRITY']],
            'strong alone' => ['deviceIntegrity', 'deviceRecognitionVerdict', ['MEETS_STRONG_INTEGRITY']],
            'licensing unevaluated' => ['accountDetails', 'appLicensingVerdict', 'UNEVALUATED'],
            'an integer timestamp' => ['requestDetails', 'timestampMillis', (self::RECEIVED - 600) * 1000],
            'two minutes of skew' => ['requestDetails', 'timestampMillis', (string) ((self::RECEIVED + 120) * 1000)],
        ] as $name => [$a, $b, $value]) {
            $v = self::passing();
            $v[$a][$b] = $value;
            self::assertTrue(IntegrityPolicy::judge($v, self::PACKAGE, self::HASH, self::RECEIVED)->valid, $name);
        }
        $v = self::passing();
        unset($v['accountDetails']);
        self::assertTrue(IntegrityPolicy::judge($v, self::PACKAGE, self::HASH, self::RECEIVED)->valid, 'no licensing verdict at all');
    }

    public function testAnUnreadableModeRequiresAndTheStatesOnArrival(): void
    {
        foreach ([null, '', 'Require', 'on', 0, 1, true, 'observe '] as $stored) {
            self::assertSame(IntegrityMode::REQUIRE, IntegrityMode::fromStored($stored), var_export($stored, true));
        }
        self::assertSame(IntegrityMode::OFF, IntegrityMode::fromStored('off'));
        self::assertSame(IntegrityMode::OBSERVE, IntegrityMode::fromStored('observe'));
        self::assertSame(IntegrityMode::REQUIRE, AppPolicy::fromRow(['accept_test_signals' => 0])->integrityMode, 'a row read without the column is not "off"');
        self::assertSame(IntegrityMode::REQUIRE, AppPolicy::untrusting()->integrityMode);
        self::assertSame(IntegrityMode::OFF, AppPolicy::fromRow(['accept_test_signals' => 0, 'integrity_mode' => 'off'])->integrityMode);

        self::assertSame(IntegrityState::NOT_REQUESTED, IntegrityState::onArrival(IntegrityMode::OFF, false));
        self::assertSame(IntegrityState::RECEIVED, IntegrityState::onArrival(IntegrityMode::OFF, true));
        self::assertSame(IntegrityState::MISSING, IntegrityState::onArrival(IntegrityMode::OBSERVE, false));
        self::assertSame(IntegrityState::PENDING, IntegrityState::onArrival(IntegrityMode::OBSERVE, true));
        self::assertSame(IntegrityState::MISSING, IntegrityState::onArrival(IntegrityMode::REQUIRE, false));
        self::assertSame(IntegrityState::PENDING, IntegrityState::onArrival(IntegrityMode::REQUIRE, true));
    }
}

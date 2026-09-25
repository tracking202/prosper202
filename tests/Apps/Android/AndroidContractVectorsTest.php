<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Apps\Android\InstallEventsIntake;
use Api\V3\Apps\Android\InstallIntake;
use Api\V3\Apps\Android\InstallPayload;
use Api\V3\Apps\Android\InstallToken;
use Api\V3\Apps\Android\MatchState;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * The Android wire contract as data (tests/fixtures/app-sdk-contract/android/,
 * plan §4.3): the install token, the install and events bodies, and the
 * answers. The vectors were written by an implementation independent of the
 * server's (a Python HMAC and JSON encoder), so a pass here is two
 * implementations agreeing, not one agreeing with itself; the Kotlin SDK
 * (PR 7) runs the same files.
 */
final class AndroidContractVectorsTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/app-sdk-contract/android/';

    /** @return array<string, mixed> */
    private static function vectors(string $file): array
    {
        $decoded = json_decode((string) file_get_contents(self::DIR . $file), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testEveryTokenVerifiesToItsClickAndIsWhatTheServerMints(): void
    {
        $v = self::vectors('install-token.json');
        $key = (string) hex2bin($v['key_hex']);
        self::assertSame(InstallToken::DOMAIN, $v['domain']);
        self::assertGreaterThanOrEqual(5, count($v['tokens']));
        foreach ($v['tokens'] as $t) {
            self::assertSame($t['token'], InstallToken::forClick($t['click_id'], $key), 'minted for ' . $t['click_id']);
            self::assertSame($t['click_id'], InstallToken::verify($t['token'], $key));
            self::assertSame($t['token'], rawurlencode($t['token']), 'the token survives the link builder\'s encoding unchanged');
        }
    }

    public function testMalformedTokensAreNotTokensAndWrongMacsDoNotVerify(): void
    {
        $v = self::vectors('install-token.json');
        $key = (string) hex2bin($v['key_hex']);
        self::assertGreaterThanOrEqual(15, count($v['malformed']));
        foreach ($v['malformed'] as $bad) {
            self::assertNull(InstallToken::clickIdOf($bad), json_encode($bad) . ' is not shaped like a token');
            self::assertNull(InstallToken::verify($bad, $key), json_encode($bad));
        }
        foreach ($v['wrong_mac'] as $case) {
            self::assertNotNull(InstallToken::clickIdOf($case['token']), $case['name'] . ': shaped like a token');
            self::assertNull(InstallToken::verify($case['token'], $key), $case['name']);
        }
    }

    public function testEveryInstallBodyIsReadAsTheVectorSays(): void
    {
        $v = self::vectors('install-requests.json');
        self::assertGreaterThanOrEqual(20, count($v['cases']));
        foreach ($v['cases'] as $case) {
            // Re-encoded and decoded the way the intake reads a body, so an
            // integer stays an integer and a float stays a float.
            $body = json_decode(json_encode($case['body'], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if ($case['expect']['valid']) {
                $payload = InstallPayload::fromDecoded($body);
                self::assertSame($case['expect']['canonical'], $payload->canonical(), $case['name']);
                self::assertSame($case['expect']['fingerprint'], $payload->fingerprint(), $case['name']);
                $claim = $payload->customer?->canonical();
                self::assertSame($case['expect']['customer'] ?? null, $claim, $case['name'] . ': the customer claim');
                continue;
            }
            try {
                InstallPayload::fromDecoded($body);
                self::fail($case['name'] . ': accepted');
            } catch (ValidationException $e) {
                $fields = array_keys($e->getFieldErrors());
                sort($fields);
                self::assertSame($case['expect']['field_errors'], $fields, $case['name']);
                self::assertSame(400, $case['expect']['status']);
            }
        }
    }

    public function testEveryEventsBodyIsReadAsTheVectorSays(): void
    {
        $v = self::vectors('events-requests.json');
        self::assertSame(InstallEventsIntake::MAX_EVENTS, $v['max_events']);
        self::assertGreaterThanOrEqual(20, count($v['cases']));
        foreach ($v['cases'] as $case) {
            $body = json_decode(json_encode($case['body'], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if ($case['expect']['valid']) {
                $parsed = InstallEventsIntake::parseBody($body, false, 1_727_300_000);
                $events = $parsed['events'];
                self::assertCount($case['expect']['events'], $events, $case['name']);
                $claim = $parsed['customer']?->canonical();
                self::assertSame($case['expect']['customer'], $claim, $case['name'] . ': the customer claim');
                foreach ($events as $event) {
                    self::assertSame(1_727_300_000, $event->receivedAt, 'the server stamps received_at');
                    self::assertFalse($event->revenueTrusted, 'the registration decides revenue trust');
                }
                continue;
            }
            try {
                InstallEventsIntake::parseBody($body, false, 1_727_300_000);
                self::fail($case['name'] . ': accepted');
            } catch (ValidationException $e) {
                $fields = array_keys($e->getFieldErrors());
                sort($fields);
                self::assertSame($case['expect']['field_errors'], $fields, $case['name']);
            }
        }
    }

    public function testTheAnswersNameTheServersStatesAndLimits(): void
    {
        $v = self::vectors('responses.json');
        self::assertSame(MatchState::values(), $v['match_states']);
        $when = static fn (array $rows): array => array_column($rows, 'retry', 'status');
        foreach ([$when($v['installs']), $when($v['events'])] as $table) {
            foreach ($table as $status => $retry) {
                self::assertSame($status === 429 || $status >= 500, $retry, "status $status: only 429 and 5xx are retried");
            }
        }
        $installs = implode(' ', array_column($v['installs'], 'when'));
        self::assertStringContainsString((string) InstallIntake::MAX_BODY_BYTES, $installs);
        self::assertStringContainsString((string) InstallEventsIntake::MAX_BODY_BYTES, implode(' ', array_column($v['events'], 'when')));
    }
}

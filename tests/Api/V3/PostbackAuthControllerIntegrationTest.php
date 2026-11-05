<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\PostbackAuthController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * GET, PUT /conversions/postback-auth and POST /conversions/postback-auth/rotate
 * on a real database: the token and the signing secret are minted on the
 * first read and kept, the mode and each rotation are the caller's
 * account's alone, and every value the page would not offer is refused by
 * name.
 *
 * @group integration
 */
final class PostbackAuthControllerIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990311;
    private const OTHER = 990312;
    private const NOW = 1_800_000_000;

    public static function setUpBeforeClass(): void
    {
        self::connectScratch();
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q('DELETE FROM 202_postback_auth WHERE user_id IN (' . self::USER . ',' . self::OTHER . ')');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::$db->query('DELETE FROM 202_postback_auth WHERE user_id IN (' . self::USER . ',' . self::OTHER . ')');
        }
    }

    private function controller(int $user = self::USER): PostbackAuthController
    {
        return new PostbackAuthController(self::$db, $user);
    }

    public function testTheFirstReadMintsTheCredentialsAndSaysHowToSign(): void
    {
        $data = $this->controller()->show(self::NOW)['data'];
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $data['token']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $data['signing_secret']);
        self::assertSame('token', $data['mode'], 'an account made from 1.9.80 on needs the token or a signature');
        self::assertSame([self::NOW, self::NOW], [$data['token_created_at'], $data['signing_secret_created_at']]);
        self::assertNull($data['previous_token_valid_until']);
        self::assertNull($data['previous_signing_secret_valid_until']);
        self::assertSame('p202_token', $data['parameter']);
        self::assertSame(['algorithm' => 'HMAC-SHA256', 'version' => 'P202-POSTBACK-V1', 'timestamp_parameter' => 'p202_ts',
            'signature_parameter' => 'p202_sig', 'window_seconds' => 300, 'endpoints' => ['pb', 'gpb', 'px', 'gpx', 'upx']], $data['signing']);
        $again = $this->controller()->show(self::NOW + 5)['data'];
        self::assertSame([$data['token'], $data['signing_secret']], [$again['token'], $again['signing_secret']]);
        self::assertNotSame($data['token'], $this->controller(self::OTHER)->show(self::NOW)['data']['token'], 'each account has its own');
    }

    public function testTheModeIsOneOfThreeWords(): void
    {
        foreach (['signed', 'off', 'token'] as $mode) {
            self::assertSame($mode, $this->controller()->update(['mode' => $mode], self::NOW)['data']['mode']);
        }
        self::assertSame('token', $this->controller(self::OTHER)->show(self::NOW)['data']['mode'], 'the other account is untouched');
        self::assertSame('off', $this->controller()->update(['mode' => 'off'], self::NOW)['data']['mode']);
        foreach ([[], ['mode' => 'OFF'], ['mode' => true], ['mode' => null], ['mode' => 'required']] as $body) {
            self::assertArrayHasKey('mode', $this->refusal(fn () => $this->controller()->update($body, self::NOW)), json_encode($body));
        }
        self::assertArrayHasKey('token_required', $this->refusal(fn () => $this->controller()->update(['mode' => 'token', 'token_required' => true], self::NOW)));
        self::assertSame('off', $this->controller()->show(self::NOW)['data']['mode'], 'a refused write changed nothing');
    }

    public function testEachCredentialRotatesOnItsOwn(): void
    {
        $first = $this->controller()->show(self::NOW)['data'];
        $rotated = $this->controller()->rotate([], self::NOW)['data'];
        self::assertNotSame($first['token'], $rotated['token'], 'the token when not said');
        self::assertSame($first['signing_secret'], $rotated['signing_secret']);
        self::assertSame(self::NOW + 168 * 3600, $rotated['previous_token_valid_until'], 'a week when not said');

        $secret = $this->controller()->rotate(['credential' => 'signing_secret', 'grace_hours' => 24], self::NOW)['data'];
        self::assertNotSame($first['signing_secret'], $secret['signing_secret']);
        self::assertSame($rotated['token'], $secret['token']);
        self::assertSame(self::NOW + 24 * 3600, $secret['previous_signing_secret_valid_until']);

        $now = $this->controller()->rotate(['credential' => 'token', 'grace_hours' => 0], self::NOW + 1)['data'];
        self::assertNull($now['previous_token_valid_until']);
        foreach ([-1, 721, '24', 1.5, null] as $hours) {
            self::assertArrayHasKey('grace_hours', $this->refusal(fn () => $this->controller()->rotate(['grace_hours' => $hours], self::NOW)), json_encode($hours));
        }
        foreach (['secret', 'TOKEN', 1, null] as $credential) {
            self::assertArrayHasKey('credential', $this->refusal(fn () => $this->controller()->rotate(['credential' => $credential], self::NOW)), json_encode($credential));
        }
        self::assertSame($now['token'], $this->controller()->show(self::NOW + 2)['data']['token'], 'a refused rotation changed nothing');
    }

    /** @return array<string, string> */
    private function refusal(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->getFieldErrors();
        }
        self::fail('the request was not refused');
    }
}

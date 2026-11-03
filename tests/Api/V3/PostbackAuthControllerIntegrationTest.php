<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\PostbackAuthController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * GET, PUT /conversions/postback-auth and POST /conversions/postback-auth/rotate
 * on a real database: the token is minted on the first read and kept, the
 * requirement and the rotation are the caller's account's alone, and every
 * value the page would not offer is refused by name.
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

    public function testTheFirstReadMintsTheTokenAndSaysHowToSign(): void
    {
        $data = $this->controller()->show(self::NOW)['data'];
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $data['token']);
        self::assertTrue($data['token_required'], 'an account made from 1.9.80 on requires it');
        self::assertSame(self::NOW, $data['token_created_at']);
        self::assertNull($data['previous_token_valid_until']);
        self::assertSame('p202_token', $data['parameter']);
        self::assertSame(['algorithm' => 'HMAC-SHA256', 'version' => 'P202-POSTBACK-V1', 'timestamp_parameter' => 'p202_ts',
            'signature_parameter' => 'p202_sig', 'window_seconds' => 300, 'endpoints' => ['pb', 'gpb', 'px', 'gpx', 'upx']], $data['signing']);
        self::assertSame($data['token'], $this->controller()->show(self::NOW + 5)['data']['token']);
        self::assertNotSame($data['token'], $this->controller(self::OTHER)->show(self::NOW)['data']['token'], 'each account has its own');
    }

    public function testTheRequirementIsSetAsABoolean(): void
    {
        self::assertFalse($this->controller()->update(['token_required' => false], self::NOW)['data']['token_required']);
        self::assertTrue($this->controller(self::OTHER)->show(self::NOW)['data']['token_required'], 'the other account is untouched');
        foreach ([[], ['token_required' => 'false'], ['token_required' => 0], ['token_required' => null]] as $body) {
            self::assertArrayHasKey('token_required', $this->refusal(fn () => $this->controller()->update($body, self::NOW)), json_encode($body));
        }
        self::assertArrayHasKey('required', $this->refusal(fn () => $this->controller()->update(['token_required' => true, 'required' => true], self::NOW)));
        self::assertFalse($this->controller()->show(self::NOW)['data']['token_required'], 'a refused write changed nothing');
    }

    public function testARotationKeepsTheOldTokenForTheHoursAsked(): void
    {
        $old = $this->controller()->show(self::NOW)['data']['token'];
        $rotated = $this->controller()->rotate([], self::NOW)['data'];
        self::assertNotSame($old, $rotated['token']);
        self::assertSame(self::NOW + 168 * 3600, $rotated['previous_token_valid_until'], 'a week when not said');
        $now = $this->controller()->rotate(['grace_hours' => 0], self::NOW + 1)['data'];
        self::assertNull($now['previous_token_valid_until']);
        foreach ([-1, 721, '24', 1.5, null] as $hours) {
            self::assertArrayHasKey('grace_hours', $this->refusal(fn () => $this->controller()->rotate(['grace_hours' => $hours], self::NOW)), json_encode($hours));
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

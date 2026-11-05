<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Destinations\DestinationInputException;
use Prosper202\Destinations\GoogleAdsOAuth;

/**
 * Connecting Google Ads: the signed, expiring state that binds Google's
 * callback to the user and the destination (OAuth CSRF), the consent URL,
 * and the code-for-token exchange (stubbed transport, no network).
 */
final class GoogleAdsOAuthTest extends TestCase
{
    private const KEY = 'k-0123456789abcdef0123456789abcd';
    private const NOW = 1_780_000_000;

    private static function refused(string $state, int $user, int $now, string $key, string $says): void
    {
        try {
            GoogleAdsOAuth::verifyState($state, $user, $now, $key);
            self::fail('refused: ' . $says);
        } catch (DestinationInputException $e) {
            self::assertSame('state', $e->field);
            self::assertStringContainsString($says, $e->getMessage());
        }
    }

    public function testAStateNamesItsUserAndDestinationUntilItExpires(): void
    {
        $state = GoogleAdsOAuth::state(3, 41, self::NOW, self::KEY);
        self::assertSame(41, GoogleAdsOAuth::verifyState($state, 3, self::NOW, self::KEY), 'the positive control');
        self::assertSame(41, GoogleAdsOAuth::verifyState($state, 3, self::NOW + GoogleAdsOAuth::STATE_SECONDS, self::KEY), 'valid to its last second');
        self::assertNotSame($state, GoogleAdsOAuth::state(3, 41, self::NOW, self::KEY), 'each start has its own nonce');
    }

    public function testATamperedForeignStaleOrOtherUsersStateIsRefused(): void
    {
        $state = GoogleAdsOAuth::state(3, 41, self::NOW, self::KEY);
        $parts = explode('.', $state);
        $parts[2] = '42';
        self::refused(implode('.', $parts), 3, self::NOW, self::KEY, 'did not sign');
        $parts = explode('.', $state);
        $parts[3] = (string) (self::NOW + 999_999);
        self::refused(implode('.', $parts), 3, self::NOW, self::KEY, 'did not sign');
        self::refused($state, 3, self::NOW, 'another-install-key-0123456789ab', 'did not sign');
        self::refused($state, 4, self::NOW, self::KEY, 'another user');
        self::refused($state, 3, self::NOW + GoogleAdsOAuth::STATE_SECONDS + 1, self::KEY, 'expired');
        self::refused('', 3, self::NOW, self::KEY, 'did not make');
        self::refused('v2.' . substr($state, 3), 3, self::NOW, self::KEY, 'did not make');
    }

    public function testTheConsentUrlAsksForOfflineAdsAccess(): void
    {
        $url = GoogleAdsOAuth::authorisationUrl('1234-abcd.apps.googleusercontent.com', 'https://track.example.com/tracking202/setup/destination-oauth.php', 'v1.s');
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame([
            'client_id' => '1234-abcd.apps.googleusercontent.com',
            'redirect_uri' => 'https://track.example.com/tracking202/setup/destination-oauth.php',
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/adwords',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => 'v1.s',
        ], $query);
    }

    public function testTheRedirectUriIsTheCallbackOnThisRequestsHost(): void
    {
        $uri = GoogleAdsOAuth::redirectUri(['HTTP_HOST' => 'track.example.com', 'HTTPS' => 'on', 'DOCUMENT_ROOT' => '/nonexistent']);
        self::assertSame('https://track.example.com/' . GoogleAdsOAuth::CALLBACK_PATH, $uri);
    }

    /** @param array<string, mixed> $answer */
    private static function oauth(array $answer, ?array &$sent): GoogleAdsOAuth
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['203.0.113.10'], '203.0.113.0/24');

        return new GoogleAdsOAuth(new WebhookSender($guard, static function (array $options) use ($answer, &$sent): array {
            $sent = $options;

            return $answer + ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.10', 'location' => null, 'body' => ''];
        }));
    }

    public function testTheCodeIsTradedForARefreshToken(): void
    {
        $sent = null;
        $answer = self::oauth(['body' => '{"access_token":"ya29.a","refresh_token":"1//refresh","expires_in":3599}'], $sent)
            ->exchange('4/code', 'cid.apps.googleusercontent.com', 'GOCSPX-secret', 'https://track.example.com/cb');
        self::assertSame(['refresh_token' => '1//refresh', 'error' => null], $answer);
        self::assertSame(GoogleAdsOAuth::TOKEN_URL, $sent[CURLOPT_URL]);
        parse_str((string) $sent[CURLOPT_POSTFIELDS], $form);
        self::assertSame(['grant_type' => 'authorization_code', 'code' => '4/code', 'client_id' => 'cid.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-secret', 'redirect_uri' => 'https://track.example.com/cb'], $form);

        $none = self::oauth(['body' => '{"access_token":"ya29.a","expires_in":3599}'], $sent)->exchange('4/code', 'cid', 'GOCSPX-secret', 'https://x/cb');
        self::assertNull($none['refresh_token']);
        self::assertStringContainsString('no refresh token', (string) $none['error']);

        $refused = self::oauth(['status' => 400, 'body' => '{"error":"invalid_grant","error_description":"Bad code 4/code for GOCSPX-secret"}'], $sent)
            ->exchange('4/code', 'cid', 'GOCSPX-secret', 'https://x/cb');
        self::assertStringContainsString('invalid_grant', (string) $refused['error']);
        self::assertStringNotContainsString('GOCSPX-secret', (string) $refused['error']);
        self::assertStringNotContainsString('4/code', (string) $refused['error']);
    }
}

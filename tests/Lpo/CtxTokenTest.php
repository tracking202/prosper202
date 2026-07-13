<?php

declare(strict_types=1);

namespace Tests\Lpo;

use PHPUnit\Framework\TestCase;
use Prosper202\Lpo\CtxToken;
use RuntimeException;

/**
 * Cross-language byte contract for the t202ctx token (p202-edge-sync §3.2):
 * the PHP mint must reproduce the Worker reference implementation
 * (Worker repo: src/auth/t202ctx.ts) EXACTLY — same canonical claim order,
 * compact JSON (unescaped unicode + slashes), unpadded b64url, and a MAC of
 * the first 16 HMAC-SHA256 bytes over the ENCODED payload string. The
 * pinned vectors in fixtures/t202ctx-vectors.json are shared verbatim with
 * the Worker test suite; byte equality here is the whole point.
 */
final class CtxTokenTest extends TestCase
{
    private const VECTORS = __DIR__ . '/fixtures/t202ctx-vectors.json';

    /** @return array<string, mixed> */
    private static function vectors(): array
    {
        $raw = file_get_contents(self::VECTORS);
        self::assertNotFalse($raw, 'fixture file must be readable');
        $fixture = json_decode($raw, true);
        self::assertIsArray($fixture);
        self::assertIsArray($fixture['vectors']);

        return $fixture['vectors'];
    }

    private static function b64urlDecode(string $s): string
    {
        $pad = strlen($s) % 4;

        return (string) base64_decode(strtr($s, '-_', '+/') . ($pad !== 0 ? str_repeat('=', 4 - $pad) : ''), true);
    }

    /** @return array<string, mixed> */
    private static function payloadOf(string $token): array
    {
        $decoded = json_decode(self::b64urlDecode(explode('.', $token, 2)[0]), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testMintReproducesPinnedVectorsByteForByte(): void
    {
        $minted = 0;
        foreach (self::vectors() as $vector) {
            if (!is_array($vector['claims'] ?? null)) {
                continue; // verifier-only vector (expired / wrong-secret)
            }
            $key = CtxToken::deriveKey((string) $vector['secret']);
            self::assertSame(
                $vector['token'],
                CtxToken::mint($vector['claims'], $key),
                'vector "' . $vector['name'] . '" must re-mint byte-identically'
            );
            $minted++;
        }
        self::assertGreaterThanOrEqual(3, $minted, 'the fixture must pin at least three mintable vectors');
    }

    public function testDeriveKeyIsRaw32ByteHmacOfTheFixedLabel(): void
    {
        $key = CtxToken::deriveKey('whsec_rotated_0123456789abcdef');

        self::assertSame(32, strlen($key));
        self::assertSame(hash_hmac('sha256', 't202ctx-v1', 'whsec_rotated_0123456789abcdef', true), $key);
    }

    public function testMacIsFirst16HmacBytesOverTheEncodedPayloadString(): void
    {
        $key = CtxToken::deriveKey('secret-abc');
        $token = CtxToken::mint(['t' => 1720512345, 'sid' => 'click-1'], $key);

        [$encPayload, $encMac] = explode('.', $token, 2);
        self::assertSame(
            substr(hash_hmac('sha256', $encPayload, $key, true), 0, 16),
            self::b64urlDecode($encMac),
            'the MAC must cover the ASCII of the b64url payload, not the raw JSON'
        );
    }

    public function testEmptyAndNullClaimsAreOmittedAndOrderIsCanonical(): void
    {
        $key = CtxToken::deriveKey('secret-abc');
        $bare = CtxToken::mint(['t' => 1720512345, 'sid' => 's1'], $key);
        $noisy = CtxToken::mint(
            ['cc' => '', 'kw' => null, 'c2' => '', 'sid' => 's1', 't' => 1720512345],
            $key
        );

        self::assertSame($bare, $noisy, 'null/empty claims must not change the minted bytes');
        self::assertSame(
            '{"v":1,"t":1720512345,"sid":"s1"}',
            self::b64urlDecode(explode('.', $bare, 2)[0])
        );

        // Claims passed out of order still serialize in canonical §3.2 order.
        $shuffled = CtxToken::mint(['cc' => 'US', 'acc' => 12, 'sid' => 's1', 't' => 1720512345], $key);
        self::assertSame(
            '{"v":1,"t":1720512345,"sid":"s1","acc":12,"cc":"US"}',
            self::b64urlDecode(explode('.', $shuffled, 2)[0])
        );
    }

    public function testKwAndCvarsAreCharTruncatedBeforeEncoding(): void
    {
        $key = CtxToken::deriveKey('secret-abc');
        $token = CtxToken::mint([
            't' => 1720512345,
            'sid' => 's1',
            'kw' => str_repeat('ä', 70),
            'c1' => str_repeat('b', 45),
        ], $key);

        $payload = self::payloadOf($token);
        self::assertSame(str_repeat('ä', 64), $payload['kw'], 'kw truncates at 64 CHARS, not bytes');
        self::assertSame(str_repeat('b', 40), $payload['c1'], 'c-vars truncate at 40 chars');
    }

    public function testOverBudgetTokensDropClaimsInSpecOrder(): void
    {
        $key = CtxToken::deriveKey('secret-abc');
        $claims = [
            't' => 1720512345,
            'sid' => str_repeat('s', 64),
            'acc' => 12,
            'cmp' => 340,
            'lp' => 18,
            'kw' => str_repeat('k', 64),
            'c1' => str_repeat('a', 40),
            'c2' => str_repeat('b', 40),
            'c3' => str_repeat('c', 40),
            'c4' => str_repeat('d', 40),
            'cc' => 'US',
        ];
        $token = CtxToken::mint($claims, $key);

        self::assertLessThanOrEqual(400, strlen($token), 'the token must respect the §3.2 size budget');

        $payload = self::payloadOf($token);
        foreach (['v', 't', 'sid', 'acc', 'cmp'] as $required) {
            self::assertArrayHasKey($required, $payload, $required . ' is never dropped');
        }

        // Whatever had to go must be a prefix of the spec drop order
        // kw → c4 → c3 → c2 → c1 → lp (kw always first out).
        $dropped = array_values(array_diff(['kw', 'c4', 'c3', 'c2', 'c1', 'lp'], array_keys($payload)));
        self::assertNotEmpty($dropped, 'this input is deliberately over budget');
        self::assertSame(array_slice(['kw', 'c4', 'c3', 'c2', 'c1', 'lp'], 0, count($dropped)), $dropped);
    }

    public function testUndroppableOverflowStillMintsWithCoreClaimsOnly(): void
    {
        $key = CtxToken::deriveKey('secret-abc');
        $token = CtxToken::mint([
            't' => 1720512345,
            'sid' => str_repeat('s', 500), // v/t/sid/acc/cmp never drop, even over budget
            'acc' => 12,
            'cmp' => 340,
            'kw' => 'gone',
            'lp' => 18,
        ], $key);

        self::assertSame(['v', 't', 'sid', 'acc', 'cmp'], array_keys(self::payloadOf($token)));
    }

    public function testMintRejectsANonRawKey(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('32-byte');

        CtxToken::mint(['sid' => 's1'], bin2hex(CtxToken::deriveKey('secret-abc'))); // hex, not raw
    }

    public function testAppendToUrlIsFragmentSafe(): void
    {
        // the token must join the query BEFORE any #fragment, or it stays
        // client-side and never reaches the landing page (review finding)
        self::assertSame('https://lp.example/page?t202ctx=tok', CtxToken::appendToUrl('https://lp.example/page', 'tok'));
        self::assertSame('https://lp.example/page?a=1&t202ctx=tok', CtxToken::appendToUrl('https://lp.example/page?a=1', 'tok'));
        self::assertSame('https://lp.example/page?t202ctx=tok#hero', CtxToken::appendToUrl('https://lp.example/page#hero', 'tok'));
        self::assertSame('https://lp.example/page?a=1&t202ctx=tok#hero', CtxToken::appendToUrl('https://lp.example/page?a=1#hero', 'tok'));
    }
}

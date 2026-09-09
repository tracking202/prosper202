<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Attribution\PostbackReceiver;
use Api\V3\Attribution\SignatureState;
use Api\V3\Attribution\SkadnetworkProtocol;
use Tests\TestCase;

/**
 * Every signature state, under either opt-in, lands in a trust class the
 * retention pass knows: trusted rows (1) are kept forever by design, and
 * every other trust bit the policy can produce (0, NULL) is covered by a
 * bounded DELETE. A state whose trust bit fell outside those values — a
 * fifth state mapped to 2, say — would be a row an unauthenticated poster
 * can mint that no prune ever reaches, which is exactly the gap the
 * unverifiable class was added to close.
 */
final class SignatureStateRetentionTest extends TestCase
{
    use CapturingMysqli;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captured = [];
    }

    /** The WHERE clause of each retention DELETE, keyed by the trust value it prunes. */
    private function pruneClauses(): array
    {
        (new PostbackReceiver($this->capturingDb(), new SkadnetworkProtocol()))->prunePostbacks(1_800_000_000);
        $clauses = [];
        foreach ($this->capturedStatements('DELETE') as $delete) {
            $this->assertSame(1, preg_match('/WHERE (.+?) AND received_at < \?/', $delete['sql'], $m), $delete['sql']);
            $clauses[] = $m[1];
        }
        return $clauses;
    }

    public function testEveryStateMapsToATrustValueTheRetentionPassCovers(): void
    {
        $clauses = $this->pruneClauses();
        $prunedTrustValues = [];
        foreach ($clauses as $clause) {
            $prunedTrustValues[] = match ($clause) {
                'signature_valid = 0' => 0,
                'signature_valid IS NULL' => null,
                'user_id = 0' => 'unclaimed',
                default => self::fail("retention DELETE with an unrecognised condition: $clause"),
            };
        }
        $this->assertContains(0, $prunedTrustValues, 'forged rows must have a retention class');
        $this->assertContains(null, $prunedTrustValues, 'rows nobody vouched for must have a retention class');

        $this->assertNotEmpty(SignatureState::cases());
        foreach (SignatureState::cases() as $state) {
            foreach ([false, true] as $acceptDevelopment) {
                $bit = $state->trustBit($acceptDevelopment);
                if ($bit === 1) {
                    continue; // trusted: kept forever once claimed, by design
                }
                $this->assertContains(
                    $bit,
                    [0, null],
                    "state '{$state->value}' (opt-in " . var_export($acceptDevelopment, true) . ") yields trust bit "
                    . var_export($bit, true) . ', which no retention class prunes'
                );
                $this->assertContains($bit, $prunedTrustValues, "state '{$state->value}' maps to a trust value without a DELETE");
            }
        }
    }

    public function testOnlyTheProductionKeyOrAnOptedInDevelopmentKeyIsTrusted(): void
    {
        $trusted = [];
        foreach (SignatureState::cases() as $state) {
            foreach ([false, true] as $acceptDevelopment) {
                if ($state->trustBit($acceptDevelopment) === 1) {
                    $trusted[] = $state->value . ($acceptDevelopment ? '+opt-in' : '');
                }
            }
        }
        $this->assertEqualsCanonicalizing(
            ['valid', 'valid+opt-in', 'development+opt-in'],
            $trusted,
            'the set of trusted (state, opt-in) combinations is a security decision; widen it deliberately, with a test'
        );
    }

    public function testAStateOutsideTheEnumCannotBeConstructed(): void
    {
        // The states are a closed set: a verdict no trust policy knows how
        // to price is unrepresentable rather than mapped to a fall-through.
        // tryFrom is the only door in from a string (the stored column, the
        // API filter) and it refuses anything else.
        $this->assertNull(SignatureState::tryFrom('probably-fine'));
        $this->assertNull(SignatureState::tryFrom('Valid'), 'the backing values are exact, not case-insensitive');
        foreach (SignatureState::values() as $value) {
            $this->assertInstanceOf(SignatureState::class, SignatureState::tryFrom($value));
        }
    }
}

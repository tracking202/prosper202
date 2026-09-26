<?php

declare(strict_types=1);

namespace Tests\Apps\Apple;

use Api\V3\Apps\AppPolicy;
use Api\V3\Apps\AppRetention;
use Api\V3\Apps\Apple\PostbackReceiver;
use Api\V3\Apps\Apple\SignatureState;
use Tests\TestCase;

/**
 * Every signature state, under either test-signal policy, lands in a trust
 * class the retention pass knows: trusted rows (1) are kept forever by
 * design, and every other trust bit the policy can produce (0 refuted, NULL
 * unvouched) is covered by a bounded DELETE the Apple source registers with
 * AppRetention. A state whose trust bit fell outside those values — a fifth
 * state mapped to 2, say — would be a row an unauthenticated poster can mint
 * that no prune ever reaches, which is exactly the gap the unvouched class
 * was added to close.
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
        (new AppRetention($this->capturingDb(), PostbackReceiver::retentionClasses()))->prune(1_800_000_000);
        $clauses = [];
        foreach ($this->capturedStatements('DELETE') as $delete) {
            $this->assertSame(1, preg_match('/^DELETE FROM 202_app_postbacks WHERE (.+?) AND received_at < \?/', $delete['sql'], $m), $delete['sql']);
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
                'trusted = 0' => 0,
                'trusted IS NULL' => null,
                'user_id = 0' => 'unclaimed',
                default => self::fail("retention DELETE with an unrecognised condition: $clause"),
            };
        }
        $this->assertContains(0, $prunedTrustValues, 'forged rows must have a retention class');
        $this->assertContains(null, $prunedTrustValues, 'rows nobody vouched for must have a retention class');

        $this->assertNotEmpty(SignatureState::cases());
        foreach (SignatureState::cases() as $state) {
            foreach ([false, true] as $acceptDevelopment) {
                $bit = $state->trustBit(AppPolicy::withTestSignals($acceptDevelopment));
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
                if ($state->trustBit(AppPolicy::withTestSignals($acceptDevelopment)) === 1) {
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

    /**
     * The test-signal policy governs exactly the states that say they are
     * test signals, and an unreadable policy is the untrusting one.
     */
    public function testOnlyATestSignalDependsOnThePolicyAndAnUnreadablePolicyTrustsNone(): void
    {
        foreach (SignatureState::cases() as $state) {
            $withPolicy = $state->trustBit(AppPolicy::withTestSignals(true));
            $without = $state->trustBit(AppPolicy::withTestSignals(false));
            $this->assertSame($state->isTest(), $withPolicy !== $without, "state '{$state->value}'");
            foreach ([null, [], ['accept_test_signals' => null], ['accept_test_signals' => '2'],
                ['accept_test_signals' => true], ['accept_test_signals' => 'yes'], 'row'] as $unreadable) {
                $this->assertSame(
                    $without,
                    $state->trustBit(AppPolicy::fromRow($unreadable)),
                    "an unreadable policy must not trust a '{$state->value}' signal: " . var_export($unreadable, true)
                );
            }
        }
        $this->assertTrue(AppPolicy::fromRow(['accept_test_signals' => 1])->acceptTestSignals);
        $this->assertTrue(AppPolicy::fromRow(['accept_test_signals' => '1'])->acceptTestSignals);
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

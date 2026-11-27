<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\StagedChangesController;
use Api\V3\Exception\DatabaseException;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\StagedChangeStatus as S;
use PHPUnit\Framework\TestCase;
use Tests\Support\LifecycleContract;

/**
 * The staged-change transition table, and the surfaces that copy its states
 * (CLAUDE.md #5): the controller's constants and filter, the OpenAPI enum,
 * and the lists the Go CLI hard-codes.
 */
final class StagedChangeStatusTest extends TestCase
{
    public function testTheTableIsExactlyThis(): void
    {
        self::assertSame([], LifecycleContract::violations(S::class));
        self::assertSame([S::Staged], S::initial());
        $table = [];
        foreach (S::cases() as $from) {
            $table[$from->value] = array_map(static fn (S $to): string => $to->value, $from->next());
        }
        self::assertSame([
            'staged' => ['applying', 'discarded'],
            'applying' => ['staged', 'applied', 'apply_interrupted'],
            'applied' => [],
            'discarded' => [],
            'apply_interrupted' => ['applied'],
        ], $table);
        self::assertTrue(S::Applied->isTerminal());
        self::assertTrue(S::Discarded->isTerminal());
    }

    public function testAChangeWhoseWriteMayHaveLandedNeverReturnsToTheQueue(): void
    {
        // Only `applying` goes back to `staged`, and only its own dispatch
        // (which knows nothing was written) asks for that.
        self::assertSame([S::Applying], S::sources(S::Staged));
        self::assertFalse(S::ApplyInterrupted->canMoveTo(S::Staged));
        self::assertFalse(S::ApplyInterrupted->canMoveTo(S::Applying));
        self::assertFalse(S::ApplyInterrupted->canMoveTo(S::Discarded));
        self::assertFalse(S::Applying->canMoveTo(S::Discarded));
        self::assertFalse(S::Staged->canMoveTo(S::Applied), 'nothing is applied without a claim');
        self::assertFalse(S::Staged->canMoveTo(S::ApplyInterrupted));
    }

    public function testResolvedIsWhatMayBePruned(): void
    {
        $resolved = array_values(array_filter(S::cases(), static fn (S $s): bool => $s->isResolved()));
        self::assertSame([S::Applied, S::Discarded, S::ApplyInterrupted], $resolved);
    }

    public function testAStoredRecordNamingNoStateIsNoState(): void
    {
        self::assertSame(S::Applying, S::ofChange(['status' => 'applying']));
        foreach ([['status' => 'pending'], ['status' => 1], ['status' => null], [], 'staged', null] as $unreadable) {
            self::assertNull(S::ofChange($unreadable), json_encode($unreadable));
        }
    }

    public function testAChangeIsCreatedOnlyStaged(): void
    {
        $dir = sys_get_temp_dir() . '/p202-staged-status-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        try {
            $store = new ServerStateStore($dir);
            foreach ([S::Applying, S::Applied, S::Discarded, S::ApplyInterrupted] as $status) {
                try {
                    $store->stageWriteChange(5, ['change_id' => 'chg-' . $status->value, 'status' => $status->value]);
                    self::fail('a change was created ' . $status->value);
                } catch (DatabaseException $e) {
                    self::assertStringContainsString('not created as', (string) $e->getPrevious()?->getMessage());
                }
            }
            self::assertSame([], $store->listStagedChangesForUser(5));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testTheControllersConstantsAreTheStates(): void
    {
        $constants = array_filter(
            (new \ReflectionClass(StagedChangesController::class))->getConstants(),
            static fn (string $name): bool => str_starts_with($name, 'STATUS_'),
            ARRAY_FILTER_USE_KEY
        );
        self::assertSame(S::values(), array_values($constants));
    }

    public function testTheDocumentedFilterValuesAreTheStates(): void
    {
        $spec = self::read('docs/openapi.yaml');
        $at = strpos($spec, 'operationId: listStagedChanges');
        self::assertNotFalse($at);
        self::assertSame(1, preg_match('/- name: status\s+in: query\s+schema:\s+type: string\s+enum: \[([^\]]*)\]/', substr($spec, $at, 600), $m));
        self::assertSame(S::values(), array_map('trim', explode(',', $m[1])));
    }

    public function testTheGoCliStatusesAreTheServers(): void
    {
        $source = self::read('go-cli/cmd/change.go');
        self::assertSame(1, preg_match('/^var stagedChangeStatuses = \[\]string\{([^}]*)\}/m', $source, $m));
        preg_match_all('/"([^"]+)"/', $m[1], $values);
        self::assertSame(S::values(), $values[1]);
    }

    public function testTheGoCliHintsNameAStateTheServerHas(): void
    {
        // client.go picks its hint for a 409 by the state the message names,
        // and the message names it by the enum's value.
        $client = self::read('go-cli/internal/api/client.go');
        self::assertSame(1, preg_match('/strings\.Contains\(apiErr\.Message, "([a-z_]+)"\):\s+return "The write may or may not have landed/', $client, $m));
        self::assertSame(S::ApplyInterrupted->value, $m[1]);
    }

    private static function read(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
        self::assertIsString($source, $path);

        return $source;
    }
}

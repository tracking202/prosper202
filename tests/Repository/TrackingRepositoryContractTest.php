<?php

declare(strict_types=1);

namespace Tests\Repository;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Repository\Cached\CachedTrackingRepository;
use Prosper202\Repository\InMemory\InMemoryTrackingRepository;
use Prosper202\Repository\TrackingRepositoryInterface;
use RuntimeException;

final class TrackingRepositoryContractTest extends TestCase
{
    /**
     * @return iterable<string, array{TrackingRepositoryInterface}>
     */
    public static function implementations(): iterable
    {
        yield 'in-memory' => [new InMemoryTrackingRepository()];
        yield 'cached' => [self::buildCached()];
    }

    private static function buildCached(): CachedTrackingRepository
    {
        return new CachedTrackingRepository(new InMemoryTrackingRepository(), new ArrayIdCache(), 'test-hash');
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateKeywordReturnsStableId(TrackingRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateKeyword('buy shoes');
        $id2 = $repo->findOrCreateKeyword('buy shoes');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateKeywordReturnsZeroForEmpty(TrackingRepositoryInterface $repo): void
    {
        self::assertSame(0, $repo->findOrCreateKeyword(''));
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateC1ReturnsStableId(TrackingRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateC1('campaign-123');
        $id2 = $repo->findOrCreateC1('campaign-123');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateC2ReturnsStableId(TrackingRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateC2('adgroup-456');
        $id2 = $repo->findOrCreateC2('adgroup-456');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateC3ReturnsStableId(TrackingRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateC3('creative-789');
        $id2 = $repo->findOrCreateC3('creative-789');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateC4ReturnsStableId(TrackingRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateC4('placement-abc');
        $id2 = $repo->findOrCreateC4('placement-abc');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testDifferentC1ValuesGetDifferentIds(TrackingRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateC1('alpha');
        $id2 = $repo->findOrCreateC1('beta');

        self::assertNotSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateVariableReturnsStableId(TrackingRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateVariable('some-value', 42);
        $id2 = $repo->findOrCreateVariable('some-value', 42);

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testSameVariableValueDifferentPpcIdGetsDifferentId(TrackingRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateVariable('same-val', 1);
        $id2 = $repo->findOrCreateVariable('same-val', 2);

        self::assertNotSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateVariableSetReturnsStableId(TrackingRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateVariableSet('1,2,3');
        $id2 = $repo->findOrCreateVariableSet('1,2,3');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /** @return iterable<string, array{TrackingRepositoryInterface, string}> */
    public static function malformedVariableSets(): iterable
    {
        foreach (['1,,2', '', '1,x', '0', '1, 2', '01,2'] as $malformed) {
            foreach (self::implementations() as $label => [$repo]) {
                yield $label . ' "' . $malformed . '"' => [$repo, $malformed];
            }
        }
    }

    /**
     * What MysqlTrackingRepository refuses (MysqlLookupRepositoriesIntegrationTest
     * holds it to the same list), so a fake cannot take what the server would not.
     *
     * @dataProvider malformedVariableSets
     */
    public function testAVariableSetThatIsNotCommaJoinedIdsIsRefused(
        TrackingRepositoryInterface $repo,
        string $variables
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $repo->findOrCreateVariableSet($variables);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateCustomVarReturnsStableId(TrackingRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateCustomVar('sub_id', 'abc123');
        $id2 = $repo->findOrCreateCustomVar('sub_id', 'abc123');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateCustomVarRejectsInvalidName(TrackingRepositoryInterface $repo): void
    {
        $this->expectException(RuntimeException::class);
        $repo->findOrCreateCustomVar('invalid name!', 'data');
    }

    /**
     * The name becomes a table and a column name; `$` would let a final
     * newline through.
     *
     * @dataProvider implementations
     */
    public function testFindOrCreateCustomVarRejectsATrailingNewline(TrackingRepositoryInterface $repo): void
    {
        $this->expectException(RuntimeException::class);
        $repo->findOrCreateCustomVar("sub_id\n", 'data');
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateUtmReturnsStableId(TrackingRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateUtm('google', 'utm_source');
        $id2 = $repo->findOrCreateUtm('google', 'utm_source');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateUtmRejectsInvalidType(TrackingRepositoryInterface $repo): void
    {
        $this->expectException(RuntimeException::class);
        $repo->findOrCreateUtm('value', 'utm_invalid');
    }

    /**
     * @dataProvider implementations
     */
    public function testDifferentUtmTypesGetDifferentIds(TrackingRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateUtm('google', 'utm_source');
        $id2 = $repo->findOrCreateUtm('google', 'utm_medium');

        self::assertNotSame($id1, $id2);
    }
}

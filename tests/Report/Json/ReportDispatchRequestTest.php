<?php

declare(strict_types=1);

namespace Tests\Report\Json;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\SortOrder;
use Tracking202\Report\Json\ReportDispatchRequest;

/**
 * Tests for the JSON report transport's request parser.
 *
 * This is the trust boundary for the dispatch endpoint: every field on the wire is
 * attacker-controllable, and the parser is the only thing between it and the report
 * query. It is pure and DB-free, so it is exercised directly here.
 */
final class ReportDispatchRequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $postBackup = [];

    protected function setUp(): void
    {
        $this->postBackup = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
    }

    public function testParsesAMinimalValidPayload(): void
    {
        $request = ReportDispatchRequest::fromArray(['reportType' => 'keyword']);

        self::assertSame('keyword', $request->reportType);
        self::assertSame(0, $request->offset);
        self::assertSame('', $request->order);
    }

    public function testParsesAFullyPopulatedPayload(): void
    {
        $request = ReportDispatchRequest::fromArray([
            'reportType' => 'country',
            'offset' => 40,
            'order' => 'sort_breakdown_clicks desc',
        ]);

        self::assertSame('country', $request->reportType);
        self::assertSame(40, $request->offset);
        self::assertSame('sort_breakdown_clicks desc', $request->order);
    }

    /**
     * Every report page wired to the JSON transport must be accepted by the parser,
     * otherwise that page 422s at runtime with the flag on.
     *
     * @return list<array{string}>
     */
    public static function supportedReportTypeProvider(): array
    {
        return array_map(
            static fn (string $type): array => [$type],
            ['keyword', 'textad', 'referer', 'ip', 'country', 'region', 'city', 'isp', 'landingpage', 'device', 'browser', 'platform']
        );
    }

    /**
     * @dataProvider supportedReportTypeProvider
     */
    public function testAcceptsEverySupportedReportType(string $reportType): void
    {
        $request = ReportDispatchRequest::fromArray(['reportType' => $reportType]);

        self::assertSame($reportType, $request->reportType);
    }

    public function testRejectsUnknownReportType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);

        ReportDispatchRequest::fromArray(['reportType' => 'ltv']);
    }

    public function testRejectsMissingReportType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);

        ReportDispatchRequest::fromArray(['offset' => 0]);
    }

    /**
     * A typo'd or stale field must not be silently ignored (CLAUDE.md #4) — a client
     * that thinks it disabled dependent filters should not get them anyway.
     */
    public function testRejectsUnknownFieldsRatherThanIgnoringThem(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);

        ReportDispatchRequest::fromArray([
            'reportType' => 'keyword',
            'includeDependantFilters' => false,
        ]);
    }

    public function testRejectsNegativeOffset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);

        ReportDispatchRequest::fromArray(['reportType' => 'keyword', 'offset' => -1]);
    }

    public function testAcceptsNumericStringOffset(): void
    {
        $request = ReportDispatchRequest::fromArray(['reportType' => 'keyword', 'offset' => '120']);

        self::assertSame(120, $request->offset);
    }

    /**
     * @return list<array{mixed}>
     */
    public static function invalidOffsetProvider(): array
    {
        return [['abc'], ['-5'], ['1.5'], [1.5], [null], [true], [[]], ['']];
    }

    /**
     * @dataProvider invalidOffsetProvider
     */
    public function testRejectsNonIntegerOffset(mixed $offset): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);

        ReportDispatchRequest::fromArray(['reportType' => 'keyword', 'offset' => $offset]);
    }

    public function testRejectsUnsupportedOrderToken(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);

        ReportDispatchRequest::fromArray([
            'reportType' => 'keyword',
            'order' => 'clicks; DROP TABLE 202_clicks',
        ]);
    }

    public function testRejectsNonStringOrder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);

        ReportDispatchRequest::fromArray(['reportType' => 'keyword', 'order' => 1]);
    }

    /**
     * The dependent filter dropdowns were Bootstrap 3 markup for the classic
     * report calendar, and went with it (U8). A client that still asks for
     * them is told so by name rather than answered without them.
     */
    public function testTheRetiredDependentFiltersFieldIsRefusedByName(): void
    {
        foreach ([true, false] as $value) {
            try {
                ReportDispatchRequest::fromArray([
                    'reportType' => 'keyword',
                    'includeDependentFilters' => $value,
                ]);
                self::fail('includeDependentFilters was accepted');
            } catch (InvalidArgumentException $e) {
                self::assertSame(422, $e->getCode());
                self::assertStringContainsString('includeDependentFilters', $e->getMessage());
            }
        }
    }

    /**
     * An omitted field takes its default, but an explicitly-null one is malformed input.
     * Coalescing null to the default is the silent-fallback pattern the parser exists to
     * prevent, so each nullable-looking field is pinned here.
     *
     * @return array<string, array{string}>
     */
    public static function explicitlyNullFieldProvider(): array
    {
        return [
            'offset' => ['offset'],
            'order' => ['order'],
        ];
    }

    /**
     * @dataProvider explicitlyNullFieldProvider
     */
    public function testRejectsExplicitlyNullField(string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);

        ReportDispatchRequest::fromArray(['reportType' => 'keyword', $field => null]);
    }

    public function testOmittedFieldsStillTakeTheirDefaults(): void
    {
        $request = ReportDispatchRequest::fromArray(['reportType' => 'keyword']);

        self::assertSame(0, $request->offset);
        self::assertSame('', $request->order);
    }

    public function testApplyLegacyGlobalsPublishesOffsetAndOrder(): void
    {
        $_POST = ['stale' => 'value'];

        $request = ReportDispatchRequest::fromArray([
            'reportType' => 'keyword',
            'offset' => 25,
            'order' => 'sort_breakdown_epc asc',
        ]);
        $request->applyLegacyGlobals();

        self::assertSame('25', $_POST['offset']);
        self::assertSame('sort_breakdown_epc asc', $_POST['order']);
        self::assertArrayNotHasKey('stale', $_POST, 'legacy globals must not leak in from the ambient request');
    }

    /**
     * DataEngine::sortOrder() falls back to its default when $_POST['order'] is absent,
     * so an empty token must be left unset rather than published as ''.
     */
    public function testApplyLegacyGlobalsOmitsEmptyOrder(): void
    {
        $request = ReportDispatchRequest::fromArray(['reportType' => 'keyword']);
        $request->applyLegacyGlobals();

        self::assertSame('0', $_POST['offset']);
        self::assertArrayNotHasKey('order', $_POST);
    }

    /**
     * Regression guard against a silent-divergence bug.
     *
     * The parser whitelists sort tokens, but DataEngine hands them to SortOrder, which
     * whitelists them *again* and quietly falls back to "ORDER BY leads DESC" on a miss.
     * A token accepted here but unknown to SortOrder would therefore validate, 200, and
     * return data sorted by something other than what the user clicked — with no error.
     * Pin the two whitelists together.
     */
    public function testEveryAcceptedOrderTokenIsHonoredBySortOrder(): void
    {
        $tokens = array_filter(ReportDispatchRequest::supportedOrderTokens());
        self::assertNotEmpty($tokens);

        foreach ($tokens as $token) {
            self::assertSame(
                $token,
                SortOrder::canonicalKey($token),
                sprintf('Sort token "%s" is accepted by the dispatcher but SortOrder would ignore it.', $token)
            );
        }
    }
}

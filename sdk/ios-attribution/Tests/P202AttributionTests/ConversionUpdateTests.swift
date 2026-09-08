import XCTest
@testable import P202Attribution

final class ConversionUpdateTests: XCTestCase {
    private let schema = P202AttributionSchema(
        appId: 1,
        schemaVersion: "v",
        events: [
            "purchase": .init(fineValue: 63, coarseValue: .high),
            "signup": .init(fineValue: 5, coarseValue: nil),
            "engaged": .init(fineValue: nil, coarseValue: .medium),
            "broken": .init(fineValue: 99, coarseValue: nil),
        ]
    )

    func testMappedEventResolvesFineAndCoarse() {
        let update = ConversionUpdate.resolve(event: "purchase", in: schema, lastFineValue: nil)
        XCTAssertEqual(update, ConversionUpdate(fineValue: 63, coarseValue: .high, usedFineFallback: false))
    }

    func testUnmappedEventIsANoOpNotAGuess() {
        XCTAssertNil(ConversionUpdate.resolve(event: "unmapped", in: schema, lastFineValue: 40))
    }

    func testCoarseOnlyMappingKeepsTheLastFineValue() {
        // The coarse-bearing SKAdNetwork API always takes a fine value;
        // sending an arbitrary one would downgrade the fine signal.
        let update = ConversionUpdate.resolve(event: "engaged", in: schema, lastFineValue: 41)
        XCTAssertEqual(update, ConversionUpdate(fineValue: 41, coarseValue: .medium, usedFineFallback: true))
    }

    func testCoarseOnlyMappingWithNoHistoryFallsBackToZero() {
        let update = ConversionUpdate.resolve(event: "engaged", in: schema, lastFineValue: nil)
        XCTAssertEqual(update?.fineValue, 0)
        XCTAssertEqual(update?.usedFineFallback, true)
    }

    func testScopingKeepsTheDecisionAndAddsThePostbacks() {
        let decision = ConversionUpdate.resolve(event: "purchase", in: schema, lastFineValue: nil)
        XCTAssertNil(decision?.conversionTypes, "resolution itself is unscoped")
        XCTAssertEqual(decision?.includesInstall, true)
        XCTAssertEqual(decision?.includesReengagement, false)

        let scoped = decision?.scoped(to: [.reengagement])
        XCTAssertEqual(
            scoped,
            ConversionUpdate(fineValue: 63, coarseValue: .high, usedFineFallback: false, conversionTypes: [.reengagement])
        )
        XCTAssertEqual(scoped?.includesInstall, false)
        XCTAssertEqual(scoped?.includesReengagement, true)
        XCTAssertNotEqual(scoped, decision, "the scope is part of what was reported")
    }

    func testOutOfRangeFineValuesAreClampedNotCrashed() {
        let update = ConversionUpdate.resolve(event: "broken", in: schema, lastFineValue: nil)
        XCTAssertEqual(update?.fineValue, 63)
    }
}

import XCTest
@testable import P202SKAN

final class ConversionUpdateTests: XCTestCase {
    private let schema = P202SKANSchema(
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

    func testOutOfRangeFineValuesAreClampedNotCrashed() {
        let update = ConversionUpdate.resolve(event: "broken", in: schema, lastFineValue: nil)
        XCTAssertEqual(update?.fineValue, 63)
    }
}

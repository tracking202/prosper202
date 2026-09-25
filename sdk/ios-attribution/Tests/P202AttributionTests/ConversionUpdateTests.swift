import XCTest
@testable import P202Attribution

final class ConversionUpdateTests: XCTestCase {
    /// Goals 1-4 encoded; goal 5 reached but not encoded.
    private let schema = P202AttributionSchema(
        appId: 1,
        schemaVersion: "v",
        goals: [],
        encodings: [
            1: .init(fineValue: 63, coarseValue: .high),
            2: .init(fineValue: 5, coarseValue: nil),
            3: .init(fineValue: nil, coarseValue: .medium),
            4: .init(fineValue: 99, coarseValue: nil),
        ]
    )

    private func reached(_ goalIds: Int..., ineligible: String? = nil) -> [GoalOutcome] {
        return goalIds.map {
            GoalOutcome(goalId: $0, version: 1, n: 1, eventId: "e", reachedAt: 0, valueUnits: nil,
                        valueSource: "none", valueNote: nil, ineligibleReason: ineligible)
        }
    }

    private func resolve(_ outcomes: [GoalOutcome], lastFineValue: Int? = nil) -> ConversionUpdate? {
        return ConversionUpdate.resolve(outcomes: outcomes, in: schema, lastFineValue: lastFineValue)
    }

    func testAReachedGoalResolvesToItsEncoding() {
        XCTAssertEqual(resolve(reached(1)), ConversionUpdate(fineValue: 63, coarseValue: .high, usedFineFallback: false))
    }

    func testNothingReachedOrNothingEncodedIsANoOpNotAGuess() {
        XCTAssertNil(resolve([], lastFineValue: 40))
        XCTAssertNil(resolve(reached(5), lastFineValue: 40))
    }

    func testAnIneligibleOutcomeWasNotReached() {
        // A click-windowed goal on a device with no click is recorded as
        // no_click; it must not set the value a real reach would.
        XCTAssertNil(resolve(reached(1, ineligible: "no_click")))
    }

    func testSeveralGoalsInOneEventTakeTheHighestOfEachKind() {
        XCTAssertEqual(resolve(reached(2, 3)), ConversionUpdate(fineValue: 5, coarseValue: .medium, usedFineFallback: false))
        XCTAssertEqual(resolve(reached(2, 1, 3)), ConversionUpdate(fineValue: 63, coarseValue: .high, usedFineFallback: false))
    }

    func testCoarseOnlyMappingKeepsTheLastFineValue() {
        // The coarse-bearing SKAdNetwork API always takes a fine value;
        // sending an arbitrary one would downgrade the fine signal.
        XCTAssertEqual(resolve(reached(3), lastFineValue: 41), ConversionUpdate(fineValue: 41, coarseValue: .medium, usedFineFallback: true))
    }

    func testCoarseOnlyMappingWithNoHistoryFallsBackToZero() {
        let update = resolve(reached(3))
        XCTAssertEqual(update?.fineValue, 0)
        XCTAssertEqual(update?.usedFineFallback, true)
    }

    func testScopingKeepsTheDecisionAndAddsThePostbacks() {
        let decision = resolve(reached(1))
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

    func testAReengagementScopedUpdateIsDroppedWithoutTheScopedAPI() {
        // iOS 17.4-17.x: no re-engagement postback exists and no unscoped
        // overload can be scoped, so the update would land on the install
        // postback — the value the caller just declined to touch by skipping
        // SKAdNetwork. Nothing can be delivered, so nothing is.
        let update = ConversionUpdate(
            fineValue: 3,
            coarseValue: nil,
            usedFineFallback: false,
            conversionTypes: [.reengagement]
        )
        XCTAssertEqual(update.adAttributionKitDelivery(scopedAPIAvailable: false), .skip)
        XCTAssertEqual(update.adAttributionKitDelivery(scopedAPIAvailable: true), .scoped([.reengagement]))
    }

    func testAnUnscopedUpdateNamesTheInstallPostbackWhereItCan() {
        // nil means the install postback everywhere else in this type, but
        // AdAttributionKit's unscoped overloads carry no conversion types and
        // the system then updates EVERY postback type. Where the scoped API
        // exists the install scope is therefore stated outright.
        let update = ConversionUpdate(fineValue: 0, coarseValue: nil, usedFineFallback: false)
        XCTAssertNil(update.conversionTypes)
        XCTAssertEqual(update.adAttributionKitDelivery(scopedAPIAvailable: true), .scoped([.install]))
        // Below iOS 18 the unscoped overload is right: install is the only
        // postback it can reach.
        XCTAssertEqual(update.adAttributionKitDelivery(scopedAPIAvailable: false), .unscoped)
    }

    func testInstallBearingScopesAreDeliveredOnBothSystems() {
        for types in [[ConversionUpdate.ConversionType.install], [.install, .reengagement]] {
            let update = ConversionUpdate(
                fineValue: 63,
                coarseValue: .high,
                usedFineFallback: false,
                conversionTypes: types
            )
            XCTAssertEqual(update.adAttributionKitDelivery(scopedAPIAvailable: true), .scoped(types))
            XCTAssertEqual(
                update.adAttributionKitDelivery(scopedAPIAvailable: false),
                .unscoped,
                "the install postback is reachable on iOS 17.4 too"
            )
        }
    }

    func testAnEmptyScopeNamesNoPostbackRatherThanEveryPostback() {
        // [] is not nil: it selects nothing. It must not reach the scoped
        // API, whose nil conversionTypes means every postback type.
        let update = ConversionUpdate(
            fineValue: 12,
            coarseValue: nil,
            usedFineFallback: false,
            conversionTypes: []
        )
        XCTAssertEqual(update.adAttributionKitDelivery(scopedAPIAvailable: true), .skip)
        XCTAssertEqual(update.adAttributionKitDelivery(scopedAPIAvailable: false), .skip)
    }

    func testOutOfRangeFineValuesAreClampedNotCrashed() {
        let update = resolve(reached(4))
        XCTAssertEqual(update?.fineValue, 63)
    }
}

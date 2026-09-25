import XCTest
@testable import P202Attribution
#if canImport(FoundationNetworking)
import FoundationNetworking
#endif

/// A schema document as the server builds it, for the tests below.
enum TestSchema {
    /// Goal 1: every purchase (fine 63). Goal 2: every "engaged" (coarse
    /// medium only).
    static let body = document(
        goals: [
            goal(1, #"{"name":"Purchase","trigger":{"event":"purchase"},"repeat":{"mode":"each"}}"#),
            goal(2, #"{"name":"Engaged","trigger":{"event":"engaged"},"repeat":{"mode":"each"}}"#),
        ],
        encodings: [
            #"{"goal_id":1,"fine_value":63,"coarse_value":null}"#,
            #"{"goal_id":2,"fine_value":null,"coarse_value":"medium"}"#,
        ]
    )

    static func goal(_ id: Int, _ definition: String, effectiveAt: Int = 0, startsAt: Int = 0) -> String {
        return #"{"goal_id":\#(id),"starts_at":\#(startsAt),"ends_at":null,"versions":[{"version":1,"effective_at":\#(effectiveAt),"definition":\#(definition)}]}"#
    }

    static func document(goals: [String], encodings: [String], version: String = "abc", appKey: String = #""42""#, platform: String = "ios", appId: String = "42") -> Data {
        return Data("""
        {"data":{"platform":"\(platform)","app_key":\(appKey),"app_id":\(appId),"schema_version":"\(version)",
        "goals":[\(goals.joined(separator: ","))],
        "encodings":[\(encodings.joined(separator: ","))],"generated_at":1725690000}}
        """.utf8)
    }
}

/// Behaviour of the P202Attribution facade: configuration changes (a new
/// build with a rotated app token while the old build's state, and possibly
/// its in-flight fetch, is still around), and the device's goal evaluation
/// end to end through logEvent. finishRefresh is internal exactly so these
/// can run without a server.
final class FacadeTests: XCTestCase {
    /// Connection-refused immediately; configure()'s automatic fetch fails
    /// fast and harmlessly in these tests.
    private static let deadEndpoint = URL(string: "http://127.0.0.1:9")!

    private func httpResponse(status: Int) -> HTTPURLResponse {
        return HTTPURLResponse(url: Self.deadEndpoint, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    /// A configured SDK holding `body` as its schema, with a clock the test
    /// moves and a record of what reached the frameworks.
    private final class Harness {
        let store = InMemoryStore()
        let sdk: P202Attribution
        var now = 1_000_000
        var submitted: [ConversionUpdate] = []

        init(token: String = "token-a", body: Data? = TestSchema.body, test: FacadeTests) throws {
            sdk = P202Attribution(store: store, session: .shared)
            sdk.clock = { [unowned self] in self.now }
            sdk.onSubmit = { [unowned self] in self.submitted.append($0) }
            sdk.configure(endpoint: FacadeTests.deadEndpoint, appToken: token)
            if let body {
                _ = try sdk.finishRefresh(requestToken: token, data: body, response: test.httpResponse(status: 200), error: nil).get()
            }
        }
    }

    func testFinishRefreshStoresASchemaForTheCurrentToken() throws {
        let h = try Harness(test: self)
        let schema = try XCTUnwrap(h.sdk.currentSchema)
        XCTAssertEqual(schema.appId, 42)
        XCTAssertEqual(schema.encodings[1], .init(fineValue: 63, coarseValue: nil))
        XCTAssertTrue(h.store.keys.contains(SchemaCache.storageKey(appToken: "token-a")))
    }

    func testFinishRefreshDropsAResponseForASupersededToken() {
        // configure(token B) while token A's fetch is in flight: A's
        // response must not land in B's cache slot — B's app would encode
        // with A's app's values until the next successful fetch.
        let store = InMemoryStore()
        let sdk = P202Attribution(store: store, session: .shared)
        sdk.configure(endpoint: Self.deadEndpoint, appToken: "token-b")

        let result = sdk.finishRefresh(requestToken: "token-a", data: TestSchema.body, response: httpResponse(status: 200), error: nil)

        guard case let .failure(error) = result else {
            return XCTFail("a stale response must not be reported as success")
        }
        XCTAssertEqual(error as? P202Attribution.SDKError, .superseded)
        XCTAssertNil(sdk.currentSchema)
        XCTAssertFalse(store.keys.contains(SchemaCache.storageKey(appToken: "token-a")))
        XCTAssertFalse(store.keys.contains(SchemaCache.storageKey(appToken: "token-b")))
    }

    func testTheAppTokenTravelsInItsHeader() {
        XCTAssertEqual(P202Attribution.appTokenHeader, "X-P202-App-Token")
    }

    func testAnAndroidDocumentIsRefusedWhole() {
        // A token lifted into the wrong build selects another app's document;
        // encoding with it would set values that mean nothing here.
        let store = InMemoryStore()
        let sdk = P202Attribution(store: store, session: .shared)
        sdk.configure(endpoint: Self.deadEndpoint, appToken: "token-a")
        let android = TestSchema.document(goals: [], encodings: [], appKey: #""com.example.app""#, platform: "android", appId: "null")
        let result = sdk.finishRefresh(requestToken: "token-a", data: android, response: httpResponse(status: 200), error: nil)
        guard case let .failure(error) = result else {
            return XCTFail("an Android document was accepted")
        }
        XCTAssertEqual(error as? P202AttributionSchema.DecodeError, .notAnIOSApp)
        XCTAssertNil(sdk.currentSchema)
    }

    func testRegisterAttributionDoesNotResetAValueAlreadyReported() throws {
        // Developers habitually call registerAttribution() from
        // applicationDidBecomeActive. Reporting a hardcoded 0 there would
        // both downgrade what Apple holds and leave the SDK's own
        // lastFineValue untouched, so the two disagreed from then on.
        let h = try Harness(test: self)
        XCTAssertEqual(h.sdk.registerAttribution().fineValue, 0, "a fresh install reports 0")
        XCTAssertEqual(try h.sdk.logEvent("purchase")?.fineValue, 63)
        XCTAssertEqual(h.sdk.registerAttribution().fineValue, 63, "re-asserts the reported value instead of resetting it")
    }

    func testAReengagementScopedUpdateNeverTouchesTheInstallPostbacksValue() throws {
        // AdAttributionKit keeps a separate conversion value for its
        // re-engagement postback (iOS 18+). An update scoped to it must
        // neither be sent to SKAdNetwork nor fall back to, or overwrite, the
        // install postback's fine value.
        let h = try Harness(test: self)
        XCTAssertEqual(try h.sdk.logEvent("purchase")?.fineValue, 63, "install postback: fine 63")

        let coarseOnly = try XCTUnwrap(try h.sdk.logEvent("engaged", conversionTypes: [.reengagement]))
        XCTAssertEqual(coarseOnly.conversionTypes, [.reengagement])
        XCTAssertFalse(coarseOnly.includesInstall)
        XCTAssertTrue(coarseOnly.includesReengagement)
        XCTAssertEqual(coarseOnly.coarseValue, .medium)
        XCTAssertTrue(coarseOnly.usedFineFallback)
        XCTAssertEqual(coarseOnly.fineValue, 0, "the install postback's 63 must not leak in")

        let fine = try XCTUnwrap(try h.sdk.logEvent("purchase", conversionTypes: [.reengagement]))
        XCTAssertEqual(fine.fineValue, 63)
        XCTAssertEqual(LastFineValueStore.load(from: h.store, for: .reengagement), 63)
        XCTAssertEqual(try h.sdk.logEvent("engaged", conversionTypes: [.reengagement])?.fineValue, 63)

        XCTAssertEqual(h.sdk.registerAttribution().fineValue, 63)
        XCTAssertEqual(LastFineValueStore.load(from: h.store, for: .install), 63)
    }

    func testAnUpdateScopedToBothPostbacksRecordsBothHistories() throws {
        let h = try Harness(test: self)
        let update = try XCTUnwrap(try h.sdk.logEvent("purchase", conversionTypes: [.install, .reengagement]))
        XCTAssertTrue(update.includesInstall)
        XCTAssertTrue(update.includesReengagement)
        XCTAssertEqual(LastFineValueStore.load(from: h.store, for: .install), 63)
        XCTAssertEqual(LastFineValueStore.load(from: h.store, for: .reengagement), 63)

        let unscoped = try XCTUnwrap(try h.sdk.logEvent("purchase"))
        XCTAssertNil(unscoped.conversionTypes)
        XCTAssertTrue(unscoped.includesInstall)
        XCTAssertFalse(unscoped.includesReengagement)
    }

    func testTheUpdatesTheFacadeProducesReachAdAttributionKitCorrectly() throws {
        let h = try Harness(test: self)
        let reengagement = try XCTUnwrap(try h.sdk.logEvent("purchase", conversionTypes: [.reengagement]))
        XCTAssertFalse(reengagement.includesInstall)
        XCTAssertEqual(reengagement.adAttributionKitDelivery(scopedAPIAvailable: false), .skip)
        XCTAssertEqual(reengagement.adAttributionKitDelivery(scopedAPIAvailable: true), .scoped([.reengagement]))

        let reasserted = h.sdk.registerAttribution()
        XCTAssertNil(reasserted.conversionTypes)
        XCTAssertEqual(reasserted.fineValue, 0, "no install event has been reported")
        XCTAssertEqual(LastFineValueStore.load(from: h.store, for: .reengagement), 63)
        XCTAssertEqual(reasserted.adAttributionKitDelivery(scopedAPIAvailable: true), .scoped([.install]))
        XCTAssertEqual(reasserted.adAttributionKitDelivery(scopedAPIAvailable: false), .unscoped)
    }

    func testLastFineValueSurvivesATokenRotation() throws {
        let h = try Harness(test: self)
        XCTAssertEqual(try h.sdk.logEvent("purchase")?.fineValue, 63)

        // New build: rotated token, empty schema cache until its fetch —
        // but the device's conversion window kept running.
        h.sdk.configure(endpoint: Self.deadEndpoint, appToken: "token-rotated")
        XCTAssertNil(h.sdk.currentSchema)
        _ = try h.sdk.finishRefresh(requestToken: "token-rotated", data: TestSchema.body, response: httpResponse(status: 200), error: nil).get()

        let coarseOnly = try h.sdk.logEvent("engaged")
        XCTAssertEqual(coarseOnly?.usedFineFallback, true)
        XCTAssertEqual(coarseOnly?.fineValue, 63, "a coarse-only update after rotation must keep the pre-rotation fine value")
    }

    func testANotModifiedForADocumentTheDeviceDoesNotHoldRefetchesUnconditionally() throws {
        // A cache that kept an ETag but lost its document (a legacy cache,
        // or any other way): every conditional fetch is answered 304, which
        // says nothing the device can use.
        let store = InMemoryStore()
        store.set(try JSONEncoder().encode(["etag": "\"abc\""]), forKey: SchemaCache.storageKey(appToken: "token-a"))
        let sdk = P202Attribution(store: store, session: .shared)
        var requests: [URLRequest] = []
        sdk.onFetch = { requests.append($0) }
        sdk.configure(endpoint: Self.deadEndpoint, appToken: "token-a")
        XCTAssertNil(requests.last?.value(forHTTPHeaderField: "If-None-Match"), "an ETag without its document is not sent")

        // A 304 for a document the device does not hold (a server or a proxy
        // answering one anyway) starts an unconditional fetch rather than
        // leaving the device without a document until the next refresh.
        let fetchesBefore = requests.count
        let result = sdk.finishRefresh(requestToken: "token-a", data: nil, response: httpResponse(status: 304), error: nil)
        guard case let .failure(error) = result else {
            return XCTFail("a 304 with nothing cached is not a schema")
        }
        XCTAssertEqual(error as? P202Attribution.SDKError, .unexpectedStatus(304))
        XCTAssertEqual(requests.count, fetchesBefore + 1, "a new fetch starts at once")
        XCTAssertNil(requests.last?.value(forHTTPHeaderField: "If-None-Match"), "and it is unconditional")
        XCTAssertNil(SchemaCache.load(from: store, appToken: "token-a").etag, "for good, not just this once")

        // The unconditional answer is stored and used.
        _ = try sdk.finishRefresh(requestToken: "token-a", data: TestSchema.body, response: httpResponse(status: 200), error: nil).get()
        XCTAssertEqual(sdk.currentSchema?.appId, 42)
    }

    func testEachPostbackKeepsItsOwnGoalProgress() throws {
        // A once goal and a count goal on the same event. Shared progress
        // would spend the install postback's first purchase for the
        // re-engagement postback and count its purchases toward the second.
        let body = TestSchema.document(
            goals: [
                TestSchema.goal(1, #"{"name":"First purchase","trigger":{"event":"purchase"}}"#),
                TestSchema.goal(2, #"{"name":"Second purchase","trigger":{"event":"purchase"},"threshold":{"count":2}}"#),
            ],
            encodings: [
                #"{"goal_id":1,"fine_value":30,"coarse_value":null}"#,
                #"{"goal_id":2,"fine_value":50,"coarse_value":null}"#,
            ]
        )
        let h = try Harness(body: body, test: self)
        XCTAssertEqual(try h.sdk.logEvent("purchase")?.fineValue, 30, "install: the first purchase")

        let first = try XCTUnwrap(try h.sdk.logEvent("purchase", conversionTypes: [.reengagement]))
        XCTAssertEqual([first.fineValue], [30], "re-engagement: its own first purchase, not the install postback's second")
        XCTAssertEqual(first.conversionTypes, [.reengagement])
        XCTAssertEqual(try h.sdk.logEvent("purchase", conversionTypes: [.reengagement])?.fineValue, 50)

        // Scoped to both: each postback from its own progress. The install
        // postback reaches its second purchase; the re-engagement postback
        // has reached both goals already, so it is not touched.
        h.submitted = []
        let both = try XCTUnwrap(try h.sdk.logEvent("purchase", conversionTypes: [.install, .reengagement]))
        XCTAssertEqual(h.submitted, [ConversionUpdate(fineValue: 50, coarseValue: nil, usedFineFallback: false, conversionTypes: [.install])])
        XCTAssertEqual(both.conversionTypes, [.install])

        // Kept across a relaunch, and a new re-engagement starts its own lifecycle.
        let relaunched = P202Attribution(store: h.store, session: .shared)
        relaunched.clock = { h.now }
        relaunched.configure(endpoint: Self.deadEndpoint, appToken: "token-a")
        XCTAssertNil(try relaunched.logEvent("purchase", conversionTypes: [.reengagement]), "both re-engagement goals already reached")
        relaunched.beginReengagement()
        XCTAssertNil(LastFineValueStore.load(from: h.store, for: .reengagement))
        XCTAssertEqual(try relaunched.logEvent("purchase", conversionTypes: [.reengagement])?.fineValue, 30)
        XCTAssertEqual(LastFineValueStore.load(from: h.store, for: .install), 50, "the install postback is untouched")
    }

    func testAGoalStateStoredBeforeReengagementHadItsOwnStillLoads() throws {
        let store = InMemoryStore()
        store.set(Data(#"{"installAt":1000,"installEvaluated":true,"lastReceivedAt":1000,"state":{"progress":{},"reached":[]},"pending":[]}"#.utf8), forKey: DeviceGoalState.key)
        let loaded = DeviceGoalState.load(from: store)
        XCTAssertEqual(loaded.installAt, 1000, "decoded, not started over")
        XCTAssertNil(loaded.reengagementState)
        XCTAssertEqual(loaded.progress(for: .reengagement), EvaluationState())
    }

    // MARK: - Goals on the device

    func testAFunnelGoalIsReachedOnlyInOrderAndOnlyAtItsLevel() throws {
        // "Reached level 3 after the tutorial" — what a plain event name
        // could not express, and why the device evaluates goals now.
        let body = TestSchema.document(
            goals: [
                TestSchema.goal(10, #"{"name":"Tutorial","trigger":{"event":"tutorial_complete"}}"#),
                TestSchema.goal(11, #"{"name":"Level 3","trigger":{"event":"level_reached","where":[{"prop":"level","op":"gte","value":3}]},"after":[10]}"#),
            ],
            encodings: [#"{"goal_id":10,"fine_value":5,"coarse_value":"low"}"#, #"{"goal_id":11,"fine_value":20,"coarse_value":"medium"}"#]
        )
        let h = try Harness(body: body, test: self)

        XCTAssertNil(try h.sdk.logEvent("level_reached", properties: ["level": .int(3)]), "before the tutorial: not reached")
        XCTAssertEqual(try h.sdk.logEvent("tutorial_complete")?.fineValue, 5)
        XCTAssertNil(try h.sdk.logEvent("level_reached", properties: ["level": .int(2)]), "level 2 is not level 3")
        XCTAssertNil(try h.sdk.logEvent("level_reached", properties: ["level": .string("3")]), "a string is not a number")
        let reached = try XCTUnwrap(try h.sdk.logEvent("level_reached", properties: ["level": .double(3.0)]))
        XCTAssertEqual(reached.fineValue, 20)
        XCTAssertEqual(reached.coarseValue, .medium)
        XCTAssertNil(try h.sdk.logEvent("level_reached", properties: ["level": .int(4)]), "once: never reached twice")
        XCTAssertEqual(h.submitted.map(\.fineValue), [5, 20])
    }

    func testCumulativeSpendCrossesItsThresholdExactly() throws {
        let body = TestSchema.document(
            goals: [TestSchema.goal(3, #"{"name":"Spent 20","trigger":{"event":"purchase"},"threshold":{"sum":{"prop":"$revenue","gte":"0.3"}}}"#)],
            encodings: [#"{"goal_id":3,"fine_value":40,"coarse_value":"high"}"#]
        )
        let h = try Harness(body: body, test: self)
        XCTAssertNil(try h.sdk.logEvent("purchase", revenue: 0.1))
        // 0.1 + 0.2 is 0.30000000000000004 in doubles and exactly 0.3 in the
        // ledger's units; the device must answer as the server does.
        XCTAssertEqual(try h.sdk.logEvent("purchase", revenue: 0.2)?.fineValue, 40)
    }

    func testAClickWindowNeverCountsOnADevice() throws {
        // SKAdNetwork never tells the app which click it came from, so a
        // goal windowed from the click is ineligible here (no_click) and
        // sets nothing — the server refuses to encode such a goal for the
        // same reason.
        let body = TestSchema.document(
            goals: [TestSchema.goal(4, #"{"name":"Fast buyer","trigger":{"event":"purchase"},"within":{"days":7,"from":"click"}}"#)],
            encodings: [#"{"goal_id":4,"fine_value":50,"coarse_value":null}"#]
        )
        let h = try Harness(body: body, test: self)
        XCTAssertNil(try h.sdk.logEvent("purchase"))
    }

    func testAnInstallWindowCountsFromTheFirstLaunch() throws {
        let body = TestSchema.document(
            goals: [TestSchema.goal(5, #"{"name":"Week-one buyer","trigger":{"event":"purchase"},"within":{"days":7,"from":"install"}}"#)],
            encodings: [#"{"goal_id":5,"fine_value":30,"coarse_value":null}"#]
        )
        let late = try Harness(body: body, test: self)
        late.now += 7 * 86_400
        XCTAssertNil(try late.sdk.logEvent("purchase"), "the window's end is excluded")

        let early = try Harness(body: body, test: self)
        early.now += 7 * 86_400 - 1
        XCTAssertEqual(try early.sdk.logEvent("purchase")?.fineValue, 30)
    }

    func testTheInstallGoalAndEarlyEventsWaitForTheFirstSchema() throws {
        // First launch, no network yet: the install and the events logged
        // before the schema arrives are evaluated when it does, with their
        // own times — not dropped, and not re-timed to the arrival.
        let body = TestSchema.document(
            goals: [
                TestSchema.goal(6, #"{"name":"Install","trigger":{"install":true}}"#),
                TestSchema.goal(7, #"{"name":"Signup","trigger":{"event":"signup"},"within":{"days":1,"from":"install"}}"#),
            ],
            encodings: [#"{"goal_id":6,"fine_value":1,"coarse_value":"low"}"#, #"{"goal_id":7,"fine_value":12,"coarse_value":null}"#]
        )
        let h = try Harness(body: nil, test: self)
        XCTAssertNil(try h.sdk.logEvent("signup"), "no schema yet: waits")
        XCTAssertTrue(h.submitted.isEmpty)

        h.now += 2 * 86_400 // the schema arrives after the signup's window closed
        _ = try h.sdk.finishRefresh(requestToken: "token-a", data: body, response: httpResponse(status: 200), error: nil).get()
        XCTAssertEqual(h.submitted.map(\.fineValue), [1, 12], "the install, then the signup inside its window")

        // A relaunch neither re-reports the install nor forgets it.
        h.submitted = []
        h.sdk.configure(endpoint: Self.deadEndpoint, appToken: "token-a")
        XCTAssertTrue(h.submitted.isEmpty)
        XCTAssertNil(try h.sdk.logEvent("signup"), "once")
    }

    func testWaitingEventsAreBoundedAndTheEarliestAreKept() throws {
        let h = try Harness(body: nil, test: self)
        for _ in 0..<P202Attribution.maxPendingEvents {
            XCTAssertNil(try h.sdk.logEvent("purchase"))
        }
        XCTAssertThrowsError(try h.sdk.logEvent("purchase")) {
            XCTAssertEqual($0 as? P202Attribution.SDKError, .pendingEventsFull)
        }
    }

    func testAClockSetBackCannotMoveAnEventBeforeWhatWasAlreadyEvaluated() throws {
        // The user winds the clock back past the first launch. An event
        // timed there would sort before the install the state has already
        // folded in, and fall outside the install window it is plainly in;
        // the device never times an event before the last one evaluated.
        let body = TestSchema.document(
            goals: [TestSchema.goal(8, #"{"name":"Day-one buyer","trigger":{"event":"purchase"},"within":{"days":1,"from":"install"}}"#)],
            encodings: [#"{"goal_id":8,"fine_value":9,"coarse_value":null}"#]
        )
        let h = try Harness(body: body, test: self)
        h.now -= 3 * 86_400
        // The document's own fetch time is now in the future, so its age is
        // unknown and it is not used (see the max-age tests); fetch again.
        _ = try h.sdk.finishRefresh(requestToken: "token-a", data: body, response: httpResponse(status: 200), error: nil).get()
        XCTAssertEqual(try h.sdk.logEvent("purchase")?.fineValue, 9, "counted at the install, not three days before it")
    }

    // MARK: - The age of the document the device encodes with

    /// The server decodes a postback under every meaning its value had in a
    /// horizon built from `maxSchemaAge` (SkanEncodingTimeline). A device
    /// that kept encoding with an older cached document could set a value
    /// for a meaning retired before the report looks back to, and have it
    /// credited to whatever replaced it.
    func testAnEventWaitsRatherThanEncodeWithADocumentOlderThanTheMaxAge() throws {
        XCTAssertEqual(P202Attribution.maxSchemaAge, 7 * 86_400)
        let h = try Harness(test: self)
        XCTAssertEqual(try h.sdk.logEvent("purchase")?.fineValue, 63, "fresh: encodes")
        h.now += Int(P202Attribution.maxSchemaAge)
        XCTAssertEqual(try h.sdk.logEvent("purchase")?.fineValue, 63, "exactly the max age: still encodes")

        h.now += 1
        h.submitted = []
        XCTAssertNil(try h.sdk.logEvent("purchase"), "older: the event waits for a fresh document")
        XCTAssertTrue(h.submitted.isEmpty)

        // Offline, the device's next launch still holds only the old
        // document: configure must not flush with it either.
        h.sdk.configure(endpoint: Self.deadEndpoint, appToken: "token-a")
        XCTAssertTrue(h.submitted.isEmpty, "a relaunch does not encode with the stale document")

        // Meanwhile Purchase was re-encoded as 21. The waiting event is
        // encoded with the document that arrives, never the old one.
        let edited = TestSchema.document(
            goals: [TestSchema.goal(1, #"{"name":"Purchase","trigger":{"event":"purchase"},"repeat":{"mode":"each"}}"#)],
            encodings: [#"{"goal_id":1,"fine_value":21,"coarse_value":null}"#],
            version: "def"
        )
        _ = try h.sdk.finishRefresh(requestToken: "token-a", data: edited, response: httpResponse(status: 200), error: nil).get()
        XCTAssertEqual(h.submitted.map(\.fineValue), [21])
        XCTAssertEqual(try h.sdk.logEvent("purchase")?.fineValue, 21)
    }

    func testAnUnchangedDocumentConfirmedBy304IsYoungAgain() throws {
        let h = try Harness(test: self)
        h.now += Int(P202Attribution.maxSchemaAge) + 1
        XCTAssertNil(try h.sdk.logEvent("purchase"))
        h.submitted = []
        _ = try h.sdk.finishRefresh(requestToken: "token-a", data: nil, response: httpResponse(status: 304), error: nil).get()
        XCTAssertEqual(h.submitted.map(\.fineValue), [63], "the waiting event, encoded once the document is confirmed current")
    }

    func testADocumentFetchedInTheDevicesFutureIsNotUsed() throws {
        // The clock was set back after the fetch: the document's real age
        // is unknown, so it could be any age at all.
        let h = try Harness(test: self)
        h.now -= 60
        XCTAssertNil(try h.sdk.logEvent("purchase"))
    }

    func testAnEventTheServerWouldRefuseIsRefusedHere() throws {
        let h = try Harness(test: self)
        XCTAssertThrowsError(try h.sdk.logEvent("level up")) {
            XCTAssertEqual($0 as? P202Attribution.EventError, .invalidName("level up"))
        }
        XCTAssertThrowsError(try h.sdk.logEvent("a", properties: ["1x": .int(1)]))
        XCTAssertThrowsError(try h.sdk.logEvent("a", properties: ["s": .string(String(repeating: "x", count: 256))]))
        XCTAssertThrowsError(try h.sdk.logEvent("a", properties: ["d": .double(.nan)]))
        XCTAssertThrowsError(try h.sdk.logEvent("a", revenue: .infinity))
        var many: [String: EventValue] = [:]
        for i in 0..<33 { many["p\(i)"] = .int(i) }
        XCTAssertThrowsError(try h.sdk.logEvent("a", properties: many))
        XCTAssertTrue(h.submitted.isEmpty)
    }

    func testLoggingBeforeConfigureIsAnError() {
        let sdk = P202Attribution(store: InMemoryStore(), session: .shared)
        XCTAssertThrowsError(try sdk.logEvent("purchase")) {
            XCTAssertEqual($0 as? P202Attribution.SDKError, .notConfigured)
        }
    }

    // MARK: - Customer id

    func testASignedCustomerIdIsKeptAcrossLaunchesAndCleared() throws {
        let h = try Harness(test: self)
        let sig = String(repeating: "Ab", count: 32)
        try h.sdk.setCustomerId("  u-829 ", signature: sig)
        let stored = try XCTUnwrap(h.sdk.customerId)
        XCTAssertEqual(stored.canonical, "custom:u-829")
        XCTAssertEqual(stored.wireObject, ["id": "u-829", "type": "custom", "signature": sig.lowercased()])

        let relaunched = P202Attribution(store: h.store, session: .shared)
        XCTAssertEqual(relaunched.customerId, stored)

        h.sdk.clearCustomerId()
        XCTAssertNil(h.sdk.customerId)
    }

    func testAnIdTheServerWouldRefuseIsNotStored() throws {
        let h = try Harness(test: self)
        XCTAssertThrowsError(try h.sdk.setCustomerId("u-829", signature: "not-a-signature")) {
            XCTAssertEqual($0 as? P202CustomerId.Invalid, .signature)
        }
        XCTAssertThrowsError(try h.sdk.setCustomerId("ada@example.com", signature: String(repeating: "a", count: 64), type: .emailSHA256)) {
            XCTAssertEqual($0 as? P202CustomerId.Invalid, .id, "an email in the clear is not a digest")
        }
        XCTAssertNil(h.sdk.customerId)
    }
}

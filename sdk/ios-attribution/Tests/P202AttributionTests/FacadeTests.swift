import XCTest
@testable import P202Attribution
#if canImport(FoundationNetworking)
import FoundationNetworking
#endif

/// Behaviour of the P202Attribution facade around configuration changes — the
/// paths a device hits when a new build ships with a rotated schema token
/// while the old build's state (and possibly its in-flight fetch) is still
/// around. finishRefresh is internal exactly so these can run without a
/// server.
final class FacadeTests: XCTestCase {
    /// Connection-refused immediately; configure()'s automatic fetch fails
    /// fast and harmlessly in these tests.
    private static let deadEndpoint = URL(string: "http://127.0.0.1:9")!

    private static let schemaBody = Data("""
    {"data":{"app_id":42,"schema_version":"abc","events":{
        "purchase":{"fine_value":63,"coarse_value":null},
        "engaged":{"fine_value":null,"coarse_value":"medium"}
    }}}
    """.utf8)

    private func httpResponse(status: Int) -> HTTPURLResponse {
        return HTTPURLResponse(
            url: Self.deadEndpoint,
            statusCode: status,
            httpVersion: nil,
            headerFields: nil
        )!
    }

    func testFinishRefreshStoresASchemaForTheCurrentToken() throws {
        let store = InMemoryStore()
        let sdk = P202Attribution(store: store, session: .shared)
        sdk.configure(endpoint: Self.deadEndpoint, schemaToken: "token-a")

        let result = sdk.finishRefresh(
            requestToken: "token-a",
            data: Self.schemaBody,
            response: httpResponse(status: 200),
            error: nil
        )

        let schema = try result.get()
        XCTAssertEqual(schema.appId, 42)
        XCTAssertEqual(sdk.currentSchema, schema)
        XCTAssertTrue(store.keys.contains(SchemaCache.storageKey(schemaToken: "token-a")))
    }

    func testFinishRefreshDropsAResponseForASupersededToken() {
        // configure(token B) while token A's fetch is in flight: A's
        // response must not land in B's cache slot — B's app would encode
        // with A's app's values until the next successful fetch.
        let store = InMemoryStore()
        let sdk = P202Attribution(store: store, session: .shared)
        sdk.configure(endpoint: Self.deadEndpoint, schemaToken: "token-b")

        let result = sdk.finishRefresh(
            requestToken: "token-a",
            data: Self.schemaBody,
            response: httpResponse(status: 200),
            error: nil
        )

        guard case .failure(let error) = result else {
            return XCTFail("a stale response must not be reported as success")
        }
        XCTAssertEqual(error as? P202Attribution.SDKError, .superseded)
        XCTAssertNil(sdk.currentSchema)
        XCTAssertFalse(store.keys.contains(SchemaCache.storageKey(schemaToken: "token-a")))
        XCTAssertFalse(store.keys.contains(SchemaCache.storageKey(schemaToken: "token-b")))
    }

    func testRegisterAttributionDoesNotResetAValueAlreadyReported() throws {
        // Developers habitually call registerAttribution() from
        // applicationDidBecomeActive. Reporting a hardcoded 0 there would
        // both downgrade what Apple holds and leave the SDK's own
        // lastFineValue untouched, so the two disagreed from then on.
        let store = InMemoryStore()
        let sdk = P202Attribution(store: store, session: .shared)
        sdk.configure(endpoint: Self.deadEndpoint, schemaToken: "token-a")
        _ = try sdk.finishRefresh(
            requestToken: "token-a",
            data: Self.schemaBody,
            response: httpResponse(status: 200),
            error: nil
        ).get()

        XCTAssertEqual(sdk.registerAttribution().fineValue, 0, "a fresh install reports 0")
        XCTAssertEqual(sdk.logEvent("purchase")?.fineValue, 63)
        XCTAssertEqual(
            sdk.registerAttribution().fineValue,
            63,
            "re-asserts the reported value instead of resetting it"
        )
    }

    func testAReengagementScopedUpdateNeverTouchesTheInstallPostbacksValue() throws {
        // AdAttributionKit keeps a separate conversion value for its
        // re-engagement postback (iOS 18+). An update scoped to it must
        // neither be sent to SKAdNetwork (whose only postback is the install
        // one) nor fall back to, or overwrite, the install postback's fine
        // value — each postback has its own history.
        let store = InMemoryStore()
        let sdk = P202Attribution(store: store, session: .shared)
        sdk.configure(endpoint: Self.deadEndpoint, schemaToken: "token-a")
        _ = try sdk.finishRefresh(
            requestToken: "token-a",
            data: Self.schemaBody,
            response: httpResponse(status: 200),
            error: nil
        ).get()

        XCTAssertEqual(sdk.logEvent("purchase")?.fineValue, 63, "install postback: fine 63")

        let coarseOnly = try XCTUnwrap(sdk.logEvent("engaged", conversionTypes: [.reengagement]))
        XCTAssertEqual(coarseOnly.conversionTypes, [.reengagement])
        XCTAssertFalse(coarseOnly.includesInstall)
        XCTAssertTrue(coarseOnly.includesReengagement)
        XCTAssertEqual(coarseOnly.coarseValue, .medium)
        XCTAssertTrue(coarseOnly.usedFineFallback)
        XCTAssertEqual(
            coarseOnly.fineValue,
            0,
            "no re-engagement fine value has been reported yet; the install postback's 63 must not leak in"
        )

        let fine = try XCTUnwrap(sdk.logEvent("purchase", conversionTypes: [.reengagement]))
        XCTAssertEqual(fine.fineValue, 63)
        XCTAssertEqual(LastFineValueStore.load(from: store, for: .reengagement), 63)
        XCTAssertEqual(
            sdk.logEvent("engaged", conversionTypes: [.reengagement])?.fineValue,
            63,
            "a coarse-only re-engagement update keeps the re-engagement postback's own last fine value"
        )

        XCTAssertEqual(sdk.registerAttribution().fineValue, 63)
        XCTAssertEqual(LastFineValueStore.load(from: store, for: .install), 63)
    }

    func testAnUpdateScopedToBothPostbacksRecordsBothHistories() throws {
        let store = InMemoryStore()
        let sdk = P202Attribution(store: store, session: .shared)
        sdk.configure(endpoint: Self.deadEndpoint, schemaToken: "token-a")
        _ = try sdk.finishRefresh(
            requestToken: "token-a",
            data: Self.schemaBody,
            response: httpResponse(status: 200),
            error: nil
        ).get()

        let update = try XCTUnwrap(sdk.logEvent("purchase", conversionTypes: [.install, .reengagement]))
        XCTAssertTrue(update.includesInstall)
        XCTAssertTrue(update.includesReengagement)
        XCTAssertEqual(LastFineValueStore.load(from: store, for: .install), 63)
        XCTAssertEqual(LastFineValueStore.load(from: store, for: .reengagement), 63)

        // An unscoped update is the frameworks' default: the install postback.
        let unscoped = try XCTUnwrap(sdk.logEvent("purchase"))
        XCTAssertNil(unscoped.conversionTypes)
        XCTAssertTrue(unscoped.includesInstall)
        XCTAssertFalse(unscoped.includesReengagement)
    }

    func testTheUpdatesTheFacadeProducesReachAdAttributionKitCorrectly() throws {
        // The two device scenarios that corrupt conversion values if the
        // dispatch is wrong, checked on the updates the facade actually
        // builds rather than on hand-made ones.
        let store = InMemoryStore()
        let sdk = P202Attribution(store: store, session: .shared)
        sdk.configure(endpoint: Self.deadEndpoint, schemaToken: "token-a")
        _ = try sdk.finishRefresh(
            requestToken: "token-a",
            data: Self.schemaBody,
            response: httpResponse(status: 200),
            error: nil
        ).get()

        // iOS 17.4-17.x: a re-engagement event is skipped for SKAdNetwork so
        // the install postback keeps its value — and must be skipped for
        // AdAttributionKit too, whose only postback there is that same
        // install one.
        let reengagement = try XCTUnwrap(sdk.logEvent("purchase", conversionTypes: [.reengagement]))
        XCTAssertFalse(reengagement.includesInstall)
        XCTAssertEqual(reengagement.adAttributionKitDelivery(scopedAPIAvailable: false), .skip)
        XCTAssertEqual(reengagement.adAttributionKitDelivery(scopedAPIAvailable: true), .scoped([.reengagement]))

        // iOS 18: registerAttribution() re-asserts the INSTALL postback's
        // value from applicationDidBecomeActive. Sent unscoped it would write
        // that value (0 here) onto the re-engagement postback as well, wiping
        // the 63 the SDK still tracks — and re-sends — for that postback.
        let reasserted = sdk.registerAttribution()
        XCTAssertNil(reasserted.conversionTypes)
        XCTAssertEqual(reasserted.fineValue, 0, "no install event has been reported")
        XCTAssertEqual(LastFineValueStore.load(from: store, for: .reengagement), 63)
        XCTAssertEqual(reasserted.adAttributionKitDelivery(scopedAPIAvailable: true), .scoped([.install]))
        XCTAssertEqual(reasserted.adAttributionKitDelivery(scopedAPIAvailable: false), .unscoped)
    }

    func testLastFineValueSurvivesATokenRotation() throws {
        let store = InMemoryStore()
        let sdk = P202Attribution(store: store, session: .shared)
        sdk.configure(endpoint: Self.deadEndpoint, schemaToken: "token-a")
        _ = try sdk.finishRefresh(
            requestToken: "token-a",
            data: Self.schemaBody,
            response: httpResponse(status: 200),
            error: nil
        ).get()

        XCTAssertEqual(sdk.logEvent("purchase")?.fineValue, 63)

        // New build: rotated token, empty schema cache until its fetch —
        // but the device's conversion window kept running.
        sdk.configure(endpoint: Self.deadEndpoint, schemaToken: "token-rotated")
        XCTAssertNil(sdk.currentSchema)
        _ = try sdk.finishRefresh(
            requestToken: "token-rotated",
            data: Self.schemaBody,
            response: httpResponse(status: 200),
            error: nil
        ).get()

        let coarseOnly = sdk.logEvent("engaged")
        XCTAssertEqual(coarseOnly?.usedFineFallback, true)
        XCTAssertEqual(
            coarseOnly?.fineValue,
            63,
            "a coarse-only update after rotation must keep the pre-rotation fine value, not regress to 0"
        )
    }
}

import XCTest
@testable import P202SKAN
#if canImport(FoundationNetworking)
import FoundationNetworking
#endif

/// Behaviour of the P202SKAN facade around configuration changes — the
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
        let sdk = P202SKAN(store: store, session: .shared)
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
        let sdk = P202SKAN(store: store, session: .shared)
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
        XCTAssertEqual(error as? P202SKAN.SDKError, .superseded)
        XCTAssertNil(sdk.currentSchema)
        XCTAssertFalse(store.keys.contains(SchemaCache.storageKey(schemaToken: "token-a")))
        XCTAssertFalse(store.keys.contains(SchemaCache.storageKey(schemaToken: "token-b")))
    }

    func testLastFineValueSurvivesATokenRotation() throws {
        let store = InMemoryStore()
        let sdk = P202SKAN(store: store, session: .shared)
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

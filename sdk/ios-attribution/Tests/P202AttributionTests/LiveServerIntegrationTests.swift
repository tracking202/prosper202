import XCTest
@testable import P202Attribution

/// Exercises the real SDK <-> server contract against a live Prosper202
/// instance — the check that the layers actually meet (a guard is only as
/// good as the layer that delivers the input). Skipped unless the
/// environment provides an instance:
///
///     P202ATTRIBUTION_LIVE_ENDPOINT=http://127.0.0.1:8000 \
///     P202ATTRIBUTION_LIVE_TOKEN=<schema token from POST /attribution/apps> \
///     swift test --filter LiveServerIntegrationTests
final class LiveServerIntegrationTests: XCTestCase {
    private var endpoint: URL? {
        guard let raw = ProcessInfo.processInfo.environment["P202ATTRIBUTION_LIVE_ENDPOINT"] else {
            return nil
        }
        return URL(string: raw)
    }

    private var token: String? {
        return ProcessInfo.processInfo.environment["P202ATTRIBUTION_LIVE_TOKEN"]
    }

    func testFetchesDecodesAndConditionallyRefetchesTheSchema() throws {
        guard let endpoint, let token else {
            throw XCTSkip("Set P202ATTRIBUTION_LIVE_ENDPOINT and P202ATTRIBUTION_LIVE_TOKEN to run against a live instance")
        }

        let sdk = P202Attribution(store: InMemoryStore())
        var firstSchema: P202AttributionSchema?

        let first = expectation(description: "initial fetch")
        sdk.configure(endpoint: endpoint, schemaToken: token)
        sdk.refreshSchema { result in
            if case let .success(schema) = result {
                firstSchema = schema
            } else {
                XCTFail("initial fetch failed: \(result)")
            }
            first.fulfill()
        }
        wait(for: [first], timeout: 15)

        let schema = try XCTUnwrap(firstSchema)
        XCTAssertFalse(schema.schemaVersion.isEmpty)
        XCTAssertEqual(sdk.currentSchema, schema)

        // Second fetch rides the ETag: the server answers 304 and the SDK
        // must surface the cached schema as success, not an error.
        let second = expectation(description: "conditional fetch")
        sdk.refreshSchema { result in
            if case let .success(again) = result {
                XCTAssertEqual(again, schema)
            } else {
                XCTFail("conditional fetch failed: \(result)")
            }
            second.fulfill()
        }
        wait(for: [second], timeout: 15)

        // Resolution runs against the live document (the SKAdNetwork call
        // itself is a no-op off-iOS). Every event the server serves must
        // resolve; an unmapped name must not.
        for (name, mapping) in schema.events where mapping.fineValue != nil {
            let update = sdk.logEvent(name)
            XCTAssertEqual(update?.fineValue, mapping.fineValue)
        }
        XCTAssertNil(sdk.logEvent("an-event-nobody-mapped-\(UUID().uuidString)"))
    }

    func testWrongTokenIsSurfacedAsAnHTTPStatusNotACrash() throws {
        guard let endpoint else {
            throw XCTSkip("Set P202ATTRIBUTION_LIVE_ENDPOINT and P202ATTRIBUTION_LIVE_TOKEN to run against a live instance")
        }

        let sdk = P202Attribution(store: InMemoryStore())
        sdk.configure(endpoint: endpoint, schemaToken: String(repeating: "ff", count: 32))
        let done = expectation(description: "rejected fetch")
        sdk.refreshSchema { result in
            if case let .failure(error) = result {
                XCTAssertEqual(error as? P202Attribution.SDKError, .unexpectedStatus(404))
            } else {
                XCTFail("a wrong token must fail the fetch")
            }
            done.fulfill()
        }
        wait(for: [done], timeout: 15)
        XCTAssertNil(sdk.logEvent("purchase"), "no schema means logEvent must be a no-op")
    }
}

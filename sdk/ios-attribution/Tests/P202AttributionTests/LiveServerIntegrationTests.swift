import XCTest
@testable import P202Attribution

/// Exercises the real SDK <-> server contract against a live Prosper202
/// instance — the check that the layers actually meet (a guard is only as
/// good as the layer that delivers the input). Skipped unless the
/// environment provides an instance:
///
///     P202ATTRIBUTION_LIVE_ENDPOINT=http://127.0.0.1:8000 \
///     P202ATTRIBUTION_LIVE_TOKEN=<app_token from POST /apps> \
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
        sdk.configure(endpoint: endpoint, appToken: token)
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

        // The live document's goals evaluate on this device: every goal the
        // server served parses (none is disabled), and an event nobody
        // mapped changes nothing. P202ATTRIBUTION_LIVE_EXPECT, when set, is a
        // JSON list of [event name, properties object, expected fine value
        // or null] steps the live pass seeded goals for, run in order.
        let probe = try GoalEvaluator.evaluateAll(schema.goals, subject: GoalSubject(kind: .install, clickAt: nil, installAt: 0), events: [])
        XCTAssertEqual(probe.disabled, [], "the server served a goal this evaluator cannot read")
        XCTAssertNil(try sdk.logEvent("an-event-nobody-mapped-\(UUID().uuidString.prefix(8))"))
        if let raw = ProcessInfo.processInfo.environment["P202ATTRIBUTION_LIVE_EXPECT"] {
            guard case let .array(steps) = try JSONValue.parse(raw) else {
                return XCTFail("P202ATTRIBUTION_LIVE_EXPECT is not a JSON list")
            }
            XCTAssertFalse(steps.isEmpty)
            for step in steps {
                guard case let .array(parts) = step, parts.count == 3, let name = parts[0].stringValue else {
                    return XCTFail("a step is [name, properties, expected fine value]")
                }
                var properties: [String: EventValue] = [:]
                if case let .object(pairs) = parts[1] {
                    for (k, v) in pairs { properties[k] = EventValue(json: v) }
                }
                let update = try sdk.logEvent(name, properties: properties)
                XCTAssertEqual(update?.fineValue, parts[2].intValue, "after \(name) \(parts[1].jsonText)")
            }
        }
    }

    func testWrongTokenIsSurfacedAsAnHTTPStatusNotACrash() throws {
        guard let endpoint else {
            throw XCTSkip("Set P202ATTRIBUTION_LIVE_ENDPOINT and P202ATTRIBUTION_LIVE_TOKEN to run against a live instance")
        }

        let sdk = P202Attribution(store: InMemoryStore())
        sdk.configure(endpoint: endpoint, appToken: String(repeating: "ff", count: 32))
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
        XCTAssertNil(try sdk.logEvent("purchase"), "no schema: the event waits and nothing is set")
    }
}

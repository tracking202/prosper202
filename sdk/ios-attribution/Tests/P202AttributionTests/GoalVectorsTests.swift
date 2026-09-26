import XCTest
@testable import P202Attribution

/// The cross-language contract vectors in tests/fixtures/app-sdk-contract/,
/// read from the repository this package lives in (or from
/// P202_CONTRACT_VECTORS). The PHP suite runs the same files
/// (tests/Goals/GoalVectorsTest.php, tests/Apps/AppIdentityTest.php,
/// tests/Identity/CustomerIdVectorsTest.php), so the device and the server
/// cannot drift. A missing file fails the suite: skipping would pass it.
enum ContractVectors {
    static var directory: URL {
        if let override = ProcessInfo.processInfo.environment["P202_CONTRACT_VECTORS"] {
            return URL(fileURLWithPath: override)
        }
        var url = URL(fileURLWithPath: #filePath)
        for _ in 0..<5 {
            url.deleteLastPathComponent() // file, P202AttributionTests, Tests, ios-attribution, sdk
        }
        return url.appendingPathComponent("tests/fixtures/app-sdk-contract")
    }

    /// `versioned`: the file declares a format_version, and this suite
    /// reads only version 1 (app-identity.json predates the field).
    static func load(_ relative: String, versioned: Bool = true) throws -> JSONValue {
        let data = try Data(contentsOf: directory.appendingPathComponent(relative))
        let doc = try JSONValue.parse(data)
        if versioned {
            XCTAssertEqual(doc["format_version"], .int(1), "\(relative): this suite reads format_version 1 only")
        }
        return doc
    }
}

/// Objects compared without regard to key order (the vectors are written
/// by hand; the evaluator writes keys in its own order).
func normalized(_ v: JSONValue) -> JSONValue {
    switch v {
    case let .array(items):
        return .array(items.map(normalized))
    case let .object(pairs):
        var seen: [String: JSONValue] = [:]
        for (k, value) in pairs { seen[k] = normalized(value) }
        return .object(seen.keys.sorted().map { ($0, seen[$0]!) })
    default:
        return v
    }
}

final class GoalVectorsTests: XCTestCase {
    private func cases(_ file: String) throws -> [JSONValue] {
        guard case let .array(items)? = try ContractVectors.load("goals/" + file)["cases"] else {
            XCTFail("\(file) has no cases")
            return []
        }
        return items
    }

    func testTheVectorFilesHoldEnoughCasesToMeanSomething() throws {
        // A file that decoded to no cases would pass every test below by
        // running none of them.
        // At least what the files held when the rules were last changed
        // (the sum bounds, PR 4's fix), so a suite reading an older copy
        // fails here rather than passing on the cases it has.
        XCTAssertGreaterThanOrEqual(try cases("evaluator.json").count, 45)
        XCTAssertGreaterThanOrEqual(try cases("definitions.json").count, 44)
    }

    func testEveryDefinitionVector() throws {
        var checked = 0
        for c in try cases("definitions.json") {
            let name = c["name"]?.stringValue ?? "?"
            let definition = try XCTUnwrap(c["definition"], name)
            if c["valid"] == .bool(true) {
                do {
                    let parsed = try GoalDefinition.parse(definition)
                    XCTAssertEqual(normalized(parsed.canonical), normalized(try XCTUnwrap(c["canonical"])), "canonical: \(name)")
                    // The canonical form is a fixed point, through its text.
                    let again = try GoalDefinition.parse(JSONValue.parse(parsed.canonical.jsonText))
                    XCTAssertEqual(again, parsed, "fixed point: \(name)")
                } catch {
                    XCTFail("refused a valid definition (\(name)): \(error)")
                }
            } else {
                do {
                    _ = try GoalDefinition.parse(definition)
                    XCTFail("accepted an invalid definition: \(name)")
                } catch let invalid as GoalDefinition.Invalid {
                    guard case let .array(expected)? = c["errors"] else {
                        XCTFail("no errors listed: \(name)")
                        continue
                    }
                    XCTAssertEqual(
                        invalid.errors.keys.sorted(),
                        expected.compactMap(\.stringValue).sorted(),
                        "error paths: \(name)"
                    )
                }
            }
            checked += 1
        }
        XCTAssertGreaterThanOrEqual(checked, 40)
    }

    func testEveryEvaluatorVector() throws {
        var checked = 0
        for c in try cases("evaluator.json") {
            let name = c["name"]?.stringValue ?? "?"
            let (specs, subject, events) = try inputs(c, name)
            let expect = try XCTUnwrap(c["expect"], name)
            let result = try GoalEvaluator.evaluateAll(specs, subject: subject, events: events)
            XCTAssertEqual(normalized(.array(result.outcomes.map(\.json))), normalized(try XCTUnwrap(expect["outcomes"])), "outcomes: \(name)")
            XCTAssertEqual(normalized(.array(result.disabled.map(\.json))), normalized(try XCTUnwrap(expect["disabled"])), "disabled: \(name)")
            if let progress = expect["progress"] {
                XCTAssertEqual(normalized(result.state.progressJSON), normalized(progress), "progress: \(name)")
            }
            checked += 1
        }
        XCTAssertGreaterThanOrEqual(checked, 40)
    }

    /// What the device does: one event at a time, from the state the last
    /// one left, with that state stored and read back between events (as
    /// DeviceGoalState keeps it across launches). It must agree with the
    /// whole evaluation on every vector — the proof that evaluating on the
    /// device incrementally cannot fork from the specification.
    func testOneEventAtATimeFromTheStoredStateAgrees() throws {
        for c in try cases("evaluator.json") {
            let name = c["name"]?.stringValue ?? "?"
            let (specs, subject, given) = try inputs(c, name)
            var events = given
            if subject.kind == .install, let installAt = subject.installAt {
                events.append(.install(at: installAt))
            }
            events.sort(by: GoalEvent.precedes)

            var state = EvaluationState()
            var outcomes: [GoalOutcome] = []
            var disabled = Set<String>()
            for event in events {
                let step = try GoalEvaluator.continueFrom(specs, subject: subject, state: state, events: [event])
                outcomes += step.outcomes
                for d in step.disabled {
                    disabled.insert("\(d.goalId):\(d.version):\(d.reason)")
                }
                state = try JSONDecoder().decode(EvaluationState.self, from: JSONEncoder().encode(step.state))
            }
            let expect = try XCTUnwrap(c["expect"], name)
            XCTAssertEqual(normalized(.array(outcomes.map(\.json))), normalized(try XCTUnwrap(expect["outcomes"])), "incremental outcomes: \(name)")
            if !events.isEmpty, case let .array(want)? = expect["disabled"] {
                let wanted = Set(want.map { "\($0["goal_id"]!.intValue!):\($0["version"]!.intValue!):\($0["reason"]!.stringValue!)" })
                XCTAssertEqual(disabled, wanted, "incremental disabled: \(name)")
            }
        }
    }

    func testAnEvaluationRefusesADuplicateEventOrGoal() throws {
        let spec = GoalSpec(goalId: 1, versions: [.init(version: 1, effectiveAt: 0, definition: try JSONValue.parse(#"{"name":"A","trigger":{"event":"a"}}"#))])
        let subject = GoalSubject(kind: .click, clickAt: 0, installAt: nil)
        let event = GoalEvent(eventId: "e1", name: "a", occurredAt: 1, receivedAt: 1)
        XCTAssertThrowsError(try GoalEvaluator.evaluateAll([spec], subject: subject, events: [event, event]))
        XCTAssertThrowsError(try GoalEvaluator.evaluateAll([spec, spec], subject: subject, events: [event]))
    }

    /// A sum below zero is carried, not floored at zero (rule 8's floor is
    /// −10^15 units): −5 then 20 is 15, short of 20, and the next 5 reaches
    /// it; then the largest summand jumps to max and the sum is held at
    /// max × gte. The shared vectors do not pin the negative carry, so this
    /// case pins it here; the expectation is the server's answer, executed
    /// (`GoalEvaluator::evaluateAll()` on the same input), not derived.
    func testANegativeSumIsCarriedAndTheCeilingHolds() throws {
        let definition = try JSONValue.parse(#"{"name":"Spent 20","trigger":{"event":"purchase"},"threshold":{"sum":{"prop":"amount","gte":20}},"repeat":{"mode":"each","max":3}}"#)
        let spec = GoalSpec(goalId: 1, versions: [.init(version: 1, effectiveAt: 0, definition: definition)])
        let subject = GoalSubject(kind: .install, clickAt: nil, installAt: 1000)
        let amounts: [(String, Int, EventValue)] = [("e1", 1100, .int(-5)), ("e2", 1200, .int(20)), ("e3", 1300, .int(5)), ("e4", 1400, .double(999_999.99999))]
        let events = amounts.map { GoalEvent(eventId: $0.0, name: "purchase", occurredAt: $0.1, receivedAt: $0.1, properties: ["amount": $0.2]) }
        let result = try GoalEvaluator.evaluateAll([spec], subject: subject, events: events)
        XCTAssertEqual(result.outcomes.map { "\($0.n)@\($0.eventId)" }, ["1@e3", "2@e4", "3@e4"])
        XCTAssertEqual(result.state.progress["1:1"]?.sum, 6_000_000)
        XCTAssertEqual(result.state.progress["1:1"]?.reachedAt, 1300)
    }

    /// The device reads its state back from storage, and a damaged one must
    /// not trap the host app: a sum or count at the top of its type
    /// saturates, and the clamp brings the sum back to the ceiling.
    func testADamagedStoredStateDoesNotTrap() throws {
        let definition = try JSONValue.parse(#"{"name":"Spent 20","trigger":{"event":"purchase"},"threshold":{"sum":{"prop":"amount","gte":20}},"repeat":{"mode":"each","max":3}}"#)
        let spec = GoalSpec(goalId: 1, versions: [.init(version: 1, effectiveAt: 0, definition: definition)])
        let subject = GoalSubject(kind: .install, clickAt: nil, installAt: 1000)
        var state = EvaluationState()
        state.progress["1:1"] = .init(goalId: 1, version: 1, count: Int.max, sum: Int64.max, times: 0, reachedAt: nil)
        let event = GoalEvent(eventId: "e1", name: "purchase", occurredAt: 1100, receivedAt: 1100, properties: ["amount": .int(10)])
        let result = try GoalEvaluator.continueFrom([spec], subject: subject, state: state, events: [event])
        XCTAssertEqual(result.outcomes.map(\.n), [1, 2, 3])
        XCTAssertEqual(result.state.progress["1:1"]?.sum, 6_000_000)
        XCTAssertEqual(result.state.progress["1:1"]?.count, Int.max)
    }

    private func inputs(_ c: JSONValue, _ name: String) throws -> ([GoalSpec], GoalSubject, [GoalEvent]) {
        guard case let .array(goalItems)? = c["goals"], case let .array(eventItems)? = c["events"], let s = c["subject"] else {
            throw XCTSkip("malformed case \(name)")
        }
        var specs: [GoalSpec] = []
        for g in goalItems {
            guard case let .array(versionItems)? = g["versions"] else {
                XCTFail("no versions in \(name)")
                continue
            }
            let versions = versionItems.map {
                GoalSpec.Version(version: $0["version"]!.intValue!, effectiveAt: $0["effective_at"]!.intValue!, definition: $0["definition"]!)
            }
            specs.append(GoalSpec(
                goalId: g["goal_id"]!.intValue!,
                versions: versions,
                startsAt: g["starts_at"]?.intValue ?? 0,
                endsAt: g["ends_at"]?.intValue
            ))
        }
        var rebases: [Int: Int] = [:]
        if case let .object(pairs)? = s["rebases"] {
            for (goal, version) in pairs {
                rebases[Int(goal)!] = version.intValue!
            }
        }
        let subject = GoalSubject(
            kind: GoalSubject.Kind(rawValue: s["type"]!.stringValue!)!,
            clickAt: s["click_at"]?.intValue,
            installAt: s["install_at"]?.intValue,
            rebases: rebases
        )
        var events: [GoalEvent] = []
        for e in eventItems {
            var properties: [String: EventValue] = [:]
            if case let .object(pairs)? = e["properties"] {
                for (k, v) in pairs {
                    properties[k] = try XCTUnwrap(EventValue(json: v), "property \(k) in \(name)")
                }
            }
            let revenue = e["revenue"].flatMap { $0.isNull ? nil : EventValue(json: $0) }
            events.append(GoalEvent(
                eventId: e["event_id"]!.stringValue!,
                name: e["name"]!.stringValue!,
                occurredAt: e["occurred_at"]!.intValue!,
                receivedAt: e["received_at"]!.intValue!,
                properties: properties,
                revenue: revenue
            ))
        }
        return (specs, subject, events)
    }
}

final class ContractVectorsTests: XCTestCase {
    func testWhatEachRawAppKeyResolvesTo() throws {
        guard case let .array(keys)? = try ContractVectors.load("app-identity.json", versioned: false)["keys"] else {
            return XCTFail("app-identity.json has no keys")
        }
        XCTAssertGreaterThanOrEqual(keys.count, 15)
        for k in keys {
            let name = k["name"]?.stringValue ?? "?"
            let resolved = P202AppKey.resolve(platform: k["platform"], appKey: k["app_key"] ?? .null)
            if let expect = k["expect"]?.stringValue {
                XCTAssertEqual(resolved?.key, expect, name)
                if let platform = k["expect_platform"]?.stringValue {
                    XCTAssertEqual(resolved?.platform, platform, name)
                }
            } else {
                XCTAssertNil(resolved, "accepted: \(name)")
            }
        }
    }

    func testCustomerIdsCanonicaliseAsTheServerDoes() throws {
        let doc = try ContractVectors.load("customer-id.json")
        guard case let .array(cases)? = doc["cases"] else {
            return XCTFail("customer-id.json has no cases")
        }
        XCTAssertGreaterThanOrEqual(cases.count, 10)
        for c in cases {
            let name = c["name"]?.stringValue ?? "?"
            let canonical = P202CustomerId.canonical(id: c["id"]!.stringValue!, type: c["type"]?.stringValue)
            XCTAssertEqual(canonical, c["expect"]?.stringValue, name)
        }
    }

    func testTheSDKRefusesASignatureThatIsNotOneAndKeepsOneThatIs() throws {
        let doc = try ContractVectors.load("customer-id.json")
        guard case let .array(signatures)? = doc["signatures"] else {
            return XCTFail("customer-id.json has no signatures")
        }
        for s in signatures {
            let name = s["name"]?.stringValue ?? "?"
            let canonical = s["canonical"]!.stringValue!
            let colon = canonical.firstIndex(of: ":")!
            let type = P202CustomerId.IdType(rawValue: String(canonical[..<colon]))!
            let id = String(canonical[canonical.index(after: colon)...])
            let signature = s["signature"]!.stringValue!
            if s["well_formed"] == .bool(true) {
                let customer = try P202CustomerId(id: id, signature: signature, type: type)
                XCTAssertEqual(customer.canonical, canonical, name)
                XCTAssertEqual(customer.signature, signature.lowercased(), name)
                XCTAssertEqual(customer.wireObject, ["id": id, "type": type.rawValue, "signature": signature.lowercased()])
            } else {
                XCTAssertThrowsError(try P202CustomerId(id: id, signature: signature, type: type), name) {
                    XCTAssertEqual($0 as? P202CustomerId.Invalid, .signature)
                }
            }
        }
    }
}

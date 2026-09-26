import XCTest
@testable import P202Attribution

final class SchemaTests: XCTestCase {
    func testDecodesTheServerEnvelope() throws {
        let body = TestSchema.document(
            goals: [TestSchema.goal(7, #"{"name":"Level 3","trigger":{"event":"level_reached","where":[{"prop":"level","op":"gte","value":3}]}}"#, effectiveAt: 1_725_600_000)],
            encodings: [#"{"goal_id":7,"fine_value":40,"coarse_value":"high"}"#],
            appKey: #""525463029""#,
            appId: "525463029"
        )
        let schema = try P202AttributionSchema.decode(responseBody: body)
        XCTAssertEqual(schema.appId, 525463029)
        XCTAssertEqual(schema.schemaVersion, "abc")
        XCTAssertEqual(schema.goals.map(\.goalId), [7])
        XCTAssertEqual(schema.goals[0].versions[0].effectiveAt, 1_725_600_000)
        XCTAssertEqual(schema.encodings[7], .init(fineValue: 40, coarseValue: .high))
    }

    func testDecodesAnEmptyDocument() throws {
        let schema = try P202AttributionSchema.decode(responseBody: TestSchema.document(goals: [], encodings: []))
        XCTAssertTrue(schema.goals.isEmpty)
        XCTAssertTrue(schema.encodings.isEmpty)
    }

    func testRejectsAMalformedBodyInsteadOfGuessing() throws {
        let cases: [(String, Data)] = [
            ("not JSON", Data("{".utf8)),
            ("no data", Data(#"{"app_id":1}"#.utf8)),
            ("app_id disagrees with app_key", TestSchema.document(goals: [], encodings: [], appId: "43")),
            ("an app key with a leading zero", TestSchema.document(goals: [], encodings: [], appKey: #""042""#)),
            ("a fine value past 63", TestSchema.document(goals: [TestSchema.goal(1, "{}")], encodings: [#"{"goal_id":1,"fine_value":64}"#])),
            ("a fine value as 3.0", TestSchema.document(goals: [TestSchema.goal(1, "{}")], encodings: [#"{"goal_id":1,"fine_value":3.0}"#])),
            ("an unknown coarse value", TestSchema.document(goals: [TestSchema.goal(1, "{}")], encodings: [#"{"goal_id":1,"coarse_value":"max"}"#])),
            ("an encoding with no value", TestSchema.document(goals: [TestSchema.goal(1, "{}")], encodings: [#"{"goal_id":1}"#])),
            ("an encoding for a goal not in the document", TestSchema.document(goals: [], encodings: [#"{"goal_id":1,"fine_value":1}"#])),
            ("one goal twice", TestSchema.document(goals: [TestSchema.goal(1, "{}"), TestSchema.goal(1, "{}")], encodings: [])),
            ("goals as an object", Data(#"{"data":{"platform":"ios","app_key":"42","app_id":42,"schema_version":"v","goals":{},"encodings":[]}}"#.utf8)),
        ]
        for (name, body) in cases {
            XCTAssertThrowsError(try P202AttributionSchema.decode(responseBody: body), name)
        }
    }

    func testAnInvalidDefinitionDisablesItsGoalNotTheDocument() throws {
        // The server only serves definitions it accepted; one that arrives
        // damaged is the evaluator's to disable (invalid_definition), and
        // the other goals still encode.
        let body = TestSchema.document(
            goals: [TestSchema.goal(1, #"{"name":"A","trigger":{"event":"a"},"treshold":{"count":2}}"#), TestSchema.goal(2, #"{"name":"B","trigger":{"event":"b"}}"#)],
            encodings: [#"{"goal_id":1,"fine_value":1}"#, #"{"goal_id":2,"fine_value":2}"#]
        )
        let schema = try P202AttributionSchema.decode(responseBody: body)
        let result = try GoalEvaluator.evaluateAll(
            schema.goals,
            subject: GoalSubject(kind: .install, clickAt: nil, installAt: 0),
            events: [GoalEvent(eventId: "e1", name: "b", occurredAt: 1, receivedAt: 1)]
        )
        XCTAssertEqual(result.disabled, [DisabledGoal(goalId: 1, version: 1, reason: "invalid_definition")])
        XCTAssertEqual(result.outcomes.map(\.goalId), [2])
    }

    func testSchemaURLIsTheDocumentedPath() {
        let url = P202Attribution.schemaURL(endpoint: URL(string: "https://tracker.example.com")!)
        XCTAssertEqual(url.absoluteString, "https://tracker.example.com/api/v3/apps/schema")
    }

    func testJSONKeepsIntegersApartFromFractions() throws {
        XCTAssertEqual(try JSONValue.parse("3"), .int(3))
        XCTAssertEqual(try JSONValue.parse("3.0"), .double(3.0))
        XCTAssertEqual(try JSONValue.parse("1e2"), .double(100))
        XCTAssertEqual(try JSONValue.parse("99999999999999999999"), .double(1e20), "past 64 bits, as json_decode() reads it")
        XCTAssertEqual(try JSONValue.parse(#""😀""#), .string("😀"))
        XCTAssertThrowsError(try JSONValue.parse("[1,]"))
        XCTAssertThrowsError(try JSONValue.parse("01"))
        XCTAssertThrowsError(try JSONValue.parse(#""\ud83d""#), "an unpaired surrogate")
        XCTAssertThrowsError(try JSONValue.parse(String(repeating: "[", count: 100) + String(repeating: "]", count: 100)))
        let object = try JSONValue.parse(#"{"a":1,"a":2}"#)
        XCTAssertEqual(object["a"], .int(2), "a repeated key keeps its last value")
        XCTAssertEqual(try JSONValue.parse(object.jsonText), .object([("a", .int(2))]))
    }
}

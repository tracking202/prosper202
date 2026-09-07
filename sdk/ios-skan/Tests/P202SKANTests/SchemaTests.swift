import XCTest
@testable import P202SKAN

final class SchemaTests: XCTestCase {
    func testDecodesTheServerEnvelope() throws {
        let body = """
        {"data":{"app_id":525463029,"schema_version":"abc123","events":{
            "purchase":{"fine_value":63,"coarse_value":"high"},
            "signup":{"fine_value":5,"coarse_value":null},
            "trial":{"fine_value":null,"coarse_value":"medium"}
        },"generated_at":1725690000}}
        """.data(using: .utf8)!

        let schema = try P202SKANSchema.decode(responseBody: body)
        XCTAssertEqual(schema.appId, 525463029)
        XCTAssertEqual(schema.schemaVersion, "abc123")
        XCTAssertEqual(schema.events["purchase"], .init(fineValue: 63, coarseValue: .high))
        XCTAssertEqual(schema.events["signup"], .init(fineValue: 5, coarseValue: nil))
        XCTAssertEqual(schema.events["trial"], .init(fineValue: nil, coarseValue: .medium))
    }

    func testDecodesAnEmptyEventsObject() throws {
        let body = #"{"data":{"app_id":1,"schema_version":"x","events":{}}}"#.data(using: .utf8)!
        let schema = try P202SKANSchema.decode(responseBody: body)
        XCTAssertTrue(schema.events.isEmpty)
    }

    func testRejectsAMalformedBodyInsteadOfGuessing() throws {
        let body = #"{"data":{"app_id":"not-a-number"}}"#.data(using: .utf8)!
        XCTAssertThrowsError(try P202SKANSchema.decode(responseBody: body))
    }

    func testSchemaURLIsTheDocumentedPath() {
        let url = P202SKAN.schemaURL(endpoint: URL(string: "https://tracker.example.com")!)
        XCTAssertEqual(url.absoluteString, "https://tracker.example.com/api/v3/skan/schema")
    }
}

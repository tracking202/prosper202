import Foundation

/// The conversion-value schema served by a Prosper202 server at
/// `GET /api/v3/skan/schema` — the encode side of the rules configured in
/// `/skan/conversion-values`. The server builds this document and the
/// decode reports from the same rows, so the two directions cannot drift.
public struct P202SKANSchema: Codable, Equatable {
    /// SKAN's coarse conversion values, as the server serves them.
    public enum CoarseValue: String, Codable, Equatable {
        case low
        case medium
        case high
    }

    /// What one named event encodes to. Either side can be absent: a rule
    /// set may define only a fine value, only a coarse value, or both.
    public struct EventMapping: Codable, Equatable {
        public let fineValue: Int?
        public let coarseValue: CoarseValue?

        public init(fineValue: Int?, coarseValue: CoarseValue?) {
            self.fineValue = fineValue
            self.coarseValue = coarseValue
        }

        enum CodingKeys: String, CodingKey {
            case fineValue = "fine_value"
            case coarseValue = "coarse_value"
        }
    }

    /// The advertised app's App Store id.
    public let appId: Int
    /// Content hash of the mapping; changes exactly when encoding behaviour
    /// changes. Also the ETag the server honours for conditional fetches.
    public let schemaVersion: String
    /// Event name -> values to set when the app reports that event.
    public let events: [String: EventMapping]

    public init(appId: Int, schemaVersion: String, events: [String: EventMapping]) {
        self.appId = appId
        self.schemaVersion = schemaVersion
        self.events = events
    }

    enum CodingKeys: String, CodingKey {
        case appId = "app_id"
        case schemaVersion = "schema_version"
        case events
    }

    /// Decode the server response body (`{"data": {...}}`).
    public static func decode(responseBody: Data) throws -> P202SKANSchema {
        struct Envelope: Codable {
            let data: P202SKANSchema
        }
        return try JSONDecoder().decode(Envelope.self, from: responseBody).data
    }
}

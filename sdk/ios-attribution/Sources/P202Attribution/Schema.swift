import Foundation

/// The document a Prosper202 server serves an iOS build at
/// `GET /api/v3/apps/schema` (documentation/api/21-app-sdk-contract.md):
/// the app's goals, as an evaluation-only view, and the SKAN encodings that
/// say which conversion value means each goal was reached.
///
///     {"data": {"platform": "ios", "app_key": "525463029", "app_id": 525463029,
///       "schema_version": "…", "generated_at": 1725690000,
///       "goals": [{"goal_id": 7, "starts_at": 0, "ends_at": null,
///                  "versions": [{"version": 1, "effective_at": 1725600000,
///                                "definition": {…no value…}}]}],
///       "encodings": [{"goal_id": 7, "fine_value": 40, "coarse_value": "high"}]}}
///
/// The device evaluates the goals on its own events (the server never sees
/// them: Apple's postback carries only the value), and sets the value an
/// encoding gives the goals an event reaches. Revenue is not in the document
/// — no goal value, no payout, no revenue override — because a device never
/// needs it.
///
/// Decoding is strict about everything the SDK acts on and ignores fields it
/// does not know, so a server can add to the document without breaking
/// shipped builds, but a document that is not an iOS app's, or whose values
/// cannot be read, is refused whole rather than half-applied.
public struct P202AttributionSchema: Equatable {
    /// The coarse conversion values (the same three in SKAdNetwork and
    /// AdAttributionKit), as the server serves them.
    public enum CoarseValue: String, Codable, Equatable, Sendable, CaseIterable {
        case low
        case medium
        case high

        var rank: Int {
            switch self {
            case .low: return 1
            case .medium: return 2
            case .high: return 3
            }
        }
    }

    /// What reaching one goal encodes to. Either side can be absent.
    public struct EventMapping: Equatable, Sendable {
        public let fineValue: Int?
        public let coarseValue: CoarseValue?

        public init(fineValue: Int?, coarseValue: CoarseValue?) {
            self.fineValue = fineValue
            self.coarseValue = coarseValue
        }
    }

    public enum DecodeError: Error, Equatable {
        /// The body is not JSON, or has no `data` object.
        case notADocument
        /// The document names another platform's app (a token lifted into
        /// the wrong build), or its identity cannot be read.
        case notAnIOSApp
        /// A field the SDK acts on has the wrong type or range; the path
        /// names it.
        case malformed(String)
    }

    /// The advertised app's App Store id.
    public let appId: Int
    /// Content hash of the document; changes exactly when device behaviour
    /// changes. Also the ETag the server honours for conditional fetches.
    public let schemaVersion: String
    /// The goals the encodings name, with every prerequisite they wait for.
    public let goals: [GoalSpec]
    /// goal id → the values to set when the device reaches that goal.
    public let encodings: [Int: EventMapping]

    public init(appId: Int, schemaVersion: String, goals: [GoalSpec], encodings: [Int: EventMapping]) {
        self.appId = appId
        self.schemaVersion = schemaVersion
        self.goals = goals
        self.encodings = encodings
    }

    /// Decode the server response body (`{"data": {...}}`).
    public static func decode(responseBody: Data) throws -> P202AttributionSchema {
        guard let root = try? JSONValue.parse(responseBody), let data = root["data"], case .object = data else {
            throw DecodeError.notADocument
        }

        guard let identity = P202AppKey.resolve(platform: data["platform"] ?? .null, appKey: data["app_key"] ?? .null),
              identity.platform == "ios",
              let appId = data["app_id"]?.intValue, String(appId) == identity.key else {
            throw DecodeError.notAnIOSApp
        }
        guard let version = data["schema_version"]?.stringValue, !version.isEmpty else {
            throw DecodeError.malformed("schema_version")
        }

        guard let goalList = data["goals"], case let .array(goalItems) = goalList else {
            throw DecodeError.malformed("goals")
        }
        var goals: [GoalSpec] = []
        var seenGoals = Set<Int>()
        for (i, item) in goalItems.enumerated() {
            let path = "goals[\(i)]"
            guard let goalId = item["goal_id"]?.intValue, goalId > 0, seenGoals.insert(goalId).inserted else {
                throw DecodeError.malformed(path + ".goal_id")
            }
            guard let startsAt = item["starts_at"]?.intValue else {
                throw DecodeError.malformed(path + ".starts_at")
            }
            var endsAt: Int?
            if let ends = item["ends_at"], !ends.isNull {
                guard let value = ends.intValue else {
                    throw DecodeError.malformed(path + ".ends_at")
                }
                endsAt = value
            }
            guard let versionList = item["versions"], case let .array(versionItems) = versionList, !versionItems.isEmpty else {
                throw DecodeError.malformed(path + ".versions")
            }
            var versions: [GoalSpec.Version] = []
            for (j, v) in versionItems.enumerated() {
                guard let number = v["version"]?.intValue, number > 0,
                      let effectiveAt = v["effective_at"]?.intValue,
                      let definition = v["definition"] else {
                    throw DecodeError.malformed(path + ".versions[\(j)]")
                }
                // The definition is handed to the evaluator as it came: an
                // invalid one disables that version, never the document.
                versions.append(GoalSpec.Version(version: number, effectiveAt: effectiveAt, definition: definition))
            }
            goals.append(GoalSpec(goalId: goalId, versions: versions, startsAt: startsAt, endsAt: endsAt))
        }

        guard let encodingList = data["encodings"], case let .array(encodingItems) = encodingList else {
            throw DecodeError.malformed("encodings")
        }
        var encodings: [Int: EventMapping] = [:]
        for (i, item) in encodingItems.enumerated() {
            let path = "encodings[\(i)]"
            guard let goalId = item["goal_id"]?.intValue, seenGoals.contains(goalId), encodings[goalId] == nil else {
                throw DecodeError.malformed(path + ".goal_id")
            }
            var fine: Int?
            if let f = item["fine_value"], !f.isNull {
                guard let value = f.intValue, (0...63).contains(value) else {
                    throw DecodeError.malformed(path + ".fine_value")
                }
                fine = value
            }
            var coarse: CoarseValue?
            if let c = item["coarse_value"], !c.isNull {
                guard let text = c.stringValue, let value = CoarseValue(rawValue: text) else {
                    throw DecodeError.malformed(path + ".coarse_value")
                }
                coarse = value
            }
            guard fine != nil || coarse != nil else {
                throw DecodeError.malformed(path)
            }
            encodings[goalId] = EventMapping(fineValue: fine, coarseValue: coarse)
        }

        return P202AttributionSchema(appId: appId, schemaVersion: version, goals: goals, encodings: encodings)
    }
}

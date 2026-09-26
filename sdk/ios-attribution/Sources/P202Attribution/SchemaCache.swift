import Foundation

/// Minimal persistence seam so the cache is testable without UserDefaults.
public protocol P202KeyValueStore {
    func data(forKey key: String) -> Data?
    func set(_ value: Data, forKey key: String)
    func removeValue(forKey key: String)
}

extension UserDefaults: P202KeyValueStore {
    public func set(_ value: Data, forKey key: String) {
        set(value as Any, forKey: key)
    }

    public func removeValue(forKey key: String) {
        removeObject(forKey: key)
    }
}

/// Persisted fetch state: the last schema document (as the server sent it),
/// its ETag, and when it was fetched. Keyed by a hash of the app token, so
/// rotating the token in a new build never reuses a stale cache.
///
/// The body is stored rather than a re-encoding of the decoded schema: goal
/// definitions are validated with JSON's integer/fraction distinction intact
/// (see JSONValue), and only the server's own bytes are sure to keep it. A
/// body that no longer decodes loads as an empty cache, never a crash.
///
/// The ETag and the fetch time describe the body, so they are kept only
/// with one. A cache written before the body was stored (it held `schema`,
/// `etag` and `fetchedAt`, and the schema is not re-decodable) loads with
/// no ETag: sent as If-None-Match, that ETag would be answered 304 — "you
/// have it" — for a document this device no longer holds, on every fetch.
struct SchemaCache: Codable, Equatable {
    var body: Data?
    var etag: String?
    var fetchedAt: Date?
    /// Decoded from `body`; not stored.
    private(set) var schema: P202AttributionSchema?

    enum CodingKeys: String, CodingKey {
        case body, etag, fetchedAt
    }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        body = try c.decodeIfPresent(Data.self, forKey: .body)
        etag = try c.decodeIfPresent(String.self, forKey: .etag)
        fetchedAt = try c.decodeIfPresent(Date.self, forKey: .fetchedAt)
        if let body {
            schema = try P202AttributionSchema.decode(responseBody: body)
        }
        if schema == nil {
            // No document to vouch for: the next fetch is unconditional.
            etag = nil
            fetchedAt = nil
        }
    }

    /// Replace the document with a newly fetched one.
    mutating func store(body: Data, schema: P202AttributionSchema) {
        self.body = body
        self.schema = schema
        self.etag = "\"\(schema.schemaVersion)\""
    }

    static func storageKey(appToken: String) -> String {
        // djb2 — stable, dependency-free; this is a namespace, not a secret.
        var hash: UInt64 = 5381
        for byte in appToken.utf8 {
            hash = (hash &* 33) &+ UInt64(byte)
        }
        return "p202attribution.cache.\(hash)"
    }

    static func load(from store: P202KeyValueStore, appToken: String) -> SchemaCache {
        guard let data = store.data(forKey: storageKey(appToken: appToken)),
              let cache = try? JSONDecoder().decode(SchemaCache.self, from: data)
        else {
            return SchemaCache()
        }
        return cache
    }

    func save(to store: P202KeyValueStore, appToken: String) {
        guard let data = try? JSONEncoder().encode(self) else {
            return
        }
        store.set(data, forKey: Self.storageKey(appToken: appToken))
    }
}

/// The last fine conversion value handed to the frameworks, per postback —
/// the fallback that keeps a coarse-only update from regressing the fine
/// signal. The install postback (SKAdNetwork's only one, and
/// AdAttributionKit's default) and AdAttributionKit's re-engagement
/// postback each carry their own conversion value, so each has its own
/// slot: a re-engagement update must never fall back to the install
/// postback's value, or vice versa.
///
/// Deliberately NOT part of SchemaCache: it is device conversion state, not
/// fetch state. The conversion windows keep running across an app-token
/// rotation (a new build with a new token), so forgetting the value with
/// the old token's cache would make the next coarse-only update report
/// fine value 0 and silently downgrade the postback.
enum LastFineValueStore {
    /// The install postback's slot — the key predates re-engagement support,
    /// so devices upgrading the helper keep their value.
    static let key = "p202attribution.lastFineValue"
    static let reengagementKey = "p202attribution.lastFineValue.reengagement"

    static func key(for conversionType: ConversionUpdate.ConversionType) -> String {
        switch conversionType {
        case .install: return key
        case .reengagement: return reengagementKey
        }
    }

    static func load(
        from store: P202KeyValueStore,
        for conversionType: ConversionUpdate.ConversionType = .install
    ) -> Int? {
        guard let data = store.data(forKey: key(for: conversionType)) else {
            return nil
        }
        return try? JSONDecoder().decode(Int.self, from: data)
    }

    static func save(
        _ value: Int,
        to store: P202KeyValueStore,
        for conversionType: ConversionUpdate.ConversionType = .install
    ) {
        guard let data = try? JSONEncoder().encode(value) else {
            return
        }
        store.set(data, forKey: key(for: conversionType))
    }
}

/// The device's goal progress: when it was installed, the evaluator's state
/// after the last event, and the events still waiting for a first schema.
///
/// Token-independent, like LastFineValueStore: the install and its
/// conversion windows outlive a token rotation. `lastReceivedAt` keeps event
/// times from going backwards (a clock set back), so every new event sorts
/// after the ones already folded into `state` — the one condition under
/// which evaluating from the stored state equals evaluating everything
/// again, the property GoalVectorsTests checks for every vector.
struct DeviceGoalState: Codable, Equatable {
    struct Pending: Codable, Equatable {
        var event: GoalEvent
        var conversionTypes: [ConversionUpdate.ConversionType]?
        /// The re-engagement lifecycle the event was logged in
        /// (`reengagementGeneration` then); nil for one queued before
        /// lifecycles were counted, which is lifecycle 0.
        var reengagementGeneration: Int? = nil
    }

    static let key = "p202attribution.goals"

    var installAt: Int?
    var installEvaluated = false
    var lastReceivedAt = 0
    /// The install postback's goal progress.
    var state = EvaluationState()
    /// The re-engagement postback's, kept apart (P202Attribution.evaluate):
    /// optional so a state stored before re-engagement had its own decodes
    /// (as a fresh lifecycle) rather than failing and starting everything
    /// over.
    var reengagementState: EvaluationState?
    /// How many re-engagement lifecycles have begun (`beginReengagement()`);
    /// nil — never stored, or stored before it was counted — is 0. A queued
    /// event scoped to re-engagement counts toward the lifecycle it was
    /// logged in and no other.
    var reengagementGeneration: Int?
    var pending: [Pending] = []

    var currentReengagementGeneration: Int { reengagementGeneration ?? 0 }

    func progress(for type: ConversionUpdate.ConversionType) -> EvaluationState {
        switch type {
        case .install: return state
        case .reengagement: return reengagementState ?? EvaluationState()
        }
    }

    mutating func setProgress(_ progress: EvaluationState, for type: ConversionUpdate.ConversionType) {
        switch type {
        case .install: state = progress
        case .reengagement: reengagementState = progress
        }
    }

    /// A stored state that cannot be read starts over rather than crash;
    /// that is the one path that can re-report the install's goals, and the
    /// frameworks keep only the latest value, so re-reporting is harmless.
    static func load(from store: P202KeyValueStore) -> DeviceGoalState {
        guard let data = store.data(forKey: key),
              let state = try? JSONDecoder().decode(DeviceGoalState.self, from: data) else {
            return DeviceGoalState()
        }
        return state
    }

    func save(to store: P202KeyValueStore) {
        guard let data = try? JSONEncoder().encode(self) else {
            return
        }
        store.set(data, forKey: Self.key)
    }
}

/// The customer id setCustomerId stored. Token-independent: it is who the
/// user is, not which build fetched what.
enum CustomerIdStore {
    static let key = "p202attribution.customer"

    static func load(from store: P202KeyValueStore) -> P202CustomerId? {
        guard let data = store.data(forKey: key) else {
            return nil
        }
        return try? JSONDecoder().decode(P202CustomerId.self, from: data)
    }

    static func save(_ customer: P202CustomerId, to store: P202KeyValueStore) {
        guard let data = try? JSONEncoder().encode(customer) else {
            return
        }
        store.set(data, forKey: key)
    }
}

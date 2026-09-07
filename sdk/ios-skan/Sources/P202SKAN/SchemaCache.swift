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

/// Persisted fetch state: the last schema document, its ETag, when it was
/// fetched, and the last fine value sent (the fallback for coarse-only
/// updates). Keyed by a hash of the schema token, so rotating the token in
/// a new build never reuses a stale cache.
struct SchemaCache: Codable, Equatable {
    var schema: P202SKANSchema?
    var etag: String?
    var fetchedAt: Date?
    var lastFineValue: Int?

    static func storageKey(schemaToken: String) -> String {
        // djb2 — stable, dependency-free; this is a namespace, not a secret.
        var hash: UInt64 = 5381
        for byte in schemaToken.utf8 {
            hash = (hash &* 33) &+ UInt64(byte)
        }
        return "p202skan.cache.\(hash)"
    }

    static func load(from store: P202KeyValueStore, schemaToken: String) -> SchemaCache {
        guard let data = store.data(forKey: storageKey(schemaToken: schemaToken)),
              let cache = try? JSONDecoder().decode(SchemaCache.self, from: data)
        else {
            return SchemaCache()
        }
        return cache
    }

    func save(to store: P202KeyValueStore, schemaToken: String) {
        guard let data = try? JSONEncoder().encode(self) else {
            return
        }
        store.set(data, forKey: Self.storageKey(schemaToken: schemaToken))
    }
}

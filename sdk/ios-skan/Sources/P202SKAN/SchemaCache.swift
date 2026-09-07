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

/// Persisted fetch state: the last schema document, its ETag, and when it
/// was fetched. Keyed by a hash of the schema token, so rotating the token
/// in a new build never reuses a stale cache.
struct SchemaCache: Codable, Equatable {
    var schema: P202SKANSchema?
    var etag: String?
    var fetchedAt: Date?

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

/// The last fine conversion value handed to SKAdNetwork — the fallback that
/// keeps a coarse-only update from regressing the fine signal.
///
/// Deliberately NOT part of SchemaCache: it is device conversion state, not
/// fetch state. SKAdNetwork's conversion windows keep running across a
/// schema-token rotation (a new build with a new token), so forgetting the
/// value with the old token's cache would make the next coarse-only update
/// report fine value 0 and silently downgrade the postback.
enum LastFineValueStore {
    static let key = "p202skan.lastFineValue"

    static func load(from store: P202KeyValueStore) -> Int? {
        guard let data = store.data(forKey: key) else {
            return nil
        }
        return try? JSONDecoder().decode(Int.self, from: data)
    }

    static func save(_ value: Int, to store: P202KeyValueStore) {
        guard let data = try? JSONEncoder().encode(value) else {
            return
        }
        store.set(data, forKey: key)
    }
}

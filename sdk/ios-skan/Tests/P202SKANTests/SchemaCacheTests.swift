import XCTest
@testable import P202SKAN

final class InMemoryStore: P202KeyValueStore {
    private var storage: [String: Data] = [:]

    func data(forKey key: String) -> Data? {
        return storage[key]
    }

    func set(_ value: Data, forKey key: String) {
        storage[key] = value
    }

    func removeValue(forKey key: String) {
        storage[key] = nil
    }

    var keys: [String] {
        return Array(storage.keys)
    }
}

final class SchemaCacheTests: XCTestCase {
    func testRoundTripsThroughTheStore() {
        let store = InMemoryStore()
        var cache = SchemaCache()
        cache.schema = P202SKANSchema(
            appId: 42,
            schemaVersion: "abc",
            events: ["purchase": .init(fineValue: 63, coarseValue: .high)]
        )
        cache.etag = "\"abc\""
        cache.fetchedAt = Date(timeIntervalSince1970: 1_725_690_000)
        cache.save(to: store, schemaToken: "token-a")

        let loaded = SchemaCache.load(from: store, schemaToken: "token-a")
        XCTAssertEqual(loaded, cache)
    }

    func testLastFineValueLivesOutsideTheTokenKeyedCache() {
        // It is device conversion state, not fetch state: rotating the
        // schema token must NOT forget the last fine value, or the next
        // coarse-only update would regress the postback to fine 0.
        let store = InMemoryStore()
        LastFineValueStore.save(41, to: store)

        XCTAssertEqual(LastFineValueStore.load(from: store), 41)
        XCTAssertFalse(store.keys.contains(SchemaCache.storageKey(schemaToken: "token-a")))
        XCTAssertTrue(store.keys.contains(LastFineValueStore.key))

        store.set(Data("junk".utf8), forKey: LastFineValueStore.key)
        XCTAssertNil(LastFineValueStore.load(from: store), "corrupt data must load as nil, not crash")
    }

    func testDifferentTokensUseDifferentSlots() {
        // Rotating the token in a new build must never serve the old
        // token's cached schema.
        let store = InMemoryStore()
        var cache = SchemaCache()
        cache.etag = "\"abc\""
        cache.save(to: store, schemaToken: "token-a")

        XCTAssertEqual(SchemaCache.load(from: store, schemaToken: "token-b"), SchemaCache())
        XCTAssertNotEqual(
            SchemaCache.storageKey(schemaToken: "token-a"),
            SchemaCache.storageKey(schemaToken: "token-b")
        )
    }

    func testCorruptStoredDataLoadsAsEmptyNotACrash() {
        let store = InMemoryStore()
        store.set(Data("not json".utf8), forKey: SchemaCache.storageKey(schemaToken: "token-a"))
        XCTAssertEqual(SchemaCache.load(from: store, schemaToken: "token-a"), SchemaCache())
    }
}

import Foundation
#if canImport(FoundationNetworking)
import FoundationNetworking
#endif
#if os(iOS) && canImport(StoreKit)
import StoreKit
#endif

/// Remote-configured SKAdNetwork conversion values for a Prosper202 server.
///
/// The app ships once with the server URL and its app's schema token; from
/// then on, what each event encodes to is edited in Prosper202
/// (`/skan/conversion-values`) and picked up at runtime — no App Store
/// resubmission. Usage:
///
///     P202SKAN.shared.configure(
///         endpoint: URL(string: "https://your-domain.com")!,
///         schemaToken: "<from POST /skan/apps>"
///     )
///     ...
///     P202SKAN.shared.logEvent("purchase")
///
/// `logEvent` resolves the event through the fetched schema and calls
/// `SKAdNetwork.updatePostbackConversionValue`. Unmapped events are no-ops
/// by design. The schema is cached (with its ETag) across launches, so the
/// device encodes correctly offline and refreshes cheaply — the server
/// answers 304 until a rule actually changes.
///
/// Note: `NSAdvertisingAttributionReportEndpoint` in Info.plist cannot be
/// set at runtime; that one line still ships with the app.
public final class P202SKAN {
    public struct Configuration {
        public let endpoint: URL
        public let schemaToken: String
        public let lockWindow: Bool
        public let refreshInterval: TimeInterval

        public init(
            endpoint: URL,
            schemaToken: String,
            lockWindow: Bool = false,
            refreshInterval: TimeInterval = 6 * 60 * 60
        ) {
            self.endpoint = endpoint
            self.schemaToken = schemaToken
            self.lockWindow = lockWindow
            self.refreshInterval = refreshInterval
        }
    }

    public enum SDKError: Error, Equatable {
        case notConfigured
        case unexpectedStatus(Int)
        case emptyResponse
        /// The configuration changed (a different schema token) while this
        /// fetch was in flight; its response was discarded rather than
        /// written into the new configuration's cache.
        case superseded
        /// The P202SKAN instance was deallocated before the response
        /// arrived. Unreachable through `shared`; possible for short-lived
        /// injected instances.
        case deallocated
    }

    public static let shared = P202SKAN()

    private let queue = DispatchQueue(label: "com.prosper202.skan")
    private let store: P202KeyValueStore
    private let session: URLSession
    private var configuration: Configuration?
    private var cache = SchemaCache()
    private var lastFineValue: Int?
    private var refreshInFlight = false
    /// When the last refresh ATTEMPT resolved, successfully or not. Distinct
    /// from `cache.fetchedAt`, which records only successes — see
    /// refreshIfStale() for why a failure has to be remembered too.
    private var lastAttemptAt: Date?

    /// The store and session are injectable for tests; production uses
    /// UserDefaults and the shared URLSession.
    public init(store: P202KeyValueStore = UserDefaults.standard, session: URLSession = .shared) {
        self.store = store
        self.session = session
    }

    /// Configure and kick off the first (or a conditional) schema fetch.
    public func configure(
        endpoint: URL,
        schemaToken: String,
        lockWindow: Bool = false,
        refreshInterval: TimeInterval = 6 * 60 * 60
    ) {
        let config = Configuration(
            endpoint: endpoint,
            schemaToken: schemaToken,
            lockWindow: lockWindow,
            refreshInterval: refreshInterval
        )
        queue.sync {
            self.configuration = config
            self.cache = SchemaCache.load(from: store, schemaToken: schemaToken)
            // Token-independent on purpose: the device's conversion windows
            // keep running across a token rotation (see LastFineValueStore).
            self.lastFineValue = LastFineValueStore.load(from: store)
        }
        refreshSchema()
    }

    /// The schema currently driving `logEvent`, if any (cached or fetched).
    public var currentSchema: P202SKANSchema? {
        return queue.sync { cache.schema }
    }

    /// Report an event by the name it carries in `/skan/conversion-values`.
    /// Returns the update that was handed to SKAdNetwork, or nil when the
    /// schema does not map the event (a deliberate no-op) or no schema is
    /// available yet. The return value exists for the app's own logging.
    @discardableResult
    public func logEvent(_ name: String) -> ConversionUpdate? {
        let resolved: (update: ConversionUpdate, lockWindow: Bool)? = queue.sync {
            guard let config = configuration, let schema = cache.schema else {
                return nil
            }
            guard let update = ConversionUpdate.resolve(
                event: name,
                in: schema,
                lastFineValue: lastFineValue
            ) else {
                return nil
            }
            // Persist only on change: repeated events with the same fine
            // value must not write the store on every call.
            if !update.usedFineFallback && lastFineValue != update.fineValue {
                lastFineValue = update.fineValue
                LastFineValueStore.save(update.fineValue, to: store)
            }
            return (update, config.lockWindow)
        }

        refreshIfStale()

        guard let (update, lockWindow) = resolved else {
            return nil
        }
        Self.submitToSKAdNetwork(update, lockWindow: lockWindow)
        return update
    }

    /// Signal install attribution before any conversion event has happened
    /// (the modern replacement for registerAppForAdNetworkAttribution): sets
    /// conversion value 0 on iOS 15.4+, where the API exists.
    ///
    /// Safe to call at any point in the app's life, including from
    /// `applicationDidBecomeActive`, which is where developers habitually
    /// put it: it re-asserts the value already reported rather than
    /// resetting to 0. Hardcoding 0 would have reported 0 to Apple after a
    /// `logEvent` had reported 40 AND left `lastFineValue` at 40, so the
    /// SDK's own state disagreed with what Apple held — and the next
    /// coarse-only event would have re-reported 40 out of nowhere.
    /// Returns the update handed to SKAdNetwork, like `logEvent`, so the
    /// app can log what was reported.
    @discardableResult
    public func registerAttribution() -> ConversionUpdate {
        let update = ConversionUpdate(
            fineValue: queue.sync { lastFineValue ?? 0 },
            coarseValue: nil,
            usedFineFallback: false
        )
        Self.submitToSKAdNetwork(update, lockWindow: false)
        return update
    }

    /// Fetch the schema now. Uses If-None-Match, so an unchanged schema
    /// costs a 304. Safe to call at app foreground; `logEvent` also
    /// refreshes opportunistically when the cache is older than
    /// `refreshInterval`. An explicit call always fetches (overlapping GETs
    /// are idempotent and cheap); only the opportunistic path deduplicates
    /// against an in-flight fetch — so a caller-provided completion is
    /// always invoked exactly once.
    public func refreshSchema(completion: ((Result<P202SKANSchema, Error>) -> Void)? = nil) {
        // The token is captured alongside the request: finishRefresh writes
        // the response into the cache only while the SAME token is still
        // configured. Without that check, reconfiguring mid-flight would let
        // the OLD app's schema land in the NEW token's cache slot.
        let prepared: (request: URLRequest, token: String)? = queue.sync {
            guard let config = configuration else {
                return nil
            }
            refreshInFlight = true
            var req = URLRequest(url: Self.schemaURL(endpoint: config.endpoint))
            req.httpMethod = "GET"
            req.setValue(config.schemaToken, forHTTPHeaderField: "X-P202-Schema-Token")
            if let etag = cache.etag {
                req.setValue(etag, forHTTPHeaderField: "If-None-Match")
            }
            return (req, config.schemaToken)
        }
        guard let prepared else {
            completion?(.failure(SDKError.notConfigured))
            return
        }

        session.dataTask(with: prepared.request) { [weak self] data, response, error in
            // The completion contract is exactly-once even when the instance
            // died while the request was out.
            guard let self else {
                completion?(.failure(SDKError.deallocated))
                return
            }
            completion?(self.finishRefresh(
                requestToken: prepared.token,
                data: data,
                response: response,
                error: error
            ))
        }.resume()
    }

    /// Apply one fetch's outcome to the cache. Internal (not private) so
    /// tests can drive it with crafted responses — the stale-token guard is
    /// exactly the kind of seam a unit test must exercise for real.
    func finishRefresh(
        requestToken: String,
        data: Data?,
        response: URLResponse?,
        error: Error?
    ) -> Result<P202SKANSchema, Error> {
        return queue.sync {
            guard let config = configuration, config.schemaToken == requestToken else {
                // A different token is configured now (or none). This
                // response belongs to the old configuration: drop it without
                // touching the cache or the new fetch's in-flight marker.
                return .failure(SDKError.superseded)
            }
            refreshInFlight = false
            lastAttemptAt = Date()
            if let error {
                return .failure(error)
            }
            let status = (response as? HTTPURLResponse)?.statusCode ?? 0
            if status == 304, let cached = cache.schema {
                cache.fetchedAt = Date()
                cache.save(to: store, schemaToken: config.schemaToken)
                return .success(cached)
            }
            guard status == 200 else {
                return .failure(SDKError.unexpectedStatus(status))
            }
            guard let data, !data.isEmpty else {
                return .failure(SDKError.emptyResponse)
            }
            do {
                let schema = try P202SKANSchema.decode(responseBody: data)
                cache.schema = schema
                cache.etag = "\"\(schema.schemaVersion)\""
                cache.fetchedAt = Date()
                cache.save(to: store, schemaToken: config.schemaToken)
                return .success(schema)
            } catch {
                return .failure(error)
            }
        }
    }

    // MARK: - Internals

    static func schemaURL(endpoint: URL) -> URL {
        return endpoint
            .appendingPathComponent("api")
            .appendingPathComponent("v3")
            .appendingPathComponent("skan")
            .appendingPathComponent("schema")
    }

    private func refreshIfStale() {
        let stale: Bool = queue.sync {
            guard let config = configuration, !refreshInFlight else {
                return false
            }
            // A FAILED fetch throttles too. cache.fetchedAt is written only
            // on success, so a device that is offline, holds a rotated
            // token (404), or has just been told 429 would otherwise be
            // stale forever and start a fresh GET on every single
            // logEvent — hammering the very limiter that produced the 429,
            // with no error the app can see.
            guard let last = [cache.fetchedAt, lastAttemptAt].compactMap({ $0 }).max() else {
                return true
            }
            return Date().timeIntervalSince(last) > config.refreshInterval
        }
        if stale {
            refreshSchema()
        }
    }

    /// The one place StoreKit is touched. On non-iOS platforms (tests, this
    /// repository's CI) it compiles to a no-op.
    private static func submitToSKAdNetwork(_ update: ConversionUpdate, lockWindow: Bool) {
        #if os(iOS) && canImport(StoreKit)
        if #available(iOS 16.1, *) {
            if let coarse = update.coarseValue {
                SKAdNetwork.updatePostbackConversionValue(
                    update.fineValue,
                    coarseValue: coarse.skValue,
                    lockWindow: lockWindow
                ) { _ in }
            } else {
                // lockWindow rides on the coarse-bearing overload; a
                // fine-only mapping cannot lock the window (documented).
                SKAdNetwork.updatePostbackConversionValue(update.fineValue) { _ in }
            }
        } else if #available(iOS 15.4, *) {
            SKAdNetwork.updatePostbackConversionValue(update.fineValue) { _ in }
        }
        // iOS 14.0-15.3 only offers deprecated calls; devices there no
        // longer receive app updates built with current SDKs, so this
        // helper stays silent rather than shipping deprecated API usage.
        #endif
    }
}

#if os(iOS) && canImport(StoreKit)
@available(iOS 16.1, *)
private extension P202SKANSchema.CoarseValue {
    var skValue: SKAdNetwork.CoarseConversionValue {
        switch self {
        case .low: return .low
        case .medium: return .medium
        case .high: return .high
        }
    }
}
#endif

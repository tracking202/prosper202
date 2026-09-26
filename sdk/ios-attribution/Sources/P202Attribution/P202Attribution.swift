import Foundation
#if canImport(FoundationNetworking)
import FoundationNetworking
#endif
#if os(iOS) && canImport(StoreKit)
import StoreKit
#endif
#if os(iOS) && canImport(AdAttributionKit)
import AdAttributionKit
#endif

/// Remote-configured goals and conversion values for a Prosper202 server,
/// reported to both of Apple's attribution frameworks.
///
/// The app ships once with the server URL and its app token; from then on,
/// what counts as an outcome (goals: `/goals`) and which conversion value
/// means it (`/apps/skan-encodings`) are edited in Prosper202 and picked up
/// at runtime — no App Store resubmission. Usage:
///
///     P202Attribution.shared.configure(
///         endpoint: URL(string: "https://your-domain.com")!,
///         appToken: "<the app_token from POST /apps>"
///     )
///     ...
///     try P202Attribution.shared.logEvent("level_reached", properties: ["level": .int(3)])
///     try P202Attribution.shared.logEvent("purchase", revenue: 4.99)
///     try P202Attribution.shared.setCustomerId("u-829", signature: sigFromYourServer)
///
/// Apple's postback carries only a conversion value, so the goals are
/// evaluated **on the device** (plan §5.5): each event runs through the same
/// evaluator the server uses (held to the shared vectors in
/// tests/fixtures/app-sdk-contract/goals/), against the evaluation-only
/// goals the schema document carries. When an event reaches goals that an
/// encoding maps, the highest mapped fine and coarse values are handed to
/// `SKAdNetwork.updatePostbackConversionValue` and, on iOS 17.4+, to
/// AdAttributionKit's `Postback.updateConversionValue` — Apple's guidance for
/// an app whose ad networks may use either framework is to call both. An
/// update scoped with `conversionTypes` (iOS 18+) reaches only those
/// AdAttributionKit postbacks; one that leaves out `.install` is never sent
/// to SKAdNetwork, whose only postback is the install one, and is dropped
/// entirely on iOS 17.4-17.x. An event that reaches no encoded goal changes
/// nothing, by design.
///
/// The device is the subject `install`: its install time is the first
/// launch that configured the SDK, and it has no click, so a goal windowed
/// `from: click` never counts here. Events logged before the first schema
/// arrives wait (up to `maxPendingEvents`) and are evaluated, with their own
/// times, as soon as it does, so a first-launch goal is not lost to a slow
/// network. The schema is cached (with its ETag) across launches, so the
/// device encodes correctly offline and refreshes cheaply — for
/// `maxSchemaAge`: an event logged while the device holds only an older
/// document waits, like one logged before the first, for a fresh one.
///
/// Note: `NSAdvertisingAttributionReportEndpoint` (SKAdNetwork) and
/// `AttributionCopyEndpoint` (AdAttributionKit) in Info.plist cannot be set
/// at runtime; those lines still ship with the app.
public final class P202Attribution {
    public struct Configuration {
        public let endpoint: URL
        public let appToken: String
        public let lockWindow: Bool
        public let refreshInterval: TimeInterval

        public init(
            endpoint: URL,
            appToken: String,
            lockWindow: Bool = false,
            refreshInterval: TimeInterval = 6 * 60 * 60
        ) {
            self.endpoint = endpoint
            self.appToken = appToken
            self.lockWindow = lockWindow
            self.refreshInterval = refreshInterval
        }
    }

    public enum SDKError: Error, Equatable {
        case notConfigured
        case unexpectedStatus(Int)
        case emptyResponse
        /// The configuration changed (a different app token) while this
        /// fetch was in flight; its response was discarded rather than
        /// written into the new configuration's cache.
        case superseded
        /// The P202Attribution instance was deallocated before the response
        /// arrived. Unreachable through `shared`; possible for short-lived
        /// injected instances.
        case deallocated
        /// No usable schema is held (none has arrived yet, or the one held
        /// is older than `maxSchemaAge`) and `maxPendingEvents` events are
        /// already waiting for one; this event was not recorded. The
        /// earliest events are the ones kept, because the first conversion
        /// window is the one they belong to.
        case pendingEventsFull
    }

    /// Why `logEvent` refused an event: the rules the server applies to an
    /// event (plan §5.5), checked where the mistake can be fixed.
    public enum EventError: Error, Equatable {
        /// 1-64 of letters, digits, `_ . : -`, starting with a letter,
        /// digit or `_`.
        case invalidName(String)
        /// At most 32 properties.
        case tooManyProperties(Int)
        /// A letter or `_`, then letters, digits or `_`, up to 64.
        case invalidPropertyName(String)
        /// A string of at most 255 bytes, a finite number or a bool.
        case invalidPropertyValue(String)
        case invalidRevenue
    }

    public static let shared = P202Attribution()

    /// How many events may wait for a usable schema.
    public static let maxPendingEvents = 100

    /// The oldest schema document the SDK encodes with. A conversion value
    /// means what the document the device held said it meant, and the
    /// server decodes a postback under every meaning its value had in a
    /// fixed horizon before the postback arrived (the 35-day conversion
    /// windows, Apple's delivery delay of up to 144 hours, and this age:
    /// `SkanEncodingTimeline::HORIZON_DAYS`). A device offline since before
    /// an encoding was edited would otherwise set the old meaning's value
    /// at any later time, and the report would credit it to the new one.
    /// Not configurable, because the server's horizon is built from it.
    public static let maxSchemaAge: TimeInterval = 7 * 24 * 60 * 60

    private let queue = DispatchQueue(label: "com.prosper202.attribution")
    private let store: P202KeyValueStore
    private let session: URLSession
    private var configuration: Configuration?
    private var cache = SchemaCache()
    /// The last fine value reported for the install postback and, separately,
    /// for AdAttributionKit's re-engagement postback (see LastFineValueStore).
    private var lastFineValue: Int?
    private var lastReengagementFineValue: Int?
    private var goals = DeviceGoalState()
    private var refreshInFlight = false
    /// Set by a body-less 304; the fetch that answered it starts one more.
    private var refetchUnconditionally = false
    /// When the last refresh ATTEMPT resolved, successfully or not. Distinct
    /// from `cache.fetchedAt`, which records only successes — see
    /// refreshIfStale() for why a failure has to be remembered too.
    private var lastAttemptAt: Date?

    /// The clock, in unix seconds. Injectable so tests can walk windows.
    /// Every time the SDK keeps (event times, fetch and attempt times) is
    /// read from it, so a schema's age and an event's time agree.
    var clock: () -> Int = { Int(Date().timeIntervalSince1970) }

    private func clockDate() -> Date {
        return Date(timeIntervalSince1970: TimeInterval(clock()))
    }

    /// The cached schema, when it is young enough to encode with (call on
    /// `queue`). A fetch time in the future means the clock was set back
    /// since: the document's real age is unknown, so it is not used either.
    private func usableSchema() -> P202AttributionSchema? {
        guard let schema = cache.schema, let fetchedAt = cache.fetchedAt else {
            return nil
        }
        let age = clockDate().timeIntervalSince(fetchedAt)
        return age >= 0 && age <= Self.maxSchemaAge ? schema : nil
    }

    /// Every update handed to the frameworks, for tests (off-iOS the
    /// framework calls compile to nothing).
    var onSubmit: ((ConversionUpdate) -> Void)?

    /// Every schema request as it is sent, for tests.
    var onFetch: ((URLRequest) -> Void)?

    /// The store and session are injectable for tests; production uses
    /// UserDefaults and the shared URLSession.
    public init(store: P202KeyValueStore = UserDefaults.standard, session: URLSession = .shared) {
        self.store = store
        self.session = session
    }

    /// Configure and kick off the first (or a conditional) schema fetch.
    /// The first call on a device records the install time.
    public func configure(
        endpoint: URL,
        appToken: String,
        lockWindow: Bool = false,
        refreshInterval: TimeInterval = 6 * 60 * 60
    ) {
        let config = Configuration(
            endpoint: endpoint,
            appToken: appToken,
            lockWindow: lockWindow,
            refreshInterval: refreshInterval
        )
        let flushed: [ConversionUpdate] = queue.sync {
            self.configuration = config
            self.cache = SchemaCache.load(from: store, appToken: appToken)
            // Token-independent on purpose: the device's conversion windows
            // keep running across a token rotation (see LastFineValueStore),
            // and so does its install.
            self.lastFineValue = LastFineValueStore.load(from: store, for: .install)
            self.lastReengagementFineValue = LastFineValueStore.load(from: store, for: .reengagement)
            self.goals = DeviceGoalState.load(from: store)
            if self.goals.installAt == nil {
                let now = clock()
                self.goals.installAt = now
                self.goals.lastReceivedAt = max(self.goals.lastReceivedAt, now)
                self.goals.save(to: store)
            }
            return flushPending()
        }
        submitAll(flushed)
        refreshSchema()
    }

    /// The schema last fetched, if any (cached or fetched). `logEvent`
    /// encodes with it only while it is at most `maxSchemaAge` old.
    public var currentSchema: P202AttributionSchema? {
        return queue.sync { cache.schema }
    }

    /// The customer id `setCustomerId` stored, if any.
    public var customerId: P202CustomerId? {
        return queue.sync { CustomerIdStore.load(from: store) }
    }

    /// Record who the app's user is, as a customer id your own server has
    /// signed (`cust_sig`, documentation/features/visitor-identity.md). The
    /// signature is what makes the id link one person across devices; there
    /// is no unsigned form, because the app token is public and an id
    /// anyone can send links nothing. Kept across launches until
    /// `clearCustomerId()`; carried as the `customer` object of the SDK wire
    /// contract. Throws `P202CustomerId.Invalid` for an id or signature the
    /// server would refuse, and stores nothing then.
    public func setCustomerId(_ id: String, signature: String, type: P202CustomerId.IdType = .custom) throws {
        let customer = try P202CustomerId(id: id, signature: signature, type: type)
        queue.sync {
            CustomerIdStore.save(customer, to: store)
        }
    }

    /// Forget the customer id (for example on sign-out).
    public func clearCustomerId() {
        queue.sync {
            store.removeValue(forKey: CustomerIdStore.key)
        }
    }

    /// Report an event, with optional properties and revenue, for the goals
    /// to evaluate. Returns the update handed to the frameworks, or nil when
    /// the event reached no encoded goal (a deliberate no-op) or is waiting
    /// for the first schema. The return value exists for the app's own
    /// logging.
    ///
    /// Throws `EventError` for an event the server's rules refuse (nothing
    /// is recorded then), `SDKError.notConfigured` before `configure`, and
    /// `SDKError.pendingEventsFull` when no usable schema is held (none has
    /// arrived, or the one held is older than `maxSchemaAge`) and the
    /// waiting queue is full.
    ///
    /// `conversionTypes` scopes the update to AdAttributionKit's install
    /// and/or re-engagement postback (iOS 18+). Leave it nil for the install
    /// postback, which is the only one every supported system has. An update
    /// that leaves out `.install` is not sent to SKAdNetwork at all and is
    /// dropped on iOS 17.4-17.x: a re-engagement conversion must not
    /// overwrite the install postback's value.
    @discardableResult
    public func logEvent(
        _ name: String,
        properties: [String: EventValue] = [:],
        revenue: Double? = nil,
        conversionTypes: [ConversionUpdate.ConversionType]? = nil
    ) throws -> ConversionUpdate? {
        try Self.validate(name: name, properties: properties, revenue: revenue)

        let updates: [ConversionUpdate] = try queue.sync {
            guard configuration != nil else {
                throw SDKError.notConfigured
            }
            // Never earlier than an event already evaluated: a clock set
            // back must not make a new event sort before an old one, which
            // the incremental evaluation cannot take back.
            let now = max(clock(), goals.lastReceivedAt)
            let event = GoalEvent(
                eventId: UUID().uuidString,
                name: name,
                occurredAt: now,
                receivedAt: now,
                properties: properties,
                revenue: revenue.map { EventValue.double($0) }
            )
            guard let schema = usableSchema(), goals.installEvaluated else {
                guard goals.pending.count < Self.maxPendingEvents else {
                    throw SDKError.pendingEventsFull
                }
                goals.pending.append(DeviceGoalState.Pending(
                    event: event,
                    conversionTypes: conversionTypes,
                    reengagementGeneration: goals.currentReengagementGeneration
                ))
                goals.lastReceivedAt = now
                goals.save(to: store)
                return []
            }
            goals.lastReceivedAt = now
            let updates = evaluate(event, conversionTypes: conversionTypes, schema: schema)
            goals.save(to: store)
            return updates
        }

        refreshIfStale()

        submitAll(updates)
        // One update, or — for an event scoped to both postbacks whose
        // progress differs — the install postback's (both are submitted).
        return updates.first(where: { $0.includesInstall }) ?? updates.first
    }

    /// Signal install attribution before any conversion event has happened
    /// (the modern replacement for registerAppForAdNetworkAttribution): sets
    /// conversion value 0 on iOS 15.4+, where the API exists.
    ///
    /// Safe to call at any point in the app's life, including from
    /// `applicationDidBecomeActive`, which is where developers habitually
    /// put it: it re-asserts the value already reported rather than
    /// resetting to 0. Returns the update handed to the frameworks, like
    /// `logEvent`, so the app can log what was reported.
    @discardableResult
    public func registerAttribution() -> ConversionUpdate {
        let update = ConversionUpdate(
            fineValue: queue.sync { lastFineValue ?? 0 },
            coarseValue: nil,
            usedFineFallback: false
        )
        submitAll([update])
        return update
    }

    /// Fetch the schema now. Uses If-None-Match, so an unchanged schema
    /// costs a 304. Safe to call at app foreground; `logEvent` also
    /// refreshes opportunistically when the cache is older than
    /// `refreshInterval`. An explicit call always fetches (overlapping GETs
    /// are idempotent and cheap); only the opportunistic path deduplicates
    /// against an in-flight fetch — so a caller-provided completion is
    /// always invoked exactly once.
    public func refreshSchema(completion: ((Result<P202AttributionSchema, Error>) -> Void)? = nil) {
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
            req.setValue(config.appToken, forHTTPHeaderField: Self.appTokenHeader)
            if let etag = cache.etag {
                req.setValue(etag, forHTTPHeaderField: "If-None-Match")
            }
            return (req, config.appToken)
        }
        guard let prepared else {
            completion?(.failure(SDKError.notConfigured))
            return
        }
        onFetch?(prepared.request)

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

    /// Apply one fetch's outcome to the cache, and evaluate any events that
    /// were waiting for a schema. Internal (not private) so tests can drive
    /// it with crafted responses — the stale-token guard is exactly the kind
    /// of seam a unit test must exercise for real.
    func finishRefresh(
        requestToken: String,
        data: Data?,
        response: URLResponse?,
        error: Error?
    ) -> Result<P202AttributionSchema, Error> {
        let (result, flushed): (Result<P202AttributionSchema, Error>, [ConversionUpdate]) = queue.sync {
            guard let config = configuration, config.appToken == requestToken else {
                // A different token is configured now (or none). This
                // response belongs to the old configuration: drop it without
                // touching the cache or the new fetch's in-flight marker.
                return (.failure(SDKError.superseded), [])
            }
            refreshInFlight = false
            lastAttemptAt = clockDate()
            if let error {
                return (.failure(error), [])
            }
            let status = (response as? HTTPURLResponse)?.statusCode ?? 0
            if status == 304, let cached = cache.schema {
                cache.fetchedAt = clockDate()
                cache.save(to: store, appToken: config.appToken)
                return (.success(cached), flushPending())
            }
            if status == 304 {
                // "Not modified" for a document this device does not hold:
                // the ETag it sent vouches for nothing. It is dropped, so the
                // next fetch — started below, and every one after — is
                // unconditional and brings the document itself.
                cache.etag = nil
                cache.save(to: store, appToken: config.appToken)
                refetchUnconditionally = true
                return (.failure(SDKError.unexpectedStatus(304)), [])
            }
            guard status == 200 else {
                return (.failure(SDKError.unexpectedStatus(status)), [])
            }
            guard let data, !data.isEmpty else {
                return (.failure(SDKError.emptyResponse), [])
            }
            do {
                let schema = try P202AttributionSchema.decode(responseBody: data)
                cache.store(body: data, schema: schema)
                cache.fetchedAt = clockDate()
                cache.save(to: store, appToken: config.appToken)
                return (.success(schema), flushPending())
            } catch {
                return (.failure(error), [])
            }
        }
        submitAll(flushed)
        let refetch: Bool = queue.sync {
            defer { refetchUnconditionally = false }
            return refetchUnconditionally
        }
        if refetch {
            refreshSchema()
        }
        return result
    }

    // MARK: - Goal evaluation (call on `queue`)

    /// Evaluate the install (once) and every waiting event, in order, now
    /// that a usable schema is here. Returns the updates to submit.
    private func flushPending() -> [ConversionUpdate] {
        guard let schema = usableSchema(), let installAt = goals.installAt else {
            return []
        }
        var updates: [ConversionUpdate] = []
        if !goals.installEvaluated {
            updates += evaluate(.install(at: installAt), conversionTypes: nil, schema: schema)
            goals.installEvaluated = true
        }
        let waiting = goals.pending
        goals.pending = []
        for item in waiting {
            guard let item = Self.withinLifecycle(item, generation: goals.currentReengagementGeneration) else {
                continue
            }
            updates += evaluate(item.event, conversionTypes: item.conversionTypes, schema: schema)
        }
        goals.save(to: store)
        return updates
    }

    /// A queued event as it still counts: scoped as it was logged, less the
    /// re-engagement postback when a re-engagement lifecycle began after it
    /// was logged — that postback belongs to the lifecycle the event came
    /// from, which has ended, and crediting the event to the new one would
    /// count a lifecycle-1 event in lifecycle 2. nil when nothing is left
    /// (an event scoped to re-engagement alone).
    static func withinLifecycle(_ item: DeviceGoalState.Pending, generation current: Int) -> DeviceGoalState.Pending? {
        guard (item.reengagementGeneration ?? 0) != current, let types = item.conversionTypes, types.contains(.reengagement) else {
            return item
        }
        let kept = types.filter { $0 != .reengagement }
        guard !kept.isEmpty else {
            return nil
        }
        var narrowed = item
        narrowed.conversionTypes = kept
        return narrowed
    }

    /// One event through the evaluator of each postback it is scoped to;
    /// the updates their eligible outcomes encode to.
    ///
    /// Each postback keeps its own goal progress (DeviceGoalState): the
    /// install postback's from the install on, the re-engagement postback's
    /// from the start of its conversion lifecycle (`beginReengagement()`).
    /// Shared, a goal reached for one postback — a `once` goal, a count, a
    /// sum — would already be spent when the other's events arrive, and a
    /// re-engagement event would advance the install postback's funnel. When
    /// both postbacks reach the same value it is one update scoped to both;
    /// when they differ, one update per postback.
    private func evaluate(
        _ event: GoalEvent,
        conversionTypes: [ConversionUpdate.ConversionType]?,
        schema: P202AttributionSchema
    ) -> [ConversionUpdate] {
        let subject = GoalSubject(kind: .install, clickAt: nil, installAt: goals.installAt)
        let types = conversionTypes ?? [.install]
        var decisions: [(type: ConversionUpdate.ConversionType, update: ConversionUpdate)] = []
        for type in ConversionUpdate.ConversionType.allCases where types.contains(type) {
            guard let result = try? GoalEvaluator.continueFrom(schema.goals, subject: subject, state: goals.progress(for: type), events: [event]) else {
                // Only a document with a goal twice can get here, and decode()
                // refuses that; the state is left as it was.
                continue
            }
            goals.setProgress(result.state, for: type)
            // A coarse-only mapping falls back to the last fine value of the
            // postback being updated — never the other postback's.
            let history = type == .install ? lastFineValue : lastReengagementFineValue
            if let decision = ConversionUpdate.resolve(outcomes: result.outcomes, in: schema, lastFineValue: history) {
                decisions.append((type, decision))
            }
        }
        guard !decisions.isEmpty else {
            return []
        }
        var updates: [ConversionUpdate]
        if decisions.count == 2, decisions[0].update == decisions[1].update {
            updates = [decisions[0].update.scoped(to: conversionTypes)]
        } else if decisions.count == 1, decisions[0].type == .install, conversionTypes == nil {
            updates = [decisions[0].update]
        } else {
            updates = decisions.map { $0.update.scoped(to: [$0.type]) }
        }
        for update in updates {
            // Persist only on change: repeated events with the same fine
            // value must not write the store on every call.
            if !update.usedFineFallback {
                if update.includesInstall && lastFineValue != update.fineValue {
                    lastFineValue = update.fineValue
                    LastFineValueStore.save(update.fineValue, to: store, for: .install)
                }
                if update.includesReengagement && lastReengagementFineValue != update.fineValue {
                    lastReengagementFineValue = update.fineValue
                    LastFineValueStore.save(update.fineValue, to: store, for: .reengagement)
                }
            }
        }
        return updates
    }

    /// Start a new re-engagement conversion lifecycle: call when the app is
    /// opened from a re-engagement ad (AdAttributionKit, iOS 18+). The
    /// re-engagement postback's goal progress and its last fine value start
    /// over, as the postback itself does; the install postback's are not
    /// touched.
    public func beginReengagement() {
        queue.sync {
            goals.setProgress(EvaluationState(), for: .reengagement)
            // Events still waiting for a schema were logged in the lifecycle
            // this one ends; the new generation keeps them out of it.
            goals.reengagementGeneration = goals.currentReengagementGeneration + 1
            goals.save(to: store)
            lastReengagementFineValue = nil
            store.removeValue(forKey: LastFineValueStore.key(for: .reengagement))
        }
    }

    static func validate(name: String, properties: [String: EventValue], revenue: Double?) throws {
        guard GoalDefinition.isEventName(name) else {
            throw EventError.invalidName(name)
        }
        guard properties.count <= GoalEvent.maxProperties else {
            throw EventError.tooManyProperties(properties.count)
        }
        for (key, value) in properties {
            guard GoalDefinition.isPropName(key) else {
                throw EventError.invalidPropertyName(key)
            }
            switch value {
            case let .string(s) where s.utf8.count > GoalDefinition.maxString:
                throw EventError.invalidPropertyValue(key)
            case let .double(d) where !d.isFinite:
                throw EventError.invalidPropertyValue(key)
            default:
                break
            }
        }
        if let revenue, !revenue.isFinite {
            throw EventError.invalidRevenue
        }
    }

    private func submitAll(_ updates: [ConversionUpdate]) {
        let lockWindow = queue.sync { configuration?.lockWindow ?? false }
        for update in updates {
            onSubmit?(update)
            Self.submit(update, lockWindow: lockWindow)
        }
    }

    // MARK: - Internals

    /// The header the app token travels in, and only there.
    static let appTokenHeader = "X-P202-App-Token"

    static func schemaURL(endpoint: URL) -> URL {
        return endpoint
            .appendingPathComponent("api")
            .appendingPathComponent("v3")
            .appendingPathComponent("apps")
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
            // Never waits so long that the document ages out of use between
            // two attempts.
            let interval = min(config.refreshInterval, Self.maxSchemaAge / 2)
            let since = clockDate().timeIntervalSince(last)
            return since < 0 || since > interval
        }
        if stale {
            refreshSchema()
        }
    }

    /// Hand one update to both frameworks. Apple's guidance for an app that
    /// cannot know which framework its ad networks integrate is to call
    /// both; the system ignores the one with no pending postback. (Calling
    /// SKAdNetwork alone is bridged into AdAttributionKit by the system, but
    /// only AdAttributionKit's own API can scope an update to a
    /// re-engagement postback.)
    private static func submit(_ update: ConversionUpdate, lockWindow: Bool) {
        if update.includesInstall {
            submitToSKAdNetwork(update, lockWindow: lockWindow)
        }
        submitToAdAttributionKit(update, lockWindow: lockWindow)
    }

    /// The one place AdAttributionKit is touched. On non-iOS platforms
    /// (tests, this repository's CI) it compiles to a no-op. Which of the
    /// framework's two call shapes to use — and whether to call it at all —
    /// is decided by `ConversionUpdate.adAttributionKitDelivery(scopedAPIAvailable:)`,
    /// which is pure so that decision is covered off-iOS; the only thing
    /// left here is answering the availability question. Errors are
    /// swallowed on purpose: the framework throws when the app has no
    /// pending postback to update (no ad was seen), which is the same
    /// silent outcome SKAdNetwork's completion handler reports.
    private static func submitToAdAttributionKit(_ update: ConversionUpdate, lockWindow: Bool) {
        #if os(iOS) && canImport(AdAttributionKit)
        guard #available(iOS 17.4, *), Postback.isSupported else {
            return
        }
        if #available(iOS 18.0, *) {
            // Always the scoped API here, even for an unscoped update: the
            // overloads below carry no conversion types, and the system then
            // updates every postback type — including the re-engagement one,
            // whose value this SDK tracks separately.
            guard case .scoped(let types) = update.adAttributionKitDelivery(scopedAPIAvailable: true) else {
                return
            }
            Task {
                do {
                    try await Postback.updateConversionValue(PostbackUpdate(
                        fineConversionValue: update.fineValue,
                        lockPostback: lockWindow,
                        coarseConversionValue: update.coarseValue?.aakValue,
                        conversionTypes: types.map { $0.aakValue }
                    ))
                } catch {
                    // No pending postback (or a framework refusal): nothing
                    // to report, exactly as with SKAdNetwork.
                }
            }
            return
        }
        // iOS 17.4-17.x: only the unscoped overloads exist, and the only
        // postback they can reach is the install one. A re-engagement-scoped
        // update is therefore dropped rather than applied to the install
        // postback — the value submit() just declined to touch by skipping
        // SKAdNetwork.
        guard case .unscoped = update.adAttributionKitDelivery(scopedAPIAvailable: false) else {
            return
        }
        Task {
            do {
                if let coarse = update.coarseValue {
                    try await Postback.updateConversionValue(
                        update.fineValue,
                        coarseConversionValue: coarse.aakValue,
                        lockPostback: lockWindow
                    )
                } else {
                    try await Postback.updateConversionValue(update.fineValue, lockPostback: lockWindow)
                }
            } catch {
                // As above: no pending postback is not an error the app can
                // act on.
            }
        }
        #endif
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
private extension P202AttributionSchema.CoarseValue {
    var skValue: SKAdNetwork.CoarseConversionValue {
        switch self {
        case .low: return .low
        case .medium: return .medium
        case .high: return .high
        }
    }
}
#endif

#if os(iOS) && canImport(AdAttributionKit)
@available(iOS 17.4, *)
private extension P202AttributionSchema.CoarseValue {
    var aakValue: CoarseConversionValue {
        switch self {
        case .low: return .low
        case .medium: return .medium
        case .high: return .high
        }
    }
}

@available(iOS 18.0, *)
private extension ConversionUpdate.ConversionType {
    var aakValue: PostbackUpdate.ConversionType {
        switch self {
        case .install: return .install
        case .reengagement: return .reengagement
        }
    }
}
#endif

import Foundation

/// One goal as the evaluator sees it: every version it has had, the time
/// each started to apply, and the span of arrivals it evaluates
/// (`[startsAt, endsAt)`). Definitions are held raw, so a damaged one
/// disables that version with its reason and never the other goals.
public struct GoalSpec: Equatable, Sendable {
    public struct Version: Equatable, Sendable {
        public let version: Int
        public let effectiveAt: Int
        public let definition: JSONValue

        public init(version: Int, effectiveAt: Int, definition: JSONValue) {
            self.version = version
            self.effectiveAt = effectiveAt
            self.definition = definition
        }
    }

    public let goalId: Int
    public let versions: [Version]
    public let startsAt: Int
    public let endsAt: Int?

    public init(goalId: Int, versions: [Version], startsAt: Int = 0, endsAt: Int? = nil) {
        self.goalId = goalId
        self.versions = versions
        self.startsAt = startsAt
        self.endsAt = endsAt
    }
}

/// Whose progress is tracked. On a device it is always the install: the
/// install time is the first launch the SDK saw, and there is no click
/// (SKAdNetwork never tells the app which ad it came from), so a goal
/// windowed `from: click` is ineligible there, as it is for an organic
/// install on the server.
public struct GoalSubject: Equatable, Sendable {
    public enum Kind: String, Sendable {
        case click, install
    }

    public let kind: Kind
    public let clickAt: Int?
    public let installAt: Int?
    /// goal id → the version a re-evaluation rebased this subject onto.
    public let rebases: [Int: Int]

    public init(kind: Kind, clickAt: Int?, installAt: Int?, rebases: [Int: Int] = [:]) {
        self.kind = kind
        self.clickAt = clickAt
        self.installAt = installAt
        self.rebases = rebases
    }
}

/// One reported event. `occurredAt` is the reporter's clock and
/// `receivedAt` the receiver's; the evaluation time is the earlier of the
/// two, so a clock can move an event earlier but never into a window it
/// missed.
public struct GoalEvent: Equatable, Sendable, Codable {
    public static let installEventId = "@install"
    public static let maxProperties = 32

    public let eventId: String
    public let name: String?
    public let occurredAt: Int
    public let receivedAt: Int
    public let properties: [String: EventValue]
    public let revenue: EventValue?
    public let isInstall: Bool

    public init(
        eventId: String,
        name: String?,
        occurredAt: Int,
        receivedAt: Int,
        properties: [String: EventValue] = [:],
        revenue: EventValue? = nil,
        isInstall: Bool = false
    ) {
        self.eventId = eventId
        self.name = name
        self.occurredAt = occurredAt
        self.receivedAt = receivedAt
        self.properties = properties
        self.revenue = revenue
        self.isInstall = isInstall
    }

    /// The install itself, as the one event an install trigger matches.
    public static func install(at time: Int) -> GoalEvent {
        return GoalEvent(eventId: installEventId, name: nil, occurredAt: time, receivedAt: time, isInstall: true)
    }

    public var effectiveAt: Int {
        return min(occurredAt, receivedAt)
    }

    /// `$revenue` reads the event's own revenue field.
    public func property(_ prop: String) -> EventValue? {
        if prop == GoalDefinition.revenueProp {
            return revenue
        }
        return properties[prop]
    }

    /// Evaluation order: effective time, then arrival, then event id bytes.
    public static func precedes(_ a: GoalEvent, _ b: GoalEvent) -> Bool {
        if a.effectiveAt != b.effectiveAt { return a.effectiveAt < b.effectiveAt }
        if a.receivedAt != b.receivedAt { return a.receivedAt < b.receivedAt }
        return Array(a.eventId.utf8).lexicographicallyPrecedes(Array(b.eventId.utf8))
    }
}

/// A property value an app reports: a string (at most 255 bytes), a finite
/// number or a bool — nothing nested. Integers and fractional numbers are
/// kept apart, as the server keeps them.
public enum EventValue: Equatable, Sendable, Codable {
    case string(String)
    case int(Int)
    case double(Double)
    case bool(Bool)

    var json: JSONValue {
        switch self {
        case let .string(s): return .string(s)
        case let .int(i): return .int(i)
        case let .double(d): return .double(d)
        case let .bool(b): return .bool(b)
        }
    }

    /// A JSON scalar as an event value, or nil for anything else.
    public init?(json: JSONValue) {
        switch json {
        case let .string(s): self = .string(s)
        case let .int(i): self = .int(i)
        case let .double(d) where d.isFinite: self = .double(d)
        case let .bool(b): self = .bool(b)
        default: return nil
        }
    }
}

/// The n-th time a goal version was reached by a subject, and by which
/// event. Values are the goal's own, in units of 0.00001; what a campaign
/// pays is never the device's business.
public struct GoalOutcome: Equatable, Sendable {
    public let goalId: Int
    public let version: Int
    public let n: Int
    public let eventId: String
    public let reachedAt: Int
    public let valueUnits: Int?
    public let valueSource: String
    public let valueNote: String?
    public let ineligibleReason: String?

    public var isEligible: Bool {
        return ineligibleReason == nil
    }

    /// The vector format (tests/fixtures/app-sdk-contract/goals/README.md).
    public var json: JSONValue {
        return .object([
            ("goal_id", .int(goalId)),
            ("version", .int(version)),
            ("n", .int(n)),
            ("event_id", .string(eventId)),
            ("reached_at", .int(reachedAt)),
            ("value", valueUnits.map { .string(Amount.format($0)) } ?? .null),
            ("value_source", .string(valueSource)),
            ("value_note", valueNote.map { .string($0) } ?? .null),
            ("ineligible_reason", ineligibleReason.map { .string($0) } ?? .null),
        ])
    }
}

/// What the evaluator carries from one event to the next for a subject:
/// per (goal, version) the count, the running sum, how many times it has
/// been reached and when first; and which goals were reached eligibly.
/// Codable, because the device keeps it between launches.
public struct EvaluationState: Equatable, Sendable, Codable {
    public struct Progress: Equatable, Sendable, Codable {
        public var goalId: Int
        public var version: Int
        public var count: Int
        public var sum: Int
        public var times: Int
        public var reachedAt: Int?
    }

    /// Keyed "<goal>:<version>".
    public var progress: [String: Progress] = [:]
    public var reached: Set<Int> = []

    public init() {}

    /// The vector format, ordered by goal then version.
    public var progressJSON: JSONValue {
        let rows = progress.values.sorted { ($0.goalId, $0.version) < ($1.goalId, $1.version) }
        return .array(rows.map {
            .object([
                ("goal_id", .int($0.goalId)),
                ("version", .int($0.version)),
                ("count", .int($0.count)),
                ("sum", .string(Amount.format($0.sum))),
                ("times_reached", .int($0.times)),
                ("reached_at", $0.reachedAt.map { .int($0) } ?? .null),
            ])
        })
    }
}

public struct DisabledGoal: Equatable, Hashable, Sendable {
    public let goalId: Int
    public let version: Int
    /// invalid_definition, prerequisite_missing or cycle.
    public let reason: String

    public var json: JSONValue {
        return .object([("goal_id", .int(goalId)), ("version", .int(version)), ("reason", .string(reason))])
    }
}

public struct EvaluationResult: Equatable, Sendable {
    public let outcomes: [GoalOutcome]
    public let disabled: [DisabledGoal]
    public let state: EvaluationState
}

/// The goal evaluator: the device's copy of the server's
/// `Prosper202\Goals\GoalEvaluator`, held to the same vectors. Pure — no
/// clock, no storage — so a test runs it exactly as the SDK does.
///
/// The rules are README.md's in tests/fixtures/app-sdk-contract/goals/;
/// in short, per event in evaluation order: one version per goal (the
/// newest effective when the event arrived, inside the goal's span, raised
/// by a rebase); goals visited prerequisites first; a goal counts the event
/// when its trigger and predicates match, its `after` goals were reached
/// eligibly, and the event is inside its window; reaching is `count >= n·N`
/// (or `sum >= n·S`) up to the repeat cap.
public enum GoalEvaluator {
    public enum InputError: Error, Equatable {
        case duplicateEvent(String)
        case duplicateGoal(Int)
        case unknownRebase(goal: Int, version: Int)
    }

    /// Evaluate every event of a subject from nothing. For an install
    /// subject the install itself is the first event.
    public static func evaluateAll(_ specs: [GoalSpec], subject: GoalSubject, events: [GoalEvent]) throws -> EvaluationResult {
        var all = events
        if subject.kind == .install, let installAt = subject.installAt {
            all.append(.install(at: installAt))
        }
        return try fold(specs, subject: subject, events: all, state: EvaluationState())
    }

    /// Evaluate more events from the state an earlier evaluation left. Every
    /// new event must sort after every event the state has seen.
    public static func continueFrom(_ specs: [GoalSpec], subject: GoalSubject, state: EvaluationState, events: [GoalEvent]) throws -> EvaluationResult {
        return try fold(specs, subject: subject, events: events, state: state)
    }

    private static func fold(_ specs: [GoalSpec], subject: GoalSubject, events unsorted: [GoalEvent], state initial: EvaluationState) throws -> EvaluationResult {
        var seen = Set<String>()
        for event in unsorted where !seen.insert(event.eventId).inserted {
            throw InputError.duplicateEvent(event.eventId)
        }
        let events = unsorted.sorted(by: GoalEvent.precedes)

        var bySpec: [Int: GoalSpec] = [:]
        for spec in specs {
            if bySpec[spec.goalId] != nil {
                throw InputError.duplicateGoal(spec.goalId)
            }
            bySpec[spec.goalId] = spec
        }
        let goalIds = bySpec.keys.sorted()

        // Parse every version once; an unusable one is disabled with its
        // reason and the others still evaluate.
        var parsed: [Int: [Int: GoalDefinition?]] = [:]
        var disabled = Set<DisabledGoal>()
        for goalId in goalIds {
            for v in bySpec[goalId]!.versions {
                var def: GoalDefinition?
                do {
                    def = try GoalDefinition.parse(v.definition, selfId: goalId)
                } catch {
                    disabled.insert(DisabledGoal(goalId: goalId, version: v.version, reason: "invalid_definition"))
                    parsed[goalId, default: [:]][v.version] = .some(nil)
                    continue
                }
                if let d = def, d.after.contains(where: { bySpec[$0] == nil }) {
                    disabled.insert(DisabledGoal(goalId: goalId, version: v.version, reason: "prerequisite_missing"))
                    def = nil
                }
                parsed[goalId, default: [:]][v.version] = .some(def)
            }
        }
        for (goalId, version) in subject.rebases where bySpec[goalId] != nil {
            if parsed[goalId]?[version] == nil {
                throw InputError.unknownRebase(goal: goalId, version: version)
            }
        }

        var state = initial
        var outcomes: [GoalOutcome] = []
        for event in events {
            // Rule 1: one version per goal for this event.
            var selected: [Int: (Int, GoalDefinition)] = [:]
            for goalId in goalIds {
                guard let version = versionFor(bySpec[goalId]!, subject: subject, event: event),
                      let entry = parsed[goalId]?[version], let def = entry else {
                    continue
                }
                selected[goalId] = (version, def)
            }
            // Rule 2: prerequisites first.
            let (order, cyclic) = self.order(selected)
            for goalId in order {
                let (version, def) = selected[goalId]!
                outcomes += step(goalId, version, def, subject, event, &state)
            }
            for goalId in cyclic {
                disabled.insert(DisabledGoal(goalId: goalId, version: selected[goalId]!.0, reason: "cycle"))
            }
        }

        let sortedDisabled = disabled.sorted { ($0.goalId, $0.version, $0.reason) < ($1.goalId, $1.version, $1.reason) }
        return EvaluationResult(outcomes: outcomes, disabled: sortedDisabled, state: state)
    }

    /// The version a goal evaluates an event under, or nil.
    static func versionFor(_ spec: GoalSpec, subject: GoalSubject, event: GoalEvent) -> Int? {
        if let endsAt = spec.endsAt, event.receivedAt >= endsAt {
            return nil
        }
        var natural: Int?
        if event.receivedAt >= spec.startsAt {
            for v in spec.versions where v.effectiveAt <= event.receivedAt && (natural == nil || v.version > natural!) {
                natural = v.version
            }
        }
        if let rebase = subject.rebases[spec.goalId], natural == nil || rebase > natural! {
            return rebase
        }
        return natural
    }

    /// Kahn's algorithm, lowest goal id first among the ready ones; goals
    /// left over sit on a cycle.
    private static func order(_ selected: [Int: (Int, GoalDefinition)]) -> ([Int], [Int]) {
        var waitingOn: [Int: Int] = [:]
        var dependents: [Int: [Int]] = [:]
        for (goalId, entry) in selected {
            waitingOn[goalId] = 0
            for prereq in entry.1.after where selected[prereq] != nil {
                waitingOn[goalId, default: 0] += 1
                dependents[prereq, default: []].append(goalId)
            }
        }
        var ready = waitingOn.filter { $0.value == 0 }.map(\.key)
        var order: [Int] = []
        while !ready.isEmpty {
            ready.sort()
            let goalId = ready.removeFirst()
            order.append(goalId)
            for dependent in dependents[goalId] ?? [] {
                waitingOn[dependent]! -= 1
                if waitingOn[dependent] == 0 {
                    ready.append(dependent)
                }
            }
        }
        let cyclic = waitingOn.filter { $0.value > 0 }.map(\.key).sorted()
        return (order, cyclic)
    }

    /// Rules 3–5 for one goal version and one event.
    private static func step(_ goalId: Int, _ version: Int, _ def: GoalDefinition, _ subject: GoalSubject, _ event: GoalEvent, _ state: inout EvaluationState) -> [GoalOutcome] {
        if def.triggerInstall != event.isInstall {
            return []
        }
        if !def.triggerInstall {
            guard event.name == def.triggerEvent else {
                return []
            }
            for predicate in def.where where !holds(predicate, event) {
                return []
            }
        }
        for prereq in def.after where !state.reached.contains(prereq) {
            return []
        }

        let t = event.effectiveAt
        var ineligible: String?
        if let days = def.withinDays {
            let anchor = def.withinFrom == .click ? subject.clickAt : subject.installAt
            if let anchor {
                if t < anchor || t - anchor >= days * 86_400 {
                    return []
                }
            } else {
                ineligible = def.withinFrom == .click ? "no_click" : "no_install"
            }
        }

        var sumUnits: Int?
        if case let .sum(prop, _) = def.threshold {
            guard let units = units(event.property(prop)) else {
                return []
            }
            sumUnits = units
        }

        let key = "\(goalId):\(version)"
        var p = state.progress[key] ?? .init(goalId: goalId, version: version, count: 0, sum: 0, times: 0, reachedAt: nil)
        p.count += 1
        if let sumUnits {
            p.sum += sumUnits
        }

        var out: [GoalOutcome] = []
        while p.times < def.cap {
            let next = p.times + 1
            let crossed: Bool
            switch def.threshold {
            case let .count(n):
                let (need, overflow) = next.multipliedReportingOverflow(by: n)
                crossed = !overflow && p.count >= need
            case let .sum(_, gte):
                let (need, overflow) = next.multipliedReportingOverflow(by: gte)
                crossed = !overflow && p.sum >= need
            }
            if !crossed {
                break
            }
            p.times = next
            if p.reachedAt == nil {
                p.reachedAt = t
            }
            let (valueUnits, source, note) = value(def, event)
            out.append(GoalOutcome(
                goalId: goalId,
                version: version,
                n: next,
                eventId: event.eventId,
                reachedAt: t,
                valueUnits: valueUnits,
                valueSource: source,
                valueNote: note,
                ineligibleReason: ineligible
            ))
            if ineligible == nil {
                state.reached.insert(goalId)
            }
        }
        state.progress[key] = p
        return out
    }

    private static func holds(_ predicate: GoalDefinition.Predicate, _ event: GoalEvent) -> Bool {
        let actual = event.property(predicate.prop)?.json
        if predicate.op == .exists {
            return actual != nil
        }
        guard let actual, let want = predicate.value else {
            return false
        }
        switch predicate.op {
        case .eq: return equal(actual, want)
        case .neq: return !equal(actual, want)
        case .in: return (want.phpList ?? []).contains { equal(actual, $0) }
        case .gt, .gte, .lt, .lte:
            guard actual.isNumber, want.isNumber, let a = actual.doubleValue, let b = want.doubleValue else {
                return false
            }
            switch predicate.op {
            case .gt: return a > b
            case .gte: return a >= b
            case .lt: return a < b
            default: return a <= b
            }
        case .exists:
            return true
        }
    }

    /// Typed equality: numbers by value (3 equals 3.0), strings byte for
    /// byte, bools as bools; nothing across types.
    private static func equal(_ a: JSONValue, _ b: JSONValue) -> Bool {
        if a.isNumber && b.isNumber {
            return a.doubleValue == b.doubleValue
        }
        if case let .string(x) = a, case let .string(y) = b {
            return Array(x.utf8) == Array(y.utf8)
        }
        if case let .bool(x) = a, case let .bool(y) = b {
            return x == y
        }
        return false
    }

    /// A property value in units, or nil when it is not a number the ledger
    /// can hold.
    private static func units(_ v: EventValue?) -> Int? {
        guard let json = v?.json, json.isNumber, let d = json.doubleValue, abs(d) <= 99_999_999_999_999.0 else {
            return nil
        }
        return Amount.units(number: json)
    }

    private static func value(_ def: GoalDefinition, _ event: GoalEvent) -> (Int?, String, String?) {
        switch def.value {
        case let .fixed(units):
            return (units, "fixed", nil)
        case .none:
            return (nil, "none", nil)
        case let .fromProperty(prop):
            guard let raw = event.property(prop)?.json else {
                return (nil, "property", "missing")
            }
            guard raw.isNumber, let d = raw.doubleValue else {
                return (nil, "property", "not_a_number")
            }
            if d < 0 {
                return (nil, "property", "negative")
            }
            guard let u = units(event.property(prop)), u <= Amount.maxUnits else {
                return (nil, "property", "out_of_range")
            }
            return (u, "property", nil)
        }
    }
}

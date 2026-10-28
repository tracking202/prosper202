import Foundation

/// One validated goal definition: the device's copy of the server's
/// `Prosper202\Goals\GoalDefinition`, held to the same vectors
/// (tests/fixtures/app-sdk-contract/goals/definitions.json, whose README is
/// the specification).
///
/// Strict at every level, as the server is: an unknown key is an error, and
/// nothing is cast — a count of `"3"` or `3.0` is refused. The schema
/// document only ever carries definitions the server accepted, so on a
/// device a refusal means the document is damaged, and the goal is disabled
/// (`invalid_definition`) rather than guessed at.
///
/// The document the server sends a device is evaluation-only: it strips
/// every `value` (plan §4.3), so a definition read from it has value
/// `none`. The type still parses values, because the vectors carry them.
public struct GoalDefinition: Equatable, Sendable {
    public enum Op: String, Sendable, CaseIterable {
        case eq, neq, gt, gte, lt, lte, `in`, exists
    }

    public struct Predicate: Equatable, Sendable {
        public let prop: String
        public let op: Op
        /// nil for `exists`; a scalar, or a list of scalars for `in`.
        public let value: JSONValue?
    }

    public enum Threshold: Equatable, Sendable {
        case count(Int)
        case sum(prop: String, gteUnits: Int)
    }

    public enum Anchor: String, Sendable {
        case install, click
    }

    public enum Value: Equatable, Sendable {
        case none
        case fixed(units: Int)
        case fromProperty(prop: String)
    }

    public static let maxName = 100
    public static let maxPredicates = 20
    public static let maxAfter = 5
    public static let maxIn = 50
    public static let maxString = 255
    public static let maxCount = 10_000
    public static let maxDays = 3650
    public static let maxRepeat = 10_000
    public static let revenueProp = "$revenue"

    public let name: String
    /// true: `{"install": true}`; the event trigger is then nil.
    public let triggerInstall: Bool
    public let triggerEvent: String?
    public let `where`: [Predicate]
    public let threshold: Threshold
    public let after: [Int]
    public let withinDays: Int?
    public let withinFrom: Anchor?
    /// nil: `once`. Otherwise `each`, with `repeatMax` (nil = unbounded).
    public let repeatEach: Bool
    public let repeatMax: Int?
    public let value: Value

    /// The definition was refused; `errors` maps each JSON path the server's
    /// validator would name to why.
    public struct Invalid: Error, Equatable {
        public let errors: [String: String]
    }

    // MARK: - Parsing

    /// Parse and validate a decoded definition. `selfId` is the goal's own
    /// id, so `after` cannot name the goal itself.
    public static func parse(_ raw: JSONValue, selfId: Int? = nil) throws -> GoalDefinition {
        var e: [String: String] = [:]
        guard raw.isPHPObject else {
            throw Invalid(errors: ["definition": "must be a JSON object"])
        }
        unknownKeys(raw, ["name", "trigger", "threshold", "after", "within", "repeat", "value"], "", &e)

        // name
        var name = ""
        if let rawName = raw["name"] {
            if let s = rawName.stringValue {
                name = trimPHP(s)
                if name.isEmpty {
                    e["name"] = "must not be empty"
                } else if name.unicodeScalars.count > maxName {
                    e["name"] = "must be at most \(maxName) characters"
                } else if name.unicodeScalars.contains(where: { $0.value < 0x20 || $0.value == 0x7F }) {
                    e["name"] = "must be valid UTF-8 text without control characters"
                }
            } else {
                e["name"] = "must be a string"
            }
        } else {
            e["name"] = "is required"
        }

        // trigger
        var triggerInstall = false
        var triggerEvent: String?
        var predicates: [Predicate] = []
        if let t = raw["trigger"] {
            if !t.isPHPObject {
                e["trigger"] = "must be an object: {\"event\": \"<name>\"} or {\"install\": true}"
            } else if t.has("install") {
                unknownKeys(t, ["install"], "trigger", &e)
                if t["install"] != .bool(true) {
                    e["trigger.install"] = "must be true (an install trigger has no other form)"
                }
                triggerInstall = true
            } else {
                unknownKeys(t, ["event", "where"], "trigger", &e)
                if let ev = t["event"] {
                    if let s = ev.stringValue, isEventName(s) {
                        triggerEvent = s
                    } else {
                        e["trigger.event"] = "must be an event name"
                    }
                } else {
                    e["trigger.event"] = "is required unless the trigger is {\"install\": true}"
                }
                if let w = t["where"] {
                    predicates = parseWhere(w, &e)
                }
            }
        } else {
            e["trigger"] = "is required: {\"event\": \"<name>\"} or {\"install\": true}"
        }

        // threshold
        var threshold = Threshold.count(1)
        if let th = raw["threshold"] {
            if !th.isPHPObject {
                e["threshold"] = "must be an object: {\"count\": N} or {\"sum\": {\"prop\": \"<prop>\", \"gte\": <amount>}}"
            } else {
                unknownKeys(th, ["count", "sum"], "threshold", &e)
                let hasCount = th.has("count")
                let hasSum = th.has("sum")
                if hasCount == hasSum {
                    e["threshold"] = "must have exactly one of count or sum"
                } else if hasCount {
                    threshold = .count(intIn(th["count"], 1, maxCount, "threshold.count", &e) ?? 1)
                } else if let s = th["sum"] {
                    if !s.isPHPObject {
                        e["threshold.sum"] = "must be an object: {\"prop\": \"<prop>\", \"gte\": <amount>}"
                        threshold = .sum(prop: "", gteUnits: 0)
                    } else {
                        unknownKeys(s, ["prop", "gte"], "threshold.sum", &e)
                        let prop = self.prop(s["prop"], "threshold.sum.prop", &e)
                        var gte = 0
                        if let g = s["gte"] {
                            let units = amount(g, "threshold.sum.gte", &e)
                            if let units, units <= 0 {
                                e["threshold.sum.gte"] = "must be greater than 0"
                            }
                            gte = units ?? 0
                        } else {
                            e["threshold.sum.gte"] = "is required"
                        }
                        threshold = .sum(prop: prop ?? "", gteUnits: gte)
                    }
                }
            }
        }

        // after
        var after: [Int] = []
        if let a = raw["after"] {
            if let list = a.phpList {
                if list.count > maxAfter {
                    e["after"] = "may name at most \(maxAfter) goals"
                } else {
                    for (i, item) in list.enumerated() {
                        guard let id = item.intValue, id > 0 else {
                            e["after[\(i)]"] = "must be a goal id (a positive integer)"
                            continue
                        }
                        if let selfId, id == selfId {
                            e["after[\(i)]"] = "a goal cannot require itself"
                            continue
                        }
                        if after.contains(id) {
                            e["after[\(i)]"] = "names goal \(id) twice"
                            continue
                        }
                        after.append(id)
                    }
                }
            } else {
                e["after"] = "must be a list of goal ids"
            }
        }

        // within
        var withinDays: Int?
        var withinFrom: Anchor?
        if let w = raw["within"], !w.isNull {
            if !w.isPHPObject {
                e["within"] = "must be an object: {\"days\": N, \"from\": \"install\" | \"click\"}, or null"
            } else {
                unknownKeys(w, ["days", "from"], "within", &e)
                withinDays = intIn(w["days"], 1, maxDays, "within.days", &e)
                if let from = w["from"]?.stringValue, let anchor = Anchor(rawValue: from) {
                    withinFrom = anchor
                } else {
                    e["within.from"] = "must be \"install\" or \"click\""
                }
            }
        }

        // repeat
        var repeatEach = false
        var repeatMax: Int?
        if let r = raw["repeat"] {
            if !r.isPHPObject {
                e["repeat"] = "must be an object: {\"mode\": \"once\"} or {\"mode\": \"each\", \"max\": N}"
            } else {
                unknownKeys(r, ["mode", "max"], "repeat", &e)
                let mode = r["mode"]?.stringValue
                if mode == "once" || mode == "each" {
                    repeatEach = mode == "each"
                } else {
                    e["repeat.mode"] = "must be \"once\" or \"each\""
                }
                if r.has("max") {
                    if mode == "once" {
                        e["repeat.max"] = "applies only to mode \"each\""
                    } else {
                        repeatMax = intIn(r["max"], 1, maxRepeat, "repeat.max", &e)
                    }
                }
            }
        }

        // A sum can jump past many multiples of its threshold in one event
        // (a $1,000 purchase against "every $0.01"), so a sum that repeats
        // must say how many times it can be reached; a count grows by one
        // per event, so its `each` may stay unbounded. The server's rule
        // (GoalDefinition::parse()), path and all.
        if case .sum = threshold, repeatEach, repeatMax == nil, e["repeat.max"] == nil {
            e["repeat.max"] = "is required for a sum threshold with mode \"each\" (1-\(maxRepeat)): one event can cross many multiples of a sum, so a sum goal that repeats needs a bound"
        }

        // value
        var value = Value.none
        if let v = raw["value"] {
            if !v.isPHPObject {
                e["value"] = "must be an object"
            } else {
                switch v["type"]?.stringValue {
                case "fixed":
                    unknownKeys(v, ["type", "amount"], "value", &e)
                    if let a = v["amount"] {
                        value = .fixed(units: amount(a, "value.amount", &e) ?? 0)
                    } else {
                        e["value.amount"] = "is required for a fixed value"
                        value = .fixed(units: 0)
                    }
                case "from_property":
                    unknownKeys(v, ["type", "prop"], "value", &e)
                    let p = v.has("prop") ? prop(v["prop"], "value.prop", &e) : revenueProp
                    value = .fromProperty(prop: p ?? "")
                case "none":
                    unknownKeys(v, ["type"], "value", &e)
                default:
                    e["value.type"] = "must be \"fixed\", \"from_property\" or \"none\""
                }
            }
        }

        if triggerInstall {
            if !predicates.isEmpty {
                e["trigger.where"] = "an install trigger takes no predicates"
            }
            if threshold != .count(1) {
                e["threshold"] = "an install is reached once: the threshold must be {\"count\": 1}"
            }
            if repeatEach {
                e["repeat"] = "an install is reached once: repeat must be \"once\""
            }
            if !after.isEmpty {
                e["after"] = "an install trigger cannot wait for other goals"
            }
            if withinDays != nil {
                e["within"] = "an install trigger takes no window"
            }
            if case .fromProperty = value {
                e["value.type"] = "an install carries no properties or revenue: use \"fixed\" or \"none\""
            }
        }

        if !e.isEmpty {
            throw Invalid(errors: e)
        }
        return GoalDefinition(
            name: name,
            triggerInstall: triggerInstall,
            triggerEvent: triggerEvent,
            where: predicates,
            threshold: threshold,
            after: after,
            withinDays: withinDays,
            withinFrom: withinFrom,
            repeatEach: repeatEach,
            repeatMax: repeatMax,
            value: value
        )
    }

    /// The canonical form: every key present, defaults written out, amounts
    /// as decimal strings — what the server stores and serves.
    public var canonical: JSONValue {
        let trigger: JSONValue = triggerInstall
            ? .object([("install", .bool(true))])
            : .object([("event", .string(triggerEvent ?? "")), ("where", .array(self.where.map(\.json)))])
        let thresholdJSON: JSONValue
        switch threshold {
        case let .count(n):
            thresholdJSON = .object([("count", .int(n))])
        case let .sum(prop, gte):
            thresholdJSON = .object([("sum", .object([("prop", .string(prop)), ("gte", .string(Amount.formatShort(gte)))]))])
        }
        let repeatJSON: JSONValue
        if !repeatEach {
            repeatJSON = .object([("mode", .string("once"))])
        } else if let max = repeatMax {
            repeatJSON = .object([("mode", .string("each")), ("max", .int(max))])
        } else {
            repeatJSON = .object([("mode", .string("each"))])
        }
        let valueJSON: JSONValue
        switch value {
        case let .fixed(units):
            valueJSON = .object([("type", .string("fixed")), ("amount", .string(Amount.formatShort(units)))])
        case let .fromProperty(prop):
            valueJSON = .object([("type", .string("from_property")), ("prop", .string(prop))])
        case .none:
            valueJSON = .object([("type", .string("none"))])
        }
        let within: JSONValue = withinDays.map {
            .object([("days", .int($0)), ("from", .string(withinFrom?.rawValue ?? ""))])
        } ?? .null
        return .object([
            ("name", .string(name)),
            ("trigger", trigger),
            ("threshold", thresholdJSON),
            ("after", .array(after.map { .int($0) })),
            ("within", within),
            ("repeat", repeatJSON),
            ("value", valueJSON),
        ])
    }

    /// How many times the goal can be reached: 1 for `once`. Unbounded
    /// (`Int.max`) only for a count's `each` without `max`; parse() refuses
    /// a repeating sum without one.
    var cap: Int {
        return repeatEach ? (repeatMax ?? Int.max) : 1
    }

    // MARK: - Validation helpers

    static func isEventName(_ s: String) -> Bool {
        let bytes = Array(s.utf8)
        guard (1...64).contains(bytes.count) else {
            return false
        }
        for (i, b) in bytes.enumerated() {
            let alnum = (b >= 0x30 && b <= 0x39) || (b >= 0x41 && b <= 0x5A) || (b >= 0x61 && b <= 0x7A) || b == 0x5F
            if i == 0 ? !alnum : !(alnum || b == 0x2E || b == 0x3A || b == 0x2D) {
                return false
            }
        }
        return true
    }

    static func isPropName(_ s: String) -> Bool {
        let bytes = Array(s.utf8)
        guard (1...64).contains(bytes.count) else {
            return false
        }
        for (i, b) in bytes.enumerated() {
            let letter = (b >= 0x41 && b <= 0x5A) || (b >= 0x61 && b <= 0x7A) || b == 0x5F
            if i == 0 ? !letter : !(letter || (b >= 0x30 && b <= 0x39)) {
                return false
            }
        }
        return true
    }

    /// A string of at most 255 bytes, a finite number or a bool.
    static func isScalarValue(_ v: JSONValue) -> Bool {
        switch v {
        case .bool: return true
        case .int, .double: return v.isNumber
        case let .string(s): return s.utf8.count <= maxString
        default: return false
        }
    }

    /// A definition amount in units: a number or a plain decimal string, not
    /// negative, at most five decimal places, within the ledger's column.
    static func amountUnits(_ v: JSONValue) -> Int? {
        let units: Int?
        switch v {
        case let .string(s):
            let pieces = s.split(separator: ".", maxSplits: 1, omittingEmptySubsequences: false)
            guard (1...6).contains(pieces[0].count), pieces[0].allSatisfy(\.isASCIIDigit) else {
                return nil
            }
            if pieces.count > 1 {
                guard (1...5).contains(pieces[1].count), pieces[1].allSatisfy(\.isASCIIDigit) else {
                    return nil
                }
            }
            units = Amount.units(decimal: s)
        case .int, .double:
            guard v.isNumber, let d = v.doubleValue, d >= 0, d <= 999_999.99999 else {
                return nil
            }
            units = Amount.units(number: v)
            if let u = units, abs(d - Double(u) / 100_000) > 1e-9 {
                return nil // more than five decimal places
            }
        default:
            return nil
        }
        guard let u = units, u >= 0, u <= Amount.maxUnits else {
            return nil
        }
        return u
    }

    private static func unknownKeys(_ obj: JSONValue, _ allowed: [String], _ path: String, _ e: inout [String: String]) {
        for key in obj.keys where !allowed.contains(key) {
            e[(path.isEmpty ? "" : path + ".") + key] = "is not a field here (allowed: \(allowed.joined(separator: ", ")))"
        }
    }

    private static func intIn(_ v: JSONValue?, _ min: Int, _ max: Int, _ path: String, _ e: inout [String: String]) -> Int? {
        guard let i = v?.intValue else {
            e[path] = "must be an integer from \(min) to \(max)"
            return nil
        }
        guard i >= min && i <= max else {
            e[path] = "must be from \(min) to \(max)"
            return nil
        }
        return i
    }

    private static func prop(_ v: JSONValue?, _ path: String, _ e: inout [String: String]) -> String? {
        if let s = v?.stringValue, s == revenueProp || isPropName(s) {
            return s
        }
        e[path] = "must be a property name (a letter or _, then letters, digits or _, up to 64) or \"$revenue\""
        return nil
    }

    private static func amount(_ v: JSONValue, _ path: String, _ e: inout [String: String]) -> Int? {
        let units = amountUnits(v)
        if units == nil {
            e[path] = "must be an amount from 0 to 999999.99999 with at most 5 decimal places"
        }
        return units
    }

    private static func parseWhere(_ w: JSONValue, _ e: inout [String: String]) -> [Predicate] {
        guard let list = w.phpList else {
            e["trigger.where"] = "must be a list of predicates"
            return []
        }
        if list.count > maxPredicates {
            e["trigger.where"] = "may hold at most \(maxPredicates) predicates"
            return []
        }
        var out: [Predicate] = []
        for (i, p) in list.enumerated() {
            let path = "trigger.where[\(i)]"
            guard p.isPHPObject else {
                e[path] = "must be an object: {\"prop\": \"<prop>\", \"op\": \"<op>\", \"value\": …}"
                continue
            }
            unknownKeys(p, ["prop", "op", "value"], path, &e)
            let propName = prop(p["prop"], path + ".prop", &e)
            guard let opText = p["op"]?.stringValue, let op = Op(rawValue: opText) else {
                e[path + ".op"] = "must be one of " + Op.allCases.map(\.rawValue).joined(separator: ", ")
                continue
            }
            let hasValue = p.has("value")
            let value = p["value"] ?? .null
            if op == .exists {
                if hasValue {
                    e[path + ".value"] = "\"exists\" takes no value"
                }
                if let propName {
                    out.append(Predicate(prop: propName, op: .exists, value: nil))
                }
                continue
            }
            guard hasValue else {
                e[path + ".value"] = "is required for \"\(op.rawValue)\""
                continue
            }
            switch op {
            case .gt, .gte, .lt, .lte:
                guard value.isNumber else {
                    e[path + ".value"] = "\"\(op.rawValue)\" compares numbers: the value must be a number"
                    continue
                }
            case .in:
                guard let items = value.phpList, !items.isEmpty else {
                    e[path + ".value"] = "\"in\" takes a non-empty list"
                    continue
                }
                if items.count > maxIn {
                    e[path + ".value"] = "\"in\" takes at most \(maxIn) values"
                    continue
                }
                var bad = false
                for (j, item) in items.enumerated() where !isScalarValue(item) {
                    e[path + ".value[\(j)]"] = "must be a string (up to 255 bytes), a number or a bool"
                    bad = true
                }
                if bad {
                    continue
                }
            default:
                guard isScalarValue(value) else {
                    e[path + ".value"] = "must be a string (up to 255 bytes), a number or a bool"
                    continue
                }
            }
            if let propName {
                // `in` keeps its items as a plain list whatever PHP-list
                // spelling the document used.
                let stored: JSONValue = op == .in ? .array(value.phpList ?? []) : value
                out.append(Predicate(prop: propName, op: op, value: stored))
            }
        }
        return out
    }
}

extension GoalDefinition.Predicate {
    var json: JSONValue {
        var pairs: [(String, JSONValue)] = [("prop", .string(prop)), ("op", .string(op.rawValue))]
        if let value {
            pairs.append(("value", value))
        }
        return .object(pairs)
    }
}

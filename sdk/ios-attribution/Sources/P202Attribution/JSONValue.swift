import Foundation

/// A decoded JSON value that remembers what the text said.
///
/// The goal specification is strict about types in a way Foundation's
/// decoders cannot see: a count of `3` is valid and `3.0` is not, and an
/// integer amount is exact where a fractional one is rounded. `JSONDecoder`
/// happily decodes `3.0` as an `Int`, and `JSONSerialization` hands back an
/// `NSNumber` whose int-ness depends on the platform. So the SDK parses the
/// schema document itself, keeping integers (`.int`) apart from every other
/// number (`.double`) exactly as PHP's `json_decode()` does on the server:
/// a number with a fraction or an exponent, or one too large for 64 bits,
/// is a double.
///
/// Objects keep their keys in document order; a repeated key keeps its last
/// value, as `json_decode()` does.
public indirect enum JSONValue: Equatable, Sendable {
    case null
    case bool(Bool)
    case int(Int)
    case double(Double)
    case string(String)
    case array([JSONValue])
    case object([(String, JSONValue)])

    public static func == (lhs: JSONValue, rhs: JSONValue) -> Bool {
        switch (lhs, rhs) {
        case (.null, .null): return true
        case let (.bool(a), .bool(b)): return a == b
        case let (.int(a), .int(b)): return a == b
        case let (.double(a), .double(b)): return a == b
        case let (.string(a), .string(b)): return a == b
        case let (.array(a), .array(b)): return a == b
        case let (.object(a), .object(b)):
            return a.count == b.count && zip(a, b).allSatisfy { $0.0 == $1.0 && $0.1 == $1.1 }
        default: return false
        }
    }

    // MARK: - Reading

    /// The value under `key` when this is an object (the last one, when the
    /// key repeats), else nil.
    public subscript(key: String) -> JSONValue? {
        guard case let .object(pairs) = self else {
            return nil
        }
        return pairs.last(where: { $0.0 == key })?.1
    }

    /// Whether an object holds `key` at all (a present `null` counts).
    public func has(_ key: String) -> Bool {
        return self[key] != nil
    }

    /// The object's keys in document order, each once.
    public var keys: [String] {
        guard case let .object(pairs) = self else {
            return []
        }
        var seen = Set<String>()
        return pairs.map(\.0).filter { seen.insert($0).inserted }
    }

    public var stringValue: String? {
        if case let .string(s) = self { return s }
        return nil
    }

    public var intValue: Int? {
        if case let .int(i) = self { return i }
        return nil
    }

    public var boolValue: Bool? {
        if case let .bool(b) = self { return b }
        return nil
    }

    /// A finite JSON number of either spelling (a bool is not a number).
    public var isNumber: Bool {
        switch self {
        case .int: return true
        case let .double(d): return d.isFinite
        default: return false
        }
    }

    public var doubleValue: Double? {
        switch self {
        case let .int(i): return Double(i)
        case let .double(d): return d
        default: return nil
        }
    }

    public var isNull: Bool {
        if case .null = self { return true }
        return false
    }

    /// The elements, for a value PHP's `array_is_list()` would call a list.
    ///
    /// `json_decode($x, true)` cannot tell `{}` from `[]`, and it turns an
    /// object whose keys are "0", "1", … in order into a list. The server
    /// validates what it decoded, so to agree with it on every definition
    /// this reads the same way.
    public var phpList: [JSONValue]? {
        switch self {
        case let .array(items):
            return items
        case let .object(pairs):
            let unique = uniquePairs(pairs)
            for (i, pair) in unique.enumerated() where pair.0 != String(i) {
                return nil
            }
            return unique.map(\.1)
        default:
            return nil
        }
    }

    /// Whether PHP would read this as an associative array (an object): a
    /// non-list object, or an empty array or object, which decode the same.
    public var isPHPObject: Bool {
        switch self {
        case .array(let items):
            return items.isEmpty
        case .object(let pairs):
            return pairs.isEmpty || phpList == nil
        default:
            return false
        }
    }

    // MARK: - Parsing

    public struct ParseError: Error, Equatable, CustomStringConvertible {
        public let offset: Int
        public let reason: String
        public var description: String { "JSON parse error at byte \(offset): \(reason)" }
    }

    /// Parse a complete JSON text (RFC 8259). Nesting deeper than
    /// `maxDepth` is refused rather than recursed into.
    public static func parse(_ data: Data, maxDepth: Int = 64) throws -> JSONValue {
        var parser = Parser(bytes: [UInt8](data), maxDepth: maxDepth)
        return try parser.parseDocument()
    }

    public static func parse(_ text: String, maxDepth: Int = 64) throws -> JSONValue {
        return try parse(Data(text.utf8), maxDepth: maxDepth)
    }

    // MARK: - Writing

    /// Compact JSON text. Integers print as integers and doubles in their
    /// shortest round-tripping form, so a parse of the output is equal to
    /// the value.
    public var jsonText: String {
        var out = ""
        write(into: &out)
        return out
    }

    private func write(into out: inout String) {
        switch self {
        case .null: out += "null"
        case let .bool(b): out += b ? "true" : "false"
        case let .int(i): out += String(i)
        case let .double(d):
            if d.isFinite {
                var text = "\(d)"
                if !text.contains(".") && !text.contains("e") && !text.contains("E") {
                    text += ".0"
                }
                out += text
            } else {
                out += "null"
            }
        case let .string(s): Self.writeString(s, into: &out)
        case let .array(items):
            out += "["
            for (i, item) in items.enumerated() {
                if i > 0 { out += "," }
                item.write(into: &out)
            }
            out += "]"
        case let .object(pairs):
            out += "{"
            for (i, pair) in uniquePairs(pairs).enumerated() {
                if i > 0 { out += "," }
                Self.writeString(pair.0, into: &out)
                out += ":"
                pair.1.write(into: &out)
            }
            out += "}"
        }
    }

    private static func writeString(_ s: String, into out: inout String) {
        out += "\""
        for scalar in s.unicodeScalars {
            switch scalar {
            case "\"": out += "\\\""
            case "\\": out += "\\\\"
            case "\n": out += "\\n"
            case "\r": out += "\\r"
            case "\t": out += "\\t"
            default:
                if scalar.value < 0x20 {
                    out += String(format: "\\u%04x", scalar.value)
                } else {
                    out.unicodeScalars.append(scalar)
                }
            }
        }
        out += "\""
    }
}

/// Keys once each, the last value winning, in first-appearance order.
private func uniquePairs(_ pairs: [(String, JSONValue)]) -> [(String, JSONValue)] {
    var index: [String: Int] = [:]
    var out: [(String, JSONValue)] = []
    for (key, value) in pairs {
        if let i = index[key] {
            out[i] = (key, value)
        } else {
            index[key] = out.count
            out.append((key, value))
        }
    }
    return out
}

private struct Parser {
    let bytes: [UInt8]
    let maxDepth: Int
    var pos = 0

    init(bytes: [UInt8], maxDepth: Int) {
        self.bytes = bytes
        self.maxDepth = maxDepth
    }

    mutating func parseDocument() throws -> JSONValue {
        skipWhitespace()
        let value = try parseValue(depth: 0)
        skipWhitespace()
        guard pos == bytes.count else {
            throw fail("unexpected data after the value")
        }
        return value
    }

    func fail(_ reason: String) -> JSONValue.ParseError {
        return JSONValue.ParseError(offset: pos, reason: reason)
    }

    mutating func skipWhitespace() {
        while pos < bytes.count, [0x20, 0x09, 0x0A, 0x0D].contains(bytes[pos]) {
            pos += 1
        }
    }

    mutating func parseValue(depth: Int) throws -> JSONValue {
        guard depth < maxDepth else {
            throw fail("nested deeper than \(maxDepth)")
        }
        guard pos < bytes.count else {
            throw fail("unexpected end of input")
        }
        switch bytes[pos] {
        case UInt8(ascii: "{"): return try parseObject(depth: depth)
        case UInt8(ascii: "["): return try parseArray(depth: depth)
        case UInt8(ascii: "\""): return .string(try parseString())
        case UInt8(ascii: "t"): try literal("true"); return .bool(true)
        case UInt8(ascii: "f"): try literal("false"); return .bool(false)
        case UInt8(ascii: "n"): try literal("null"); return .null
        default: return try parseNumber()
        }
    }

    mutating func literal(_ word: String) throws {
        let w = Array(word.utf8)
        guard pos + w.count <= bytes.count, Array(bytes[pos..<pos + w.count]) == w else {
            throw fail("invalid literal")
        }
        pos += w.count
    }

    mutating func parseObject(depth: Int) throws -> JSONValue {
        pos += 1
        var pairs: [(String, JSONValue)] = []
        skipWhitespace()
        if pos < bytes.count, bytes[pos] == UInt8(ascii: "}") {
            pos += 1
            return .object(pairs)
        }
        while true {
            skipWhitespace()
            guard pos < bytes.count, bytes[pos] == UInt8(ascii: "\"") else {
                throw fail("expected a string key")
            }
            let key = try parseString()
            skipWhitespace()
            guard pos < bytes.count, bytes[pos] == UInt8(ascii: ":") else {
                throw fail("expected ':'")
            }
            pos += 1
            skipWhitespace()
            pairs.append((key, try parseValue(depth: depth + 1)))
            skipWhitespace()
            guard pos < bytes.count else {
                throw fail("unexpected end of input in an object")
            }
            if bytes[pos] == UInt8(ascii: ",") {
                pos += 1
                continue
            }
            if bytes[pos] == UInt8(ascii: "}") {
                pos += 1
                return .object(pairs)
            }
            throw fail("expected ',' or '}'")
        }
    }

    mutating func parseArray(depth: Int) throws -> JSONValue {
        pos += 1
        var items: [JSONValue] = []
        skipWhitespace()
        if pos < bytes.count, bytes[pos] == UInt8(ascii: "]") {
            pos += 1
            return .array(items)
        }
        while true {
            skipWhitespace()
            items.append(try parseValue(depth: depth + 1))
            skipWhitespace()
            guard pos < bytes.count else {
                throw fail("unexpected end of input in an array")
            }
            if bytes[pos] == UInt8(ascii: ",") {
                pos += 1
                continue
            }
            if bytes[pos] == UInt8(ascii: "]") {
                pos += 1
                return .array(items)
            }
            throw fail("expected ',' or ']'")
        }
    }

    mutating func parseString() throws -> String {
        pos += 1 // opening quote
        var scalars = String.UnicodeScalarView()
        var raw: [UInt8] = []
        func flush() throws {
            if raw.isEmpty { return }
            guard let text = String(bytes: raw, encoding: .utf8) else {
                throw JSONValue.ParseError(offset: 0, reason: "invalid UTF-8 in a string")
            }
            scalars.append(contentsOf: text.unicodeScalars)
            raw.removeAll()
        }
        while true {
            guard pos < bytes.count else {
                throw fail("unterminated string")
            }
            let b = bytes[pos]
            if b == UInt8(ascii: "\"") {
                pos += 1
                try flush()
                return String(scalars)
            }
            if b < 0x20 {
                throw fail("control character in a string")
            }
            if b != UInt8(ascii: "\\") {
                raw.append(b)
                pos += 1
                continue
            }
            try flush()
            pos += 1
            guard pos < bytes.count else {
                throw fail("unterminated escape")
            }
            let e = bytes[pos]
            pos += 1
            switch e {
            case UInt8(ascii: "\""): scalars.append("\"")
            case UInt8(ascii: "\\"): scalars.append("\\")
            case UInt8(ascii: "/"): scalars.append("/")
            case UInt8(ascii: "b"): scalars.append("\u{08}")
            case UInt8(ascii: "f"): scalars.append("\u{0C}")
            case UInt8(ascii: "n"): scalars.append("\n")
            case UInt8(ascii: "r"): scalars.append("\r")
            case UInt8(ascii: "t"): scalars.append("\t")
            case UInt8(ascii: "u"):
                var code = try hex4()
                if (0xD800...0xDBFF).contains(code) {
                    // A high surrogate must be followed by an escaped low one.
                    guard pos + 1 < bytes.count, bytes[pos] == UInt8(ascii: "\\"), bytes[pos + 1] == UInt8(ascii: "u") else {
                        throw fail("unpaired surrogate")
                    }
                    pos += 2
                    let low = try hex4()
                    guard (0xDC00...0xDFFF).contains(low) else {
                        throw fail("unpaired surrogate")
                    }
                    code = 0x10000 + ((code - 0xD800) << 10) + (low - 0xDC00)
                } else if (0xDC00...0xDFFF).contains(code) {
                    throw fail("unpaired surrogate")
                }
                guard let scalar = Unicode.Scalar(code) else {
                    throw fail("invalid code point")
                }
                scalars.append(scalar)
            default:
                throw fail("invalid escape")
            }
        }
    }

    mutating func hex4() throws -> UInt32 {
        guard pos + 4 <= bytes.count else {
            throw fail("short \\u escape")
        }
        var value: UInt32 = 0
        for _ in 0..<4 {
            let b = bytes[pos]
            let digit: UInt32
            switch b {
            case UInt8(ascii: "0")...UInt8(ascii: "9"): digit = UInt32(b - UInt8(ascii: "0"))
            case UInt8(ascii: "a")...UInt8(ascii: "f"): digit = UInt32(b - UInt8(ascii: "a") + 10)
            case UInt8(ascii: "A")...UInt8(ascii: "F"): digit = UInt32(b - UInt8(ascii: "A") + 10)
            default: throw fail("invalid hex digit")
            }
            value = value * 16 + digit
            pos += 1
        }
        return value
    }

    mutating func parseNumber() throws -> JSONValue {
        let start = pos
        var isInteger = true
        if pos < bytes.count, bytes[pos] == UInt8(ascii: "-") {
            pos += 1
        }
        guard pos < bytes.count, isDigit(bytes[pos]) else {
            throw fail("invalid value")
        }
        if bytes[pos] == UInt8(ascii: "0") {
            pos += 1
        } else {
            while pos < bytes.count, isDigit(bytes[pos]) { pos += 1 }
        }
        if pos < bytes.count, bytes[pos] == UInt8(ascii: ".") {
            isInteger = false
            pos += 1
            guard pos < bytes.count, isDigit(bytes[pos]) else {
                throw fail("a fraction needs digits")
            }
            while pos < bytes.count, isDigit(bytes[pos]) { pos += 1 }
        }
        if pos < bytes.count, bytes[pos] == UInt8(ascii: "e") || bytes[pos] == UInt8(ascii: "E") {
            isInteger = false
            pos += 1
            if pos < bytes.count, bytes[pos] == UInt8(ascii: "+") || bytes[pos] == UInt8(ascii: "-") {
                pos += 1
            }
            guard pos < bytes.count, isDigit(bytes[pos]) else {
                throw fail("an exponent needs digits")
            }
            while pos < bytes.count, isDigit(bytes[pos]) { pos += 1 }
        }
        let text = String(decoding: bytes[start..<pos], as: UTF8.self)
        if isInteger, let i = Int(text) {
            return .int(i)
        }
        // Like json_decode(): an integer too large for 64 bits is a double.
        guard let d = Double(text) else {
            throw fail("invalid number")
        }
        return .double(d)
    }

    func isDigit(_ b: UInt8) -> Bool {
        return b >= UInt8(ascii: "0") && b <= UInt8(ascii: "9")
    }
}

import Foundation

/// Money and sums in integer units of 0.00001, as the server's ledger keeps
/// them (`Prosper202\Conversion\Ledger\Amount`). The goal evaluator sums in
/// units so that `0.1 + 0.2` reaches a `gte 0.3` threshold on the device
/// exactly when it does on the server.
enum Amount {
    static let scale = 5
    static let factor = 100_000
    /// The ledger's decimal(11,5).
    static let maxUnits = 99_999_999_999

    /// A decimal string of digits with an optional minus and fraction, in
    /// units, rounding half away from zero past the fifth place. nil for
    /// anything else (an exponent, spaces inside, more than 13 whole digits).
    static func units(decimal text: String) -> Int? {
        var chars = Substring(trimPHP(text))
        var negative = false
        if chars.first == "-" {
            negative = true
            chars = chars.dropFirst()
        }
        let parts = chars.split(separator: ".", maxSplits: 1, omittingEmptySubsequences: false)
        guard let wholePart = parts.first, !wholePart.isEmpty, wholePart.allSatisfy(\.isASCIIDigit) else {
            return nil
        }
        var fraction = parts.count > 1 ? String(parts[1]) : ""
        if parts.count > 1 && (fraction.isEmpty || !fraction.allSatisfy(\.isASCIIDigit)) {
            return nil
        }
        let whole = String(wholePart.drop(while: { $0 == "0" }))
        guard whole.count <= 13 else {
            return nil
        }
        var roundUp = false
        if fraction.count > scale {
            let idx = fraction.index(fraction.startIndex, offsetBy: scale)
            roundUp = (fraction[idx].wholeNumberValue ?? 0) >= 5
            fraction = String(fraction[..<idx])
        }
        fraction += String(repeating: "0", count: scale - fraction.count)
        var units = (Int(whole.isEmpty ? "0" : whole) ?? 0) * factor + (Int(fraction) ?? 0)
        if roundUp {
            units += 1
        }
        return negative ? -units : units
    }

    /// An integer amount in units, or nil when it would overflow.
    static func units(int value: Int) -> Int? {
        let (product, overflow) = value.multipliedReportingOverflow(by: factor)
        return overflow ? nil : product
    }

    /// A double in units: formatted with eight decimals, then rounded half
    /// away from zero to five (the server's number_format() path). nil for a
    /// value that is not finite or is too large to hold.
    static func units(double value: Double) -> Int? {
        guard value.isFinite, abs(value) <= 99_999_999_999_999.0 else {
            return nil
        }
        return units(decimal: String(format: "%.8f", value))
    }

    /// A JSON number in units (an int exactly, a double by the rule above).
    static func units(number: JSONValue) -> Int? {
        switch number {
        case let .int(i): return units(int: i)
        case let .double(d): return units(double: d)
        default: return nil
        }
    }

    /// Units as the decimal string a decimal(11,5) column stores: "4.99000".
    static func format(_ units: Int) -> String {
        let negative = units < 0
        let magnitude = units.magnitude
        let whole = magnitude / UInt(factor)
        let fraction = String(magnitude % UInt(factor))
        let padded = String(repeating: "0", count: scale - fraction.count) + fraction
        return (negative ? "-" : "") + "\(whole).\(padded)"
    }

    /// Units as the shortest decimal string with at least two places, the
    /// way a canonical definition writes an amount: "20.00", "0.12345".
    static func formatShort(_ units: Int) -> String {
        let text = format(units)
        let pieces = text.split(separator: ".", maxSplits: 1)
        var fraction = String(pieces[1])
        while fraction.last == "0" { fraction.removeLast() }
        while fraction.count < 2 { fraction += "0" }
        return "\(pieces[0]).\(fraction)"
    }
}

/// PHP's trim(): only " \t\n\r\0\x0B", never other Unicode whitespace, so
/// the device trims exactly what the server trims.
func trimPHP(_ s: String) -> String {
    let set: Set<Unicode.Scalar> = [" ", "\t", "\n", "\r", "\0", "\u{0B}"]
    let scalars = Array(s.unicodeScalars)
    var start = 0
    var end = scalars.count
    while start < end, set.contains(scalars[start]) { start += 1 }
    while end > start, set.contains(scalars[end - 1]) { end -= 1 }
    var view = String.UnicodeScalarView()
    view.append(contentsOf: scalars[start..<end])
    return String(view)
}

extension Character {
    var isASCIIDigit: Bool {
        return ("0"..."9").contains(self) && isASCII
    }
}

import Foundation

/// A customer id the operator's own server has signed, as the identity graph
/// reads it (documentation/features/visitor-identity.md; the server's
/// `Prosper202\Identity\CustomerId`).
///
/// The app token ships in every copy of the app, so an id the SDK merely
/// *says* is exactly the request-controlled `cust` a public pixel carries,
/// and links nothing. There is therefore no one-argument form: the id comes
/// with `cust_sig`, which only the operator's server can compute —
///
///     cust_sig = hex(HMAC-SHA256(linking_key, "<type>:<id>"))
///
/// — and the app fetches it from that server (never from the linking key,
/// which must not ship in a binary). The SDK checks the id and the
/// signature's shape, canonicalises the id exactly as the server will, and
/// carries it as `customer: {id, type, signature}`; the server verifies the
/// signature before the id becomes an identity signal.
public struct P202CustomerId: Equatable, Codable, Sendable {
    /// The id vocabulary (the LTV ledger's alias types). An email is sent
    /// as a digest, never in the clear.
    public enum IdType: String, Codable, CaseIterable, Sendable {
        case custom
        case emailMD5 = "email_md5"
        case emailSHA256 = "email_sha256"
        case espId = "esp_id"
        case merchantId = "merchant_id"
        case subid
    }

    public enum Invalid: Error, Equatable {
        /// Empty after trimming, or an email digest that is not one.
        case id
        /// Not 64 hexadecimal characters: not a cust_sig.
        case signature
    }

    /// The id as the server canonicalises it: trimmed, and lower-cased for
    /// the email digests.
    public let id: String
    public let type: IdType
    /// Lower-case hex.
    public let signature: String

    public init(id: String, signature: String, type: IdType = .custom) throws {
        guard let value = Self.canonicalValue(id, type: type) else {
            throw Invalid.id
        }
        let sig = asciiLower(trimPHP(signature))
        guard sig.utf8.count == 64, sig.utf8.allSatisfy({ ($0 >= 0x30 && $0 <= 0x39) || ($0 >= 0x61 && $0 <= 0x66) }) else {
            throw Invalid.signature
        }
        self.id = value
        self.type = type
        self.signature = sig
    }

    /// "<type>:<id>" — what the signature covers. The type is inside it,
    /// so the same value as a merchant id and as an ESP id are two people,
    /// and a signature over one is not a signature over the other.
    public var canonical: String {
        return type.rawValue + ":" + id
    }

    /// The `customer` object the app SDK wire contract defines.
    public var wireObject: [String: String] {
        return ["id": id, "type": type.rawValue, "signature": signature]
    }

    /// The canonical form of a raw id and type name, or nil when the server
    /// would refuse it — the device's copy of `CustomerId::canonical()`,
    /// run against the same vectors (app-sdk-contract/customer-id.json).
    public static func canonical(id: String, type: String?) -> String? {
        var name = asciiLower(trimPHP(type ?? ""))
        if name.isEmpty {
            name = IdType.custom.rawValue
        }
        guard let idType = IdType(rawValue: name), let value = canonicalValue(id, type: idType) else {
            return nil
        }
        return idType.rawValue + ":" + value
    }

    private static func canonicalValue(_ raw: String, type: IdType) -> String? {
        var value = trimPHP(raw)
        guard !value.isEmpty else {
            return nil
        }
        if type == .emailMD5 || type == .emailSHA256 {
            // strtolower(): ASCII only, as on the server.
            value = asciiLower(value)
            let want = type == .emailMD5 ? 32 : 64
            guard value.utf8.count == want,
                  value.utf8.allSatisfy({ ($0 >= 0x30 && $0 <= 0x39) || ($0 >= 0x61 && $0 <= 0x66) }) else {
                return nil
            }
        }
        return value
    }
}

/// What an app key names, by the server's `AppIdentity` rules — used to
/// check that the document a token selects is an iOS app's before the SDK
/// encodes anything with it (a token lifted into another app gets that
/// app's document). Runs the `keys` vectors in app-sdk-contract/app-identity.json.
public enum P202AppKey {
    public static let platforms = ["ios", "android"]
    public static let maxKeyLength = 255

    /// The canonical key for a platform (nil: inferred from the key), and
    /// the platform it names; nil when the server would refuse the pair.
    public static func resolve(platform: JSONValue?, appKey: JSONValue) -> (platform: String, key: String)? {
        if platform == nil || platform == .null {
            if let apple = appleKey(appKey) {
                return ("ios", apple)
            }
            if let android = androidKey(appKey) {
                return ("android", android)
            }
            return nil
        }
        guard let raw = platform?.stringValue else {
            return nil
        }
        let normalized = asciiLower(trimPHP(raw))
        switch normalized {
        case "ios": return appleKey(appKey).map { ("ios", $0) }
        case "android": return androidKey(appKey).map { ("android", $0) }
        default: return nil
        }
    }

    /// An App Store id: a positive JSON integer, or digits with no leading
    /// zero that round-trip exactly through a 64-bit integer.
    public static func appleKey(_ raw: JSONValue) -> String? {
        switch raw {
        case let .int(i):
            return i >= 1 ? String(i) : nil
        case let .string(s):
            guard let first = s.utf8.first, first >= 0x31, first <= 0x39,
                  s.utf8.allSatisfy({ $0 >= 0x30 && $0 <= 0x39 }),
                  let value = Int(s), String(value) == s else {
                return nil
            }
            return s
        default:
            return nil
        }
    }

    /// An Android application id: at least two dot-separated segments, each
    /// a letter then letters, digits or underscores; case-sensitive.
    public static func androidKey(_ raw: JSONValue) -> String? {
        guard let s = raw.stringValue, s.utf8.count <= maxKeyLength else {
            return nil
        }
        let segments = s.split(separator: ".", omittingEmptySubsequences: false)
        guard segments.count >= 2 else {
            return nil
        }
        for segment in segments {
            let bytes = Array(segment.utf8)
            guard let first = bytes.first, (first >= 0x41 && first <= 0x5A) || (first >= 0x61 && first <= 0x7A) else {
                return nil
            }
            for b in bytes.dropFirst() where !((b >= 0x41 && b <= 0x5A) || (b >= 0x61 && b <= 0x7A) || (b >= 0x30 && b <= 0x39) || b == 0x5F) {
                return nil
            }
        }
        return s
    }
}

/// PHP's strtolower(): A-Z only, so the device folds exactly what the
/// server folds.
func asciiLower(_ s: String) -> String {
    var view = String.UnicodeScalarView()
    for scalar in s.unicodeScalars {
        if scalar.value >= 0x41 && scalar.value <= 0x5A, let lower = Unicode.Scalar(scalar.value + 32) {
            view.append(lower)
        } else {
            view.append(scalar)
        }
    }
    return String(view)
}

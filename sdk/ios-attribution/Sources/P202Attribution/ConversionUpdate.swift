import Foundation

/// The pure decision of what to hand the attribution frameworks for one
/// reported event — separated from the StoreKit and AdAttributionKit calls
/// so it is testable everywhere (including this repository's Linux CI,
/// where neither framework exists).
public struct ConversionUpdate: Equatable, Sendable {
    /// Which of AdAttributionKit's postbacks an update is for. SKAdNetwork
    /// keeps only the install postback; AdAttributionKit on iOS 18+ also
    /// keeps a re-engagement postback with its own conversion value.
    public enum ConversionType: String, Codable, Equatable, CaseIterable, Sendable {
        case install
        case reengagement
    }

    /// The fine conversion value to set (0-63).
    public let fineValue: Int
    /// The coarse value to set alongside it, when the schema defines one.
    public let coarseValue: P202AttributionSchema.CoarseValue?
    /// True when the schema had no fine value for this event and the update
    /// carries the last fine value sent instead (or 0 when none was) —
    /// the coarse-bearing APIs always take a fine value, and sending an
    /// arbitrary one would silently downgrade the fine signal.
    public let usedFineFallback: Bool
    /// The postbacks the caller scoped the update to; nil is the frameworks'
    /// default (the install postback — SKAdNetwork's only one — and
    /// whatever AdAttributionKit applies an unscoped update to).
    public let conversionTypes: [ConversionType]?

    public init(
        fineValue: Int,
        coarseValue: P202AttributionSchema.CoarseValue?,
        usedFineFallback: Bool,
        conversionTypes: [ConversionType]? = nil
    ) {
        self.fineValue = fineValue
        self.coarseValue = coarseValue
        self.usedFineFallback = usedFineFallback
        self.conversionTypes = conversionTypes
    }

    /// Whether the update reaches the install postback — the only one
    /// SKAdNetwork has, so this decides whether SKAdNetwork is called at
    /// all: a re-engagement-only update must not overwrite the install
    /// postback's value.
    public var includesInstall: Bool {
        return conversionTypes?.contains(.install) ?? true
    }

    /// Whether the update reaches AdAttributionKit's re-engagement postback.
    public var includesReengagement: Bool {
        return conversionTypes?.contains(.reengagement) ?? false
    }

    /// The same decision, scoped to the given postbacks.
    public func scoped(to conversionTypes: [ConversionType]?) -> ConversionUpdate {
        return ConversionUpdate(
            fineValue: fineValue,
            coarseValue: coarseValue,
            usedFineFallback: usedFineFallback,
            conversionTypes: conversionTypes
        )
    }

    /// Resolve an event name against the schema. Returns nil for an event
    /// the schema does not map — the caller must treat that as a no-op, not
    /// an update: sending anything for an unmapped event would overwrite a
    /// meaningful value with a guess.
    public static func resolve(
        event: String,
        in schema: P202AttributionSchema,
        lastFineValue: Int?
    ) -> ConversionUpdate? {
        guard let mapping = schema.events[event] else {
            return nil
        }
        if let fine = mapping.fineValue {
            return ConversionUpdate(
                fineValue: clamp(fine),
                coarseValue: mapping.coarseValue,
                usedFineFallback: false
            )
        }
        // Coarse-only mapping: keep whatever fine value was last reported
        // so the coarse update cannot regress it.
        return ConversionUpdate(
            fineValue: clamp(lastFineValue ?? 0),
            coarseValue: mapping.coarseValue,
            usedFineFallback: true
        )
    }

    /// Fine values are 6 bits in both frameworks; the server validates its
    /// side, and the device clamps defensively rather than crashing on a
    /// bad document.
    private static func clamp(_ value: Int) -> Int {
        return min(63, max(0, value))
    }
}

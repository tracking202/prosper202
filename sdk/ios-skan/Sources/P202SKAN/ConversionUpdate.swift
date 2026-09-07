import Foundation

/// The pure decision of what to hand SKAdNetwork for one reported event —
/// separated from the StoreKit call so it is testable everywhere (including
/// this repository's Linux CI, where StoreKit does not exist).
public struct ConversionUpdate: Equatable {
    /// The fine conversion value to set (0-63).
    public let fineValue: Int
    /// The coarse value to set alongside it, when the schema defines one.
    public let coarseValue: P202SKANSchema.CoarseValue?
    /// True when the schema had no fine value for this event and the update
    /// carries the last fine value sent instead (or 0 when none was) —
    /// SKAdNetwork's coarse-bearing API always takes a fine value, and
    /// sending an arbitrary one would silently downgrade the fine signal.
    public let usedFineFallback: Bool

    public init(fineValue: Int, coarseValue: P202SKANSchema.CoarseValue?, usedFineFallback: Bool) {
        self.fineValue = fineValue
        self.coarseValue = coarseValue
        self.usedFineFallback = usedFineFallback
    }

    /// Resolve an event name against the schema. Returns nil for an event
    /// the schema does not map — the caller must treat that as a no-op, not
    /// an update: sending anything for an unmapped event would overwrite a
    /// meaningful value with a guess.
    public static func resolve(
        event: String,
        in schema: P202SKANSchema,
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

    /// SKAN fine values are 6 bits; the server validates its side, and the
    /// device clamps defensively rather than crashing on a bad document.
    private static func clamp(_ value: Int) -> Int {
        return min(63, max(0, value))
    }
}

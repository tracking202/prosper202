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
    /// The postbacks the caller scoped the update to. nil means the install
    /// postback: that is how `includesInstall` and `includesReengagement`
    /// read it, it is the only postback SKAdNetwork has, and
    /// `adAttributionKitDelivery(scopedAPIAvailable:)` names it explicitly
    /// rather than letting AdAttributionKit's unscoped call reach every
    /// postback type.
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

    /// What AdAttributionKit should be told about this update. The framework
    /// offers two call shapes that do not mean the same thing, so the choice
    /// between them is made here — pure, and therefore exercised by this
    /// package's Linux test suite — rather than inside the iOS-only call site.
    public enum Delivery: Equatable, Sendable {
        /// Send through the scoped API (`PostbackUpdate`, iOS 18+), naming
        /// exactly these postbacks.
        case scoped([ConversionType])
        /// Send through an unscoped overload (iOS 17.4+), which carries no
        /// conversion types and so reaches every postback the system has.
        case unscoped
        /// Send nothing: no postback this update names can be reached.
        case skip
    }

    /// Resolve the AdAttributionKit call for this update.
    ///
    /// `scopedAPIAvailable` is whether the system offers
    /// `Postback.updateConversionValue(_: PostbackUpdate)` (iOS 18+); the
    /// unscoped overloads are iOS 17.4+, and re-engagement postbacks exist
    /// only from iOS 18.
    ///
    /// Apple documents `PostbackUpdate.conversionTypes` as "If nil, the
    /// system updates all types of postbacks by default", and the unscoped
    /// overloads have no conversion types at all. Two consequences:
    ///
    /// - Where the scoped API exists, a nil scope is sent as an explicit
    ///   `[.install]` and never through an unscoped overload. This type
    ///   treats nil as install-only everywhere else (`includesInstall`,
    ///   `includesReengagement`, and the facade's two separate last-fine-value
    ///   stores), so the all-postbacks path would rewrite the re-engagement
    ///   postback's value while the SDK believed it untouched.
    /// - Where it does not (iOS 17.4-17.x), a scope that leaves out
    ///   `.install` has nothing it can reach: the only postback there is the
    ///   install one, whose value the caller deliberately declined to touch
    ///   when it skipped SKAdNetwork. The update is dropped instead of
    ///   silently losing its scope.
    public func adAttributionKitDelivery(scopedAPIAvailable: Bool) -> Delivery {
        let types = conversionTypes ?? [.install]
        guard scopedAPIAvailable else {
            return types.contains(.install) ? .unscoped : .skip
        }
        // An explicitly empty scope names no postback, and must not fall
        // through to the API's nil default — which is every postback.
        return types.isEmpty ? .skip : .scoped(types)
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

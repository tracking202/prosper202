package com.prosper202.attribution.core

import java.security.MessageDigest

/** Play's `InstallReferrerResponse` codes, as the wire spells them. */
enum class ReferrerStatus(val wire: String) {
    OK("ok"),
    FEATURE_NOT_SUPPORTED("feature_not_supported"),
    SERVICE_UNAVAILABLE("service_unavailable"),
    DEVELOPER_ERROR("developer_error"),
    SERVICE_DISCONNECTED("service_disconnected"),
    PERMISSION_ERROR("permission_error");

    /** Worth reading again before settling for it: Play's service was busy or went away. */
    val isTransient: Boolean get() = this == SERVICE_UNAVAILABLE || this == SERVICE_DISCONNECTED
}

/**
 * What Play's Install Referrer API returned, field for field
 * (`ReferrerDetails`). Timestamps are unix seconds as Play reports them;
 * Play's 0 means "none" and is sent as 0.
 */
data class ReferrerDetails(
    val status: ReferrerStatus,
    val installReferrer: String? = null,
    val referrerClickTimestampSeconds: Long = 0,
    val installBeginTimestampSeconds: Long = 0,
    val referrerClickTimestampServerSeconds: Long = 0,
    val installBeginTimestampServerSeconds: Long = 0,
    val installVersion: String? = null,
    val googlePlayInstant: Boolean? = null,
) {
    companion object {
        /** The referrer API failed with [status]: nothing else is reported. */
        @JvmStatic
        fun unavailable(status: ReferrerStatus): ReferrerDetails {
            require(status != ReferrerStatus.OK) { "an OK response carries the referrer" }
            return ReferrerDetails(status)
        }
    }
}

/**
 * The body of `POST /api/v3/apps/installs` (plan §5.2; the server's
 * `InstallPayload`): built once, persisted, and resent unchanged until it
 * is answered, because the server tells a retry from a reused install_uuid
 * by the [fingerprint] of its [canonical] form.
 *
 * [validate] is the server's validation, rule for rule, run against every
 * case of tests/fixtures/app-sdk-contract/android/install-requests.json:
 * [build] never hands the transport a body the server would refuse. Device
 * facts that break a rule (an app version with a control character, a
 * version string longer than the column) are sent as null rather than
 * costing the whole install — they are optional on the wire, and an
 * install that is never reported is the larger loss; the listener hears
 * which ones.
 */
object InstallPayload {
    val STORES = listOf("google_play")
    private val TOP = listOf(
        "install_uuid", "app_key", "store", "referrer", "first_open_at", "app_version", "sdk_version",
        "os_version", "test", "integrity_token", "customer",
    )
    private val REFERRER = listOf(
        "status", "install_referrer", "referrer_click_timestamp_seconds", "install_begin_timestamp_seconds",
        "referrer_click_timestamp_server_seconds", "install_begin_timestamp_server_seconds", "install_version", "google_play_instant",
    )
    private val TIMES = REFERRER.subList(2, 6)
    private val UUID = Regex("^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
    const val MAX_INTEGRITY_TOKEN = 8192
    const val MAX_BODY_BYTES = 16384
    const val MAX_UNIX_SECONDS = 4294967295L
    private val STRING_LIMITS = mapOf("app_version" to 64, "sdk_version" to 32, "os_version" to 32)

    /** The result of [build]: the body, and the optional fields it had to leave out. */
    class Built(val body: JsonValue.Obj, val dropped: List<String>)

    /**
     * An install body. [customer] is included when the app set one before
     * the body was first built; after that it rides the events route.
     */
    @JvmStatic
    fun build(
        installUuid: String,
        appKey: String,
        referrer: ReferrerDetails,
        firstOpenAt: Long?,
        appVersion: String?,
        sdkVersion: String?,
        osVersion: String?,
        test: Boolean,
        customer: CustomerId?,
    ): Built {
        val dropped = ArrayList<String>()
        fun optional(path: String, v: String?, max: Int): JsonValue =
            if (v == null || validOptionalString(v, max)) {
                v.json()
            } else {
                dropped.add(path)
                JsonValue.Null
            }

        val ref = LinkedHashMap<String, JsonValue>()
        ref["status"] = referrer.status.wire.json()
        if (referrer.status == ReferrerStatus.OK) {
            ref["install_referrer"] = (referrer.installReferrer ?: "").json()
            ref["referrer_click_timestamp_seconds"] = clampTime(referrer.referrerClickTimestampSeconds).json()
            ref["install_begin_timestamp_seconds"] = clampTime(referrer.installBeginTimestampSeconds).json()
            ref["referrer_click_timestamp_server_seconds"] = clampTime(referrer.referrerClickTimestampServerSeconds).json()
            ref["install_begin_timestamp_server_seconds"] = clampTime(referrer.installBeginTimestampServerSeconds).json()
            ref["install_version"] = optional("referrer.install_version", referrer.installVersion, 64)
            ref["google_play_instant"] = referrer.googlePlayInstant.json()
        }
        val body = LinkedHashMap<String, JsonValue>()
        body["install_uuid"] = installUuid.json()
        body["app_key"] = appKey.json()
        body["store"] = STORES[0].json()
        body["referrer"] = JsonValue.Obj(ref)
        body["first_open_at"] = firstOpenAt?.takeIf { it in 0..MAX_UNIX_SECONDS }.json()
        body["app_version"] = optional("app_version", appVersion, 64)
        body["sdk_version"] = optional("sdk_version", sdkVersion, 32)
        body["os_version"] = optional("os_version", osVersion, 32)
        body["test"] = test.json()
        if (customer != null) body["customer"] = customer.toWire()
        val built = JsonValue.Obj(body)
        val errors = validate(built)
        require(errors.isEmpty()) { "the SDK built an install body the server would refuse: $errors" }
        return Built(built, dropped)
    }

    /** The canonical form: keys sorted by UTF-8 bytes at every level, no whitespace, integrity_token left out. */
    @JvmStatic
    fun canonical(body: JsonValue.Obj): String = Json.canonical(JsonValue.Obj(body.fields.filterKeys { it != "integrity_token" }))

    /** Lower-case hex SHA-256 of the canonical form's UTF-8 bytes: the server's replay check. */
    @JvmStatic
    fun fingerprint(body: JsonValue.Obj): String = sha256Hex(canonical(body))

    /**
     * The server's validation of an install body (`InstallPayload::fromDecoded`),
     * as field path → reason; empty when the server would accept it.
     */
    @JvmStatic
    fun validate(body: JsonValue): Map<String, String> {
        val o = phpObject(body) ?: return mapOf("body" to "must be a JSON object")
        val e = sortedMapOf<String, String>()
        for (k in o.keys) if (k !in TOP) e[k] = "is not an install field"

        val uuid = o["install_uuid"]
        if (uuid !is JsonValue.Str || !UUID.matches(uuid.value)) e["install_uuid"] = "is required: a canonical lower-case UUID"
        val appKey = o["app_key"]
        if (appKey !is JsonValue.Str || appKey.value.isEmpty() || utf8Length(appKey.value) > 255) e["app_key"] = "is required: the application id"
        val store = o["store"]
        if (store !is JsonValue.Str || store.value !in STORES) e["store"] = "is required: one of $STORES"
        o["first_open_at"].let { if (!isNull(it) && !isTime(it)) e["first_open_at"] = "must be unix seconds or null" }
        for ((k, max) in STRING_LIMITS) {
            val v = o[k]
            if (!isNull(v) && !(v is JsonValue.Str && validOptionalString(v.value, max))) e[k] = "must be null or a string of 1-$max printable characters"
        }
        o["test"].let { if (!isNull(it) && it !is JsonValue.Bool) e["test"] = "must be true or false" }
        o["integrity_token"].let {
            if (!isNull(it) && !(it is JsonValue.Str && it.value.isNotEmpty() && utf8Length(it.value) <= MAX_INTEGRITY_TOKEN)) {
                e["integrity_token"] = "must be null or a Play Integrity token of up to $MAX_INTEGRITY_TOKEN bytes"
            }
        }
        CustomerClaim.validate(o["customer"], "customer", e)

        val referrer = o["referrer"]
        val r = if (referrer == null) null else phpObject(referrer)
        if (r == null) {
            e["referrer"] = "is required: an object with at least \"status\""
        } else {
            for (k in r.keys) if (k !in REFERRER) e["referrer.$k"] = "is not a referrer field"
            val status = r["status"]
            val known = ReferrerStatus.values().map { it.wire }
            if (status !is JsonValue.Str || status.value !in known) {
                e["referrer.status"] = "is required: one of $known"
            } else if (status.value != "ok") {
                for (k in REFERRER) if (k != "status" && !isNull(r[k])) e["referrer.$k"] = "must be absent or null when status is not ok"
            } else {
                if (r["install_referrer"] !is JsonValue.Str) e["referrer.install_referrer"] = "is required when status is ok"
                for (k in TIMES) if (!isTime(r[k])) e["referrer.$k"] = "is required when status is ok: unix seconds"
                r["install_version"].let {
                    if (!isNull(it) && !(it is JsonValue.Str && validOptionalString(it.value, 64))) e["referrer.install_version"] = "must be null or a string of 1-64 printable characters"
                }
                r["google_play_instant"].let { if (!isNull(it) && it !is JsonValue.Bool) e["referrer.google_play_instant"] = "must be true, false or null" }
            }
        }
        return e
    }

    private fun clampTime(v: Long): Long = if (v in 0..MAX_UNIX_SECONDS) v else 0

    private fun isNull(v: JsonValue?) = v == null || v is JsonValue.Null

    private fun isTime(v: JsonValue?) = v is JsonValue.Int && v.value in 0..MAX_UNIX_SECONDS

    /** The server's rule for an optional short string: 1-max bytes, no control character or DEL. */
    internal fun validOptionalString(v: String, max: Int): Boolean {
        if (v.isEmpty() || utf8Length(v) > max) return false
        return v.none { it.code < 0x20 || it.code == 0x7F }
    }
}

/**
 * A decoded JSON value as PHP's json_decode(…, true) presents it to an
 * `is_array($x) && ($x === [] || !array_is_list($x))` check: an object, or
 * an empty array (PHP cannot tell `{}` from `[]`). Null for anything else.
 */
internal fun phpObject(v: JsonValue): Map<String, JsonValue>? = when {
    v is JsonValue.Obj -> v.fields
    v is JsonValue.Arr && v.items.isEmpty() -> emptyMap()
    else -> null
}

internal fun sha256Hex(s: String): String {
    val digest = MessageDigest.getInstance("SHA-256").digest(s.toByteArray(Charsets.UTF_8))
    val out = StringBuilder(64)
    for (b in digest) {
        val v = b.toInt() and 0xFF
        out.append("0123456789abcdef"[v ushr 4]).append("0123456789abcdef"[v and 0x0F])
    }
    return out.toString()
}

/** The server's `CustomerClaim` rules, shared by the install and events validators. */
internal object CustomerClaim {
    private val FIELDS = listOf("id", "type", "signature")
    private val HEX64 = Regex("^[0-9a-fA-F]{64}$")

    fun validate(raw: JsonValue?, path: String, e: MutableMap<String, String>) {
        if (raw == null || raw is JsonValue.Null) return
        if (raw !is JsonValue.Obj || raw.fields.isEmpty()) {
            e[path] = "must be null or an object {id, type, signature}"
            return
        }
        for (k in raw.fields.keys) if (k !in FIELDS) e["$path.$k"] = "is not a customer field"
        val type = raw["type"]
        var typeName: String? = null
        if (type == null || type is JsonValue.Null) {
            typeName = "custom"
        } else if (type is JsonValue.Str && CustomerId.Type.fromWire(asciiLower(phpTrim(type.value))) != null) {
            typeName = asciiLower(phpTrim(type.value))
        } else {
            e["$path.type"] = "must be null or one of ${CustomerId.Type.values().map { it.wire }}"
        }
        val id = raw["id"]
        if (id !is JsonValue.Str || utf8Length(id.value) > CustomerId.MAX_ID_BYTES) {
            e["$path.id"] = "is required: a string of up to ${CustomerId.MAX_ID_BYTES} bytes"
        } else if (unpairedSurrogateAt(id.value) >= 0) {
            e["$path.id"] = "must be valid Unicode text: it holds an unpaired UTF-16 surrogate at index ${unpairedSurrogateAt(id.value)}"
        } else if (typeName != null && CustomerId.canonical(id.value, typeName) == null) {
            e["$path.id"] = "is not a customer id of type $typeName"
        }
        val sig = raw["signature"]
        if (sig !is JsonValue.Str || !HEX64.matches(sig.value)) e["$path.signature"] = "is required: 64 hexadecimal characters"
    }
}

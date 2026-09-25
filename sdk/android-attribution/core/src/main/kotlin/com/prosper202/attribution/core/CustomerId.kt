package com.prosper202.attribution.core

/**
 * A customer id the operator's own server has signed, as the identity graph
 * reads it (documentation/features/visitor-identity.md; the server's
 * `Prosper202\Identity\CustomerId` and `Api\V3\Apps\Android\CustomerClaim`).
 *
 * The app token ships in every copy of the app, so an id the SDK merely
 * *says* is exactly the request-controlled `cust` a public pixel carries and
 * links nothing. There is therefore no one-argument form: the id comes with
 * `cust_sig`, which only the operator's server can compute —
 *
 *     cust_sig = hex(HMAC-SHA256(linking_key, "<type>:<id>"))
 *
 * — and the app fetches it from that server (never the linking key, which
 * must not ship in a binary). This class checks the id and the signature's
 * shape, canonicalises the id exactly as the server will (the shared vectors
 * in tests/fixtures/app-sdk-contract/customer-id.json pin it), and carries it
 * as the `customer: {id, type, signature}` object of the wire contract.
 */
class CustomerId private constructor(
    /** Trimmed, and lower-cased for the email digests. */
    val id: String,
    val type: Type,
    /** Lower-case hex. */
    val signature: String,
) {
    /** The id vocabulary (the LTV ledger's alias types). An email is sent as a digest, never in the clear. */
    enum class Type(val wire: String) {
        CUSTOM("custom"),
        EMAIL_MD5("email_md5"),
        EMAIL_SHA256("email_sha256"),
        ESP_ID("esp_id"),
        MERCHANT_ID("merchant_id"),
        SUBID("subid");

        companion object {
            fun fromWire(name: String): Type? = values().firstOrNull { it.wire == name }
        }
    }

    /** `"<type>:<id>"`, what the signature covers. */
    val canonical: String get() = type.wire + ":" + id

    /** The `customer` object of the wire contract. */
    fun toWire(): JsonValue.Obj = jsonObj("id" to id.json(), "type" to type.wire.json(), "signature" to signature.json())

    override fun equals(other: Any?): Boolean =
        other is CustomerId && other.id == id && other.type == type && other.signature == signature

    override fun hashCode(): Int = canonical.hashCode() * 31 + signature.hashCode()

    override fun toString(): String = "CustomerId($canonical)"

    companion object {
        /** The alias column's width on the server. */
        const val MAX_ID_BYTES = 255

        /**
         * A signed customer id, or [InvalidCustomerIdException] naming what
         * the server would refuse: an id that is empty once trimmed, longer
         * than [MAX_ID_BYTES], or an email digest that is not one; a
         * signature that is not 64 hexadecimal characters.
         */
        @JvmStatic
        @JvmOverloads
        @Throws(InvalidCustomerIdException::class)
        fun of(id: String, signature: String, type: Type = Type.CUSTOM): CustomerId {
            val value = canonicalValue(id, type)
                ?: throw InvalidCustomerIdException(
                    "id",
                    if (type == Type.EMAIL_MD5 || type == Type.EMAIL_SHA256) {
                        "must be the ${if (type == Type.EMAIL_MD5) "MD5" else "SHA-256"} digest of the email in hexadecimal, never the email itself"
                    } else {
                        "must not be empty"
                    },
                )
            if (utf8Length(id) > MAX_ID_BYTES) {
                throw InvalidCustomerIdException("id", "may be at most $MAX_ID_BYTES bytes")
            }
            val sig = asciiLower(phpTrim(signature))
            if (sig.length != 64 || !sig.all { it in '0'..'9' || it in 'a'..'f' }) {
                throw InvalidCustomerIdException("signature", "must be cust_sig: 64 hexadecimal characters computed by your server")
            }
            return CustomerId(value, type, sig)
        }

        /**
         * The canonical form of a raw id and type name, or null when the
         * server would refuse it — the device's copy of
         * `CustomerId::canonical()`, run against the shared vectors.
         */
        @JvmStatic
        fun canonical(id: String, type: String?): String? {
            var name = asciiLower(phpTrim(type ?: ""))
            if (name.isEmpty()) name = Type.CUSTOM.wire
            val t = Type.fromWire(name) ?: return null
            val value = canonicalValue(id, t) ?: return null
            return t.wire + ":" + value
        }

        /** Read the persisted wire object back; null when it is not one this class would make. */
        internal fun fromWire(v: JsonValue?): CustomerId? {
            val o = v?.objOrNull ?: return null
            val type = Type.fromWire(o["type"]?.stringOrNull ?: return null) ?: return null
            return try {
                of(o["id"]?.stringOrNull ?: return null, o["signature"]?.stringOrNull ?: return null, type)
            } catch (e: InvalidCustomerIdException) {
                null
            }
        }

        private fun canonicalValue(raw: String, type: Type): String? {
            var value = phpTrim(raw)
            if (value.isEmpty()) return null
            if (type == Type.EMAIL_MD5 || type == Type.EMAIL_SHA256) {
                value = asciiLower(value)
                val want = if (type == Type.EMAIL_MD5) 32 else 64
                if (value.length != want || !value.all { it in '0'..'9' || it in 'a'..'f' }) return null
            }
            return value
        }
    }
}

class InvalidCustomerIdException(val field: String, message: String) : IllegalArgumentException("customer.$field $message")

/** PHP's trim(): space, tab, newline, return, NUL and vertical tab, nothing else. */
internal fun phpTrim(s: String): String {
    val strip = { c: Char -> c == ' ' || c == '\t' || c == '\n' || c == '\r' || c == '\u0000' || c == '\u000B' }
    var a = 0
    var b = s.length
    while (a < b && strip(s[a])) a++
    while (b > a && strip(s[b - 1])) b--
    return s.substring(a, b)
}

/** PHP's strtolower(): A-Z only, so the device folds exactly what the server folds. */
internal fun asciiLower(s: String): String {
    val out = StringBuilder(s.length)
    for (c in s) out.append(if (c in 'A'..'Z') c + 32 else c)
    return out.toString()
}

internal fun utf8Length(s: String): Int = s.toByteArray(Charsets.UTF_8).size

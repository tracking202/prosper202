package com.prosper202.attribution.core

import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

/**
 * The install token a Prosper202 store link carries in
 * `referrer=p202%3D<token>` (plan §5.1): `<click_id>.<mac>`, where `mac` is
 * the first 12 bytes of `HMAC-SHA256(key, "p202-install-v1|" + click_id)` in
 * base64url without padding.
 *
 * The SDK never holds the key and never judges a token: it passes Play's
 * referrer string to the server untouched, and the server verifies it. What
 * the SDK does with this class is recognise a Prosper202 referrer, for the
 * app's own diagnostics (the listener's `onInstallRecorded`), and — in tests
 * and operator tooling — mint and verify under a known key. The shape rules
 * and the MAC are pinned by tests/fixtures/app-sdk-contract/android/install-token.json,
 * which the server runs too.
 */
object InstallToken {
    const val DOMAIN = "p202-install-v1|"
    const val MAC_BYTES = 12
    private val SHAPE = Regex("^([1-9][0-9]{0,18})\\.([A-Za-z0-9_-]{16})$")

    /**
     * The click id a token names, when it is shaped like one (canonical
     * decimal click id, at most 2^63 − 1, and a 16-character mac); null
     * otherwise. Says nothing about whether the mac verifies.
     */
    @JvmStatic
    fun clickIdOf(token: String): Long? {
        val m = SHAPE.matchEntire(token) ?: return null
        val digits = m.groupValues[1]
        val id = digits.toLongOrNull() ?: return null
        return if (id.toString() == digits) id else null
    }

    /** The click id when the token verifies under [key] (32 bytes); null otherwise. */
    @JvmStatic
    fun verify(token: String, key: ByteArray): Long? {
        require(key.size == 32) { "the install-token key is 32 bytes" }
        val clickId = clickIdOf(token) ?: return null
        val given = token.substring(token.indexOf('.') + 1)
        val want = mac(clickId, key)
        // Constant time, as the server compares.
        var diff = 0
        for (i in want.indices) diff = diff or (want[i].code xor given[i].code)
        return if (diff == 0) clickId else null
    }

    /** The token for a click under [key] — what the redirect writes into the store link. */
    @JvmStatic
    fun forClick(clickId: Long, key: ByteArray): String {
        require(clickId > 0) { "a click id to sign is positive" }
        require(key.size == 32) { "the install-token key is 32 bytes" }
        return clickId.toString() + "." + mac(clickId, key)
    }

    /**
     * The one Prosper202 token in an install referrer (`p202=<token>&…`),
     * when there is exactly one `p202` parameter and it is shaped like a
     * token. Two `p202` parameters are ambiguous, as on the server, and read
     * as none here.
     */
    @JvmStatic
    fun inReferrer(installReferrer: String): String? {
        val values = ArrayList<String>()
        for (pair in installReferrer.split('&')) {
            if (pair.isEmpty()) continue
            val eq = pair.indexOf('=')
            val name = urldecode(if (eq < 0) pair else pair.substring(0, eq))
            if (name == "p202") values.add(urldecode(if (eq < 0) "" else pair.substring(eq + 1)))
        }
        val only = values.singleOrNull() ?: return null
        return if (clickIdOf(only) != null) only else null
    }

    private fun mac(clickId: Long, key: ByteArray): String {
        val hmac = Mac.getInstance("HmacSHA256")
        hmac.init(SecretKeySpec(key, "HmacSHA256"))
        val raw = hmac.doFinal((DOMAIN + clickId).toByteArray(Charsets.UTF_8)).copyOf(MAC_BYTES)
        return base64Url(raw)
    }

    /** base64url without padding (java.util.Base64 is API 26+; the SDK runs from API 21). */
    internal fun base64Url(bytes: ByteArray): String {
        val alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_"
        val out = StringBuilder()
        var i = 0
        while (i < bytes.size) {
            val b0 = bytes[i].toInt() and 0xFF
            val b1 = if (i + 1 < bytes.size) bytes[i + 1].toInt() and 0xFF else -1
            val b2 = if (i + 2 < bytes.size) bytes[i + 2].toInt() and 0xFF else -1
            out.append(alphabet[b0 ushr 2])
            out.append(alphabet[((b0 and 0x03) shl 4) or (if (b1 < 0) 0 else b1 ushr 4)])
            if (b1 >= 0) out.append(alphabet[((b1 and 0x0F) shl 2) or (if (b2 < 0) 0 else b2 ushr 6)])
            if (b2 >= 0) out.append(alphabet[b2 and 0x3F])
            i += 3
        }
        return out.toString()
    }

    /** PHP's urldecode(): `+` is a space, `%XX` a byte, anything else literal; the bytes read as UTF-8. */
    internal fun urldecode(s: String): String {
        val bytes = java.io.ByteArrayOutputStream()
        var i = 0
        while (i < s.length) {
            val c = s[i]
            if (c == '+') {
                bytes.write(' '.code)
                i++
            } else if (c == '%' && i + 2 < s.length && isHex(s[i + 1]) && isHex(s[i + 2])) {
                bytes.write(s.substring(i + 1, i + 3).toInt(16))
                i += 3
            } else {
                val enc = c.toString().toByteArray(Charsets.UTF_8)
                if (Character.isHighSurrogate(c) && i + 1 < s.length) {
                    val pair = s.substring(i, i + 2).toByteArray(Charsets.UTF_8)
                    bytes.write(pair, 0, pair.size)
                    i += 2
                    continue
                }
                bytes.write(enc, 0, enc.size)
                i++
            }
        }
        return String(bytes.toByteArray(), Charsets.UTF_8)
    }

    private fun isHex(c: Char) = c in '0'..'9' || c in 'a'..'f' || c in 'A'..'F'
}

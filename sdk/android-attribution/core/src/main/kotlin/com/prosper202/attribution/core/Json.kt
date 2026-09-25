package com.prosper202.attribution.core

/**
 * The JSON the SDK reads and writes, with no dependency (plan §5.6: the only
 * dependency is Play's install-referrer client).
 *
 * Two writers, on purpose:
 *
 *  - [Json.write] is ordinary JSON for the wire (events, persisted state);
 *  - [Json.canonical] is the install body's canonical form — the bytes the
 *    server fingerprints to tell a retry from a reused install_uuid, and
 *    Play Integrity's requestHash input from PR 6 on. It has to be byte for
 *    byte what the server's `InstallPayload::canonicalJson()` makes
 *    (`json_encode` with sorted keys, `JSON_UNESCAPED_SLASHES |
 *    JSON_UNESCAPED_UNICODE`), and the contract vectors pin it: keys sorted
 *    by their UTF-8 bytes (PHP's `ksort(SORT_STRING)`, which is not Kotlin's
 *    UTF-16 `compareTo`), control characters as lower-case `\u00xx`, U+2028
 *    and U+2029 escaped (PHP escapes them even under UNESCAPED_UNICODE), DEL
 *    and `/` left alone. An install body carries no fractional number, and
 *    PHP's float spelling (`1.0e-5`) is not Java's (`1.0E-5`), so the
 *    canonical writer refuses a non-integral number rather than guess.
 */
sealed class JsonValue {
    object Null : JsonValue()
    data class Bool(val value: Boolean) : JsonValue()
    /** An integer literal that fits a Long. */
    data class Int(val value: Long) : JsonValue()
    /** Any other number: a fraction or an exponent, or an integer beyond Long. */
    data class Num(val value: Double) : JsonValue()
    data class Str(val value: String) : JsonValue()
    data class Arr(val items: List<JsonValue>) : JsonValue()
    /** Keys keep their order of arrival; the canonical writer sorts. */
    data class Obj(val fields: Map<String, JsonValue>) : JsonValue() {
        operator fun get(key: String): JsonValue? = fields[key]
    }

    val stringOrNull: String? get() = (this as? Str)?.value
    val longOrNull: Long? get() = (this as? Int)?.value
    val objOrNull: Obj? get() = this as? Obj
    val arrOrNull: Arr? get() = this as? Arr
    val boolOrNull: Boolean? get() = (this as? Bool)?.value
}

class JsonException(message: String) : Exception(message)

object Json {
    /** Parse one JSON text (RFC 8259, strictly: no trailing commas, no comments, no leading zeros). */
    @Throws(JsonException::class)
    fun parse(text: String): JsonValue {
        val p = Parser(text)
        p.ws()
        val v = p.value(0)
        p.ws()
        if (p.i != text.length) {
            throw JsonException("trailing characters at offset ${p.i}")
        }
        return v
    }

    /** Ordinary JSON, keys in the object's own order. */
    fun write(value: JsonValue): String = StringBuilder().also { emit(it, value, sort = false) }.toString()

    /**
     * The canonical form (see the class comment). Throws for a
     * non-integral number and for a string that is not valid Unicode (a
     * lone surrogate): neither can occur in a body this SDK builds.
     */
    fun canonical(value: JsonValue): String = StringBuilder().also { emit(it, value, sort = true) }.toString()

    /** Byte order of the UTF-8 encodings: PHP's ksort(SORT_STRING), strcmp. */
    val UTF8_ORDER: Comparator<String> = Comparator { a, b ->
        val x = a.toByteArray(Charsets.UTF_8)
        val y = b.toByteArray(Charsets.UTF_8)
        val n = minOf(x.size, y.size)
        for (k in 0 until n) {
            val d = (x[k].toInt() and 0xFF) - (y[k].toInt() and 0xFF)
            if (d != 0) return@Comparator d
        }
        x.size - y.size
    }

    private fun emit(out: StringBuilder, v: JsonValue, sort: Boolean) {
        when (v) {
            is JsonValue.Null -> out.append("null")
            is JsonValue.Bool -> out.append(if (v.value) "true" else "false")
            is JsonValue.Int -> out.append(v.value)
            is JsonValue.Num -> out.append(number(v.value, sort))
            is JsonValue.Str -> string(out, v.value, sort)
            is JsonValue.Arr -> {
                out.append('[')
                v.items.forEachIndexed { i, item ->
                    if (i > 0) out.append(',')
                    emit(out, item, sort)
                }
                out.append(']')
            }
            is JsonValue.Obj -> {
                out.append('{')
                val keys = if (sort) v.fields.keys.sortedWith(UTF8_ORDER) else v.fields.keys.toList()
                keys.forEachIndexed { i, k ->
                    if (i > 0) out.append(',')
                    string(out, k, sort)
                    out.append(':')
                    emit(out, v.fields.getValue(k), sort)
                }
                out.append('}')
            }
        }
    }

    private fun number(d: Double, canonical: Boolean): String {
        require(!d.isNaN() && !d.isInfinite()) { "JSON has no NaN or infinity" }
        if (canonical) {
            require(d == Math.rint(d) && Math.abs(d) < 9.007199254740992E15) {
                "the canonical form carries no fractional number (got $d)"
            }
            // PHP's JSON_PRESERVE_ZERO_FRACTION: an integral float keeps ".0".
            return d.toLong().toString() + ".0"
        }
        return d.toString()
    }

    private fun string(out: StringBuilder, s: String, canonical: Boolean) {
        out.append('"')
        var i = 0
        while (i < s.length) {
            val c = s[i]
            when {
                c == '"' -> out.append("\\\"")
                c == '\\' -> out.append("\\\\")
                c == '\b' -> out.append("\\b")
                c == '\u000C' -> out.append("\\f")
                c == '\n' -> out.append("\\n")
                c == '\r' -> out.append("\\r")
                c == '\t' -> out.append("\\t")
                c < ' ' || c == '\u2028' || c == '\u2029' -> out.append("\\u").append(String.format("%04x", c.code))
                Character.isHighSurrogate(c) -> {
                    val next = if (i + 1 < s.length) s[i + 1] else '\u0000'
                    if (!Character.isLowSurrogate(next)) {
                        if (canonical) throw IllegalArgumentException("a lone surrogate is not Unicode")
                        out.append("\\u").append(String.format("%04x", c.code))
                    } else {
                        out.append(c).append(next)
                        i++
                    }
                }
                Character.isLowSurrogate(c) -> {
                    if (canonical) throw IllegalArgumentException("a lone surrogate is not Unicode")
                    out.append("\\u").append(String.format("%04x", c.code))
                }
                else -> out.append(c)
            }
            i++
        }
        out.append('"')
    }

    private class Parser(val s: String) {
        var i = 0

        fun ws() {
            while (i < s.length && (s[i] == ' ' || s[i] == '\t' || s[i] == '\n' || s[i] == '\r')) i++
        }

        fun fail(what: String): Nothing = throw JsonException("$what at offset $i")

        fun value(depth: Int): JsonValue {
            if (depth > 64) fail("nesting deeper than 64")
            if (i >= s.length) fail("unexpected end")
            return when (val c = s[i]) {
                '{' -> obj(depth)
                '[' -> arr(depth)
                '"' -> JsonValue.Str(str())
                't' -> literal("true", JsonValue.Bool(true))
                'f' -> literal("false", JsonValue.Bool(false))
                'n' -> literal("null", JsonValue.Null)
                else -> if (c == '-' || c in '0'..'9') num() else fail("unexpected '$c'")
            }
        }

        fun literal(word: String, v: JsonValue): JsonValue {
            if (!s.startsWith(word, i)) fail("expected $word")
            i += word.length
            return v
        }

        fun obj(depth: Int): JsonValue {
            i++
            val fields = LinkedHashMap<String, JsonValue>()
            ws()
            if (i < s.length && s[i] == '}') {
                i++
                return JsonValue.Obj(fields)
            }
            while (true) {
                ws()
                if (i >= s.length || s[i] != '"') fail("expected a key")
                val k = str()
                ws()
                if (i >= s.length || s[i] != ':') fail("expected ':'")
                i++
                ws()
                fields[k] = value(depth + 1)
                ws()
                if (i >= s.length) fail("unexpected end")
                if (s[i] == ',') {
                    i++
                    continue
                }
                if (s[i] == '}') {
                    i++
                    return JsonValue.Obj(fields)
                }
                fail("expected ',' or '}'")
            }
        }

        fun arr(depth: Int): JsonValue {
            i++
            val items = ArrayList<JsonValue>()
            ws()
            if (i < s.length && s[i] == ']') {
                i++
                return JsonValue.Arr(items)
            }
            while (true) {
                ws()
                items.add(value(depth + 1))
                ws()
                if (i >= s.length) fail("unexpected end")
                if (s[i] == ',') {
                    i++
                    continue
                }
                if (s[i] == ']') {
                    i++
                    return JsonValue.Arr(items)
                }
                fail("expected ',' or ']'")
            }
        }

        fun str(): String {
            i++
            val out = StringBuilder()
            while (true) {
                if (i >= s.length) fail("unterminated string")
                val c = s[i++]
                when {
                    c == '"' -> return out.toString()
                    c == '\\' -> {
                        if (i >= s.length) fail("unterminated escape")
                        when (val e = s[i++]) {
                            '"' -> out.append('"')
                            '\\' -> out.append('\\')
                            '/' -> out.append('/')
                            'b' -> out.append('\b')
                            'f' -> out.append('\u000C')
                            'n' -> out.append('\n')
                            'r' -> out.append('\r')
                            't' -> out.append('\t')
                            'u' -> {
                                if (i + 4 > s.length) fail("short \\u escape")
                                val hex = s.substring(i, i + 4)
                                if (!hex.all { it in '0'..'9' || it in 'a'..'f' || it in 'A'..'F' }) fail("bad \\u escape")
                                out.append(hex.toInt(16).toChar())
                                i += 4
                            }
                            else -> fail("bad escape '\\$e'")
                        }
                    }
                    c < ' ' -> fail("raw control character in a string")
                    else -> out.append(c)
                }
            }
        }

        fun num(): JsonValue {
            val start = i
            if (s[i] == '-') i++
            if (i >= s.length) fail("bad number")
            if (s[i] == '0') {
                i++
            } else if (s[i] in '1'..'9') {
                while (i < s.length && s[i] in '0'..'9') i++
            } else {
                fail("bad number")
            }
            var integral = true
            if (i < s.length && s[i] == '.') {
                integral = false
                i++
                if (i >= s.length || s[i] !in '0'..'9') fail("bad fraction")
                while (i < s.length && s[i] in '0'..'9') i++
            }
            if (i < s.length && (s[i] == 'e' || s[i] == 'E')) {
                integral = false
                i++
                if (i < s.length && (s[i] == '+' || s[i] == '-')) i++
                if (i >= s.length || s[i] !in '0'..'9') fail("bad exponent")
                while (i < s.length && s[i] in '0'..'9') i++
            }
            val text = s.substring(start, i)
            if (integral) {
                text.toLongOrNull()?.let { return JsonValue.Int(it) }
            }
            return JsonValue.Num(text.toDouble())
        }
    }
}

/** Small builders, so the SDK's bodies read as the wire does. */
fun jsonObj(vararg pairs: Pair<String, JsonValue>): JsonValue.Obj = JsonValue.Obj(linkedMapOf(*pairs))
fun String?.json(): JsonValue = if (this == null) JsonValue.Null else JsonValue.Str(this)
fun Long?.json(): JsonValue = if (this == null) JsonValue.Null else JsonValue.Int(this)
fun Boolean?.json(): JsonValue = if (this == null) JsonValue.Null else JsonValue.Bool(this)

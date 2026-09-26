package com.prosper202.attribution.core

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith

/**
 * The canonical writer against PHP's own output. The expected strings were
 * produced by the server's encoder (`json_encode(…, JSON_UNESCAPED_SLASHES |
 * JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)` after
 * `ksort(SORT_STRING)`), executed, not recalled: PHP escapes U+2028 even
 * under UNESCAPED_UNICODE, writes control characters as lower-case
 * `\u00xx`, leaves DEL alone, and sorts keys by UTF-8 bytes — so U+FF5E
 * sorts before U+1F600, which UTF-16 order would reverse.
 */
class JsonTest {
    @Test
    fun stringsAreEscapedAsPhpEscapesThem() {
        val s = "x\u001f\u007f\u2028\u00e9/\"\\\b\u000c\n\r\t"
        assertEquals("{\"a\":\"x\\u001f\u007f\\u2028\u00e9/\\\"\\\\\\b\\f\\n\\r\\t\"}", Json.canonical(jsonObj("a" to s.json())))
    }

    @Test
    fun keysSortByTheirUtf8Bytes() {
        val obj = JsonValue.Obj(linkedMapOf("\u00e9" to JsonValue.Int(1), "z" to JsonValue.Int(2), "Z" to JsonValue.Int(3), "\uff5e" to JsonValue.Int(4), "\ud83d\ude00" to JsonValue.Int(5)))
        assertEquals("{\"Z\":3,\"z\":2,\"\u00e9\":1,\"\uff5e\":4,\"\ud83d\ude00\":5}", Json.canonical(obj))
    }

    @Test
    fun nestedObjectsSortAndListsKeepTheirOrder() {
        val v = Json.parse("""{"b":{"y":1,"x":[3,1,{"q":true,"p":null}]},"a":"s"}""")
        assertEquals("""{"a":"s","b":{"x":[3,1,{"p":null,"q":true}],"y":1}}""", Json.canonical(v))
    }

    @Test
    fun theCanonicalFormRefusesWhatItCannotSpellLikePhp() {
        assertEquals("{\"n\":3.0}", Json.canonical(jsonObj("n" to JsonValue.Num(3.0))))
        assertFailsWith<IllegalArgumentException> { Json.canonical(jsonObj("n" to JsonValue.Num(4.99))) }
        assertFailsWith<IllegalArgumentException> { Json.canonical(jsonObj("s" to "\ud800".json())) }
    }

    @Test
    fun theParserIsStrictAndKeepsIntegersIntegers() {
        assertEquals(JsonValue.Int(1727200000), Json.parse("1727200000"))
        assertEquals(JsonValue.Num(1727200000.0), Json.parse("1727200000.0"))
        assertEquals(JsonValue.Num(1e3), Json.parse("1e3"))
        // Beyond a Long is not an integer the server would take either
        // (json_decode's BIGINT_AS_STRING makes it a string).
        assertEquals(JsonValue.Num(9.223372036854775808E18), Json.parse("9223372036854775808"))
        for (bad in listOf("", "{", "[1,]", "{\"a\":1,}", "01", "1.", "-", "tru", "\"\u0001\"", "{} x", "'a'", "NaN")) {
            assertFailsWith<JsonException>(bad) { Json.parse(bad) }
        }
        assertEquals(JsonValue.Str("\u00e9\u2028"), Json.parse("\"\\u00e9\\u2028\""))
    }

    @Test
    fun theWireWriterRoundTrips() {
        val text = """{"events":[{"event_id":"e-1","name":"purchase","occurred_at":1727200600,"revenue":4.99,"properties":{"s":"a\"b","n":-3,"f":0.5,"t":true}}]}"""
        assertEquals(text, Json.write(Json.parse(text)))
    }
}

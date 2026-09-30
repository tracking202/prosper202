package com.prosper202.attribution.core

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * Every string the app hands the SDK is well-formed Unicode by the time it
 * is accepted.
 *
 * A Kotlin/Java String can hold an unpaired UTF-16 surrogate (a string cut
 * mid-pair, an encoding bug upstream). Json.canonical() refuses one, so a
 * customer id carrying it passed setCustomerId(), was persisted in the
 * install body, and then made every sendInstall() throw before any request:
 * the install was never sent and events never flushed, forever, with only a
 * Logcat warning a minute. An event property or transaction id carrying one
 * is refused by the server's json_decode. Each is now refused where the app
 * passes it, by name.
 */
class UnicodeInputTest {
    private val sig = "c21cbbf0bddfc93538dd9329f809dbb663e3029dc51fb6c145dc2fbae3e670b4"

    /** Lone high, lone low at each end and inside, and a pair in the wrong order. */
    private val illFormed = listOf("abc\uD800def", "\uD800", "x\uD800", "\uDC00x", "ab\uDC00", "\uDC00\uD83D", "\uDE00\uD83D")

    @Test
    fun unpairedSurrogatesAreFoundAndPairsAreNot() {
        assertEquals(-1, unpairedSurrogateAt("u-829"))
        assertEquals(-1, unpairedSurrogateAt("café 😀 𝄞"), "a valid pair is one code point")
        assertEquals(3, unpairedSurrogateAt("abc\uD800def"))
        assertEquals(1, unpairedSurrogateAt("x\uD800"))
        assertEquals(0, unpairedSurrogateAt("\uDC00x"))
        assertEquals(0, unpairedSurrogateAt("\uDE00\uD83D"), "a low before its high pairs with nothing")
        assertEquals(2, unpairedSurrogateAt("😀\uD83D"), "a pair then a lone high")
    }

    @Test
    fun aCustomerIdThatIsNotUnicodeIsRefusedWhereTheAppPassesIt() {
        for (id in illFormed) {
            val e = assertFailsWith<InvalidCustomerIdException>(id.map { it.code.toString(16) }.toString()) { CustomerId.of(id, sig) }
            assertEquals("id", e.field)
            assertTrue("unpaired UTF-16 surrogate" in (e.message ?: ""), e.message)
            for (type in CustomerId.Type.values()) {
                assertFailsWith<InvalidCustomerIdException> { CustomerId.of(id, sig, type) }
                assertNull(CustomerId.canonical(id, type.wire))
            }
        }
        // A well-formed id with a supplementary character is a customer id.
        val ok = CustomerId.of("u-😀", sig)
        assertEquals("custom:u-😀", ok.canonical)
        Json.canonical(ok.toWire())
    }

    @Test
    fun theInstallBodyValidatorRefusesItToo() {
        val errors = LinkedHashMap<String, String>()
        CustomerClaim.validate(jsonObj("id" to "u-\uD800".json(), "type" to "custom".json(), "signature" to sig.json()), "customer", errors)
        assertTrue("unpaired UTF-16 surrogate" in (errors["customer.id"] ?: ""), errors.toString())
    }

    @Test
    fun anEventPropertyOrTransactionIdThatIsNotUnicodeIsRefusedByName() {
        for (bad in illFormed) {
            val p = assertFailsWith<InvalidEventException> { EventPayload.event("e1", "purchase", 1_700_000_000, mapOf("sku" to bad), null, null) }
            assertTrue("unpaired UTF-16 surrogate" in (p.fieldErrors["event.properties.sku"] ?: ""), p.fieldErrors.toString())
            val t = assertFailsWith<InvalidEventException> { EventPayload.event("e1", "purchase", 1_700_000_000, emptyMap(), 1.0, bad) }
            assertTrue("unpaired UTF-16 surrogate" in (t.fieldErrors["event.transaction_id"] ?: ""), t.fieldErrors.toString())
        }
        // Names and ids are ASCII by their patterns, so a surrogate there is
        // already refused.
        assertFailsWith<InvalidEventException> { EventPayload.event("e\uD800", "purchase", 1_700_000_000, emptyMap(), null, null) }
        assertFailsWith<InvalidEventException> { EventPayload.event("e1", "purchase\uD800", 1_700_000_000, emptyMap(), null, null) }
        assertFailsWith<InvalidEventException> { EventPayload.event("e1", "purchase", 1_700_000_000, mapOf("k\uD800" to "v"), null, null) }
        // Well-formed text is accepted.
        val ok = EventPayload.event("e1", "purchase", 1_700_000_000, mapOf("sku" to "café 😀"), 2.5, "tx-😀")
        assertTrue(Json.write(ok).contains("caf\u00e9 \uD83D\uDE00"))
    }

    @Test
    fun configurationStringsThatAreNotUnicodeAreRefused() {
        val token = "a".repeat(64)
        for ((field, make) in listOf<Pair<String, () -> Unit>>(
            "endpoint" to { AttributionConfig.of("https://track.example.com/\uD800", token, "com.example.app") },
            "appVersion" to { AttributionConfig.of("https://track.example.com", token, "com.example.app", appVersion = "1.0\uD800") },
            "osVersion" to { AttributionConfig.of("https://track.example.com", token, "com.example.app", osVersion = "\uDC0014") },
        )) {
            val e = assertFailsWith<IllegalArgumentException>(field) { make() }
            assertTrue((e.message ?: "").startsWith("$field must be valid Unicode"), e.message)
        }
        assertNotNull(AttributionConfig.of("https://track.example.com", token, "com.example.app", appVersion = "1.0 😀", osVersion = "14"))
    }
}

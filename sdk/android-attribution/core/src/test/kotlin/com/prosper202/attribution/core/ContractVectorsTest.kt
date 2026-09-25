package com.prosper202.attribution.core

import java.io.File
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue
import kotlin.test.fail

/**
 * The cross-language vectors in tests/fixtures/app-sdk-contract/ (plan
 * §4.3), the same files the PHP suite runs (AndroidContractVectorsTest,
 * CustomerIdVectorsTest, AppIdentityTest) and — for customer-id.json — the
 * Swift suite. They were written by an implementation independent of both
 * (Python's hmac, hashlib and json), so a pass is three implementations
 * agreeing on the bytes.
 */
class ContractVectorsTest {
    companion object {
        fun dir(): File {
            val path = System.getProperty("p202.contractDir") ?: fail("p202.contractDir is not set (run through Gradle)")
            return File(path).also { assertTrue(it.isDirectory, "no contract vectors at $it") }
        }

        fun vectors(name: String): JsonValue.Obj = Json.parse(File(dir(), name).readText()).objOrNull ?: fail("$name is not an object")

        fun hex(s: String): ByteArray = ByteArray(s.length / 2) { s.substring(it * 2, it * 2 + 2).toInt(16).toByte() }

        fun JsonValue?.list(): List<JsonValue> = this?.arrOrNull?.items ?: fail("expected a list")

        fun JsonValue?.str(): String = this?.stringOrNull ?: fail("expected a string")
    }

    @Test
    fun everyTokenVerifiesToItsClickAndIsMintedTheSameWay() {
        val v = vectors("android/install-token.json")
        val key = hex(v["key_hex"].str())
        assertEquals(InstallToken.DOMAIN, v["domain"].str())
        val tokens = v["tokens"].list()
        assertTrue(tokens.size >= 5)
        for (t in tokens) {
            val o = t.objOrNull!!
            val clickId = o["click_id"]!!.longOrNull!!
            val token = o["token"].str()
            assertEquals(token, InstallToken.forClick(clickId, key), "minted for $clickId")
            assertEquals(clickId, InstallToken.verify(token, key))
            assertEquals(clickId, InstallToken.clickIdOf(token))
            assertEquals(token, InstallToken.inReferrer("p202=$token&utm_source=news"), "recognised in a referrer")
        }
    }

    @Test
    fun malformedTokensAreNotTokensAndWrongMacsDoNotVerify() {
        val v = vectors("android/install-token.json")
        val key = hex(v["key_hex"].str())
        val malformed = v["malformed"].list()
        assertTrue(malformed.size >= 15)
        for (bad in malformed) {
            assertNull(InstallToken.clickIdOf(bad.str()), "\"${bad.str()}\" is not shaped like a token")
            assertNull(InstallToken.verify(bad.str(), key))
        }
        for (case in v["wrong_mac"].list()) {
            val token = case.objOrNull!!["token"].str()
            assertNotNull(InstallToken.clickIdOf(token), "${case.objOrNull!!["name"].str()}: shaped like a token")
            assertNull(InstallToken.verify(token, key), case.objOrNull!!["name"].str())
        }
    }

    @Test
    fun everyInstallBodyIsReadAsTheServerReadsIt() {
        val cases = vectors("android/install-requests.json")["cases"].list()
        assertTrue(cases.size >= 30, "the install vectors (with the customer cases) hold ${cases.size}")
        var valid = 0
        for (c in cases) {
            val case = c.objOrNull!!
            val name = case["name"].str()
            val body = case["body"]!!
            val expect = case["expect"]!!.objOrNull!!
            val errors = InstallPayload.validate(body)
            if (expect["valid"]!!.boolOrNull == true) {
                valid++
                assertEquals(emptyMap(), errors, name)
                val obj = body.objOrNull!!
                assertEquals(expect["canonical"].str(), InstallPayload.canonical(obj), "$name: canonical")
                assertEquals(expect["fingerprint"].str(), InstallPayload.fingerprint(obj), "$name: fingerprint")
                // Key order and whitespace are not content.
                val reordered = JsonValue.Obj(obj.fields.entries.reversed().associate { it.key to it.value })
                assertEquals(expect["fingerprint"].str(), InstallPayload.fingerprint(reordered), "$name: key order")
                val claim = obj["customer"]?.let { if (it is JsonValue.Null) null else it.objOrNull }
                val want = expect["customer"]?.stringOrNull
                assertEquals(want, claim?.let { CustomerId.canonical(it["id"].str(), it["type"]?.stringOrNull) }, "$name: customer")
            } else {
                assertEquals(400L, expect["status"]!!.longOrNull)
                assertEquals(expect["field_errors"].list().map { it.str() }.sorted(), errors.keys.sorted(), name)
            }
        }
        assertTrue(valid >= 10)
    }

    @Test
    fun everyEventsBodyIsReadAsTheServerReadsIt() {
        val v = vectors("android/events-requests.json")
        assertEquals(EventPayload.MAX_EVENTS.toLong(), v["max_events"]!!.longOrNull)
        val cases = v["cases"].list()
        assertTrue(cases.size >= 20)
        for (c in cases) {
            val case = c.objOrNull!!
            val name = case["name"].str()
            val expect = case["expect"]!!.objOrNull!!
            val errors = EventPayload.validateBody(case["body"]!!)
            if (expect["valid"]!!.boolOrNull == true) {
                assertEquals(emptyMap(), errors, name)
            } else {
                assertEquals(expect["field_errors"].list().map { it.str() }.sorted(), errors.keys.sorted(), name)
            }
        }
    }

    @Test
    fun theSdkBuildsEveryValidEventTheVectorsHoldAndRefusesWhatTheyRefuse() {
        // logEvent's builder against the vectors' single events: each valid
        // one round-trips through EventPayload.event unchanged; each refused
        // one is refused by the builder too, naming the same fields.
        for (c in vectors("android/events-requests.json")["cases"].list()) {
            val case = c.objOrNull!!
            val events = case["body"]!!.objOrNull?.get("events")?.arrOrNull?.items ?: continue
            if (events.size != 1 || case["body"]!!.objOrNull!!.fields.size != 1) continue
            val e = events[0].objOrNull ?: continue
            val keys = e.fields.keys
            if (!keys.all { it in listOf("event_id", "name", "occurred_at", "properties", "revenue", "transaction_id") }) continue
            val id = e["event_id"]?.stringOrNull ?: continue
            val name = e["name"]?.stringOrNull ?: continue
            val at = e["occurred_at"]?.longOrNull ?: continue
            val props = e["properties"]?.objOrNull?.fields?.mapValues { (_, v) -> kotlinValue(v) } ?: emptyMap()
            val revenue = when (val r = e["revenue"]) {
                null, is JsonValue.Null -> null
                is JsonValue.Int -> r.value.toDouble()
                is JsonValue.Num -> r.value
                else -> continue
            }
            val tx = e["transaction_id"]?.stringOrNull
            val expect = case["expect"]!!.objOrNull!!
            if (expect["valid"]!!.boolOrNull == true) {
                val built = EventPayload.event(id, name, at, props, revenue, tx)
                assertEquals(emptyMap(), EventPayload.validateBody(jsonObj("events" to JsonValue.Arr(listOf(built)))), case["name"].str())
            } else {
                val err = assertFailsWith<InvalidEventException>(case["name"].str()) { EventPayload.event(id, name, at, props, revenue, tx) }
                assertEquals(expect["field_errors"].list().map { it.str().replace("events[0]", "event") }.sorted(), err.fieldErrors.keys.sorted())
            }
        }
    }

    private fun kotlinValue(v: JsonValue): Any? = when (v) {
        is JsonValue.Str -> v.value
        is JsonValue.Bool -> v.value
        is JsonValue.Int -> v.value
        is JsonValue.Num -> v.value
        else -> listOf(v)
    }

    @Test
    fun theAnswersAreRetriedExactlyAsTheContractSays() {
        val v = vectors("android/responses.json")
        assertEquals(Answers.MATCH_STATES, v["match_states"].list().map { it.str() })
        for (route in listOf("installs", "events")) {
            for (row in v[route].list()) {
                val o = row.objOrNull!!
                val status = o["status"]!!.longOrNull!!.toInt()
                assertEquals(o["retry"]!!.boolOrNull, Answers.isRetried(status), "$route $status")
            }
        }
        assertTrue(v["installs"].list().any { it.objOrNull!!["when"].str().contains(InstallPayload.MAX_BODY_BYTES.toString()) })
        assertTrue(v["events"].list().any { it.objOrNull!!["when"].str().contains(EventPayload.MAX_BODY_BYTES.toString()) })
    }

    @Test
    fun customerIdsAreCanonicalisedAsTheServerDoes() {
        val v = vectors("customer-id.json")
        assertEquals(1L, v["format_version"]!!.longOrNull, "this suite reads format_version 1")
        val cases = v["cases"].list()
        assertTrue(cases.size >= 10)
        for (c in cases) {
            val o = c.objOrNull!!
            val want = o["expect"]?.stringOrNull
            val got = CustomerId.canonical(o["id"].str(), o["type"]?.stringOrNull)
            assertEquals(want, got, o["name"].str())
            // The public constructor agrees: it accepts exactly the ids the
            // canonical form accepts, and keeps the canonical value.
            val type = CustomerId.Type.fromWire(asciiLower(phpTrim(o["type"]?.stringOrNull ?: "")).ifEmpty { "custom" })
            if (type != null) {
                val sig = "0".repeat(64)
                if (want == null) {
                    assertFailsWith<InvalidCustomerIdException>(o["name"].str()) { CustomerId.of(o["id"].str(), sig, type) }
                } else {
                    assertEquals(want, CustomerId.of(o["id"].str(), sig, type).canonical, o["name"].str())
                }
            }
        }
    }

    @Test
    fun customerSignaturesAreCheckedForShapeAndTheVectorsAgreeWithHmac() {
        val v = vectors("customer-id.json")
        val key = hex(v["linking_key"].str())
        for (c in v["signatures"].list()) {
            val o = c.objOrNull!!
            val canonical = o["canonical"].str()
            val signature = o["signature"].str()
            val name = o["name"].str()
            // What the SDK checks without the key: the shape.
            val wellFormed = try {
                CustomerId.of(canonical.substringAfter(':'), signature, CustomerId.Type.fromWire(canonical.substringBefore(':'))!!)
                true
            } catch (e: InvalidCustomerIdException) {
                assertEquals("signature", e.field, name)
                false
            }
            assertEquals(o["well_formed"]!!.boolOrNull, wellFormed, name)
            // What only the server can check, done here to prove the vectors
            // are what an operator's server computes.
            val mac = Mac.getInstance("HmacSHA256").apply { init(SecretKeySpec(key, "HmacSHA256")) }
                .doFinal(canonical.toByteArray()).joinToString("") { "%02x".format(it) }
            assertEquals(o["valid"]!!.boolOrNull, mac == signature.lowercase(), name)
        }
    }

    @Test
    fun theBuildsApplicationIdIsReadByTheServersRule() {
        var n = 0
        for (c in vectors("app-identity.json")["keys"].list()) {
            val o = c.objOrNull!!
            val platform = o["platform"]
            val android = platform?.stringOrNull == "android" || ((platform == null || platform is JsonValue.Null) && o["expect_platform"]?.stringOrNull != "ios")
            if (!android) continue
            n++
            val key = o["app_key"]
            val want = o["expect"]?.stringOrNull
            assertEquals(want, key?.stringOrNull?.let { AppKey.android(it) }, o["name"].str())
        }
        assertTrue(n >= 3)
    }
}

package com.prosper202.attribution.core

import java.io.File
import java.nio.file.Files
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertFalse
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * The engine on virtual time against a scripted server: what it sends,
 * when, how often, and what it keeps across a relaunch. The server's own
 * behaviour is not faked beyond the contract's answers
 * (responses.json); `tests/live/android-sdk.sh` runs the same engine
 * against a real instance.
 */
class AttributionEngineTest {
    private val time = VirtualTime()
    private val store = InMemoryStore()
    private val http = FakeTransport()
    private val referrer = FakeReferrer(REFERRER)
    private val listener = RecordingListener()
    private var integrity: IntegrityProvider = IntegrityProvider.NONE

    private fun engine(s: KeyValueStore = store) =
        AttributionEngine(s, http, time, time, referrer, { integrity.tokenFor(it) }, listener, SilentLogger, random = { 1.0 })

    private fun started(cfg: AttributionConfig = config()): AttributionEngine = engine().also {
        it.configure(cfg)
        time.settle()
    }

    // ------------------------------------------------------------ install

    @Test
    fun theInstallIsBuiltOncePersistedAndSentOnceWithTheAppToken() {
        val e = started()
        assertEquals(1, http.installs().size)
        val r = http.installs()[0]
        assertEquals("https://track.example.com/api/v3/apps/installs", r.url)
        assertEquals(mapOf(AttributionEngine.HEADER to TOKEN), r.headers)
        val body = r.json
        assertEquals(emptyMap(), InstallPayload.validate(body), "the SDK sends only what the server accepts")
        assertEquals(e.installUuid, body["install_uuid"]!!.stringOrNull)
        assertEquals(REFERRER.installReferrer, body["referrer"]!!.objOrNull!!["install_referrer"]!!.stringOrNull)
        assertEquals("1.0.0", body["sdk_version"]!!.stringOrNull)
        assertEquals(1_727_200_100L, body["first_open_at"]!!.longOrNull)
        assertEquals("attributed", e.installMatch)
        assertEquals(listOf("attributed"), listener.recorded)

        // A relaunch neither reads the referrer nor resends, keeps the
        // install's id, and sends its events under the id the server knows.
        val relaunched = engine()
        relaunched.configure(config())
        time.advance(60_000)
        assertEquals(1, http.installs().size)
        assertEquals(1, referrer.reads)
        assertEquals(e.installUuid, relaunched.installUuid)
        relaunched.logEvent("after_relaunch")
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        assertEquals("https://track.example.com/api/v3/apps/installs/${body["install_uuid"]!!.stringOrNull}/events", http.events().single().url)
    }

    @Test
    fun aRetryResendsTheSameBytesWithBackoffAndRetryAfterAndSurvivesARelaunch() {
        http.then(503, """{"error":true}""", mapOf("retry-after" to "300")).then(500).thenFail().then(429)
        engine().configure(config())
        time.settle()
        assertEquals(1, http.installs().size)
        time.advance(299_000)
        assertEquals(1, http.installs().size, "Retry-After: 300 is honoured")
        time.advance(1_000)
        assertEquals(2, http.installs().size)
        // Backoff: 30 s after the first counted failure, doubling (jitter pinned high).
        assertEquals(time.now + 60_000, store.get(AttributionEngine.K_NEXT_AT)!!.toLong(), "second retry: 60 s")
        time.advance(60_000)
        assertEquals(3, http.installs().size, "a network failure is retried too")

        // The process dies; a new one waits out the persisted time.
        val waitUntil = store.get(AttributionEngine.K_NEXT_AT)!!.toLong()
        assertEquals(time.now + 120_000, waitUntil)
        val relaunched = VirtualTime(time.now)
        val e2 = AttributionEngine(store, http, relaunched, relaunched, referrer, IntegrityProvider.NONE, listener, SilentLogger) { 1.0 }
        e2.configure(config())
        relaunched.advance(119_000)
        assertEquals(3, http.installs().size)
        relaunched.advance(1_000)
        assertEquals(4, http.installs().size)
        relaunched.advance(240_000)
        assertEquals(5, http.installs().size)
        assertEquals("attributed", e2.installMatch)

        // Every attempt carried the same body, byte for byte.
        assertEquals(1, http.installs().map { it.body }.toSet().size)
        assertEquals(1, referrer.reads)
    }

    @Test
    fun aRefusalIsTerminalUntilTheTokenOrEndpointChanges() {
        http.then(404, """{"error":true,"message":"Unknown app token","status":404}""")
        val e = started()
        time.advance(24 * 3600_000L)
        assertEquals(1, http.installs().size, "a 4xx is never resent unchanged")
        assertEquals(listOf(404), listener.refused)
        assertNull(e.installMatch)

        engine().configure(config())
        time.advance(3600_000)
        assertEquals(1, http.installs().size, "the same token is still refused")

        engine().configure(config(OTHER_TOKEN))
        time.settle()
        assertEquals(2, http.installs().size, "a build with a corrected token re-arms it")
        assertEquals(http.installs()[0].body, http.installs()[1].body, "the same install: same body")
        assertEquals(OTHER_TOKEN, http.installs()[1].headers[AttributionEngine.HEADER])
    }

    @Test
    fun everyTerminalStatusInTheContractIsTerminalAndEveryRetriedOneIsRetried() {
        for (status in listOf(400, 404, 405, 409, 413, 422, 301)) {
            val t = VirtualTime()
            val h = FakeTransport().then(status, "{}")
            val e = AttributionEngine(InMemoryStore(), h, t, t, FakeReferrer(REFERRER), IntegrityProvider.NONE, RecordingListener(), SilentLogger) { 1.0 }
            e.configure(config())
            t.advance(7 * 24 * 3600_000L)
            assertEquals(1, h.installs().size, "status $status")
        }
        for (status in listOf(429, 500, 502, 503, 504)) {
            val t = VirtualTime()
            val h = FakeTransport().then(status, "{}")
            val e = AttributionEngine(InMemoryStore(), h, t, t, FakeReferrer(REFERRER), IntegrityProvider.NONE, RecordingListener(), SilentLogger) { 1.0 }
            e.configure(config())
            t.advance(3600_000L)
            assertEquals(2, h.installs().size, "status $status")
            assertEquals("attributed", e.installMatch)
        }
        // A 200 that is not the contract's answer (a captive portal) is retried, not believed.
        val t = VirtualTime()
        val h = FakeTransport().then(200, "<html>Sign in to Wi-Fi</html>")
        val e = AttributionEngine(InMemoryStore(), h, t, t, FakeReferrer(REFERRER), IntegrityProvider.NONE, RecordingListener(), SilentLogger) { 1.0 }
        e.configure(config())
        t.advance(3600_000L)
        assertEquals(2, h.installs().size)
        assertEquals("attributed", e.installMatch)
    }

    @Test
    fun theBackoffDoublesToItsCapWithJitterBelowIt() {
        val e = AttributionEngine(store, http, time, time, referrer, IntegrityProvider.NONE, listener, SilentLogger) { 0.0 }
        assertEquals(15_000L, e.backoff(1))
        assertEquals(30_000L, e.backoff(2))
        assertEquals(AttributionEngine.MAX_BACKOFF_MILLIS / 2, e.backoff(30))
        assertEquals(AttributionEngine.MAX_BACKOFF_MILLIS, engine().backoff(40))
    }

    @Test
    fun aTransientReferrerAnswerIsReadAgainBeforeItIsReported() {
        referrer.details = ReferrerDetails.unavailable(ReferrerStatus.SERVICE_UNAVAILABLE)
        engine().configure(config())
        time.settle()
        assertEquals(1, referrer.reads)
        assertEquals(0, http.installs().size)
        time.advance(10_000)
        assertEquals(2, referrer.reads)
        time.advance(60_000)
        assertEquals(3, referrer.reads)
        assertEquals(1, http.installs().size, "after ${AttributionEngine.MAX_REFERRER_READS} reads it is reported as it is")
        assertEquals("""{"status":"service_unavailable"}""", Json.write(http.installs()[0].json["referrer"]!!))

        // A read that recovers is reported as ok.
        val t = VirtualTime()
        val h = FakeTransport()
        val src = FakeReferrer(ReferrerDetails.unavailable(ReferrerStatus.SERVICE_DISCONNECTED))
        AttributionEngine(InMemoryStore(), h, t, t, src, IntegrityProvider.NONE, RecordingListener(), SilentLogger) { 1.0 }.configure(config())
        t.settle()
        src.details = REFERRER
        t.advance(10_000)
        assertEquals("ok", h.installs().single().json["referrer"]!!.objOrNull!!["status"]!!.stringOrNull)
    }

    @Test
    fun aReferrerThatNeverAnswersCountsAsUnavailable() {
        referrer.silent = true
        engine().configure(config())
        time.advance(AttributionEngine.REFERRER_TIMEOUT_MILLIS * 3 + 70_000)
        assertEquals(3, referrer.reads)
        assertEquals("service_unavailable", http.installs().single().json["referrer"]!!.objOrNull!!["status"]!!.stringOrNull)
    }

    @Test
    fun aPermanentReferrerErrorIsReportedAtOnce() {
        referrer.details = ReferrerDetails.unavailable(ReferrerStatus.FEATURE_NOT_SUPPORTED)
        started()
        assertEquals(1, referrer.reads)
        assertEquals("""{"status":"feature_not_supported"}""", Json.write(http.installs().single().json["referrer"]!!))
    }

    @Test
    fun deviceFactsTheServerWouldRefuseAreSentAsNull() {
        val cfg = AttributionConfig.of("https://track.example.com", TOKEN, "com.example.summit", "3.2.0\u0000beta", "x".repeat(40))
        started(cfg)
        val body = http.installs().single().json
        assertEquals(JsonValue.Null, body["app_version"])
        assertEquals(JsonValue.Null, body["os_version"])
        assertEquals("attributed", listener.recorded.single())
    }

    @Test
    fun aTokenIsRequestedOnlyWhenTheSchemaSaysAndIsBoundToTheBody() {
        // off: the provider is never asked, and the schema is read once.
        var asked = 0
        integrity = IntegrityProvider { asked++; "never" }
        val off = started()
        assertEquals(0, asked)
        assertNull(http.installs().single().json["integrity_token"])
        assertEquals(listOf("https://track.example.com/api/v3/apps/schema"), http.schemaReads)
        assertEquals("attributed", off.installMatch)
    }

    @Test
    fun theIntegritySeamSeesTheCanonicalBodyAndItsTokenStaysOutOfTheFingerprint() {
        var seen: InstallAttempt? = null
        integrity = IntegrityProvider { seen = it; "token-1" }
        http.schema = { FakeTransport.schemaDoc(request = true, project = 987654321) }
        http.then(503)
        val e = started()
        val first = http.installs()[0].json
        assertEquals("token-1", first["integrity_token"]!!.stringOrNull)
        val attempt = seen!!
        assertEquals(e.installUuid, attempt.installUuid)
        assertEquals(987654321L, attempt.cloudProjectNumber)
        assertEquals(InstallPayload.canonical(first), attempt.canonicalBody)
        assertEquals(InstallPayload.fingerprint(first), attempt.requestHash)
        assertFalse(attempt.canonicalBody.contains("integrity_token"))

        // A fresh token on the retry is still the same install.
        integrity = IntegrityProvider { "token-2" }
        time.advance(60_000)
        val second = http.installs()[1].json
        assertEquals("token-2", second["integrity_token"]!!.stringOrNull)
        assertEquals(InstallPayload.fingerprint(first), InstallPayload.fingerprint(second))

        assertEquals(1, http.schemaReads.size, "the schema is read once per process")

        // A provider that fails or returns nothing usable costs the token, not the install.
        for (p in listOf(
            IntegrityProvider { throw IllegalStateException("no Play services") },
            IntegrityProvider { throw IntegrityUnavailableException("API_NOT_AVAILABLE", retryable = false) },
            IntegrityProvider { "" },
        )) {
            val t = VirtualTime()
            val h = FakeTransport()
            h.schema = { FakeTransport.schemaDoc(request = true) }
            AttributionEngine(InMemoryStore(), h, t, t, FakeReferrer(REFERRER), p, RecordingListener(), SilentLogger) { 1.0 }.configure(config())
            t.settle()
            assertNull(h.installs().single().json["integrity_token"])
        }
    }

    @Test
    fun aRetryablePlayIntegrityFailureHoldsTheInstallBackThenItGoesWithout() {
        var asked = 0
        integrity = IntegrityProvider { asked++; throw IntegrityUnavailableException("NETWORK_ERROR", retryable = true) }
        http.schema = { FakeTransport.schemaDoc(request = true) }
        started()
        assertEquals(1, asked)
        assertEquals(0, http.installs().size, "held back: under require no token is never paid")
        time.advance(30_000)
        assertEquals(2, asked)
        assertEquals(0, http.installs().size)
        time.advance(60_000)
        assertEquals(AttributionEngine.MAX_INTEGRITY_ATTEMPTS, asked)
        assertNull(http.installs().single().json["integrity_token"], "then sent without one rather than never")
    }

    @Test
    fun aSchemaTheSdkCannotHonourMeansNoToken() {
        for (doc in listOf(
            FakeTransport.schemaDoc(request = true, scheme = "sha512_of_something_else"),
            FakeTransport.schemaDoc(request = true, type = "classic"),
            FakeTransport.schemaDoc(request = true, project = null),
            HttpResponse(404, """{"error":true,"message":"Unknown app token","status":404}"""),
            HttpResponse(200, """{"data":{"platform":"android","integrity_mode":"off"}}"""),
        )) {
            val t = VirtualTime()
            val h = FakeTransport()
            h.schema = { doc }
            var asked = 0
            AttributionEngine(InMemoryStore(), h, t, t, FakeReferrer(REFERRER), { asked++; "t" }, RecordingListener(), SilentLogger) { 1.0 }.configure(config())
            t.settle()
            assertEquals(0, asked, doc.body)
            assertEquals(1, h.installs().size)
        }
        // A schema that cannot be read now is waited out, like the install.
        http.thenSchema(503, "", mapOf("retry-after" to "120")).thenSchemaFail()
        http.schema = { FakeTransport.schemaDoc(request = true) }
        integrity = IntegrityProvider { "tok" }
        started()
        assertEquals(0, http.installs().size)
        time.advance(120_000)
        assertEquals(0, http.installs().size)
        time.advance(60_000)
        assertEquals("tok", http.installs().single().json["integrity_token"]!!.stringOrNull)
    }

    @Test
    fun aPendingIntegrityInstallKeepsItsEventsUntilItSettlesAndAFailedOneStopsThem() {
        http.thenAnswer { r ->
            HttpResponse(200, """{"data":{"install_uuid":"${r.json["install_uuid"]!!.stringOrNull}","match":"pending_integrity","reason":"Waiting for the Play Integrity verdict.","trusted":null,"test":false,"integrity":"pending","duplicate":false}}""")
        }
        http.schema = { FakeTransport.schemaDoc(request = true) }
        integrity = IntegrityProvider { "tok" }
        val e = started()
        assertEquals("pending_integrity", e.installMatch)
        assertEquals("pending", e.installIntegrity)
        val pending = """{"error":true,"message":"still pending_integrity","status":503,"match":"pending_integrity"}"""
        http.then(503, pending, mapOf("retry-after" to "60")).then(503, pending, mapOf("retry-after" to "60"))
        val a = e.logEvent("level_reached", mapOf("level" to 3))
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        time.advance(60_000)
        assertEquals(1, e.queuedEvents, "kept while the verdict is pending")
        time.advance(60_000)
        assertEquals(listOf(a), listener.delivered)
        assertEquals(3, http.events().size)

        // integrity_failed: refuted, events stop.
        val t = VirtualTime()
        val h = FakeTransport()
        val l = RecordingListener()
        val f = AttributionEngine(InMemoryStore(), h, t, t, FakeReferrer(REFERRER), IntegrityProvider.NONE, l, SilentLogger) { 1.0 }
        f.configure(config())
        t.settle()
        h.then(409, """{"error":true,"message":"classified integrity_failed","status":409,"match":"integrity_failed"}""")
        val b = f.logEvent("x")
        t.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        f.logEvent("y")
        t.advance(3600_000)
        assertEquals(1, h.events().size)
        assertEquals(b, l.dropped.first().first)
    }

    @Test
    fun configureRefusesWhatTheServerWould() {
        assertFailsWith<IllegalArgumentException> { AttributionConfig.of("track.example.com", TOKEN, "com.example.summit") }
        assertFailsWith<IllegalArgumentException> { AttributionConfig.of("ftp://track.example.com", TOKEN, "com.example.summit") }
        assertFailsWith<IllegalArgumentException> { AttributionConfig.of("https://track.example.com?x=1", TOKEN, "com.example.summit") }
        assertFailsWith<IllegalArgumentException> { AttributionConfig.of("https://track.example.com", "p202_live_abc", "com.example.summit") }
        assertFailsWith<IllegalArgumentException> { AttributionConfig.of("https://track.example.com", TOKEN.dropLast(1), "com.example.summit") }
        assertFailsWith<IllegalArgumentException> { AttributionConfig.of("https://track.example.com", TOKEN, "summit") }
        assertEquals("https://t.example.com/p202", AttributionConfig.of("https://t.example.com/p202/api/v3/", TOKEN, "a.b").endpoint)
        assertEquals("https://t.example.com/api/v3/apps/installs", AttributionConfig.of("https://t.example.com/", TOKEN.uppercase(), "a.b").installsUrl)
    }

    // ------------------------------------------------------------- events

    @Test
    fun eventsWaitForTheInstallThenGoInOneBatchAfterTheFlushDelay() {
        http.then(503)
        val e = started()
        val a = e.logEvent("tutorial_complete")
        val b = e.logEvent("level_reached", mapOf("level" to 3))
        val c = e.logEvent("purchase", revenue = 4.99, transactionId = "GPA.1234-5678")
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        assertEquals(0, http.events().size, "no events before the install is recorded")
        time.advance(60_000)
        assertEquals("attributed", e.installMatch)
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        val sent = http.events().single()
        assertEquals("https://track.example.com/api/v3/apps/installs/${e.installUuid}/events", sent.url)
        assertEquals(emptyMap(), EventPayload.validateBody(sent.json))
        val events = sent.json["events"]!!.arrOrNull!!.items.map { it.objOrNull!! }
        assertEquals(listOf(a, b, c), events.map { it["event_id"]!!.stringOrNull })
        assertEquals(JsonValue.Int(3), events[1]["properties"]!!.objOrNull!!["level"])
        assertEquals(JsonValue.Num(4.99), events[2]["revenue"])
        assertEquals(listOf(a, b, c), listener.delivered)
        assertEquals(0, e.queuedEvents)
        time.advance(3600_000)
        assertEquals(1, http.events().size, "delivered once")
    }

    @Test
    fun aFullBatchGoesAtOnceAndBatchesStayUnderTheCaps() {
        val e = started()
        repeat(250) { e.logEvent("tick", mapOf("i" to it)) }
        time.settle()
        assertEquals(2, http.events().size, "two full batches of 100 go without waiting")
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        assertEquals(listOf(100, 100, 50), http.events().map { it.json["events"]!!.arrOrNull!!.items.size })

        // Large events are split by size, under the 64 KB cap.
        val big = "x".repeat(250)
        repeat(100) { e.logEvent("big", (0 until 30).associate { k -> "p$k" to big }) }
        time.settle()
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS * 3)
        val batches = http.events().drop(3)
        assertTrue(batches.size >= 2, "split by size")
        for (b in batches) assertTrue(b.body.toByteArray().size <= EventPayload.MAX_BODY_BYTES)
        assertEquals(100, batches.sumOf { it.json["events"]!!.arrOrNull!!.items.size })
    }

    @Test
    fun occurredAtNeverGoesBackwards() {
        val e = started()
        e.logEvent("a")
        time.settle()
        time.now -= 3600_000 // the user sets the clock back
        e.logEvent("b")
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        val at = http.events().single().json["events"]!!.arrOrNull!!.items.map { it.objOrNull!!["occurred_at"]!!.longOrNull!! }
        assertEquals(at[0], at[1])
    }

    @Test
    fun logEventRefusesWhatTheServerWouldAndQueuesNothing() {
        val e = started()
        val cases = listOf<() -> Unit>(
            { e.logEvent("") },
            { e.logEvent("has space") },
            { e.logEvent("x".repeat(65)) },
            { e.logEvent("ok", mapOf("1bad" to 1)) },
            { e.logEvent("ok", mapOf("nested" to mapOf("n" to 3))) },
            { e.logEvent("ok", mapOf("n" to null)) },
            { e.logEvent("ok", mapOf("s" to "\u00e9".repeat(128))) },
            { e.logEvent("ok", mapOf("f" to Double.NaN)) },
            { e.logEvent("ok", (0..32).associate { "p$it" to it }) },
            { e.logEvent("ok", revenue = Double.POSITIVE_INFINITY) },
            { e.logEvent("ok", transactionId = "  ") },
        )
        for (c in cases) assertFailsWith<InvalidEventException> { c() }
        val err = assertFailsWith<InvalidEventException> { e.logEvent("has space", mapOf("x" to listOf(1))) }
        assertEquals(setOf("event.name", "event.properties.x"), err.fieldErrors.keys)
        time.advance(3600_000)
        assertEquals(0, e.queuedEvents)
        assertEquals(0, http.events().size)
        assertFailsWith<IllegalStateException> { engine().logEvent("before_configure") }
    }

    @Test
    fun aPendingInstallsEventsWaitForRetryAfter() {
        val e = started()
        http.then(503, """{"error":true,"message":"still pending_click","status":503,"match":"pending_click"}""", mapOf("retry-after" to "60"))
        e.logEvent("a")
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        assertEquals(1, http.events().size)
        time.advance(59_000)
        assertEquals(1, http.events().size)
        time.advance(1_000)
        assertEquals(2, http.events().size)
        assertEquals(0, e.queuedEvents)
    }

    @Test
    fun aRefutedInstallStopsEventsForGood() {
        val e = started()
        http.then(409, """{"error":true,"message":"classified bad_token","status":409,"match":"bad_token"}""")
        val a = e.logEvent("a")
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        assertEquals(listOf(a), listener.dropped.map { it.first })
        val b = e.logEvent("b")
        time.advance(3600_000)
        assertEquals(1, http.events().size)
        assertEquals(listOf(a, b), listener.dropped.map { it.first })
        assertEquals(0, e.queuedEvents)
    }

    @Test
    fun aRefusedEventIsDroppedAndTheRestAreSent() {
        val e = started()
        http.then(400, """{"error":true,"message":"The events are invalid","status":400,"field_errors":{"events[1].name":"bad"}}""")
        val ids = (0 until 3).map { e.logEvent("e$it") }
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        assertEquals(listOf(ids[1]), listener.dropped.map { it.first })
        assertEquals(2, http.events().size)
        assertEquals(listOf(ids[0], ids[2]), http.events()[1].json["events"]!!.arrOrNull!!.items.map { it.objOrNull!!["event_id"]!!.stringOrNull })
        assertEquals(listOf(ids[0], ids[2]), listener.delivered)
    }

    @Test
    fun aTooLargeBodyIsHalvedAndOtherStatusesFollowTheContract() {
        val e = started()
        http.then(413, "{}")
        repeat(10) { e.logEvent("e") }
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        assertEquals(listOf(10, 5, 5), http.events().map { it.json["events"]!!.arrOrNull!!.items.size })

        // 422: the install holds the server's maximum; nothing more is sent.
        http.then(422, "{}")
        e.logEvent("e")
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        e.logEvent("e")
        time.advance(3600_000)
        assertEquals(4, http.events().size)

        // 404: the install is unknown to this token; events wait, then go under another token.
        val t = VirtualTime()
        val h = FakeTransport()
        val s = InMemoryStore()
        val e2 = AttributionEngine(s, h, t, t, FakeReferrer(REFERRER), IntegrityProvider.NONE, RecordingListener(), SilentLogger) { 1.0 }
        e2.configure(config())
        t.settle()
        h.then(404, """{"error":true,"message":"Unknown install","status":404}""")
        e2.logEvent("kept")
        t.advance(3600_000)
        assertEquals(1, h.events().size)
        assertEquals(1, e2.queuedEvents, "kept, not dropped")
        e2.configure(config(OTHER_TOKEN))
        t.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        assertEquals(2, h.events().size)
        assertEquals(0, e2.queuedEvents)
    }

    @Test
    fun aRefusalAlwaysCostsTheQueueSomethingSoNothingIsResentInALoop() {
        val sig = "c21cbbf0bddfc93538dd9329f809dbb663e3029dc51fb6c145dc2fbae3e670b4"
        // A 400 naming an event the batch does not hold: the batch goes.
        val e = started()
        http.then(400, """{"error":true,"status":400,"field_errors":{"events[7].name":"bad"}}""")
        val a = e.logEvent("a")
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        assertEquals(listOf(a), listener.dropped.map { it.first })
        assertEquals(1, http.events().size)

        // A customer alone, refused with a status the contract does not
        // retry: sent once, kept, not sent again until it changes.
        http.then(405, "{}")
        e.setCustomerId(CustomerId.of("u-829", sig))
        time.advance(3600_000)
        assertEquals(2, http.events().size)
        assertNotNull(e.customerId)
        // … and a 400 that names nothing, on a customer alone: cleared.
        http.then(400, """{"error":true,"status":400,"message":"The events body is invalid"}""")
        e.setCustomerId(CustomerId.of("u-830", sig))
        time.advance(3600_000)
        assertEquals(3, http.events().size)
        assertNull(e.customerId)
    }

    @Test
    fun theQueueIsBoundedAndKeepsTheOldest() {
        http.then(503).then(503).then(503)
        val e = started()
        http.fallback = { HttpResponse(503, "") }
        val ids = (0 until AttributionEngine.MAX_QUEUED_EVENTS + 5).map { e.logEvent("e") }
        time.settle()
        assertEquals(AttributionEngine.MAX_QUEUED_EVENTS, e.queuedEvents)
        assertEquals(ids.takeLast(5), listener.dropped.map { it.first })
    }

    // ----------------------------------------------------------- customer

    /**
     * A customer id that is not Unicode (an unpaired surrogate) is refused
     * by CustomerId.of(), so it never reaches the engine; accepted, it was
     * persisted in the install body and every sendInstall() threw computing
     * the canonical body, before any request, forever.
     */
    @Test
    fun aCustomerIdThatIsNotUnicodeIsRefusedAndTheInstallIsStillSent() {
        val e = engine()
        assertFailsWith<InvalidCustomerIdException> {
            e.setCustomerId(CustomerId.of("abc\uD800def", "c21cbbf0bddfc93538dd9329f809dbb663e3029dc51fb6c145dc2fbae3e670b4"))
        }
        e.configure(config())
        time.settle()
        val install = http.installs().single().json
        assertNull(install["customer"], "the install went, without the refused customer")
        assertNull(e.customerId)
    }

    @Test
    fun aCustomerSetBeforeTheInstallRidesItsBody() {
        val e = engine()
        e.setCustomerId(CustomerId.of("  u-829 ", "C21CBBF0BDDFC93538DD9329F809DBB663E3029DC51FB6C145DC2FBAE3E670B4"))
        e.configure(config())
        time.settle()
        val body = http.installs().single().json
        assertEquals(
            """{"id":"u-829","type":"custom","signature":"c21cbbf0bddfc93538dd9329f809dbb663e3029dc51fb6c145dc2fbae3e670b4"}""",
            Json.write(body["customer"]!!),
        )
        assertEquals(emptyMap(), InstallPayload.validate(body))
        assertEquals(listOf("linked"), listener.customer)
        time.advance(3600_000)
        assertEquals(0, http.events().size, "delivered with the install: not sent again")
    }

    @Test
    fun aCustomerSetLaterGoesAloneOnTheEventsRouteOnceAndAgainWhenItChanges() {
        val e = started()
        val c1 = CustomerId.of("u-829", "c21cbbf0bddfc93538dd9329f809dbb663e3029dc51fb6c145dc2fbae3e670b4")
        e.setCustomerId(c1)
        time.settle()
        val alone = http.events().single().json
        assertNull(alone["events"])
        assertEquals(emptyMap(), EventPayload.validateBody(alone))
        assertEquals("u-829", alone["customer"]!!.objOrNull!!["id"]!!.stringOrNull)

        // Events after it do not carry it again.
        e.logEvent("a")
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        assertNull(http.events()[1].json["customer"])

        // A new id is sent once more, beside the events waiting to go.
        e.logEvent("b")
        e.setCustomerId(CustomerId.of("sub_123", "b2a3c954b1f334ae7227c1defc9ba26195a4d77e0041b02c7e4232feb72b3d2c", CustomerId.Type.ESP_ID))
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        val third = http.events()[2].json
        assertEquals("esp_id", third["customer"]!!.objOrNull!!["type"]!!.stringOrNull)
        assertNotNull(third["events"])
        e.clearCustomerId()
        e.logEvent("c")
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        assertNull(http.events()[3].json["customer"])
        assertNull(e.customerId)
    }

    @Test
    fun aCustomerTheServerRefusesIsClearedAndAnOldServerStillGetsTheInstall() {
        // A server that predates the customer field refuses it by name on the
        // install: the install goes without it.
        http.then(400, """{"error":true,"message":"The install is invalid","status":400,"field_errors":{"customer":"is not an install field"}}""")
            .then(200, "").thenAnswer { FakeTransport.ok(it) }
            .then(400, """{"error":true,"message":"The events body is invalid","status":400,"field_errors":{"customer":"is not a field here"}}""")
        val e = engine()
        e.setCustomerId(CustomerId.of("u-829", "c21cbbf0bddfc93538dd9329f809dbb663e3029dc51fb6c145dc2fbae3e670b4"))
        e.configure(config())
        time.settle()
        assertNotNull(http.installs()[0].json["customer"])
        assertNull(http.installs()[1].json["customer"])
        time.advance(60_000)
        assertEquals("attributed", e.installMatch)
        assertEquals(http.installs()[1].body, http.installs()[2].body)
        // … and offers it on the events route, where it is refused too and cleared.
        assertNotNull(http.events().single().json["customer"])
        assertNull(e.customerId)
        time.advance(3600_000)
        assertEquals(1, http.events().size)
    }

    @Test
    fun clearingOrReplacingTheCustomerTakesItsClaimOutOfTheUnansweredInstall() {
        val c1 = CustomerId.of("u-829", "c21cbbf0bddfc93538dd9329f809dbb663e3029dc51fb6c145dc2fbae3e670b4")
        val c2 = CustomerId.of("sub_123", "b2a3c954b1f334ae7227c1defc9ba26195a4d77e0041b02c7e4232feb72b3d2c", CustomerId.Type.ESP_ID)

        // Cleared while the install waits out a 503: the retry carries no claim.
        http.then(503, "{}")
        val e = engine()
        e.setCustomerId(c1)
        e.configure(config())
        time.settle()
        assertNotNull(http.installs()[0].json["customer"])
        e.clearCustomerId()
        time.advance(3600_000)
        assertNull(http.installs()[1].json["customer"], "the signed-out customer's claim is not resent")
        assertEquals("attributed", e.installMatch)
        assertEquals(0, http.events().size, "and nothing offers it on the events route either")

        // Replaced: the old claim leaves the body; the new id goes on the
        // events route once the install is recorded.
        val t = VirtualTime()
        val h = FakeTransport().then(503, "{}")
        val f = AttributionEngine(InMemoryStore(), h, t, t, FakeReferrer(REFERRER), IntegrityProvider.NONE, RecordingListener(), SilentLogger) { 1.0 }
        f.setCustomerId(c1)
        f.configure(config())
        t.settle()
        f.setCustomerId(c2)
        t.advance(3600_000)
        assertNull(h.installs()[1].json["customer"])
        assertEquals("sub_123", h.events().single().json["customer"]!!.objOrNull!!["id"]!!.stringOrNull)
        // Setting the same id again changes nothing.
        val t3 = VirtualTime()
        val h3 = FakeTransport().then(503, "{}")
        val g = AttributionEngine(InMemoryStore(), h3, t3, t3, FakeReferrer(REFERRER), IntegrityProvider.NONE, RecordingListener(), SilentLogger) { 1.0 }
        g.setCustomerId(c1)
        g.configure(config())
        t3.settle()
        g.setCustomerId(CustomerId.of("u-829", "c21cbbf0bddfc93538dd9329f809dbb663e3029dc51fb6c145dc2fbae3e670b4"))
        t3.advance(3600_000)
        assertEquals(h3.installs()[0].body, h3.installs()[1].body, "the same claim: the same bytes")
    }

    @Test
    fun anInstallTheServerKeptBeforeItsClaimWasWithdrawnIsTakenAsRecorded() {
        // The first send reached the server but its answer did not come back;
        // the retry, without the withdrawn claim, is "other content" (409).
        http.thenFail().then(409, """{"error":true,"message":"install_uuid … was already reported with different content.","status":409}""")
        val e = engine()
        e.setCustomerId(CustomerId.of("u-829", "c21cbbf0bddfc93538dd9329f809dbb663e3029dc51fb6c145dc2fbae3e670b4"))
        e.configure(config())
        time.settle()
        e.clearCustomerId()
        time.advance(3600_000)
        assertEquals(2, http.installs().size, "the first version, which carries the claim, is not sent again")
        assertNull(http.installs()[1].json["customer"])
        assertEquals(emptyList(), listener.refused)
        assertEquals(listOf(""), listener.recorded)
        assertNull(e.installMatch, "recorded, classification unknown")
        e.logEvent("after")
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        assertEquals(1, http.events().size, "its events go")

        // A 409 without a withdrawn claim is still a refusal.
        val t = VirtualTime()
        val h = FakeTransport().then(409, "{}")
        val l = RecordingListener()
        AttributionEngine(InMemoryStore(), h, t, t, FakeReferrer(REFERRER), IntegrityProvider.NONE, l, SilentLogger) { 1.0 }.configure(config())
        t.advance(3600_000)
        assertEquals(listOf(409), l.refused)
    }

    @Test
    fun aTwoHundredThatIsNotTheRoutesReceiptIsRetried() {
        // Install: a 2xx with `data` that does not echo this install.
        for (body in listOf(
            """{"data":{}}""",
            """{"data":{"install_uuid":"00000000-0000-4000-8000-000000000000","match":"attributed","duplicate":false}}""",
            """{"data":{"install_uuid":"%s","duplicate":false}}""",
        )) {
            val t = VirtualTime()
            val h = FakeTransport().thenAnswer { r -> HttpResponse(200, body.replace("%s", r.json["install_uuid"]!!.stringOrNull!!)) }
            val e = AttributionEngine(InMemoryStore(), h, t, t, FakeReferrer(REFERRER), IntegrityProvider.NONE, RecordingListener(), SilentLogger) { 1.0 }
            e.configure(config())
            t.settle()
            assertNull(e.installMatch, body)
            t.advance(3600_000)
            assertEquals(2, h.installs().size, body)
            assertEquals("attributed", e.installMatch, body)
        }

        // Events: an answer that does not cover the batch keeps it queued.
        val e = started()
        val a = e.logEvent("a")
        val b = e.logEvent("b")
        http.thenAnswer { r ->
            val uuid = r.url.substringAfter("/installs/").substringBefore("/events")
            HttpResponse(200, """{"data":{"install_uuid":"$uuid","accepted":["$a"],"duplicates":[]}}""")
        }.thenAnswer { HttpResponse(200, """{"data":{}}""") }
            .thenAnswer { HttpResponse(200, """{"data":{"install_uuid":"someone-else","accepted":["$a","$b"],"duplicates":[]}}""") }
        time.advance(AttributionEngine.FLUSH_DELAY_MILLIS)
        assertEquals(2, e.queuedEvents, "a receipt naming one of two events is not a receipt for the batch")
        assertEquals(emptyList(), listener.delivered)
        time.advance(3600_000)
        assertEquals(4, http.events().size)
        assertEquals(0, e.queuedEvents)
        assertEquals(listOf(a, b), listener.delivered)
        assertEquals(1, http.events().map { it.body }.toSet().size, "the same batch each time")

        // Schema: a 2xx that is not the document is waited out, not read as "no integrity".
        val t = VirtualTime()
        val h = FakeTransport().thenSchema(200, """{"data":{}}""").thenSchema(200, "<html>portal</html>")
        h.schema = { FakeTransport.schemaDoc(request = true) }
        AttributionEngine(InMemoryStore(), h, t, t, FakeReferrer(REFERRER), { "tok" }, RecordingListener(), SilentLogger) { 1.0 }.configure(config())
        t.settle()
        assertEquals(0, h.installs().size)
        t.advance(3600_000)
        assertEquals("tok", h.installs().single().json["integrity_token"]!!.stringOrNull)
    }

    @Test
    fun setCustomerIdRefusesWhatTheServerWould() {
        val sig = "c21cbbf0bddfc93538dd9329f809dbb663e3029dc51fb6c145dc2fbae3e670b4"
        assertEquals("signature", assertFailsWith<InvalidCustomerIdException> { CustomerId.of("u", sig.dropLast(1)) }.field)
        assertEquals("id", assertFailsWith<InvalidCustomerIdException> { CustomerId.of("  ", sig) }.field)
        assertEquals("id", assertFailsWith<InvalidCustomerIdException> { CustomerId.of("ada@example.com", sig, CustomerId.Type.EMAIL_SHA256) }.field)
        assertEquals("id", assertFailsWith<InvalidCustomerIdException> { CustomerId.of("u".repeat(256), sig) }.field)
    }

    // ------------------------------------------------------------ storage

    @Test
    fun theFileStoreKeepsStateAcrossProcessesAndSetsACorruptFileAside() {
        val dir = Files.createTempDirectory("p202-store").toFile()
        val file = File(dir, "p202-attribution.json")
        val e = engine(FileStore(file))
        e.configure(config())
        time.settle()
        e.logEvent("queued")
        time.settle()
        val uuid = e.installUuid!!
        val reopened = FileStore(file)
        assertEquals(uuid, reopened.get(AttributionEngine.K_UUID))
        assertEquals(AttributionEngine.RECORDED, reopened.get(AttributionEngine.K_STATE))
        assertEquals(1, Json.parse(reopened.get(AttributionEngine.K_EVENTS)!!).arrOrNull!!.items.size)

        file.writeText("{\"install_uuid\": ")
        val notes = ArrayList<String>()
        val fresh = FileStore(file) { notes.add(it) }
        assertNull(fresh.get(AttributionEngine.K_UUID))
        assertEquals(1, notes.size)
        assertEquals(1, dir.listFiles()!!.count { it.name.startsWith("p202-attribution.json.corrupt-") }, "moved aside, not deleted")
        dir.deleteRecursively()
    }
}

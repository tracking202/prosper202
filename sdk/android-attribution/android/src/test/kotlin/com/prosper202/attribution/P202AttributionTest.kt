package com.prosper202.attribution

import android.app.Application
import com.prosper202.attribution.core.AttributionEngine
import com.prosper202.attribution.core.AttributionListener
import com.prosper202.attribution.core.InstallPayload
import com.prosper202.attribution.core.InstallToken
import com.prosper202.attribution.core.Json
import com.prosper202.attribution.core.JsonValue
import com.prosper202.attribution.testing.FakeInstance
import com.prosper202.attribution.testing.contract
import com.prosper202.attribution.testing.hexBytes
import com.prosper202.attribution.testing.pumpUntil
import com.prosper202.attribution.testing.sha256Hex
import java.io.File
import java.util.Collections
import kotlin.test.AfterTest
import kotlin.test.BeforeTest
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNotEquals
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment
import org.robolectric.shadows.ShadowBuild

/**
 * The SDK as an app calls it — `P202Attribution.configure()` — on
 * Robolectric's Android: Play's real referrer client bound to a played
 * Play Store, the state file in the real `noBackupFilesDir`, the device
 * facts from the real PackageManager and `Build`, and the real
 * HttpURLConnection transport posting to an HTTP server on 127.0.0.1.
 *
 * The reference install of install-requests.json is reproduced from the
 * device end: the same package, version, OS release, Play answer, first
 * open and install id give a body whose canonical form and fingerprint are
 * the vector's, byte for byte — the form the server compares for replays
 * and binds Play Integrity to — and whose referrer carries a token the
 * server's HMAC check (install-token.json's test key) verifies as click 42.
 */
@RunWith(RobolectricTestRunner::class)
class P202AttributionTest {
    private val app: Application = RuntimeEnvironment.getApplication()
    private val server = FakeInstance()
    private val token = "a".repeat(64)
    private val reference = contract("android/install-requests.json")["cases"]!!.arrOrNull!!.items[0].objOrNull!!
    private val referenceBody = reference["body"]!!.objOrNull!!
    private val installs = contract("android/responses.json")["installs"]!!.arrOrNull!!.items.map { it.objOrNull!! }
    private lateinit var ctx: AppContext
    private lateinit var play: FakePlayStore
    private val events = Collections.synchronizedList(ArrayList<String>())
    private val time = VirtualTime()
    private val realScheduler = P202Attribution.newScheduler
    private val realClock = P202Attribution.clock

    private val listener = object : AttributionListener {
        override fun onInstallRecorded(match: String, reason: String, duplicate: Boolean) {
            events.add("recorded $match")
        }

        override fun onInstallRefused(status: Int, message: String) {
            events.add("refused $status")
        }
    }

    private val stateFile: File get() = File(ctx.noBackupFilesDir, "p202-attribution.json")

    private fun state(): Map<String, String> =
        Json.parse(stateFile.readText(Charsets.UTF_8)).objOrNull!!.fields.mapValues { it.value.stringOrNull!! }

    /**
     * responses.json's recorded-install answer, as the server sends it,
     * echoing the posted install_uuid (the SDK takes an answer naming
     * another install for a proxy's page, and retries).
     */
    private fun recorded(): FakeInstance.Answer {
        val answer = installs.first { it["when"]!!.stringOrNull == "recorded" }["body"]!!.objOrNull!!
        return FakeInstance.Answer(200, "") { request ->
            val uuid = Json.parse(request.body).objOrNull!!["install_uuid"]!!
            val data = answer["data"]!!.objOrNull!!.fields + ("install_uuid" to uuid)
            Json.write(JsonValue.Obj(mapOf("data" to JsonValue.Obj(data))))
        }
    }

    @BeforeTest
    fun setUp() {
        P202Attribution.resetForTests()
        P202Attribution.newScheduler = time::newScheduler
        P202Attribution.clock = time.clock
        ctx = AppContext(app, referenceBody["app_key"]!!.stringOrNull!!, referenceBody["app_version"]!!.stringOrNull)
        ShadowBuild.setVersionRelease(referenceBody["os_version"]!!.stringOrNull)
        play = FakePlayStore(app)
        play.answer = { FakePlayStore.bundleOf(referenceBody["referrer"]!!.objOrNull!!) }
        server.on("GET /api/v3/apps/schema", FakeInstance.SCHEMA_NO_INTEGRITY)
    }

    @AfterTest
    fun tearDown() {
        P202Attribution.resetForTests()
        P202Attribution.newScheduler = realScheduler
        P202Attribution.clock = realClock
        server.close()
    }

    /** A device that has been opened once already: the vector's install id and first open. */
    private fun seedFirstOpen() {
        stateFile.parentFile!!.mkdirs()
        stateFile.writeText(
            Json.write(
                JsonValue.Obj(
                    mapOf(
                        "install_uuid" to JsonValue.Str(referenceBody["install_uuid"]!!.stringOrNull!!),
                        "first_open_at" to JsonValue.Str(referenceBody["first_open_at"]!!.longOrNull.toString()),
                    ),
                ),
            ),
        )
    }

    private fun launch() {
        P202Attribution.configure(ctx, server.base, token, P202Attribution.Options(test = false, listener = listener, logging = false))
    }

    @Test
    fun theReferenceInstallLeavesTheDeviceAsTheContractSays() {
        seedFirstOpen()
        server.on("POST /api/v3/apps/installs", recorded())

        launch()

        val schema = server.next()
        assertEquals("GET" to "/api/v3/apps/schema", schema.method to schema.path)
        assertEquals(token, schema.headers["x-p202-app-token"])
        val post = server.next()
        assertEquals("POST" to "/api/v3/apps/installs", post.method to post.path)
        assertEquals(token, post.headers["x-p202-app-token"])
        assertEquals("application/json", post.headers["content-type"])

        val body = Json.parse(post.body).objOrNull!!
        // The token rides Play's referrer untouched, and verifies under the server's key as the click.
        val tokens = contract("android/install-token.json")
        val referrer = body["referrer"]!!.objOrNull!!["install_referrer"]!!.stringOrNull!!
        val installToken = InstallToken.inReferrer(referrer)
        assertNotNull(installToken, "a Prosper202 token in the referrer")
        assertEquals(42L, InstallToken.verify(installToken, hexBytes(tokens["key_hex"]!!.stringOrNull!!)), "the HMAC the server checks verifies as click 42")

        val canonical = InstallPayload.canonical(body)
        assertEquals(reference["expect"]!!.objOrNull!!["canonical"]!!.stringOrNull, canonical, "the canonical form the server fingerprints")
        assertEquals(reference["expect"]!!.objOrNull!!["fingerprint"]!!.stringOrNull, sha256Hex(canonical))

        // Wait for the listener, not for installMatch: the engine's thread
        // persists the answer (which is what installMatch reads) and only then
        // calls the listener, so a test woken by installMatch can read events
        // before the callback has run.
        pumpUntil("the install to be recorded") { events.isNotEmpty() }
        assertEquals("attributed", P202Attribution.installMatch)
        assertEquals(listOf("recorded attributed"), events.toList())
    }

    @Test
    fun theInstallIdIsMintedOnceKeptOutOfBackupAndSurvivesARelaunch() {
        server.on("POST /api/v3/apps/installs", recorded())
        assertFalse(stateFile.exists())

        launch()
        server.next() // schema
        val first = Json.parse(server.next().body).objOrNull!!
        pumpUntil("the install to be recorded") { P202Attribution.installMatch != null }

        // Minted, persisted, and in the directory Auto Backup and device transfer skip.
        val uuid = state()["install_uuid"]
        assertNotNull(uuid)
        assertTrue(Regex("^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$").matches(uuid), uuid)
        assertEquals(uuid, first["install_uuid"]!!.stringOrNull)
        assertEquals(File(app.applicationInfo.dataDir, "no_backup").canonicalPath, stateFile.parentFile!!.canonicalPath)
        assertEquals("recorded", state()["install_state"])
        assertEquals(Json.write(first), state()["install_body"], "the body is persisted as it was sent")

        // A relaunch (a new process): the same install, and nothing sent again.
        P202Attribution.resetForTests()
        launch()
        assertEquals("attributed", P202Attribution.installMatch)
        // Every delayed task either engine holds (the referrer read's
        // timeout, any retry or wake) is run to completion, however far
        // off: none of them may send the install again.
        val fired = time.runEveryDelayedTask()
        assertTrue(AttributionEngine.REFERRER_TIMEOUT_MILLIS in fired, "the run reached the engine's delayed work: $fired")
        assertNull(server.requests.poll(), "a recorded install is not reported again")
        assertEquals(uuid, state()["install_uuid"])
    }

    @Test
    fun anUnreadableStateFileIsMovedAsideNotReadAsEmpty() {
        stateFile.parentFile!!.mkdirs()
        stateFile.writeText("{\"install_uuid\": ")
        server.on("POST /api/v3/apps/installs", recorded())

        launch()
        server.next()
        val sent = Json.parse(server.next().body).objOrNull!!
        pumpUntil("the install to be recorded") { P202Attribution.installMatch != null }

        val aside = stateFile.parentFile!!.listFiles()!!.filter { it.name.startsWith("p202-attribution.json.corrupt-") }
        assertEquals(1, aside.size, "the unreadable file is kept, renamed")
        assertEquals("{\"install_uuid\": ", aside[0].readText())
        assertEquals(sent["install_uuid"]!!.stringOrNull, state()["install_uuid"], "and a fresh install id is minted")
    }

    @Test
    fun a429AndA5xxAreRetriedWithTheSameBytesUntilAnswered() {
        seedFirstOpen()
        server.on(
            "POST /api/v3/apps/installs",
            FakeInstance.Answer(429, """{"error":true,"message":"Too many requests","status":429}""", mapOf("Retry-After" to "1")),
            FakeInstance.Answer(503, """{"error":true,"message":"Unavailable","status":503}""", mapOf("Retry-After" to "1")),
            recorded(),
        )

        launch()
        server.next() // schema
        val bodies = List(3) { server.next(seconds = 15).body }

        assertEquals(1, bodies.toSet().size, "every retry resends the persisted body byte for byte")
        pumpUntil("the install to be answered") { events.isNotEmpty() } // the listener, as above
        assertEquals("attributed", P202Attribution.installMatch)
        assertEquals(listOf("recorded attributed"), events.toList(), "neither the 429 nor the 503 was taken as an answer")
        assertNull(state()["install_attempts"], "the attempt count is cleared once answered")
    }

    @Test
    fun a400IsTerminalAndNotRetried() {
        seedFirstOpen()
        server.on("POST /api/v3/apps/installs", FakeInstance.Answer(400, """{"error":true,"message":"The install is invalid","status":400,"field_errors":{"install_uuid":"bad"}}"""))

        launch()
        server.next() // schema
        server.next() // the install
        pumpUntil("the refusal") { events.isNotEmpty() }
        assertEquals(listOf("refused 400"), events.toList())
        // Not a sleep past a guessed backoff: every delayed task the engine
        // holds is run, at the time it is due, until none is left.
        val fired = time.runEveryDelayedTask()
        assertTrue(AttributionEngine.REFERRER_TIMEOUT_MILLIS in fired, "the run reached the engine's delayed work: $fired")
        assertNull(server.requests.poll(), "a refused install is not resent, after any delay")
        assertEquals("refused", state()["install_state"])
        assertNotEquals(null, state()["install_body"])
    }
}

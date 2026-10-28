package com.prosper202.attribution.integrity

import android.app.Activity
import com.google.android.gms.tasks.Task
import com.google.android.gms.tasks.TaskCompletionSource
import com.google.android.gms.tasks.Tasks
import com.google.android.play.core.integrity.StandardIntegrityException
import com.google.android.play.core.integrity.StandardIntegrityManager
import com.google.android.play.core.integrity.StandardIntegrityManager.PrepareIntegrityTokenRequest
import com.google.android.play.core.integrity.StandardIntegrityManager.StandardIntegrityDialogRequest
import com.google.android.play.core.integrity.StandardIntegrityManager.StandardIntegrityToken
import com.google.android.play.core.integrity.StandardIntegrityManager.StandardIntegrityTokenProvider
import com.google.android.play.core.integrity.StandardIntegrityManager.StandardIntegrityTokenRequest
import com.google.android.play.core.integrity.model.StandardIntegrityErrorCode
import com.prosper202.attribution.core.AttributionConfig
import com.prosper202.attribution.core.AttributionEngine
import com.prosper202.attribution.core.Clock
import com.prosper202.attribution.core.ExecutorScheduler
import com.prosper202.attribution.core.InMemoryStore
import com.prosper202.attribution.core.InstallAttempt
import com.prosper202.attribution.core.InstallPayload
import com.prosper202.attribution.core.IntegrityUnavailableException
import com.prosper202.attribution.core.Json
import com.prosper202.attribution.core.JsonValue
import com.prosper202.attribution.core.ReferrerDetails
import com.prosper202.attribution.core.ReferrerStatus
import com.prosper202.attribution.core.UrlConnectionTransport
import com.prosper202.attribution.testing.FakeInstance
import com.prosper202.attribution.testing.contract
import com.prosper202.attribution.testing.pumpUntil
import com.prosper202.attribution.testing.sha256Hex
import java.util.Collections
import java.util.concurrent.Callable
import java.util.concurrent.ExecutionException
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import java.util.concurrent.TimeoutException
import kotlin.test.AfterTest
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertIs
import kotlin.test.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner

/**
 * PlayIntegrityProvider against Play's own API types (integrity 1.6.0's
 * request builders and exception, play-services-tasks' Task and
 * Tasks.await) on Robolectric's Android. Only the StandardIntegrityManager
 * is played: there is no Play Store to issue a real token.
 */
@RunWith(RobolectricTestRunner::class)
class PlayIntegrityProviderTest {
    /** What Play was asked, and what it answers. */
    private class FakeManager : StandardIntegrityManager {
        val prepared: MutableList<Long> = Collections.synchronizedList(ArrayList())
        val requested: MutableList<String> = Collections.synchronizedList(ArrayList())

        /** Answers to request(), in order; the last repeats. By default a token naming the hash. */
        val answers: MutableList<(String) -> Task<StandardIntegrityToken>> = Collections.synchronizedList(ArrayList())

        override fun prepareIntegrityToken(request: PrepareIntegrityTokenRequest): Task<StandardIntegrityTokenProvider> {
            // b() is the cloud project number (the builder's setCloudProjectNumber; the getter is obfuscated).
            // That name is integrity 1.6.0's, the version integrity/build.gradle.kts pins. Obfuscated
            // names are not API and may be reassigned in any release: on a version bump this line can
            // stop compiling (no b(), or one of another type), or — the quiet case — compile against a
            // b() that returns some other long, which the project-number assertions below then fail on.
            // Re-read the new PrepareIntegrityTokenRequest (javap) and rename the call.
            prepared.add(request.b())
            val generation = prepared.size
            return Tasks.forResult(
                StandardIntegrityTokenProvider { r: StandardIntegrityTokenRequest ->
                    requested.add(r.requestHash()!!)
                    val answer = synchronized(answers) { if (answers.size > 1) answers.removeAt(0) else answers.firstOrNull() }
                    answer?.invoke(r.requestHash()!!) ?: Tasks.forResult(token("token-$generation-${r.requestHash()}"))
                },
            )
        }

        override fun showDialog(request: StandardIntegrityDialogRequest): Task<Int> =
            Tasks.forException(UnsupportedOperationException("no dialogs here"))
    }

    private val worker = Executors.newSingleThreadExecutor()

    @AfterTest
    fun tearDown() {
        worker.shutdownNow()
    }

    /** Tasks.await refuses the main thread (Robolectric's test thread); the engine calls from its worker. */
    private fun <T> offMain(block: () -> T): T = try {
        worker.submit(Callable { block() }).get()
    } catch (e: ExecutionException) {
        throw e.cause!!
    }

    private fun attempt(canonical: String, project: Long = 123456789012L) =
        InstallAttempt("8d7c4a52-9f0e-4b1d-a7c3-2e5f60718293", canonical, sha256Hex(canonical), project)

    @Test
    fun everyVectorsTokenIsRequestedWithItsHashForTheProject() {
        val vectors = contract("android/integrity.json")
        val manager = FakeManager()
        val provider = PlayIntegrityProvider(manager, 5)
        val cases = vectors["cases"]!!.arrOrNull!!.items.map { it.objOrNull!! }
        assertTrue(cases.size >= 7, "integrity.json's cases were read")
        for (case in cases) {
            val body = case["body"]!!.objOrNull!!
            val want = case["request_hash"]!!.stringOrNull!!
            val token = offMain { provider.tokenFor(attempt(InstallPayload.canonical(body))) }
            assertEquals(want, manager.requested.last(), case["name"]!!.stringOrNull)
            assertEquals("token-1-$want", token)
        }
        assertEquals(listOf(123456789012L), manager.prepared, "prepared once for the project, then reused")
    }

    @Test
    fun anotherProjectIsPreparedAfresh() {
        val manager = FakeManager()
        val provider = PlayIntegrityProvider(manager, 5)
        offMain { provider.tokenFor(attempt("{}", 1)) }
        offMain { provider.tokenFor(attempt("{}", 1)) }
        offMain { provider.tokenFor(attempt("{}", 2)) }
        assertEquals(listOf(1L, 2L), manager.prepared)
    }

    @Test
    fun anInvalidTokenProviderIsPreparedAgainOnce() {
        val manager = FakeManager()
        manager.answers.add { Tasks.forException(playError(StandardIntegrityErrorCode.INTEGRITY_TOKEN_PROVIDER_INVALID)) }
        manager.answers.add { Tasks.forResult(token("fresh")) }
        val provider = PlayIntegrityProvider(manager, 5)

        assertEquals("fresh", offMain { provider.tokenFor(attempt("{}")) })
        assertEquals(2, manager.prepared.size, "prepared again after INTEGRITY_TOKEN_PROVIDER_INVALID")
    }

    @Test
    fun playsErrorsAreSortedForTheEngine() {
        val retryable = listOf(
            StandardIntegrityErrorCode.NETWORK_ERROR,
            StandardIntegrityErrorCode.TOO_MANY_REQUESTS,
            StandardIntegrityErrorCode.GOOGLE_SERVER_UNAVAILABLE,
            StandardIntegrityErrorCode.CLIENT_TRANSIENT_ERROR,
            StandardIntegrityErrorCode.INTERNAL_ERROR,
            StandardIntegrityErrorCode.CANNOT_BIND_TO_SERVICE,
        )
        val final = listOf(
            StandardIntegrityErrorCode.API_NOT_AVAILABLE,
            StandardIntegrityErrorCode.PLAY_STORE_NOT_FOUND,
            StandardIntegrityErrorCode.PLAY_SERVICES_NOT_FOUND,
            StandardIntegrityErrorCode.PLAY_STORE_VERSION_OUTDATED,
            StandardIntegrityErrorCode.CLOUD_PROJECT_NUMBER_IS_INVALID,
            StandardIntegrityErrorCode.APP_NOT_INSTALLED,
        )
        for (code in retryable + final) {
            val manager = FakeManager()
            manager.answers.add { Tasks.forException(playError(code)) }
            val e = assertFailsWith<IntegrityUnavailableException> { offMain { PlayIntegrityProvider(manager, 5).tokenFor(attempt("{}")) } }
            assertEquals(code in retryable, e.retryable, "error $code")
            assertIs<StandardIntegrityException>(e.cause)
        }
        // A failure that is not Play's: not worth waiting for.
        val manager = FakeManager()
        manager.answers.add { Tasks.forException(IllegalStateException("something else")) }
        val e = assertFailsWith<IntegrityUnavailableException> { offMain { PlayIntegrityProvider(manager, 5).tokenFor(attempt("{}")) } }
        assertEquals(false, e.retryable)
    }

    @Test
    fun playThatNeverAnswersTimesOutAsRetryable() {
        val manager = FakeManager()
        manager.answers.add { TaskCompletionSource<StandardIntegrityToken>().task }
        val started = System.nanoTime()
        val e = assertFailsWith<IntegrityUnavailableException> { offMain { PlayIntegrityProvider(manager, 1).tokenFor(attempt("{}")) } }
        val waitedMillis = TimeUnit.NANOSECONDS.toMillis(System.nanoTime() - started)
        assertTrue(e.retryable)
        // Retryable for the reason the test is named after: Tasks.await's
        // timeout, not some other failure that happens to be retryable.
        assertIs<TimeoutException>(e.cause, "the cause is the wait running out")
        assertTrue(waitedMillis >= 1000, "it waited the full 1s timeout, not ${waitedMillis}ms")
    }

    /**
     * The engine, the provider and the real transport together, against an
     * instance whose schema document asks for a standard token: the token
     * Play issues for the body's hash rides that body, and the hash is the
     * reference install's fingerprint (install-requests.json), which is
     * what the server compares with the requestHash in Google's verdict.
     */
    @Test
    fun theEngineSendsTheTokenPlayBoundToTheBodysFingerprint() {
        val reference = contract("android/install-requests.json")["cases"]!!.arrOrNull!!.items[0].objOrNull!!
        val body = reference["body"]!!.objOrNull!!
        val r = body["referrer"]!!.objOrNull!!
        val fingerprint = reference["expect"]!!.objOrNull!!["fingerprint"]!!.stringOrNull!!
        val manager = FakeManager()
        FakeInstance().use { server ->
            server.on(
                "GET /api/v3/apps/schema",
                FakeInstance.Answer(
                    200,
                    """{"data":{"platform":"android","integrity":{"request_token":true,"token_type":"standard",""" +
                        """"request_hash":"sha256_hex_of_canonical_install_body","cloud_project_number":"123456789012"}}}""",
                ),
            )
            server.on("POST /api/v3/apps/installs", FakeInstance.Answer(200, """{"data":{"install_uuid":"${body["install_uuid"]!!.stringOrNull}","match":"pending_integrity","reason":"","integrity":"pending","duplicate":false}}"""))
            val store = InMemoryStore()
            store.edit(mapOf("install_uuid" to body["install_uuid"]!!.stringOrNull, "first_open_at" to body["first_open_at"]!!.longOrNull.toString()))
            val engine = AttributionEngine(
                store = store,
                transport = UrlConnectionTransport(),
                scheduler = ExecutorScheduler(),
                clock = Clock { System.currentTimeMillis() },
                referrerSource = { cb ->
                    cb(
                        ReferrerDetails(
                            ReferrerStatus.OK, r["install_referrer"]!!.stringOrNull,
                            r["referrer_click_timestamp_seconds"]!!.longOrNull!!, r["install_begin_timestamp_seconds"]!!.longOrNull!!,
                            r["referrer_click_timestamp_server_seconds"]!!.longOrNull!!, r["install_begin_timestamp_server_seconds"]!!.longOrNull!!,
                            r["install_version"]!!.stringOrNull, false,
                        ),
                    )
                },
                integrity = PlayIntegrityProvider(manager, 5),
            )
            engine.configure(AttributionConfig.of(server.base, "b".repeat(64), "com.example.summit", "3.2.0", "15", false))

            server.next() // the schema document
            val sent = Json.parse(server.next().body).objOrNull!!
            assertEquals(listOf(123456789012L), manager.prepared, "prepared for the schema's Cloud project")
            assertEquals(listOf(fingerprint), manager.requested, "the token was requested for the body's fingerprint")
            assertEquals(JsonValue.Str("token-1-$fingerprint"), sent["integrity_token"], "and rides the body it was bound to")
            assertEquals(fingerprint, sha256Hex(InstallPayload.canonical(sent)), "the token is left out of what the server hashes")
            pumpUntil("the answer") { engine.installIntegrity != null }
            assertEquals("pending", engine.installIntegrity)
        }
    }

    private companion object {
        fun token(value: String): StandardIntegrityToken = object : StandardIntegrityToken() {
            override fun token(): String = value
            override fun showDialog(activity: Activity, requestCode: Int): Task<Int> = Tasks.forException(UnsupportedOperationException())
        }

        /**
         * Play's own exception (its constructor is package-private). The
         * (int errorCode, boolean, Throwable) constructor is integrity
         * 1.6.0's, the version integrity/build.gradle.kts pins, and not API.
         * After a version bump that changes it, getDeclaredConstructor throws
         * NoSuchMethodException and every test that plays a Play error fails
         * there; if the arguments are reordered instead, the errorCode check
         * below fails. Either way, read the new class (javap -p) and update
         * this reflection; the provider's own code uses only the public
         * getErrorCode().
         */
        fun playError(code: Int): StandardIntegrityException {
            val c = StandardIntegrityException::class.java.getDeclaredConstructor(Int::class.javaPrimitiveType, Boolean::class.javaPrimitiveType, Throwable::class.java)
            c.isAccessible = true
            val e = c.newInstance(code, false, null)
            check(e.errorCode == code) { "StandardIntegrityException($code) reports ${e.errorCode}" }
            return e
        }
    }
}

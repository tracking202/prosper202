package com.prosper202.attribution.core

import java.io.File
import java.nio.file.Files
import java.util.Collections
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertTrue
import org.junit.Assume.assumeTrue

/**
 * The real engine — file store, HttpURLConnection, its own worker thread,
 * the wall clock — against a running Prosper202 instance. Skipped unless
 * `tests/live/android-sdk.sh` hands the instance in (P202_LIVE_*), which
 * sets up the app, the campaign and the clicks first and reads the
 * database back afterwards; this side asserts what the SDK was told, and
 * writes what the shell needs to check the server's side to P202_LIVE_OUT.
 *
 * Only the referrer is supplied: there is no Play Store on the JVM, so the
 * pass hands in the referrer the store link carried — read from the real
 * redirect's Location — with Google's timestamps placed seconds after the
 * click, as Play reports them.
 */
class LiveServerTest {
    private fun env(name: String): String = System.getenv(name) ?: error("$name is not set")

    private class Recorder : AttributionListener {
        val recorded = Collections.synchronizedList(ArrayList<String>())
        val delivered = Collections.synchronizedList(ArrayList<String>())
        val dropped = Collections.synchronizedList(ArrayList<String>())
        val customer = Collections.synchronizedList(ArrayList<String>())
        val refused = Collections.synchronizedList(ArrayList<Int>())

        override fun onInstallRecorded(match: String, reason: String, duplicate: Boolean) {
            recorded.add("$match|$duplicate")
        }

        override fun onInstallRefused(status: Int, message: String) {
            refused.add(status)
        }

        override fun onEventsDelivered(eventIds: List<String>) {
            delivered.addAll(eventIds)
        }

        override fun onEventsDropped(eventIds: List<String>, reason: String) {
            dropped.addAll(eventIds)
        }

        override fun onCustomerLinked(status: String) {
            customer.add(status)
        }

        /** Wait (30 s at most) until [done] holds: callbacks arrive on the SDK's thread. */
        fun await(what: String, done: () -> Boolean) {
            val until = System.currentTimeMillis() + 30_000
            while (!done()) {
                assertTrue(System.currentTimeMillis() < until, "timed out waiting for $what")
                Thread.sleep(50)
            }
        }
    }

    /** Counts what actually went over the wire. */
    private class CountingTransport : HttpTransport {
        private val real = UrlConnectionTransport()
        val statuses = Collections.synchronizedList(ArrayList<String>())

        override fun post(url: String, headers: Map<String, String>, body: String): HttpResponse {
            val r = real.post(url, headers, body)
            statuses.add(url.substringAfter("/api/v3/") + " " + r.status)
            return r
        }
    }

    private fun referrer(installReferrer: String, clickTime: Long) = ReferrerDetails(
        status = ReferrerStatus.OK,
        installReferrer = installReferrer,
        referrerClickTimestampSeconds = clickTime + 2,
        installBeginTimestampSeconds = clickTime + 39,
        referrerClickTimestampServerSeconds = clickTime + 3,
        installBeginTimestampServerSeconds = clickTime + 40,
        installVersion = "3.2.0",
        googlePlayInstant = false,
    )

    @Test
    fun installEventsAndCustomerAgainstARunningInstance() {
        assumeTrue("set P202_LIVE_BASE (tests/live/android-sdk.sh) to run against an instance", System.getenv("P202_LIVE_BASE") != null)
        val base = env("P202_LIVE_BASE")
        val token = env("P202_LIVE_APP_TOKEN")
        val appKey = env("P202_LIVE_APP_KEY")
        val clickTime = env("P202_LIVE_CLICK_TIME").toLong()
        val out = File(env("P202_LIVE_OUT"))
        val dir = Files.createTempDirectory("p202-live").toFile()
        val results = LinkedHashMap<String, JsonValue>()

        // ---- Device A: through the store link. The install, three events, then the customer.
        val storeA = File(dir, "a.json")
        val rec = Recorder()
        val http = CountingTransport()
        val workerA = ExecutorScheduler()
        val a = AttributionEngine(FileStore(storeA), http, workerA, Clock { System.currentTimeMillis() },
            ReferrerSource { it(referrer(env("P202_LIVE_REFERRER"), clickTime)) }, IntegrityProvider.NONE, rec, PrintLogger)
        a.configure(AttributionConfig.of(base, token, appKey, "3.2.0", "15"))
        rec.await("the install") { rec.recorded.isNotEmpty() || rec.refused.isNotEmpty() }
        assertEquals(listOf("attributed|false"), rec.recorded)
        assertEquals("attributed", a.installMatch)

        // Refused on the device, before anything is queued.
        assertFailsWith<InvalidEventException> { a.logEvent("not an event name") }
        val e1 = a.logEvent("level_reached", mapOf("level" to 1))
        val e2 = a.logEvent("level_reached", mapOf("level" to 3))
        val e3 = a.logEvent("purchase", mapOf("sku" to "gems_100", "first" to true), revenue = 4.99, transactionId = "GPA.live-1")
        a.flush()
        rec.await("the events") { rec.delivered.size + rec.dropped.size >= 3 }
        assertEquals(listOf(e1, e2, e3), rec.delivered.toList())
        assertEquals(emptyList(), rec.dropped.toList())

        a.setCustomerId(CustomerId.of(env("P202_LIVE_CUSTOMER_ID"), env("P202_LIVE_CUSTOMER_SIG")))
        rec.await("the customer") { rec.customer.isNotEmpty() }
        assertEquals(listOf("linked"), rec.customer.toList(), "the signed id links the install's click")

        // A relaunch sends nothing new.
        workerA.drain(10_000)
        val sent = http.statuses.toList()
        val relaunched = ExecutorScheduler()
        val a2 = AttributionEngine(FileStore(storeA), http, relaunched, Clock { System.currentTimeMillis() },
            ReferrerSource { error("a relaunch must not read the referrer again") }, IntegrityProvider.NONE, rec, PrintLogger)
        a2.configure(AttributionConfig.of(base, token, appKey, "3.2.0", "15"))
        a2.flush()
        relaunched.drain(10_000)
        Thread.sleep(500)
        relaunched.drain(10_000)
        assertEquals(sent, http.statuses.toList(), "nothing is resent after a relaunch")
        assertEquals(listOf("apps/installs 200", "apps/installs/${a.installUuid}/events 200", "apps/installs/${a.installUuid}/events 200"), sent)

        // ---- Device B: an organic install, its customer set before the first launch.
        val recB = Recorder()
        val workerB = ExecutorScheduler()
        val b = AttributionEngine(FileStore(File(dir, "b.json")), UrlConnectionTransport(), workerB, Clock { System.currentTimeMillis() },
            ReferrerSource { it(referrer("utm_source=google-play&utm_medium=organic", clickTime)) }, IntegrityProvider.NONE, recB, PrintLogger)
        b.setCustomerId(CustomerId.of(env("P202_LIVE_CUSTOMER_ID"), env("P202_LIVE_CUSTOMER_SIG")))
        b.configure(AttributionConfig.of(base, token, appKey, "3.2.0", "15"))
        recB.await("install B and its customer answer") { recB.customer.isNotEmpty() || recB.refused.isNotEmpty() }
        assertEquals(listOf("organic|false"), recB.recorded.toList())
        assertEquals(listOf("no_click"), recB.customer.toList(), "an organic install proves no click to link")

        // ---- Device C: a forged token, then its events.
        val recC = Recorder()
        val workerC = ExecutorScheduler()
        val forged = env("P202_LIVE_REFERRER").replace(Regex("p202=([0-9]+)\\.([A-Za-z0-9_-])")) { m ->
            "p202=${m.groupValues[1]}.${if (m.groupValues[2] == "A") "B" else "A"}"
        }
        val c = AttributionEngine(FileStore(File(dir, "c.json")), UrlConnectionTransport(), workerC, Clock { System.currentTimeMillis() },
            ReferrerSource { it(referrer(forged, clickTime)) }, IntegrityProvider.NONE, recC, PrintLogger)
        c.configure(AttributionConfig.of(base, token, appKey, "3.2.0", "15"))
        recC.await("install C") { recC.recorded.isNotEmpty() || recC.refused.isNotEmpty() }
        assertEquals(listOf("bad_token|false"), recC.recorded.toList())
        val refused = c.logEvent("level_reached", mapOf("level" to 3))
        c.flush()
        recC.await("C's events") { recC.dropped.isNotEmpty() || recC.delivered.isNotEmpty() }
        assertEquals(listOf(refused), recC.dropped.toList(), "a refuted install's events are refused (409) and dropped")
        c.logEvent("level_reached", mapOf("level" to 4))
        workerC.drain(10_000)

        results["install_a"] = a.installUuid.json()
        results["install_b"] = b.installUuid.json()
        results["install_c"] = c.installUuid.json()
        results["events_a"] = JsonValue.Arr(listOf(e1, e2, e3).map { it.json() })
        results["store_a"] = storeA.absolutePath.json()
        out.writeText(Json.write(JsonValue.Obj(results)))
        println("live: " + Json.write(JsonValue.Obj(results)))
    }

    private object PrintLogger : Logger {
        override fun info(message: String) = println("  sdk: $message")
        override fun warn(message: String) = println("  sdk (warn): $message")
    }
}

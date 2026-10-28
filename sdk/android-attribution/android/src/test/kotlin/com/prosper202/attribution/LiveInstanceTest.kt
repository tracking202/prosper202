package com.prosper202.attribution

import android.app.Application
import android.os.Bundle
import com.prosper202.attribution.core.AttributionListener
import com.prosper202.attribution.core.Json
import com.prosper202.attribution.core.JsonValue
import com.prosper202.attribution.testing.pumpUntil
import java.io.File
import java.util.Collections
import kotlin.test.AfterTest
import kotlin.test.assertEquals
import kotlin.test.assertNotNull
import org.junit.Assume.assumeTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment
import org.robolectric.shadows.ShadowBuild

/**
 * The public entry point — `P202Attribution.configure()` on Robolectric's
 * Android, Play's real referrer client bound to a played Play Store, the
 * state file in the real `noBackupFilesDir`, the real HttpURLConnection
 * transport — against a running Prosper202 instance. Skipped unless
 * `tests/live/android-sdk.sh` hands the instance in (P202_LIVE_*): it makes
 * the click whose store link carried [P202_LIVE_REFERRER], and reads the
 * install back from the database afterwards. The core's LiveServerTest
 * drives the engine directly; this one drives what an app calls.
 *
 * The only thing played is the Play Store's answer: the referrer the real
 * redirect put in the store link, with Google's timestamps seconds after
 * the click, as Play reports them.
 */
@RunWith(RobolectricTestRunner::class)
class LiveInstanceTest {
    private fun env(name: String): String = System.getenv(name) ?: error("$name is not set")

    @AfterTest
    fun tearDown() {
        P202Attribution.resetForTests()
    }

    @Test
    fun anInstallReportedThroughConfigureIsAttributedByTheInstance() {
        assumeTrue("P202_LIVE_BASE is not set (tests/live/android-sdk.sh sets it)", System.getenv("P202_LIVE_BASE") != null)
        val app: Application = RuntimeEnvironment.getApplication()
        val clickTime = env("P202_LIVE_CLICK_TIME").toLong()
        val ctx = AppContext(app, env("P202_LIVE_APP_KEY"), "3.2.0")
        ShadowBuild.setVersionRelease("15")
        val play = FakePlayStore(app)
        play.answer = {
            Bundle().apply {
                putString("install_referrer", env("P202_LIVE_REFERRER"))
                putLong("referrer_click_timestamp_seconds", clickTime + 2)
                putLong("install_begin_timestamp_seconds", clickTime + 39)
                putLong("referrer_click_timestamp_server_seconds", clickTime + 3)
                putLong("install_begin_timestamp_server_seconds", clickTime + 40)
                putString("install_version", "3.2.0")
                putBoolean("google_play_instant", false)
            }
        }
        val events = Collections.synchronizedList(ArrayList<String>())
        val listener = object : AttributionListener {
            override fun onInstallRecorded(match: String, reason: String, duplicate: Boolean) {
                events.add("recorded $match $duplicate")
            }

            override fun onInstallRefused(status: Int, message: String) {
                events.add("refused $status $message")
            }
        }

        P202Attribution.resetForTests()
        P202Attribution.configure(ctx, env("P202_LIVE_BASE"), env("P202_LIVE_APP_TOKEN"), P202Attribution.Options(test = false, listener = listener, logging = true))
        pumpUntil("the instance's answer", seconds = 60) { events.isNotEmpty() }

        assertEquals(listOf("recorded attributed false"), events.toList(), "the instance attributed the install to the click")
        assertEquals(listOf(ctx.packageName), play.requests.map { it.getString("package_name") }, "Play was asked once, for this app")
        val state = Json.parse(File(ctx.noBackupFilesDir, "p202-attribution.json").readText(Charsets.UTF_8)).objOrNull!!
        val uuid = state["install_uuid"]?.stringOrNull
        assertNotNull(uuid)
        assertEquals(JsonValue.Str("recorded"), state["install_state"])
        File(env("P202_LIVE_OUT")).writeText(Json.write(JsonValue.Obj(mapOf("install_uuid" to JsonValue.Str(uuid)))))
    }
}

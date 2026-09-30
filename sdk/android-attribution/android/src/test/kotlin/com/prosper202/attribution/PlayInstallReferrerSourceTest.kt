package com.prosper202.attribution

import android.app.Application
import android.os.RemoteException
import org.robolectric.RuntimeEnvironment
import com.prosper202.attribution.core.ReferrerDetails
import com.prosper202.attribution.core.ReferrerStatus
import com.prosper202.attribution.testing.contract
import com.prosper202.attribution.testing.pumpUntil
import java.util.concurrent.atomic.AtomicInteger
import java.util.concurrent.atomic.AtomicReference
import kotlin.test.assertEquals
import kotlin.test.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.Shadows.shadowOf

/**
 * PlayInstallReferrerSource over Play's real client (installreferrer 2.2's
 * InstallReferrerClientImpl) and Android's real Bundle, binding to the
 * Play Store's referrer service as the device would. Only the Play Store is
 * played: its package, its service, and the Bundle it answers.
 */
@RunWith(RobolectricTestRunner::class)
class PlayInstallReferrerSourceTest {
    private val app: Application = RuntimeEnvironment.getApplication()
    private val ctx = AppContext(app, "com.example.summit", "3.2.0")

    private fun read(): ReferrerDetails {
        val got = AtomicReference<ReferrerDetails>()
        val calls = AtomicInteger()
        PlayInstallReferrerSource(ctx).read {
            calls.incrementAndGet()
            got.set(it)
        }
        pumpUntil("the referrer") { got.get() != null }
        shadowOf(android.os.Looper.getMainLooper()).idle()
        assertEquals(1, calls.get(), "the source answers once")
        return got.get()
    }

    /** The reference install's referrer (install-requests.json), as Play hands it over. */
    private val reference = contract("android/install-requests.json")["cases"]!!.arrOrNull!!.items[0].objOrNull!!["body"]!!.objOrNull!!["referrer"]!!.objOrNull!!

    @Test
    fun readsPlaysAnswerFieldForField() {
        val play = FakePlayStore(app)
        play.answer = { FakePlayStore.bundleOf(reference) }

        val d = read()

        assertEquals(ReferrerStatus.OK, d.status)
        assertEquals("p202=42.3MVffxqa2WX3CV49&utm_source=newsletter", d.installReferrer)
        assertEquals(reference["referrer_click_timestamp_seconds"]!!.longOrNull, d.referrerClickTimestampSeconds)
        assertEquals(reference["install_begin_timestamp_seconds"]!!.longOrNull, d.installBeginTimestampSeconds)
        assertEquals(reference["referrer_click_timestamp_server_seconds"]!!.longOrNull, d.referrerClickTimestampServerSeconds)
        assertEquals(reference["install_begin_timestamp_server_seconds"]!!.longOrNull, d.installBeginTimestampServerSeconds)
        assertEquals("3.2.0", d.installVersion)
        assertEquals(false, d.googlePlayInstant)
        // Play's client asks for this app's referrer by package name.
        assertEquals(listOf("com.example.summit"), play.requests.map { it.getString("package_name") })
        // And the connection is ended once answered.
        assertEquals(1, shadowOf(app).unboundServiceConnections.size, "the service connection is unbound after the answer")
    }

    @Test
    fun anOrganicInstallsEmptyReferrerIsReportedAsItIs() {
        val play = FakePlayStore(app)
        play.answer = { FakePlayStore.bundleOf(com.prosper202.attribution.core.jsonObj()) }

        val d = read()

        assertEquals(ReferrerStatus.OK, d.status)
        assertEquals("", d.installReferrer, "Play's null referrer is sent as the empty string")
        assertEquals(0L, d.referrerClickTimestampSeconds)
    }

    @Test
    fun noPlayStoreIsFeatureNotSupported() {
        // No com.android.vending, no service: Play's client answers FEATURE_NOT_SUPPORTED at once.
        val d = read()
        assertEquals(ReferrerStatus.FEATURE_NOT_SUPPORTED, d.status)
        assertEquals(null, d.installReferrer)
    }

    @Test
    fun aPlayStoreOlderThanTheApiIsFeatureNotSupported() {
        FakePlayStore(app, versionCode = 80837299)
        assertEquals(ReferrerStatus.FEATURE_NOT_SUPPORTED, read().status)
    }

    @Test
    fun aBindingTheSystemRefusesIsServiceUnavailable() {
        FakePlayStore(app)
        shadowOf(app).declareComponentUnbindable(FakePlayStore.COMPONENT)
        val d = read()
        assertEquals(ReferrerStatus.SERVICE_UNAVAILABLE, d.status)
        assertTrue(d.status.isTransient, "a transient answer: the engine reads again")
    }

    @Test
    fun aRemoteExceptionFromPlayIsServiceUnavailable() {
        val play = FakePlayStore(app)
        play.answer = { throw RemoteException("Play went away") }
        assertEquals(ReferrerStatus.SERVICE_UNAVAILABLE, read().status)
    }
}

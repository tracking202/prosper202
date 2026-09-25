package com.prosper202.attribution

import android.app.Activity
import android.app.Application
import android.content.Context
import android.content.pm.ApplicationInfo
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.util.Log
import com.prosper202.attribution.core.AttributionConfig
import com.prosper202.attribution.core.AttributionEngine
import com.prosper202.attribution.core.AttributionListener
import com.prosper202.attribution.core.Clock
import com.prosper202.attribution.core.CustomerId
import com.prosper202.attribution.core.ExecutorScheduler
import com.prosper202.attribution.core.FileStore
import com.prosper202.attribution.core.IntegrityProvider
import com.prosper202.attribution.core.InvalidCustomerIdException
import com.prosper202.attribution.core.InvalidEventException
import com.prosper202.attribution.core.Logger
import com.prosper202.attribution.core.SilentLogger
import com.prosper202.attribution.core.UrlConnectionTransport
import java.io.File

/**
 * Prosper202 install attribution for Android (plan §5.6).
 *
 * ```kotlin
 * // Application.onCreate():
 * P202Attribution.configure(this, "https://track.example.com", "<app_token from p202 app get>")
 *
 * // Anywhere after:
 * P202Attribution.logEvent("level_reached", mapOf("level" to 3))
 * P202Attribution.logEvent("purchase", revenue = 4.99, transactionId = order.id)
 * P202Attribution.setCustomerId(user.id, signatureFromYourServer)
 * ```
 *
 * On the first launch the SDK reads the Google Play install referrer — which
 * carries the click id your store link put there — and reports the install
 * once; events and the customer id follow it. Everything is sent from one
 * background thread, persisted in the app's no-backup directory, and
 * retried with backoff across launches. No advertising ID is read and no
 * permission beyond INTERNET is added.
 */
object P202Attribution {
    private const val TAG = "P202Attribution"
    private const val STATE_FILE = "p202-attribution.json"

    /**
     * @property test mark the install `test: true` (it counts only under the
     *   registration's `accept_test_signals`). Default: whether the app is a
     *   debuggable build.
     * @property integrity the Play Integrity seam; none by default (PR 6
     *   wires the real request).
     * @property listener what happened, for the app's own logging.
     * @property logging write the SDK's diagnostics to Logcat.
     */
    class Options @JvmOverloads constructor(
        val test: Boolean? = null,
        val integrity: IntegrityProvider = IntegrityProvider.NONE,
        val listener: AttributionListener? = null,
        val logging: Boolean = true,
    )

    @Volatile
    private var engine: AttributionEngine? = null

    /**
     * Start the SDK. Call it from `Application.onCreate()` on every launch;
     * calling it again with another endpoint or token reconfigures it (the
     * [Options]' integrity provider and listener are the first call's).
     * Throws [IllegalArgumentException] for an endpoint that is not an
     * http(s) URL or a token that is not the app's 64-character token.
     */
    @JvmStatic
    @JvmOverloads
    fun configure(context: Context, endpoint: String, appToken: String, options: Options = Options()) {
        val app = context.applicationContext
        val info = app.applicationInfo
        val test = options.test ?: ((info.flags and ApplicationInfo.FLAG_DEBUGGABLE) != 0)
        val config = AttributionConfig.of(endpoint, appToken, app.packageName, appVersion(app), Build.VERSION.RELEASE, test)
        val e = synchronized(this) {
            engine ?: AttributionEngine(
                store = FileStore(File(app.noBackupFilesDir, STATE_FILE)) { Log.w(TAG, it) },
                transport = UrlConnectionTransport(),
                scheduler = ExecutorScheduler(),
                clock = Clock { System.currentTimeMillis() },
                referrerSource = PlayInstallReferrerSource(app),
                integrity = options.integrity,
                listener = options.listener ?: object : AttributionListener {},
                logger = if (options.logging) LogcatLogger else SilentLogger,
            ).also {
                engine = it
                (app as? Application)?.registerActivityLifecycleCallbacks(FlushWhenBackgrounded(it))
            }
        }
        e.configure(config)
    }

    /**
     * Report an event for the goals to evaluate on the server. Throws
     * [InvalidEventException] for one the server would refuse (a name that
     * is not 1–64 of letters, digits, `_ . : -`; more than 32 properties; a
     * property that is not a string of up to 255 bytes, a finite number or a
     * boolean; a non-finite revenue), and [IllegalStateException] before
     * [configure]. Returns the event's id.
     */
    @JvmStatic
    @JvmOverloads
    @Throws(InvalidEventException::class)
    fun logEvent(name: String, properties: Map<String, Any?> = emptyMap(), revenue: Double? = null, transactionId: String? = null): String =
        requireEngine().logEvent(name, properties, revenue, transactionId)

    /**
     * Record who the app's user is, as a customer id your own server has
     * signed (`cust_sig`, documentation/features/visitor-identity.md). There
     * is no unsigned form: the app token is public, so an id anyone can send
     * links nothing. Kept across launches until [clearCustomerId]. Throws
     * [InvalidCustomerIdException] for an id or signature the server would
     * refuse, and stores nothing then.
     */
    @JvmStatic
    @JvmOverloads
    @Throws(InvalidCustomerIdException::class)
    fun setCustomerId(id: String, signature: String, type: CustomerId.Type = CustomerId.Type.CUSTOM) {
        val customer = CustomerId.of(id, signature, type)
        requireEngine().setCustomerId(customer)
    }

    /** Forget the customer id (on sign-out). Clicks already linked stay linked. */
    @JvmStatic
    fun clearCustomerId() {
        requireEngine().clearCustomerId()
    }

    /** Send what is waiting now (the SDK also does this when the app goes to the background). */
    @JvmStatic
    fun flush() {
        engine?.flush()
    }

    /** What the server classified the install as (`attributed`, `organic`, …); null until it answered. */
    @JvmStatic
    val installMatch: String? get() = engine?.installMatch

    private fun requireEngine(): AttributionEngine =
        engine ?: throw IllegalStateException("P202Attribution.configure() must be called first (in Application.onCreate)")

    private fun appVersion(context: Context): String? = try {
        context.packageManager.getPackageInfo(context.packageName, 0).versionName
    } catch (e: PackageManager.NameNotFoundException) {
        null
    }

    private object LogcatLogger : Logger {
        override fun info(message: String) {
            Log.i(TAG, message)
        }

        override fun warn(message: String) {
            Log.w(TAG, message)
        }
    }

    /** Flush when the last started activity stops: the app went to the background. */
    private class FlushWhenBackgrounded(private val engine: AttributionEngine) : Application.ActivityLifecycleCallbacks {
        private var started = 0

        override fun onActivityStarted(activity: Activity) {
            started++
        }

        override fun onActivityStopped(activity: Activity) {
            started = maxOf(0, started - 1)
            if (started == 0) engine.flush()
        }

        override fun onActivityCreated(activity: Activity, savedInstanceState: Bundle?) {}
        override fun onActivityResumed(activity: Activity) {}
        override fun onActivityPaused(activity: Activity) {}
        override fun onActivitySaveInstanceState(activity: Activity, outState: Bundle) {}
        override fun onActivityDestroyed(activity: Activity) {}
    }
}

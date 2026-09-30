package com.prosper202.attribution

import android.app.Application
import android.content.ComponentName
import android.content.Context
import android.content.ContextWrapper
import android.content.IntentFilter
import android.content.pm.ApplicationInfo
import android.content.pm.PackageInfo
import android.os.Bundle
import com.google.android.finsky.externalreferrer.IGetInstallReferrerService
import com.prosper202.attribution.core.Clock
import com.prosper202.attribution.core.ExecutorScheduler
import com.prosper202.attribution.core.JsonValue
import com.prosper202.attribution.core.Scheduler
import java.util.Collections
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicInteger
import org.robolectric.Shadows.shadowOf

/*
 * The Android side of the Play Store, for the :android Robolectric suite
 * (the shared server and vectors are test-shared/'s Shared.kt).
 */

/**
 * The application as the SDK sees it, under another application id. The
 * vectors are for `com.example.summit` and the live pass for the app the
 * instance registered; Robolectric's application is the test manifest's.
 * Everything else — the package manager, bindService, the no-backup
 * directory — is Robolectric's real application underneath.
 */
internal class AppContext(private val app: Application, private val packageName: String, versionName: String?) : ContextWrapper(app) {
    init {
        val info = PackageInfo()
        info.packageName = packageName
        info.versionName = versionName
        info.applicationInfo = ApplicationInfo().also { it.packageName = packageName }
        shadowOf(app.packageManager).installPackage(info)
    }

    override fun getPackageName(): String = packageName

    override fun getApplicationContext(): Context = this
}

/**
 * The Play Store's `GetInstallReferrerService`, bound the way
 * InstallReferrerClientImpl binds it: installreferrer 2.2 resolves the
 * service by action and component, requires com.android.vending at version
 * 80837300 or later, binds, and calls the AIDL method (`c(Bundle)`) with
 * the app's package name. [answer] is the Bundle Play would return.
 */
internal class FakePlayStore(private val app: Application, versionCode: Int = 80837300) {
    val requests: MutableList<Bundle> = Collections.synchronizedList(ArrayList())

    @Volatile
    var answer: (Bundle) -> Bundle = { Bundle() }

    val binder = object : IGetInstallReferrerService.Stub() {
        override fun c(request: Bundle): Bundle {
            requests.add(request)
            return answer(request)
        }
    }

    init {
        val info = PackageInfo()
        info.packageName = VENDING
        @Suppress("DEPRECATION")
        info.versionCode = versionCode
        info.applicationInfo = ApplicationInfo().also { it.packageName = VENDING }
        val pm = shadowOf(app.packageManager)
        pm.installPackage(info)
        pm.addServiceIfNotPresent(COMPONENT)
        pm.addIntentFilterForService(COMPONENT, IntentFilter(ACTION))
        shadowOf(app).setComponentNameAndServiceForBindService(COMPONENT, binder)
    }

    companion object {
        const val VENDING = "com.android.vending"
        const val ACTION = "com.google.android.finsky.BIND_GET_INSTALL_REFERRER_SERVICE"
        val COMPONENT = ComponentName(VENDING, "com.google.android.finsky.externalreferrer.GetInstallReferrerService")

        /** The Bundle Play returns for [details] (installreferrer 2.2's ReferrerDetails keys). */
        fun bundleOf(referrer: JsonValue.Obj): Bundle = Bundle().apply {
            putString("install_referrer", referrer["install_referrer"]?.stringOrNull)
            putLong("referrer_click_timestamp_seconds", referrer["referrer_click_timestamp_seconds"]?.longOrNull ?: 0)
            putLong("install_begin_timestamp_seconds", referrer["install_begin_timestamp_seconds"]?.longOrNull ?: 0)
            putLong("referrer_click_timestamp_server_seconds", referrer["referrer_click_timestamp_server_seconds"]?.longOrNull ?: 0)
            putLong("install_begin_timestamp_server_seconds", referrer["install_begin_timestamp_server_seconds"]?.longOrNull ?: 0)
            putString("install_version", referrer["install_version"]?.stringOrNull)
            putBoolean("google_play_instant", referrer["google_play_instant"] == JsonValue.Bool(true))
        }
    }
}

/**
 * The engines' worker and clock, with every delayed task on record, so a
 * test can show that nothing more will be sent without sleeping past a
 * guess at the backoff. [execute] and [schedule] run for real (one worker
 * thread per engine, real delays), so the retry tests still wait out a
 * real Retry-After; [runEveryDelayedTask] then fires whatever is still
 * waiting — a retry, a wake, the referrer timeout — one at a time in due
 * order, moving [clock] forward to each task's due time first, so the
 * engine's own "is it time yet" check agrees that it is.
 */
internal class VirtualTime {
    @Volatile
    private var offset = 0L
    private val workers = Collections.synchronizedList(ArrayList<Worker>())

    val clock = Clock { System.currentTimeMillis() + offset }

    fun newScheduler(): Scheduler = Worker().also { workers.add(it) }

    private class Delayed(val due: Long, val delay: Long, val task: () -> Unit) {
        private val claimed = AtomicBoolean(false)
        fun claim(): Boolean = claimed.compareAndSet(false, true)
        val pending: Boolean get() = !claimed.get()
    }

    private inner class Worker : Scheduler {
        val real = ExecutorScheduler()
        val delayed: MutableList<Delayed> = Collections.synchronizedList(ArrayList())

        /**
         * Tasks submitted and not yet finished. Counted up before a task is
         * queued (or, for a timer, before it claims its entry), and down
         * after it has run, so a task that queues or schedules another is
         * never seen as idle in between.
         */
        val busy = AtomicInteger()

        override fun execute(task: () -> Unit) {
            busy.incrementAndGet()
            real.execute {
                try {
                    task()
                } finally {
                    busy.decrementAndGet()
                }
            }
        }

        override fun schedule(delayMillis: Long, task: () -> Unit): () -> Unit {
            val d = Delayed(clock.nowMillis() + delayMillis, delayMillis, task)
            delayed.add(d)
            val cancel = real.schedule(delayMillis) {
                busy.incrementAndGet()
                try {
                    if (d.claim()) task()
                } finally {
                    busy.decrementAndGet()
                }
            }
            return {
                d.claim()
                cancel()
            }
        }

        /** Wait until nothing submitted to the worker is queued or running. */
        fun idle() {
            val until = System.nanoTime() + TimeUnit.SECONDS.toNanos(20)
            while (busy.get() != 0) {
                check(System.nanoTime() < until) { "the SDK's worker did not go idle in 20s" }
                Thread.sleep(2)
            }
        }
    }

    /**
     * Fire every delayed task the engines still hold, and every one those
     * schedule in turn, until none is left. Returns the delays that were
     * fired. Fails if the engines are still scheduling after [limit] tasks.
     */
    fun runEveryDelayedTask(limit: Int = 50): List<Long> {
        val fired = ArrayList<Long>()
        repeat(limit) {
            val all = synchronized(workers) { workers.toList() }
            all.forEach { it.idle() }
            val (worker, next) = all
                .flatMap { w -> synchronized(w.delayed) { w.delayed.filter { it.pending } }.map { w to it } }
                .minByOrNull { it.second.due }
                // Nothing waiting; done only if nothing started meanwhile
                // (a timer that claimed its entry after idle() returned).
                ?: if (all.all { it.busy.get() == 0 }) return fired else return@repeat
            val ahead = next.due - clock.nowMillis()
            if (ahead > 0) offset += ahead
            if (next.claim()) {
                fired.add(next.delay)
                worker.execute(next.task)
            }
        }
        throw AssertionError("the engines were still scheduling work after $limit delayed tasks: ${fired.take(10)}")
    }
}

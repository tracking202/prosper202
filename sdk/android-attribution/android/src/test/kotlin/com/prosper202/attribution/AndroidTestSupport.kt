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
import com.prosper202.attribution.core.JsonValue
import java.util.Collections
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

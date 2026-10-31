package com.prosper202.attribution

import android.content.Context
import android.os.RemoteException
import com.android.installreferrer.api.InstallReferrerClient
import com.android.installreferrer.api.InstallReferrerClient.InstallReferrerResponse
import com.android.installreferrer.api.InstallReferrerStateListener
import com.prosper202.attribution.core.ReferrerDetails
import com.prosper202.attribution.core.ReferrerSource
import com.prosper202.attribution.core.ReferrerStatus
import java.util.concurrent.atomic.AtomicBoolean

/**
 * Google Play's Install Referrer API (`installreferrer:2.2`), read once per
 * request and reported field for field. The engine decides what to do with
 * a transient answer (read again) and persists the result, so this class
 * holds no state. The referrer stays available for 90 days after install.
 */
class PlayInstallReferrerSource(context: Context) : ReferrerSource {
    private val appContext = context.applicationContext

    override fun read(callback: (ReferrerDetails) -> Unit) {
        val answered = AtomicBoolean(false)
        val client = InstallReferrerClient.newBuilder(appContext).build()
        fun answer(details: ReferrerDetails) {
            if (!answered.compareAndSet(false, true)) return
            try {
                client.endConnection()
            } catch (e: RuntimeException) {
                // Ending a connection that failed to start can throw; the answer stands.
            }
            callback(details)
        }
        client.startConnection(object : InstallReferrerStateListener {
            override fun onInstallReferrerSetupFinished(responseCode: Int) {
                answer(
                    when (responseCode) {
                        InstallReferrerResponse.OK -> try {
                            val r = client.installReferrer
                            ReferrerDetails(
                                status = ReferrerStatus.OK,
                                installReferrer = r.installReferrer ?: "",
                                referrerClickTimestampSeconds = r.referrerClickTimestampSeconds,
                                installBeginTimestampSeconds = r.installBeginTimestampSeconds,
                                referrerClickTimestampServerSeconds = r.referrerClickTimestampServerSeconds,
                                installBeginTimestampServerSeconds = r.installBeginTimestampServerSeconds,
                                installVersion = r.installVersion,
                                googlePlayInstant = r.googlePlayInstantParam,
                            )
                        } catch (e: RemoteException) {
                            ReferrerDetails.unavailable(ReferrerStatus.SERVICE_UNAVAILABLE)
                        }
                        InstallReferrerResponse.FEATURE_NOT_SUPPORTED -> ReferrerDetails.unavailable(ReferrerStatus.FEATURE_NOT_SUPPORTED)
                        InstallReferrerResponse.DEVELOPER_ERROR -> ReferrerDetails.unavailable(ReferrerStatus.DEVELOPER_ERROR)
                        InstallReferrerResponse.SERVICE_DISCONNECTED -> ReferrerDetails.unavailable(ReferrerStatus.SERVICE_DISCONNECTED)
                        InstallReferrerResponse.PERMISSION_ERROR -> ReferrerDetails.unavailable(ReferrerStatus.PERMISSION_ERROR)
                        // SERVICE_UNAVAILABLE, and any code a later client adds.
                        else -> ReferrerDetails.unavailable(ReferrerStatus.SERVICE_UNAVAILABLE)
                    },
                )
            }

            override fun onInstallReferrerServiceDisconnected() {
                answer(ReferrerDetails.unavailable(ReferrerStatus.SERVICE_DISCONNECTED))
            }
        })
    }
}

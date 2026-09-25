package com.prosper202.attribution.integrity

import android.content.Context
import com.google.android.gms.tasks.Task
import com.google.android.gms.tasks.Tasks
import com.google.android.play.core.integrity.IntegrityManagerFactory
import com.google.android.play.core.integrity.StandardIntegrityException
import com.google.android.play.core.integrity.StandardIntegrityManager
import com.google.android.play.core.integrity.StandardIntegrityManager.PrepareIntegrityTokenRequest
import com.google.android.play.core.integrity.StandardIntegrityManager.StandardIntegrityTokenProvider
import com.google.android.play.core.integrity.StandardIntegrityManager.StandardIntegrityTokenRequest
import com.google.android.play.core.integrity.model.StandardIntegrityErrorCode
import com.prosper202.attribution.core.InstallAttempt
import com.prosper202.attribution.core.IntegrityProvider
import com.prosper202.attribution.core.IntegrityUnavailableException
import java.util.concurrent.ExecutionException
import java.util.concurrent.TimeUnit
import java.util.concurrent.TimeoutException

/**
 * Play Integrity's **standard** request for the SDK's install (plan §5.6,
 * §5.11; documentation/api/24-android-installs.md §9):
 *
 * ```kotlin
 * P202Attribution.configure(this, endpoint, appToken,
 *     P202Attribution.Options(integrity = PlayIntegrityProvider(this)))
 * ```
 *
 * The engine calls [tokenFor] only when the registration's schema document
 * asks for a token, on its worker thread, before each attempt to send the
 * install. The token provider is prepared once per process for the Cloud
 * project the schema names (Google recommends preparing ahead of the
 * request), and each call requests a fresh token with
 * `requestHash` = [InstallAttempt.requestHash] — the SHA-256 of the install
 * body's canonical form, which the server compares with the hash Google
 * signs into the verdict. An `INTEGRITY_TOKEN_PROVIDER_INVALID` answer
 * prepares again once.
 *
 * Failures are sorted for the engine: network, server, quota and transient
 * client errors are [IntegrityUnavailableException] with `retryable = true`
 * (the install waits and asks again); the rest (no Play Store or services,
 * an outdated one, a bad project number) are not retryable, and the
 * install goes without a token.
 */
class PlayIntegrityProvider @JvmOverloads constructor(
    context: Context,
    private val timeoutSeconds: Long = 30,
) : IntegrityProvider {
    private val manager: StandardIntegrityManager = IntegrityManagerFactory.createStandard(context.applicationContext)
    private var prepared: StandardIntegrityTokenProvider? = null
    private var preparedFor: Long = 0

    @Synchronized
    override fun tokenFor(install: InstallAttempt): String? {
        return try {
            request(install)
        } catch (e: IntegrityUnavailableException) {
            val cause = e.cause
            if (cause is StandardIntegrityException && cause.errorCode == StandardIntegrityErrorCode.INTEGRITY_TOKEN_PROVIDER_INVALID) {
                prepared = null
                request(install)
            } else {
                throw e
            }
        }
    }

    private fun request(install: InstallAttempt): String? {
        val provider = prepared?.takeIf { preparedFor == install.cloudProjectNumber } ?: await(
            manager.prepareIntegrityToken(
                PrepareIntegrityTokenRequest.builder().setCloudProjectNumber(install.cloudProjectNumber).build(),
            ),
        ).also {
            prepared = it
            preparedFor = install.cloudProjectNumber
        }
        val token = await(provider.request(StandardIntegrityTokenRequest.builder().setRequestHash(install.requestHash).build()))
        return token.token()
    }

    private fun <T> await(task: Task<T>): T {
        try {
            return Tasks.await(task, timeoutSeconds, TimeUnit.SECONDS)
        } catch (e: ExecutionException) {
            val cause = e.cause
            if (cause is StandardIntegrityException) {
                throw IntegrityUnavailableException("Play Integrity error ${cause.errorCode}", retryable(cause.errorCode), cause)
            }
            throw IntegrityUnavailableException("Play Integrity failed: ${cause?.message}", retryable = false, cause = cause)
        } catch (e: TimeoutException) {
            throw IntegrityUnavailableException("Play Integrity did not answer in ${timeoutSeconds}s", retryable = true, cause = e)
        } catch (e: InterruptedException) {
            Thread.currentThread().interrupt()
            throw IntegrityUnavailableException("interrupted while waiting for Play Integrity", retryable = true, cause = e)
        }
    }

    private fun retryable(code: Int): Boolean = when (code) {
        StandardIntegrityErrorCode.NETWORK_ERROR,
        StandardIntegrityErrorCode.TOO_MANY_REQUESTS,
        StandardIntegrityErrorCode.GOOGLE_SERVER_UNAVAILABLE,
        StandardIntegrityErrorCode.CLIENT_TRANSIENT_ERROR,
        StandardIntegrityErrorCode.INTERNAL_ERROR,
        StandardIntegrityErrorCode.CANNOT_BIND_TO_SERVICE,
        StandardIntegrityErrorCode.INTEGRITY_TOKEN_PROVIDER_INVALID,
        -> true
        else -> false
    }
}

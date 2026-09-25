package com.prosper202.attribution.core

import java.net.URI
import java.net.URISyntaxException

/**
 * What `configure()` was given, checked before anything is sent (plan §4.3:
 * `configure(endpoint, appToken)`).
 *
 * - [endpoint] is the Prosper202 install's origin, `https://track.example.com`
 *   (a trailing `/` or `/api/v3` is tolerated and removed); the SDK appends
 *   the contract's paths. `http://` is accepted for a development server.
 * - [appToken] is the registration's app token: 64 hexadecimal characters,
 *   an identifier rather than a secret (it ships in every copy of the app).
 * - [appKey] is the build's application id (the package name), which must
 *   be the token's app: the server answers 422 otherwise.
 * - [test] marks a debug build's install `test: true`; it counts only under
 *   the registration's `accept_test_signals`.
 */
class AttributionConfig private constructor(
    val endpoint: String,
    val appToken: String,
    val appKey: String,
    val appVersion: String?,
    val osVersion: String?,
    val test: Boolean,
) {
    val installsUrl: String get() = "$endpoint/api/v3/apps/installs"

    fun eventsUrl(installUuid: String): String = "$endpoint/api/v3/apps/installs/$installUuid/events"

    /** What a refusal is tied to: a new endpoint or token re-arms a refused install. */
    internal val target: String get() = sha256Hex(endpoint + "\n" + appToken.lowercase())

    companion object {
        @JvmStatic
        @JvmOverloads
        fun of(
            endpoint: String,
            appToken: String,
            appKey: String,
            appVersion: String? = null,
            osVersion: String? = null,
            test: Boolean = false,
        ): AttributionConfig {
            val uri = try {
                URI(endpoint)
            } catch (e: URISyntaxException) {
                throw IllegalArgumentException("endpoint must be the Prosper202 install's URL, such as https://track.example.com (${e.message})")
            }
            val scheme = uri.scheme?.lowercase()
            require((scheme == "https" || scheme == "http") && !uri.host.isNullOrEmpty()) {
                "endpoint must be an http(s) URL with a host, such as https://track.example.com"
            }
            require(uri.rawQuery == null && uri.rawFragment == null && uri.rawUserInfo == null) {
                "endpoint must not carry a query, a fragment or credentials; the app token travels in its own header"
            }
            var base = endpoint.trimEnd('/')
            if (base.endsWith("/api/v3")) base = base.removeSuffix("/api/v3").trimEnd('/')
            require(appToken.length == 64 && appToken.all { it in '0'..'9' || it in 'a'..'f' || it in 'A'..'F' }) {
                "appToken must be the app's 64-character token (p202 app get <id>), not an API key"
            }
            require(AppKey.android(appKey) != null) { "appKey \"$appKey\" is not an Android application id" }
            return AttributionConfig(base, appToken, appKey, appVersion, osVersion, test)
        }
    }
}

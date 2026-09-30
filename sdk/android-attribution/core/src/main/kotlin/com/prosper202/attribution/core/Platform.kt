package com.prosper202.attribution.core

import java.io.File
import java.io.FileOutputStream
import java.io.IOException
import java.util.concurrent.ScheduledThreadPoolExecutor
import java.util.concurrent.ThreadFactory
import java.util.concurrent.TimeUnit

/*
 * The seams between the plain-JVM core and the platform. Android supplies
 * the referrer (Play's InstallReferrerClient), the storage directory
 * (noBackupFilesDir, so the install id is never restored onto another
 * device) and the device facts; everything else — the transport, the
 * worker, the store format — is plain Java that runs on Android from
 * API 21 and on the JVM in the SDK's tests.
 */

/** Durable key → value state. [edit] applies every change or none. */
interface KeyValueStore {
    fun get(key: String): String?

    /** Apply [changes] (a null value removes the key) atomically and durably. */
    @Throws(IOException::class)
    fun edit(changes: Map<String, String?>)
}

/** For tests and short-lived tools: nothing survives the process. */
class InMemoryStore : KeyValueStore {
    private val map = LinkedHashMap<String, String>()

    @Synchronized
    override fun get(key: String): String? = map[key]

    @Synchronized
    override fun edit(changes: Map<String, String?>) {
        for ((k, v) in changes) if (v == null) map.remove(k) else map[k] = v
    }

    @Synchronized
    fun snapshot(): Map<String, String> = LinkedHashMap(map)
}

/**
 * One JSON file, rewritten whole through a temporary file and a rename, so
 * a crash leaves the old state or the new one, never half of either.
 *
 * On Android it lives in `Context.getNoBackupFilesDir()`: Auto Backup and
 * device-to-device transfer never copy it, so a restored phone mints its
 * own install_uuid rather than replaying another device's install (plan
 * §5.6: "install_uuid is excluded from Auto Backup").
 *
 * A file that cannot be read as this store's JSON is not silently treated
 * as empty (CLAUDE.md #4): it is moved aside as `<name>.corrupt-<time>`
 * and reported through [onCorrupt], and the store starts fresh — which
 * mints a new install, the only way forward that does not guess.
 */
class FileStore(private val file: File, private val onCorrupt: (String) -> Unit = {}) : KeyValueStore {
    private var map: MutableMap<String, String>? = null

    @Synchronized
    override fun get(key: String): String? = load()[key]

    @Synchronized
    override fun edit(changes: Map<String, String?>) {
        val next = LinkedHashMap(load())
        for ((k, v) in changes) if (v == null) next.remove(k) else next[k] = v
        write(next)
        map = next
    }

    private fun load(): MutableMap<String, String> {
        map?.let { return it }
        val loaded = LinkedHashMap<String, String>()
        if (file.exists()) {
            val text = try {
                file.readText(Charsets.UTF_8)
            } catch (e: IOException) {
                throw IllegalStateException("the SDK state file ${file.path} cannot be read: ${e.message}", e)
            }
            val parsed = try {
                Json.parse(text).objOrNull
            } catch (e: JsonException) {
                null
            }
            if (parsed == null || parsed.fields.values.any { it !is JsonValue.Str }) {
                val aside = File(file.path + ".corrupt-" + System.currentTimeMillis())
                file.renameTo(aside)
                onCorrupt("the SDK state file was not readable and was moved to ${aside.name}; starting fresh")
            } else {
                for ((k, v) in parsed.fields) loaded[k] = (v as JsonValue.Str).value
            }
        }
        map = loaded
        return loaded
    }

    private fun write(state: Map<String, String>) {
        file.parentFile?.mkdirs()
        val tmp = File(file.path + ".tmp")
        val text = Json.write(JsonValue.Obj(state.mapValues { JsonValue.Str(it.value) }))
        FileOutputStream(tmp).use { out ->
            out.write(text.toByteArray(Charsets.UTF_8))
            out.flush()
            out.fd.sync()
        }
        if (!tmp.renameTo(file)) {
            // renameTo does not replace on every filesystem; delete and retry once.
            file.delete()
            if (!tmp.renameTo(file)) throw IOException("could not replace ${file.path}")
        }
    }
}

/** One HTTP exchange's outcome. [status] is 0 when the exchange failed before a status arrived. */
class HttpResponse(
    val status: Int,
    val body: String,
    /** Header names lower-cased. */
    val headers: Map<String, String> = emptyMap(),
)

interface HttpTransport {
    /**
     * POST [body] as JSON to [url] with [headers]. Throws [IOException] for
     * a network failure (the SDK retries it); any HTTP status is returned.
     */
    @Throws(IOException::class)
    fun post(url: String, headers: Map<String, String>, body: String): HttpResponse

    /** GET [url] with [headers] (the schema document), as [post] answers. */
    @Throws(IOException::class)
    fun get(url: String, headers: Map<String, String>): HttpResponse
}

/** java.net.HttpURLConnection: in the JDK and on every Android release. */
class UrlConnectionTransport(
    private val connectTimeoutMillis: Int = 15_000,
    private val readTimeoutMillis: Int = 30_000,
) : HttpTransport {
    override fun post(url: String, headers: Map<String, String>, body: String): HttpResponse = exchange(url, headers, body)

    override fun get(url: String, headers: Map<String, String>): HttpResponse = exchange(url, headers, null)

    private fun exchange(url: String, headers: Map<String, String>, body: String?): HttpResponse {
        val conn = java.net.URI(url).toURL().openConnection() as java.net.HttpURLConnection
        try {
            conn.requestMethod = if (body == null) "GET" else "POST"
            conn.connectTimeout = connectTimeoutMillis
            conn.readTimeout = readTimeoutMillis
            conn.instanceFollowRedirects = false
            conn.setRequestProperty("Accept", "application/json")
            for ((k, v) in headers) conn.setRequestProperty(k, v)
            if (body != null) {
                conn.doOutput = true
                conn.setRequestProperty("Content-Type", "application/json")
                val bytes = body.toByteArray(Charsets.UTF_8)
                conn.setFixedLengthStreamingMode(bytes.size)
                conn.outputStream.use { it.write(bytes) }
            }
            val status = conn.responseCode
            val stream = if (status >= 400) conn.errorStream else conn.inputStream
            val text = stream?.use { String(it.readBytes(), Charsets.UTF_8) } ?: ""
            val out = LinkedHashMap<String, String>()
            for ((k, v) in conn.headerFields) {
                if (k != null && v != null && v.isNotEmpty()) out[k.lowercase()] = v.last()
            }
            return HttpResponse(status, text, out)
        } finally {
            conn.disconnect()
        }
    }
}

/** Where the SDK's work runs: one task at a time, in order. */
interface Scheduler {
    fun execute(task: () -> Unit)

    /** Run [task] after [delayMillis]; the returned function cancels it. */
    fun schedule(delayMillis: Long, task: () -> Unit): () -> Unit
}

/** One daemon thread: the SDK never blocks the app's threads on disk or network. */
class ExecutorScheduler : Scheduler {
    private val executor = ScheduledThreadPoolExecutor(
        1,
        ThreadFactory { r -> Thread(r, "p202-attribution").apply { isDaemon = true } },
    ).apply { removeOnCancelPolicy = true }

    override fun execute(task: () -> Unit) {
        executor.execute(task)
    }

    override fun schedule(delayMillis: Long, task: () -> Unit): () -> Unit {
        val f = executor.schedule(task, maxOf(0L, delayMillis), TimeUnit.MILLISECONDS)
        return { f.cancel(false) }
    }

    /** Wait for everything submitted so far (tests and the live pass). */
    fun drain(timeoutMillis: Long) {
        val done = java.util.concurrent.CountDownLatch(1)
        executor.execute { done.countDown() }
        done.await(timeoutMillis, TimeUnit.MILLISECONDS)
    }
}

/** Milliseconds since the epoch. */
fun interface Clock {
    fun nowMillis(): Long
}

/** Diagnostics; the Android module sends them to Logcat. */
interface Logger {
    fun info(message: String)
    fun warn(message: String)
}

object SilentLogger : Logger {
    override fun info(message: String) {}
    override fun warn(message: String) {}
}

/**
 * Where the install referrer comes from. Android reads Play's
 * InstallReferrerClient; tests and the live pass hand one in. [read] may
 * answer on any thread, once.
 */
fun interface ReferrerSource {
    fun read(callback: (ReferrerDetails) -> Unit)
}

/**
 * Play Integrity (plan §5.6, §5.11): where the install's `integrity_token`
 * comes from.
 *
 * The engine asks only when the registration's schema document says so
 * (`integrity.request_token`, under `observe` or `require`), and asks
 * afresh before every attempt to send the install — the server refuses a
 * token issued more than 10 minutes before the install arrives. It hands
 * over the [InstallAttempt]: the **standard** token must be requested with
 * `requestHash` = [InstallAttempt.requestHash], the lower-case hex SHA-256
 * of the install body's canonical form, for the Cloud project the schema
 * names; `tests/fixtures/app-sdk-contract/android/integrity.json` pins the
 * hash. The token itself is left out of the canonical form, so a retry with
 * a fresh token is still the same install.
 *
 * Return the token, or null when there is none to give. Throw
 * [IntegrityUnavailableException] with `retryable = true` for a failure
 * worth waiting out (Play's network or server errors, too many requests):
 * the engine holds the install back and retries it with backoff, at most
 * [AttributionEngine.MAX_INTEGRITY_ATTEMPTS] times, before sending it
 * without a token — which under `require` is recorded
 * `integrity_unverified` and never paid, so it is worth the wait. Any other
 * exception sends the install without a token at once.
 *
 * Called on the SDK's worker thread; blocking is expected. The Android
 * implementation is `PlayIntegrityProvider` (the `integrity` module); the
 * default, [NONE], gives no token.
 */
fun interface IntegrityProvider {
    @Throws(IntegrityUnavailableException::class)
    fun tokenFor(install: InstallAttempt): String?

    companion object {
        @JvmField
        val NONE: IntegrityProvider = IntegrityProvider { null }
    }
}

/** A Play Integrity request that failed; [retryable] says whether waiting may help. */
class IntegrityUnavailableException(message: String, val retryable: Boolean, cause: Throwable? = null) : Exception(message, cause)

/** What the integrity provider is asked to vouch for. */
class InstallAttempt(
    val installUuid: String,
    /** The canonical body (integrity_token excluded): the bytes the server fingerprints. */
    val canonicalBody: String,
    /** Lower-case hex SHA-256 of [canonicalBody]: the standard request's `requestHash`. */
    val requestHash: String,
    /** The Cloud project the schema document names (`integrity.cloud_project_number`). */
    val cloudProjectNumber: Long,
) {
    /** The server's name for the same value (`body_hash`, the replay check). */
    val fingerprint: String get() = requestHash
}

/** What happened, for the app's own logging and for tests. Every method has a default. */
interface AttributionListener {
    /**
     * The server recorded the install (or answered a replay of it). [match]
     * is empty in one case: the install had been sent, then its customer id
     * was withdrawn, and the server's answer to the changed body said the
     * first version was already recorded — without classifying it again.
     */
    fun onInstallRecorded(match: String, reason: String, duplicate: Boolean) {}

    /** The server refused the install for good ([status]); it is not resent unless the app token changes. */
    fun onInstallRefused(status: Int, message: String) {}

    /** These events were accepted (or were already stored). */
    fun onEventsDelivered(eventIds: List<String>) {}

    /** These events were dropped: the server refused them for good, or the queue was full. */
    fun onEventsDropped(eventIds: List<String>, reason: String) {}

    /** The server's answer about the customer id: linked, unverified, no_click or not_linked. */
    fun onCustomerLinked(status: String) {}
}

/** The Android application id rules (the server's `AppIdentity`; the app-identity.json key vectors). */
object AppKey {
    const val MAX_LENGTH = 255

    /** The key when [raw] is an Android application id; null otherwise. Case-sensitive. */
    @JvmStatic
    fun android(raw: String): String? {
        if (utf8Length(raw) > MAX_LENGTH) return null
        val segments = raw.split('.')
        if (segments.size < 2) return null
        for (seg in segments) {
            if (seg.isEmpty() || !(seg[0] in 'a'..'z' || seg[0] in 'A'..'Z')) return null
            for (c in seg.substring(1)) {
                if (!(c in 'a'..'z' || c in 'A'..'Z' || c in '0'..'9' || c == '_')) return null
            }
        }
        return raw
    }
}

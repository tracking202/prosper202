package com.prosper202.attribution.core

import java.io.IOException
import java.util.PriorityQueue

/** A clock and a one-thread scheduler on virtual time: every retry and delay is stepped, never slept. */
class VirtualTime(var now: Long = 1_727_200_100_000L) : Clock, Scheduler {
    private class Task(val at: Long, val seq: Long, val run: () -> Unit) {
        var cancelled = false
    }

    private var seq = 0L
    private val tasks = PriorityQueue<Task>(compareBy<Task>({ it.at }, { it.seq }))

    override fun nowMillis(): Long = now

    override fun execute(task: () -> Unit) {
        tasks.add(Task(now, seq++, task))
    }

    override fun schedule(delayMillis: Long, task: () -> Unit): () -> Unit {
        val t = Task(now + maxOf(0L, delayMillis), seq++, task)
        tasks.add(t)
        return { t.cancelled = true }
    }

    /** Run everything due now, including what that schedules for now. */
    fun settle() = advance(0)

    /** Move the clock forward [millis], running each task at its time. */
    fun advance(millis: Long) {
        val end = now + millis
        while (true) {
            val t = tasks.peek() ?: break
            if (t.at > end) break
            tasks.poll()
            if (t.cancelled) continue
            now = maxOf(now, t.at)
            t.run()
        }
        now = end
    }

    /** When the next live task is due, or null. */
    fun nextAt(): Long? = tasks.filter { !it.cancelled }.minOfOrNull { it.at }
}

class Request(val url: String, val headers: Map<String, String>, val body: String) {
    val json: JsonValue.Obj get() = Json.parse(body).objOrNull!!
}

/** Answers from a queue of canned responses (or a function), and records every request. */
class FakeTransport : HttpTransport {
    val requests = ArrayList<Request>()
    val schemaReads = ArrayList<String>()
    private val schemaCanned = ArrayDeque<() -> HttpResponse>()

    /** The schema document's answer: Play Integrity off, as PR 5 and an `off` registration say. */
    var schema: () -> HttpResponse = { schemaDoc(request = false) }

    fun thenSchema(status: Int, body: String = "", headers: Map<String, String> = emptyMap()): FakeTransport {
        schemaCanned.addLast { HttpResponse(status, body, headers) }
        return this
    }

    fun thenSchemaFail(): FakeTransport {
        schemaCanned.addLast { throw IOException("connection refused") }
        return this
    }

    override fun get(url: String, headers: Map<String, String>): HttpResponse {
        schemaReads.add(url)
        return (schemaCanned.removeFirstOrNull() ?: schema)()
    }
    private val canned = ArrayDeque<(Request) -> HttpResponse>()
    var fallback: (Request) -> HttpResponse = { ok(it) }

    fun then(status: Int, body: String = "", headers: Map<String, String> = emptyMap()): FakeTransport {
        canned.addLast { HttpResponse(status, body, headers) }
        return this
    }

    fun thenFail(): FakeTransport {
        canned.addLast { throw IOException("connection refused") }
        return this
    }

    fun thenAnswer(f: (Request) -> HttpResponse): FakeTransport {
        canned.addLast(f)
        return this
    }

    override fun post(url: String, headers: Map<String, String>, body: String): HttpResponse {
        val r = Request(url, headers, body)
        requests.add(r)
        val next = canned.removeFirstOrNull() ?: fallback
        return next(r)
    }

    fun installs() = requests.filter { it.url.endsWith("/api/v3/apps/installs") }

    fun events() = requests.filter { it.url.endsWith("/events") }

    companion object {
        /** The Android schema document (PR 6's shape). */
        fun schemaDoc(
            request: Boolean,
            project: Long? = 123456789012,
            type: String = "standard",
            scheme: String = "sha256_hex_of_canonical_install_body",
        ): HttpResponse {
            val integrity = jsonObj(
                "request_token" to JsonValue.Bool(request),
                "token_type" to type.json(),
                // A string, as the server sends it.
                "cloud_project_number" to project?.toString().json(),
                "request_hash" to scheme.json(),
            )
            val data = jsonObj(
                "platform" to "android".json(),
                "app_key" to "com.example.summit".json(),
                "integrity_mode" to (if (request) "require" else "off").json(),
                "integrity" to integrity,
            )
            return HttpResponse(200, Json.write(jsonObj("data" to data)))
        }

        /** The contract's success for either route, echoing what was sent. */
        fun ok(r: Request, match: String = "attributed", customer: String? = "linked"): HttpResponse {
            val body = r.json
            val hasCustomer = body["customer"] != null && body["customer"] !is JsonValue.Null
            val data = if (r.url.endsWith("/events")) {
                val ids = body["events"]?.arrOrNull?.items?.map { it.objOrNull!!["event_id"]!!.stringOrNull!! } ?: emptyList()
                linkedMapOf<String, JsonValue>(
                    "install_uuid" to r.url.substringAfter("/installs/").substringBefore("/events").json(),
                    "accepted" to JsonValue.Arr(ids.map { it.json() }),
                    "duplicates" to JsonValue.Arr(emptyList()),
                )
            } else {
                linkedMapOf(
                    "install_uuid" to body["install_uuid"]!!,
                    "match" to match.json(),
                    "reason" to "Attributed to click 42.".json(),
                    "trusted" to JsonValue.Int(1),
                    "test" to JsonValue.Bool(false),
                    "duplicate" to JsonValue.Bool(false),
                )
            }
            if (hasCustomer && customer != null) data["customer"] = customer.json()
            return HttpResponse(200, Json.write(jsonObj("data" to JsonValue.Obj(data))))
        }
    }
}

class FakeReferrer(var details: ReferrerDetails) : ReferrerSource {
    var reads = 0
    var silent = false

    override fun read(callback: (ReferrerDetails) -> Unit) {
        reads++
        if (!silent) callback(details)
    }
}

class RecordingListener : AttributionListener {
    val recorded = ArrayList<String>()
    val refused = ArrayList<Int>()
    val delivered = ArrayList<String>()
    val dropped = ArrayList<Pair<String, String>>()
    val customer = ArrayList<String>()

    override fun onInstallRecorded(match: String, reason: String, duplicate: Boolean) {
        recorded.add(match)
    }

    override fun onInstallRefused(status: Int, message: String) {
        refused.add(status)
    }

    override fun onEventsDelivered(eventIds: List<String>) {
        delivered.addAll(eventIds)
    }

    override fun onEventsDropped(eventIds: List<String>, reason: String) {
        for (id in eventIds) dropped.add(id to reason)
    }

    override fun onCustomerLinked(status: String) {
        customer.add(status)
    }
}

const val TOKEN = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
const val OTHER_TOKEN = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"

val REFERRER = ReferrerDetails(
    status = ReferrerStatus.OK,
    installReferrer = "p202=42.3MVffxqa2WX3CV49&utm_source=newsletter",
    referrerClickTimestampSeconds = 1727200000,
    installBeginTimestampSeconds = 1727200042,
    referrerClickTimestampServerSeconds = 1727200001,
    installBeginTimestampServerSeconds = 1727200043,
    installVersion = "3.2.0",
    googlePlayInstant = false,
)

fun config(token: String = TOKEN, endpoint: String = "https://track.example.com") =
    AttributionConfig.of(endpoint, token, "com.example.summit", "3.2.0", "15")

package com.prosper202.attribution.core

import java.io.IOException
import java.util.UUID

/**
 * The SDK's state machine, platform-free (plan §5.6): report the install
 * once, then the events after it, and the signed customer id, with retries
 * that survive the process.
 *
 * **The install.** On the first run an `install_uuid` is minted and kept
 * (it is the install's identity for life; a new app token does not make a
 * new install). The referrer is read once — Play's transient answers
 * (`service_unavailable`, `service_disconnected`) are read again up to
 * [MAX_REFERRER_READS] times before being reported as they are — and the
 * body is built **once and persisted**, then resent unchanged until it is
 * answered: the server tells a retry from a reused id by the body's
 * fingerprint (409 for different content), so a rebuilt body would be a
 * conflict, not a retry.
 *
 * **Answers** follow tests/fixtures/app-sdk-contract/android/responses.json:
 * a 2xx whose `data` is that route's receipt — the install echoing its
 * `install_uuid` with a `match`, the events echoing it with `accepted` and
 * `duplicates` that together name every event sent — is success; 429 and
 * 5xx (and a network failure, and any other 2xx, which is what a captive
 * portal or a proxy's error page sends) are retried with backoff — [BASE_BACKOFF_MILLIS]
 * doubling to [MAX_BACKOFF_MILLIS], jittered, `Retry-After` honoured — and
 * the next attempt's time is persisted, so a relaunch waits too; every
 * other status is terminal. A refused install is tied to the endpoint and
 * token it was refused under: a build that ships a corrected token re-arms
 * it (the body, and so the install, is unchanged).
 *
 * **Events** are validated on the caller's thread ([EventPayload.event]
 * throws what the server would refuse), then queued durably — at most
 * [MAX_QUEUED_EVENTS]; beyond that the newest are dropped and reported, so
 * the history the goals read stays a prefix — and sent once the install is
 * recorded, in batches of up to [EventPayload.MAX_EVENTS] and under the 64 KB
 * cap, [FLUSH_DELAY_MILLIS] after the first unsent event (or at once when a
 * batch is full). `occurred_at` never goes backwards, as on iOS: a clock
 * set back cannot move a new event before an old one.
 *
 * **The customer id** rides the install body when the app set it before the
 * body was built, and otherwise the next events request (alone, if there
 * are no events), until one request carrying it is answered. Clearing it, or
 * replacing it with another, while the install is still unanswered takes
 * the old id's signed claim out of the persisted body, so a retry never
 * links the install to a customer the app has signed out; the new one goes
 * on the events route once the install is recorded. The body is otherwise
 * never rebuilt, so when it had already been sent the server may hold the
 * first version: its `409` (a reused install id with other content) then
 * means the install is recorded, and is taken as such.
 *
 * **Play Integrity.** Before the first attempt to send the install in a
 * process, the engine reads the registration's schema document
 * (`GET /apps/schema`); when it says `integrity.request_token` (the
 * registration is `observe` or `require`), every attempt asks [integrity]
 * for a fresh standard token bound to the body's fingerprint
 * ([InstallAttempt.requestHash]) and sends it as `integrity_token`. A
 * `require` install is answered `pending_integrity` and its events `503`
 * until the server's verdict worker settles it: they stay queued and are
 * retried. `integrity_failed` refutes the install (its events are `409`).
 *
 * Every piece of work runs on [scheduler], one at a time; the public
 * methods only validate and hand over.
 */
class AttributionEngine(
    private val store: KeyValueStore,
    private val transport: HttpTransport,
    private val scheduler: Scheduler,
    private val clock: Clock,
    private val referrerSource: ReferrerSource,
    private val integrity: IntegrityProvider = IntegrityProvider.NONE,
    private val listener: AttributionListener = object : AttributionListener {},
    private val logger: Logger = SilentLogger,
    private val random: () -> Double = { Math.random() },
) {
    companion object {
        const val SDK_VERSION = "1.0.0"
        const val HEADER = "X-P202-App-Token"
        const val MAX_QUEUED_EVENTS = 500
        const val MAX_REFERRER_READS = 3
        const val REFERRER_TIMEOUT_MILLIS = 60_000L
        const val BASE_BACKOFF_MILLIS = 30_000L
        const val MAX_BACKOFF_MILLIS = 6 * 60 * 60 * 1000L
        const val FLUSH_DELAY_MILLIS = 5_000L
        const val STORE_RETRY_MILLIS = 60_000L
        /** Attempts held back for a retryable Play Integrity failure before the install goes without a token. */
        const val MAX_INTEGRITY_ATTEMPTS = 3
        /** The binding the SDK implements; a schema naming another is not requested against (PR 6's IntegrityBinding). */
        const val REQUEST_HASH_SCHEME = "sha256_hex_of_canonical_install_body"
        const val TOKEN_TYPE = "standard"
        /** Room left in a batch for the customer object and the wrapper. */
        private const val BODY_MARGIN = 2048

        // Store keys.
        internal const val K_UUID = "install_uuid"
        internal const val K_FIRST_OPEN = "first_open_at"
        internal const val K_BODY = "install_body"
        internal const val K_STATE = "install_state"
        internal const val K_MATCH = "install_match"
        internal const val K_REASON = "install_reason"
        internal const val K_REFUSED_STATUS = "install_refused_status"
        internal const val K_REFUSED_TARGET = "install_refused_target"
        internal const val K_ATTEMPTS = "install_attempts"
        internal const val K_NEXT_AT = "install_next_at"
        internal const val K_REFERRER_READS = "referrer_reads"
        internal const val K_EVENTS = "events"
        internal const val K_EVENTS_ATTEMPTS = "events_attempts"
        internal const val K_EVENTS_NEXT_AT = "events_next_at"
        internal const val K_EVENTS_STOPPED = "events_stopped"
        internal const val K_EVENTS_BLOCKED = "events_blocked_target"
        internal const val K_BATCH_LIMIT = "events_batch_limit"
        internal const val K_CUSTOMER = "customer"
        internal const val K_CUSTOMER_SENT = "customer_sent"
        internal const val K_LAST_OCCURRED = "last_occurred_at"
        internal const val K_INTEGRITY_ATTEMPTS = "integrity_attempts"
        internal const val K_INTEGRITY = "install_integrity"
        /** Set once the install body has been handed to the transport: the server may hold it from then on. */
        internal const val K_BODY_POSTED = "install_body_posted"
        /** The body was changed (a customer claim withdrawn) after it may have reached the server. */
        internal const val K_BODY_CHANGED = "install_body_changed_after_post"

        internal const val RECORDED = "recorded"
        internal const val REFUSED = "refused"
        internal const val PENDING = "pending"
    }

    @Volatile
    private var config: AttributionConfig? = null
    private var referrerGeneration = 0
    private var referrerInFlight = false
    private var wakeAt = Long.MAX_VALUE
    private var cancelWake: (() -> Unit)? = null
    /** The schema document's integrity instruction, read once per process and target. */
    private var integrityPlan: Pair<String, IntegrityPlan>? = null
    private var warnedNoProvider = false

    /** What the schema document says about Play Integrity for this registration. */
    internal class IntegrityPlan(val request: Boolean, val cloudProjectNumber: Long?)

    /** Start, or change the endpoint/token. Safe to call on every launch. */
    fun configure(config: AttributionConfig) {
        this.config = config
        run {
            if (store.get(K_UUID) == null) {
                store.edit(mapOf(K_UUID to UUID.randomUUID().toString().lowercase(), K_FIRST_OPEN to (now() / 1000).toString()))
            }
            pump()
        }
    }

    /**
     * Queue an event. Throws [InvalidEventException] for one the server
     * would refuse (nothing is queued then) and [IllegalStateException]
     * before [configure]. Returns the event's id.
     */
    fun logEvent(name: String, properties: Map<String, Any?> = emptyMap(), revenue: Double? = null, transactionId: String? = null): String {
        check(config != null) { "configure() the SDK before logging events" }
        val eventId = UUID.randomUUID().toString().lowercase()
        val at = now() / 1000
        // Validated here, on the caller's thread, so the mistake surfaces
        // where it can be fixed; the worker only moves occurred_at forward.
        val event = EventPayload.event(eventId, name, at, properties, revenue, transactionId)
        run { enqueue(event, at) }
        return eventId
    }

    /** Keep a signed customer id; it is sent once, and again whenever it changes. */
    fun setCustomerId(customer: CustomerId) {
        run {
            store.edit(mapOf(K_CUSTOMER to Json.write(customer.toWire())))
            withdrawPendingCustomer(keep = customer)
            pump()
        }
    }

    fun clearCustomerId() {
        run {
            store.edit(mapOf(K_CUSTOMER to null, K_CUSTOMER_SENT to null))
            withdrawPendingCustomer(keep = null)
        }
    }

    /**
     * Take a customer claim other than [keep] out of an install body the
     * server has not answered yet. Built once and resent unchanged, the body
     * would otherwise carry a signed-out customer's claim on every retry,
     * and the server links whatever claim the install it records carries.
     */
    private fun withdrawPendingCustomer(keep: CustomerId?) {
        if (store.get(K_STATE) == RECORDED) return
        val body = parseOrNull(store.get(K_BODY) ?: return)?.objOrNull ?: return
        val claim = body["customer"] ?: return
        val sent = CustomerId.fromWire(claim)
        if (keep != null && sent != null && customerKey(sent) == customerKey(keep)) return
        val changes = linkedMapOf<String, String?>(K_BODY to Json.write(JsonValue.Obj(body.fields.filterKeys { it != "customer" })))
        if (store.get(K_BODY_POSTED) != null) changes[K_BODY_CHANGED] = "1"
        store.edit(changes)
        logger.info("p202: the customer id changed before the install was answered; its earlier claim was taken out of the install")
    }

    /** Send what is due now rather than after the flush delay (the app going to the background). */
    fun flush() {
        run { pump(ignoreFlushDelay = true) }
    }

    /** What the server last said about the install: null until it is recorded. */
    val installMatch: String? get() = if (store.get(K_STATE) == RECORDED) store.get(K_MATCH) else null

    val installUuid: String? get() = store.get(K_UUID)

    val customerId: CustomerId? get() = store.get(K_CUSTOMER)?.let { CustomerId.fromWire(parseOrNull(it)) }

    /** Events waiting to be sent. */
    val queuedEvents: Int get() = queue().size

    /** The install's Play Integrity state as the server last answered it (`not_requested`, `pending`, …). */
    val installIntegrity: String? get() = store.get(K_INTEGRITY)

    // ---------------------------------------------------------------- worker

    private fun now() = clock.nowMillis()

    /** Run on the worker; a failure (a full disk, say) is logged and retried, never thrown at the app. */
    private fun run(task: () -> Unit) {
        scheduler.execute {
            try {
                task()
            } catch (e: Throwable) {
                logger.warn("p202: ${e.javaClass.simpleName}: ${e.message}; retrying in ${STORE_RETRY_MILLIS / 1000}s")
                wake(now() + STORE_RETRY_MILLIS)
            }
        }
    }

    private fun wake(at: Long) {
        if (at >= wakeAt) return
        cancelWake?.invoke()
        wakeAt = at
        cancelWake = scheduler.schedule(maxOf(0L, at - now())) {
            wakeAt = Long.MAX_VALUE
            cancelWake = null
            try {
                pump(ignoreFlushDelay = true)
            } catch (e: Throwable) {
                logger.warn("p202: ${e.javaClass.simpleName}: ${e.message}; retrying in ${STORE_RETRY_MILLIS / 1000}s")
                wake(now() + STORE_RETRY_MILLIS)
            }
        }
    }

    private fun pump(ignoreFlushDelay: Boolean = false) {
        val cfg = config ?: return
        when (store.get(K_STATE)) {
            RECORDED -> pumpEvents(cfg, ignoreFlushDelay)
            REFUSED -> if (store.get(K_REFUSED_TARGET) != cfg.target) {
                logger.info("p202: the endpoint or app token changed; sending the refused install again")
                store.edit(mapOf(K_STATE to PENDING, K_REFUSED_STATUS to null, K_REFUSED_TARGET to null, K_ATTEMPTS to null, K_NEXT_AT to null))
                pump(ignoreFlushDelay)
            }
            else -> {
                if (store.get(K_BODY) == null) {
                    readReferrer()
                    return
                }
                val next = store.get(K_NEXT_AT)?.toLongOrNull() ?: 0L
                if (now() < next) {
                    wake(next)
                    return
                }
                val plan = integrityPlan(cfg) ?: return
                sendInstall(cfg, plan)
            }
        }
    }

    private fun readReferrer() {
        if (referrerInFlight) return
        referrerInFlight = true
        val generation = ++referrerGeneration
        val answer: (ReferrerDetails) -> Unit = { details ->
            scheduler.execute {
                if (generation == referrerGeneration && referrerInFlight) {
                    referrerInFlight = false
                    try {
                        onReferrer(details)
                    } catch (e: Throwable) {
                        logger.warn("p202: ${e.javaClass.simpleName}: ${e.message}; retrying in ${STORE_RETRY_MILLIS / 1000}s")
                        wake(now() + STORE_RETRY_MILLIS)
                    }
                }
            }
        }
        // Play's client can fail to call back at all; a read that never
        // answers counts as Play being unavailable, and is read again.
        scheduler.schedule(REFERRER_TIMEOUT_MILLIS) {
            if (generation == referrerGeneration && referrerInFlight) {
                logger.warn("p202: the install referrer did not answer in ${REFERRER_TIMEOUT_MILLIS / 1000}s")
                answer(ReferrerDetails.unavailable(ReferrerStatus.SERVICE_UNAVAILABLE))
            }
        }
        try {
            referrerSource.read(answer)
        } catch (e: Exception) {
            logger.warn("p202: reading the install referrer failed: ${e.message}")
            answer(ReferrerDetails.unavailable(ReferrerStatus.SERVICE_UNAVAILABLE))
        }
    }

    private fun onReferrer(details: ReferrerDetails) {
        val cfg = config ?: return
        if (store.get(K_BODY) != null) return
        val reads = (store.get(K_REFERRER_READS)?.toIntOrNull() ?: 0) + 1
        if (details.status.isTransient && reads < MAX_REFERRER_READS) {
            store.edit(mapOf(K_REFERRER_READS to reads.toString()))
            val delay = if (reads == 1) 10_000L else 60_000L
            logger.info("p202: the install referrer answered ${details.status.wire}; reading it again in ${delay / 1000}s")
            scheduler.schedule(delay) {
                try {
                    pump()
                } catch (e: Throwable) {
                    logger.warn("p202: ${e.message}")
                    wake(now() + STORE_RETRY_MILLIS)
                }
            }
            return
        }
        val uuid = store.get(K_UUID) ?: UUID.randomUUID().toString().lowercase()
        val built = InstallPayload.build(
            installUuid = uuid,
            appKey = cfg.appKey,
            referrer = details,
            firstOpenAt = store.get(K_FIRST_OPEN)?.toLongOrNull(),
            appVersion = cfg.appVersion,
            sdkVersion = SDK_VERSION,
            osVersion = cfg.osVersion,
            test = cfg.test,
            customer = customerId,
        )
        for (field in built.dropped) logger.warn("p202: $field is not a value the server stores; the install reports it as null")
        store.edit(mapOf(K_UUID to uuid, K_BODY to Json.write(built.body), K_STATE to PENDING, K_REFERRER_READS to reads.toString()))
        pump()
    }

    /**
     * The schema document's Play Integrity instruction, or null when it
     * could not be read for a reason worth waiting out (the attempt is then
     * rescheduled). A document that cannot be read for good (a 4xx) or
     * says nothing about integrity means no token: the install's own
     * answer will say what is wrong with the token or the app.
     */
    private fun integrityPlan(cfg: AttributionConfig): IntegrityPlan? {
        integrityPlan?.let { (target, plan) -> if (target == cfg.target) return plan }
        val response = try {
            transport.get(cfg.schemaUrl, mapOf(HEADER to cfg.appToken))
        } catch (e: IOException) {
            logger.info("p202: the schema document could not be read (${e.message}); retrying")
            retryInstall(null)
            return null
        }
        if (Answers.isRetried(response.status) || response.status == 0) {
            retryInstall(retryAfterMillis(response))
            return null
        }
        val data = successData(response)
        if (response.status in 200..299 && (data == null || data["platform"]?.stringOrNull != "android")) {
            // A 2xx that is not the schema document (a captive portal, a
            // proxy's page) says nothing about Play Integrity: believed, it
            // would send a `require` install without its token for good.
            logger.info("p202: the schema document's answer was not the document; retrying")
            retryInstall(null)
            return null
        }
        val plan = readIntegrityPlan(data)
        integrityPlan = cfg.target to plan
        return plan
    }

    private fun readIntegrityPlan(data: JsonValue.Obj?): IntegrityPlan {
        val integrity = data?.get("integrity")?.objOrNull ?: return IntegrityPlan(false, null)
        if (integrity["request_token"]?.boolOrNull != true) return IntegrityPlan(false, null)
        val type = integrity["token_type"]?.stringOrNull
        val scheme = integrity["request_hash"]?.stringOrNull
        // A decimal string on the wire (it can exceed 2^53); Play takes a long.
        val project = when (val raw = integrity["cloud_project_number"]) {
            is JsonValue.Str -> raw.value.takeIf { Regex("^[1-9][0-9]{0,18}$").matches(it) }?.toLongOrNull()
            is JsonValue.Int -> raw.value
            else -> null
        }
        if (type != TOKEN_TYPE || scheme != REQUEST_HASH_SCHEME) {
            // A token bound some other way would be refused as another
            // install's (integrity_failed); none is only unvouched.
            logger.warn("p202: the server asks for a $type Play Integrity token bound as $scheme, which this SDK does not implement; no token is sent")
            return IntegrityPlan(false, null)
        }
        if (project == null || project <= 0) {
            logger.warn("p202: the server asks for a Play Integrity token but names no Cloud project number; no token is sent")
            return IntegrityPlan(false, null)
        }
        return IntegrityPlan(true, project)
    }

    private fun sendInstall(cfg: AttributionConfig, plan: IntegrityPlan) {
        val body = parseOrNull(store.get(K_BODY) ?: return)?.objOrNull
        if (body == null) {
            // Never guessed back into shape: the stored body is the install.
            logger.warn("p202: the stored install body is unreadable; the install cannot be reported")
            store.edit(mapOf(K_STATE to REFUSED, K_REFUSED_STATUS to "0", K_REFUSED_TARGET to cfg.target))
            listener.onInstallRefused(0, "the stored install body is unreadable")
            return
        }
        val uuid = body["install_uuid"]?.stringOrNull ?: ""
        val canonical = InstallPayload.canonical(body)
        var token: String? = null
        if (plan.request && integrity === IntegrityProvider.NONE) {
            if (!warnedNoProvider) {
                warnedNoProvider = true
                logger.warn("p202: this app's registration uses Play Integrity, but no IntegrityProvider was configured (add the integrity module's PlayIntegrityProvider); the install is sent without a token")
            }
        } else if (plan.request) {
            token = try {
                integrity.tokenFor(InstallAttempt(uuid, canonical, sha256Hex(canonical), plan.cloudProjectNumber!!))
            } catch (e: IntegrityUnavailableException) {
                val held = store.get(K_INTEGRITY_ATTEMPTS)?.toIntOrNull() ?: 0
                if (e.retryable && held + 1 < MAX_INTEGRITY_ATTEMPTS) {
                    logger.info("p202: Play Integrity is unavailable (${e.message}); the install waits and asks again")
                    store.edit(mapOf(K_INTEGRITY_ATTEMPTS to (held + 1).toString()))
                    retryInstall(null)
                    return
                }
                logger.warn("p202: Play Integrity gave no token (${e.message}); the install is sent without one")
                null
            } catch (e: Exception) {
                logger.warn("p202: the integrity provider failed (${e.message}); the install is sent without a token")
                null
            }
        }
        val wire = LinkedHashMap(body.fields)
        if (token != null) {
            if (token.isNotEmpty() && utf8Length(token) <= InstallPayload.MAX_INTEGRITY_TOKEN) {
                wire["integrity_token"] = token.json()
            } else {
                logger.warn("p202: the integrity provider returned an empty or oversized token; the install is sent without it")
            }
        }
        if (store.get(K_BODY_POSTED) == null) store.edit(mapOf(K_BODY_POSTED to "1"))
        val response = try {
            transport.post(cfg.installsUrl, mapOf(HEADER to cfg.appToken), Json.write(JsonValue.Obj(wire)))
        } catch (e: IOException) {
            logger.info("p202: the install could not be sent (${e.message}); retrying")
            retryInstall(null)
            return
        }
        val data = successData(response)?.takeIf { isInstallReceipt(it, uuid) }
        when {
            data != null -> {
                val match = data["match"]?.stringOrNull ?: ""
                val reason = data["reason"]?.stringOrNull ?: ""
                val changes = linkedMapOf<String, String?>(
                    K_STATE to RECORDED, K_MATCH to match, K_REASON to reason, K_ATTEMPTS to null, K_NEXT_AT to null,
                    K_INTEGRITY to data["integrity"]?.stringOrNull, K_INTEGRITY_ATTEMPTS to null, K_BODY_CHANGED to null,
                )
                val sentCustomer = CustomerId.fromWire(body["customer"])
                val linked = data["customer"]?.stringOrNull
                if (sentCustomer != null && linked != null) changes[K_CUSTOMER_SENT] = customerKey(sentCustomer)
                store.edit(changes)
                listener.onInstallRecorded(match, reason, data["duplicate"]?.boolOrNull == true)
                if (linked != null) customerAnswer(linked)
                pump()
            }
            retryable(response.status) -> retryInstall(retryAfterMillis(response))
            response.status == 409 && store.get(K_BODY_CHANGED) != null -> {
                // The body changed after it may have reached the server (a
                // customer claim withdrawn), and the server answers that this
                // install id holds other content: it recorded the first
                // version. The install is recorded; the first version is not
                // sent again, since it carries the withdrawn claim.
                val reason = "The install was recorded before its customer id changed; its classification was not returned again."
                logger.info("p202: $reason")
                store.edit(mapOf(
                    K_STATE to RECORDED, K_MATCH to null, K_REASON to reason, K_ATTEMPTS to null, K_NEXT_AT to null,
                    K_INTEGRITY_ATTEMPTS to null, K_BODY_CHANGED to null,
                ))
                listener.onInstallRecorded("", reason, true)
                pump()
            }
            response.status == 400 && body["customer"] != null && onlyCustomerErrors(response) -> {
                // A server that predates the customer field: the install goes
                // without it (nothing was stored, so a changed body is not a
                // conflict), and the id is offered on the events route.
                logger.warn("p202: the server does not accept a customer on the install; sending the install without it")
                store.edit(mapOf(K_BODY to Json.write(JsonValue.Obj(body.fields.filterKeys { it != "customer" }))))
                pump()
            }
            else -> {
                val message = errorMessage(response)
                logger.warn("p202: the server refused the install (${response.status}): $message")
                store.edit(mapOf(K_STATE to REFUSED, K_REFUSED_STATUS to response.status.toString(), K_REFUSED_TARGET to cfg.target, K_ATTEMPTS to null, K_NEXT_AT to null))
                listener.onInstallRefused(response.status, message)
            }
        }
    }

    private fun retryInstall(retryAfter: Long?) {
        val attempts = (store.get(K_ATTEMPTS)?.toIntOrNull() ?: 0) + 1
        val next = now() + (retryAfter ?: backoff(attempts))
        store.edit(mapOf(K_ATTEMPTS to attempts.toString(), K_NEXT_AT to next.toString()))
        wake(next)
    }

    private fun enqueue(event: JsonValue.Obj, at: Long) {
        if (store.get(K_EVENTS_STOPPED) != null) {
            listener.onEventsDropped(listOf(event["event_id"]?.stringOrNull ?: ""), "events are no longer accepted for this install (${store.get(K_EVENTS_STOPPED)})")
            return
        }
        val queue = queue()
        val id = event["event_id"]?.stringOrNull ?: ""
        if (queue.size >= MAX_QUEUED_EVENTS) {
            logger.warn("p202: $MAX_QUEUED_EVENTS events are waiting; event $id was dropped")
            listener.onEventsDropped(listOf(id), "the queue is full")
            return
        }
        val last = store.get(K_LAST_OCCURRED)?.toLongOrNull() ?: 0L
        val occurred = maxOf(at, last)
        val fields = LinkedHashMap(event.fields)
        fields["occurred_at"] = occurred.json()
        queue.add(JsonValue.Obj(fields))
        store.edit(mapOf(K_EVENTS to Json.write(JsonValue.Arr(queue)), K_LAST_OCCURRED to occurred.toString()))
        if (queue.size >= EventPayload.MAX_EVENTS) pump(ignoreFlushDelay = true) else wake(now() + FLUSH_DELAY_MILLIS)
    }

    private fun queue(): MutableList<JsonValue> {
        val raw = store.get(K_EVENTS) ?: return ArrayList()
        val arr = parseOrNull(raw)?.arrOrNull
        if (arr == null) {
            logger.warn("p202: the stored event queue is unreadable and was discarded")
            store.edit(mapOf(K_EVENTS to null))
            return ArrayList()
        }
        return ArrayList(arr.items)
    }

    private fun pumpEvents(cfg: AttributionConfig, ignoreFlushDelay: Boolean) {
        if (store.get(K_EVENTS_STOPPED) != null) return
        store.get(K_EVENTS_BLOCKED)?.let { blocked ->
            if (blocked == cfg.target) return
            store.edit(mapOf(K_EVENTS_BLOCKED to null))
        }
        val queue = queue()
        val customer = customerId
        val customerPending = customer != null && store.get(K_CUSTOMER_SENT) != customerKey(customer)
        if (queue.isEmpty() && !customerPending) return
        val next = store.get(K_EVENTS_NEXT_AT)?.toLongOrNull() ?: 0L
        if (now() < next) {
            wake(next)
            return
        }
        if (!ignoreFlushDelay && queue.isNotEmpty() && queue.size < EventPayload.MAX_EVENTS) {
            wake(now() + FLUSH_DELAY_MILLIS)
            return
        }
        val limit = minOf(EventPayload.MAX_EVENTS, store.get(K_BATCH_LIMIT)?.toIntOrNull() ?: EventPayload.MAX_EVENTS)
        val batch = ArrayList<JsonValue>()
        var bytes = 0
        for (e in queue) {
            if (batch.size >= limit) break
            val size = utf8Length(Json.write(e)) + 1
            if (batch.isNotEmpty() && bytes + size > EventPayload.MAX_BODY_BYTES - BODY_MARGIN) break
            batch.add(e)
            bytes += size
        }
        val body = LinkedHashMap<String, JsonValue>()
        if (batch.isNotEmpty()) body["events"] = JsonValue.Arr(batch)
        if (customerPending) body["customer"] = customer!!.toWire()
        val uuid = store.get(K_UUID) ?: return
        val ids = batch.map { (it as JsonValue.Obj)["event_id"]?.stringOrNull ?: "" }

        val response = try {
            transport.post(cfg.eventsUrl(uuid), mapOf(HEADER to cfg.appToken), Json.write(JsonValue.Obj(body)))
        } catch (e: IOException) {
            logger.info("p202: events could not be sent (${e.message}); retrying")
            retryEvents(null)
            return
        }
        val data = successData(response)?.takeIf { isEventsReceipt(it, uuid, ids) }
        when {
            data != null -> {
                val changes = linkedMapOf<String, String?>(K_EVENTS_ATTEMPTS to null, K_EVENTS_NEXT_AT to null)
                changes[K_EVENTS] = Json.write(JsonValue.Arr(queue().filter { eventId(it) !in ids }))
                val linked = data["customer"]?.stringOrNull
                if (customerPending && linked != null) changes[K_CUSTOMER_SENT] = customerKey(customer!!)
                store.edit(changes)
                if (ids.isNotEmpty()) listener.onEventsDelivered(ids)
                if (linked != null) customerAnswer(linked)
                pump(ignoreFlushDelay = true)
            }
            retryable(response.status) -> retryEvents(retryAfterMillis(response))
            response.status == 400 -> {
                // Every 400 must cost the queue something, or the same body
                // is sent again at once: the events it names, else the whole
                // batch; the customer when it is named, or when it rode a
                // request the server refused without naming anything else.
                val fields = fieldErrors(response)
                val named = fields.mapNotNull { Regex("^events\\[(\\d+)]").find(it)?.groupValues?.get(1)?.toIntOrNull() }
                    .mapNotNull { ids.getOrNull(it) }.toSet()
                val customerNamed = fields.any { it == "customer" || it.startsWith("customer.") }
                drop(if (named.isEmpty()) ids.toSet() else named, "refused by the server (400): ${errorMessage(response)}")
                if (customerPending && (customerNamed || named.isEmpty())) {
                    logger.warn("p202: the server refused the customer id (${errorMessage(response)}); it is cleared")
                    store.edit(mapOf(K_CUSTOMER to null, K_CUSTOMER_SENT to null))
                }
                pump(ignoreFlushDelay = true)
            }
            response.status == 404 -> {
                // The install is unknown to this token's app: wait for a
                // build with another endpoint or token rather than drop.
                logger.warn("p202: the server does not know this install (404): ${errorMessage(response)}; events wait for another endpoint or app token")
                store.edit(mapOf(K_EVENTS_BLOCKED to cfg.target))
            }
            response.status == 409 && parseOrNull(response.body)?.objOrNull?.get("match") != null -> {
                val match = parseOrNull(response.body)?.objOrNull?.get("match")?.stringOrNull
                stop("the install was classified $match")
            }
            response.status == 413 && batch.size > 1 -> {
                store.edit(mapOf(K_BATCH_LIMIT to maxOf(1, batch.size / 2).toString()))
                pump(ignoreFlushDelay = true)
            }
            response.status == 422 -> stop("the install holds the most events the server keeps")
            else -> {
                drop(ids.toSet(), "refused by the server (${response.status}): ${errorMessage(response)}")
                if (customerPending) {
                    // Not resent unchanged (the contract), and not lost from
                    // the app's view: kept, and counted as sent.
                    logger.warn("p202: the request carrying the customer id was refused (${response.status}); it is not sent again until it changes")
                    store.edit(mapOf(K_CUSTOMER_SENT to customerKey(customer!!)))
                }
                pump(ignoreFlushDelay = true)
            }
        }
    }

    private fun drop(ids: Set<String>, reason: String) {
        if (ids.isEmpty()) return
        store.edit(mapOf(K_EVENTS to Json.write(JsonValue.Arr(queue().filter { eventId(it) !in ids }))))
        logger.warn("p202: ${ids.size} event(s) dropped: $reason")
        listener.onEventsDropped(ids.toList(), reason)
    }

    private fun stop(reason: String) {
        val ids = queue().map { eventId(it) }
        store.edit(mapOf(K_EVENTS to null, K_EVENTS_STOPPED to reason))
        logger.warn("p202: events stopped: $reason")
        if (ids.isNotEmpty()) listener.onEventsDropped(ids, reason)
    }

    private fun retryEvents(retryAfter: Long?) {
        val attempts = (store.get(K_EVENTS_ATTEMPTS)?.toIntOrNull() ?: 0) + 1
        val next = now() + (retryAfter ?: backoff(attempts))
        store.edit(mapOf(K_EVENTS_ATTEMPTS to attempts.toString(), K_EVENTS_NEXT_AT to next.toString()))
        wake(next)
    }

    private fun customerAnswer(status: String) {
        if (status == "unverified") {
            logger.warn("p202: the server could not verify the customer id's signature; check that your server signs \"<type>:<id>\" with the account's linking key")
        }
        listener.onCustomerLinked(status)
    }

    // ------------------------------------------------------------- helpers

    private fun eventId(e: JsonValue): String = (e as? JsonValue.Obj)?.get("event_id")?.stringOrNull ?: ""

    private fun customerKey(c: CustomerId) = c.canonical + "|" + c.signature

    /** A 2xx whose body is `{"data": {…}}`; whether `data` is the route's receipt is checked by the route. */
    private fun successData(r: HttpResponse): JsonValue.Obj? {
        if (r.status !in 200..299) return null
        return parseOrNull(r.body)?.objOrNull?.get("data")?.objOrNull
    }

    /**
     * The install route's receipt: this install's id echoed, with the
     * classification. Anything else in a 2xx is not an answer about this
     * install and is retried — the body is idempotent on its id.
     */
    private fun isInstallReceipt(data: JsonValue.Obj, uuid: String): Boolean =
        uuid.isNotEmpty() && data["install_uuid"]?.stringOrNull == uuid &&
            !data["match"]?.stringOrNull.isNullOrEmpty() && data["duplicate"]?.boolOrNull != null

    /**
     * The events route's receipt: this install's id echoed, and `accepted`
     * and `duplicates` lists of ids that together name every event sent.
     * An answer that leaves one out is not a receipt for the batch, and the
     * batch is retried (the server is idempotent on event ids), never
     * dropped from the queue as delivered.
     */
    private fun isEventsReceipt(data: JsonValue.Obj, uuid: String, ids: List<String>): Boolean {
        if (data["install_uuid"]?.stringOrNull != uuid) return false
        val accepted = data["accepted"]?.arrOrNull?.items ?: return false
        val duplicates = data["duplicates"]?.arrOrNull?.items ?: return false
        val named = HashSet<String>()
        for (item in accepted + duplicates) named.add(item.stringOrNull ?: return false)
        return named.containsAll(ids)
    }

    /**
     * The contract's retried answers ([Answers.isRetried]), plus two the
     * contract does not name: a 2xx that is not its answer (a captive
     * portal's page) and a response with no status.
     */
    private fun retryable(status: Int) = Answers.isRetried(status) || status in 200..299 || status == 0

    private fun retryAfterMillis(r: HttpResponse): Long? {
        val v = r.headers["retry-after"]?.trim()?.toLongOrNull() ?: return null
        return (maxOf(1L, v) * 1000).coerceAtMost(MAX_BACKOFF_MILLIS)
    }

    internal fun backoff(attempt: Int): Long {
        val exp = BASE_BACKOFF_MILLIS * (1L shl minOf(attempt - 1, 20).coerceAtLeast(0))
        val capped = minOf(exp, MAX_BACKOFF_MILLIS)
        return (capped * (0.5 + 0.5 * random())).toLong()
    }

    private fun fieldErrors(r: HttpResponse): List<String> =
        parseOrNull(r.body)?.objOrNull?.get("field_errors")?.objOrNull?.fields?.keys?.toList() ?: emptyList()

    private fun onlyCustomerErrors(r: HttpResponse): Boolean {
        val fields = fieldErrors(r)
        return fields.isNotEmpty() && fields.all { it == "customer" || it.startsWith("customer.") }
    }

    private fun errorMessage(r: HttpResponse): String =
        parseOrNull(r.body)?.objOrNull?.get("message")?.stringOrNull ?: "HTTP ${r.status}"

    private fun parseOrNull(s: String): JsonValue? = try {
        Json.parse(s)
    } catch (e: JsonException) {
        null
    }
}

/**
 * What the two routes answer (tests/fixtures/app-sdk-contract/android/responses.json).
 */
object Answers {
    /** Only 429 and 5xx are retried; every other status is terminal for that request. */
    @JvmStatic
    fun isRetried(status: Int): Boolean = status == 429 || status >= 500

    /** The states an install is classified into, in the server's order. */
    @JvmField
    val MATCH_STATES: List<String> = listOf(
        "attributed", "organic", "third_party", "unavailable", "pending_click", "bad_token",
        "foreign_click", "implausible", "outside_window", "duplicate_click", "pending_integrity",
        "integrity_failed", "integrity_unverified",
    )

    /** Refuted installs: their events are refused (409), so the SDK stops sending them. */
    @JvmField
    val REFUTED: Set<String> = setOf("bad_token", "foreign_click", "implausible", "integrity_failed")

    /** Waiting installs: their events are answered 503 until the server settles them; the SDK keeps them. */
    @JvmField
    val PENDING: Set<String> = setOf("pending_click", "pending_integrity")

    /** The values of an install answer's `integrity`, in the server's order. */
    @JvmField
    val INTEGRITY_STATES: List<String> = listOf("not_requested", "received", "missing", "pending", "valid", "invalid", "error", "skipped")
}

package com.prosper202.attribution.core

/**
 * Events after the install: the body of
 * `POST /api/v3/apps/installs/{install_uuid}/events` (plan §5.5; the
 * server's `InstallEventsIntake::parseBody` and `GoalEvent::fromArray`).
 *
 * [event] builds one event from what the app passed to `logEvent` and
 * refuses it — [InvalidEventException], naming every bad field — when the
 * server would, so a mistake surfaces where it can be fixed rather than as
 * a 400 on a background thread days later. [validateBody] is the server's
 * whole-body validation, run against every case of
 * tests/fixtures/app-sdk-contract/android/events-requests.json.
 */
object EventPayload {
    const val MAX_EVENTS = 100
    const val MAX_BODY_BYTES = 65536
    const val MAX_PROPERTIES = 32
    const val MAX_STRING_BYTES = 255
    private val DEVICE_FIELDS = listOf("event_id", "name", "occurred_at", "properties", "revenue", "transaction_id")
    private val ALLOWED = listOf("event_id", "name", "occurred_at", "received_at", "properties", "revenue", "revenue_trusted", "transaction_id")
    private val EVENT_ID = Regex("^[\\x21-\\x3F\\x41-\\x7E][\\x21-\\x7E]{0,127}$")
    private val EVENT_NAME = Regex("^[A-Za-z0-9_][A-Za-z0-9_.:\\-]{0,63}$")
    private val PROP_NAME = Regex("^[A-Za-z_][A-Za-z0-9_]{0,63}$")

    /**
     * One event, as the wire carries it. [properties] values may be a
     * String, a Boolean, or any Kotlin/Java integer or floating-point
     * number (finite); anything else is refused by name.
     */
    @JvmStatic
    @Throws(InvalidEventException::class)
    fun event(
        eventId: String,
        name: String,
        occurredAt: Long,
        properties: Map<String, Any?>,
        revenue: Double?,
        transactionId: String?,
    ): JsonValue.Obj {
        val fields = LinkedHashMap<String, JsonValue>()
        fields["event_id"] = eventId.json()
        fields["name"] = name.json()
        fields["occurred_at"] = occurredAt.json()
        if (properties.isNotEmpty()) {
            val props = LinkedHashMap<String, JsonValue>()
            for ((k, v) in properties) props[k] = propertyValue(v)
            fields["properties"] = JsonValue.Obj(props)
        }
        if (revenue != null) fields["revenue"] = JsonValue.Num(revenue)
        if (transactionId != null) fields["transaction_id"] = transactionId.json()
        val obj = JsonValue.Obj(fields)
        // Checked as the server checks it: the whole body, one event long.
        val errors = validateBody(jsonObj("events" to JsonValue.Arr(listOf(obj))))
        if (errors.isNotEmpty()) {
            throw InvalidEventException(errors.mapKeys { (k, _) -> k.replaceFirst("events[0]", "event") }.toSortedMap())
        }
        return obj
    }

    private fun propertyValue(v: Any?): JsonValue = when (v) {
        is String -> JsonValue.Str(v)
        is Boolean -> JsonValue.Bool(v)
        is Int -> JsonValue.Int(v.toLong())
        is Long -> JsonValue.Int(v)
        is Short -> JsonValue.Int(v.toLong())
        is Byte -> JsonValue.Int(v.toLong())
        is Double -> JsonValue.Num(v)
        is Float -> JsonValue.Num(v.toDouble())
        // Anything else (null, a list, a map, a BigDecimal …) is not a
        // value the server stores: the validator names it.
        else -> JsonValue.Arr(emptyList())
    }

    /**
     * The server's validation of an events body, as field path → reason;
     * empty when it would accept it. A body may carry `customer` alone.
     */
    @JvmStatic
    fun validateBody(body: JsonValue): Map<String, String> {
        val o = phpObject(body) ?: return mapOf("body" to "must be a JSON object")
        val unknown = o.keys.filter { it != "events" && it != "customer" }
        if (unknown.isNotEmpty()) {
            // The server refuses unknown keys before reading anything else.
            return unknown.associateWith { "is not a field here" }.toSortedMap()
        }
        val e = sortedMapOf<String, String>()
        CustomerClaim.validate(o["customer"], "customer", e)
        val list = o["events"]
        val customer = o["customer"]
        if ((list == null || list is JsonValue.Null) && customer != null && customer !is JsonValue.Null) {
            return e
        }
        if (list !is JsonValue.Arr || list.items.isEmpty() || list.items.size > MAX_EVENTS) {
            e["events"] = "is required: a list of 1-$MAX_EVENTS events"
            return e
        }
        list.items.forEachIndexed { i, raw ->
            val path = "events[$i]"
            if (raw is JsonValue.Obj && raw.fields.isNotEmpty()) {
                val errs = sortedMapOf<String, String>()
                for (k in raw.fields.keys) if (k !in DEVICE_FIELDS) e["$path.$k"] = "is not an event field (the server sets received_at and revenue trust)"
                // The server stamps these two before reading the event.
                val stamped = LinkedHashMap(raw.fields)
                stamped["received_at"] = JsonValue.Int(0)
                stamped["revenue_trusted"] = JsonValue.Bool(false)
                validateEvent(JsonValue.Obj(stamped), path, errs)
                for ((k, v) in errs) if (k !in e) e[k] = v
            } else {
                validateEvent(raw, path, e)
            }
        }
        return e
    }

    /** `GoalEvent::fromArray`'s rules for one event at [path]. */
    private fun validateEvent(raw: JsonValue, path: String, e: MutableMap<String, String>) {
        val o = phpObject(raw)
        if (o == null) {
            e[path] = "must be a JSON object"
            return
        }
        for (k in o.keys) if (k !in ALLOWED) e.putIfAbsent("$path.$k", "is not an event field")
        val eventId = o["event_id"]
        if (eventId !is JsonValue.Str || !EVENT_ID.matches(eventId.value)) {
            e["$path.event_id"] = "must be 1-128 printable characters without spaces, not starting with \"@\""
        }
        val name = o["name"]
        if (name !is JsonValue.Str || !EVENT_NAME.matches(name.value)) {
            e["$path.name"] = "must be 1-64 of letters, digits, _ . : -, starting with a letter, digit or _"
        }
        for (t in listOf("occurred_at", "received_at")) {
            val v = o[t]
            if (v !is JsonValue.Int || v.value < 0 || v.value > InstallPayload.MAX_UNIX_SECONDS) e["$path.$t"] = "must be a unix time in seconds"
        }
        val props = o["properties"]
        if (props != null && props !is JsonValue.Null) {
            val p = phpObject(props)
            if (p == null) {
                e["$path.properties"] = "must be an object"
            } else if (p.size > MAX_PROPERTIES) {
                e["$path.properties"] = "may hold at most $MAX_PROPERTIES properties"
            } else {
                for ((k, v) in p) {
                    if (!PROP_NAME.matches(k)) {
                        e["$path.properties.$k"] = "is not a property name (a letter or _, then letters, digits or _, up to 64)"
                    } else if (!isPropertyValue(v)) {
                        e["$path.properties.$k"] = "must be a string (up to $MAX_STRING_BYTES bytes), a finite number or a bool"
                    }
                }
            }
        }
        val revenue = o["revenue"]
        if (revenue != null && revenue !is JsonValue.Null && !isNumber(revenue)) e["$path.revenue"] = "must be a finite number or null"
        val trusted = o["revenue_trusted"]
        if (trusted != null && trusted !is JsonValue.Null && trusted !is JsonValue.Bool) e["$path.revenue_trusted"] = "must be true or false"
        val tx = o["transaction_id"]
        if (tx != null && tx !is JsonValue.Null && !(tx is JsonValue.Str && phpTrim(tx.value).isNotEmpty() && utf8Length(tx.value) <= MAX_STRING_BYTES)) {
            e["$path.transaction_id"] = "must be a non-empty string of up to $MAX_STRING_BYTES bytes, or null"
        }
    }

    private fun isNumber(v: JsonValue) = v is JsonValue.Int || (v is JsonValue.Num && !v.value.isNaN() && !v.value.isInfinite())

    private fun isPropertyValue(v: JsonValue) = v is JsonValue.Bool || isNumber(v) || (v is JsonValue.Str && utf8Length(v.value) <= MAX_STRING_BYTES)
}

/** An event the server would refuse; [fieldErrors] names every bad field (paths start with `event`). */
class InvalidEventException(val fieldErrors: Map<String, String>) :
    IllegalArgumentException("The event is invalid: " + fieldErrors.entries.joinToString("; ") { "${it.key} ${it.value}" })

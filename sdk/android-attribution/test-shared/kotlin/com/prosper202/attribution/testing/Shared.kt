package com.prosper202.attribution.testing

import android.os.Looper
import com.prosper202.attribution.core.Json
import com.prosper202.attribution.core.JsonValue
import java.io.BufferedInputStream
import java.io.File
import java.io.IOException
import java.net.InetAddress
import java.net.ServerSocket
import java.net.Socket
import java.security.MessageDigest
import java.util.concurrent.LinkedBlockingQueue
import kotlin.test.fail
import org.robolectric.Shadows.shadowOf

/*
 * Test support shared by the :android and :integrity Robolectric suites
 * (robolectric.gradle adds this directory to both): an HTTP server on
 * 127.0.0.1 for the SDK's real HttpURLConnection transport, the main-looper
 * pump, and the contract vectors.
 */

/** tests/fixtures/app-sdk-contract, handed in by robolectric.gradle. */
internal fun contract(path: String): JsonValue.Obj {
    val dir = System.getProperty("p202.contractDir") ?: fail("p202.contractDir is not set (run through Gradle)")
    return Json.parse(File(dir, path).readText(Charsets.UTF_8)).objOrNull ?: fail("$path is not a JSON object")
}

internal fun sha256Hex(s: String): String =
    MessageDigest.getInstance("SHA-256").digest(s.toByteArray(Charsets.UTF_8)).joinToString("") { "%02x".format(it) }

internal fun hexBytes(hex: String): ByteArray = ByteArray(hex.length / 2) { hex.substring(it * 2, it * 2 + 2).toInt(16).toByte() }

/**
 * The instance, played by a small HTTP/1.1 server on an ephemeral port of
 * 127.0.0.1 (the unit-test classpath is android.jar's, which has no
 * com.sun.net.httpserver). Each route answers from its script, in order,
 * repeating the last answer; every request is recorded as it arrived.
 */
internal class FakeInstance : AutoCloseable {
    class Request(val method: String, val path: String, val headers: Map<String, String>, val body: String)
    /** [body] is sent as is; [bodyFor], when given, computes it from the request (to echo an install_uuid). */
    class Answer(val status: Int, val body: String, val headers: Map<String, String> = emptyMap(), val bodyFor: ((Request) -> String)? = null)

    private val socket = ServerSocket(0, 50, InetAddress.getByName("127.0.0.1"))
    private val scripts = HashMap<String, MutableList<Answer>>()
    val requests = LinkedBlockingQueue<Request>()
    val base: String get() = "http://127.0.0.1:${socket.localPort}"

    init {
        Thread({
            while (!socket.isClosed) {
                val conn = try {
                    socket.accept()
                } catch (e: IOException) {
                    break
                }
                try {
                    conn.use { serve(it) }
                } catch (e: IOException) {
                    // A client that went away; the next one is served.
                }
            }
        }, "fake-instance").apply { isDaemon = true }.start()
    }

    private fun serve(conn: Socket) {
        val input = BufferedInputStream(conn.getInputStream())
        val requestLine = readLine(input) ?: return
        val (method, target) = requestLine.split(' ').let { it[0] to it[1] }
        val headers = LinkedHashMap<String, String>()
        while (true) {
            val line = readLine(input) ?: return
            if (line.isEmpty()) break
            val colon = line.indexOf(':')
            if (colon > 0) headers[line.substring(0, colon).trim().lowercase()] = line.substring(colon + 1).trim()
        }
        val length = headers["content-length"]?.toInt() ?: 0
        val body = ByteArray(length)
        var read = 0
        while (read < length) {
            val n = input.read(body, read, length - read)
            if (n < 0) break
            read += n
        }
        val path = target.substringBefore('?')
        val request = Request(method, path, headers, String(body, 0, read, Charsets.UTF_8))
        requests.add(request)
        val answer = synchronized(scripts) {
            val script = scripts["$method $path"]
            when {
                script == null || script.isEmpty() -> Answer(404, """{"error":true,"message":"no route","status":404}""")
                script.size == 1 -> script[0]
                else -> script.removeAt(0)
            }
        }
        val out = (answer.bodyFor?.invoke(request) ?: answer.body).toByteArray(Charsets.UTF_8)
        val head = StringBuilder("HTTP/1.1 ${answer.status} X\r\n")
        head.append("Content-Type: application/json\r\nContent-Length: ${out.size}\r\nConnection: close\r\n")
        for ((k, v) in answer.headers) head.append("$k: $v\r\n")
        head.append("\r\n")
        conn.getOutputStream().apply {
            write(head.toString().toByteArray(Charsets.ISO_8859_1))
            write(out)
            flush()
        }
    }

    private fun readLine(input: BufferedInputStream): String? {
        val line = StringBuilder()
        while (true) {
            val c = input.read()
            if (c < 0) return if (line.isEmpty()) null else line.toString()
            if (c == '\n'.code) return line.toString().removeSuffix("\r")
            line.append(c.toChar())
        }
    }

    fun on(methodAndPath: String, vararg answers: Answer) {
        synchronized(scripts) { scripts[methodAndPath] = answers.toMutableList() }
    }

    /** The next request, waiting up to [seconds] while the main looper runs (Play's callbacks arrive on it). */
    fun next(seconds: Long = 20): Request {
        var found: Request? = null
        pumpUntil("a request to the fake instance", seconds) {
            found = requests.poll()
            found != null
        }
        return found!!
    }

    override fun close() {
        socket.close()
    }

    companion object {
        /** A schema document that asks for no Play Integrity token. */
        val SCHEMA_NO_INTEGRITY = Answer(200, """{"data":{"platform":"android","integrity":{"request_token":false}}}""")
    }
}

/**
 * Run the main looper until [done] holds. Play's client delivers
 * onServiceConnected on the main thread, and Robolectric's main looper
 * runs only when the test runs it; the SDK's own worker is a real thread.
 */
internal fun pumpUntil(what: String, seconds: Long = 20, done: () -> Boolean) {
    val until = System.currentTimeMillis() + seconds * 1000
    while (!done()) {
        if (System.currentTimeMillis() > until) fail("timed out after ${seconds}s waiting for $what")
        shadowOf(Looper.getMainLooper()).idle()
        Thread.sleep(10)
    }
}

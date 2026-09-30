#!/usr/bin/env python3
"""A local stand-in for the two store listings Setup › Mobile Apps reads.

The sandbox and CI cannot reach Apple or Google, so the page's store lookup
(tracking202/Apps/StoreListing.php) is pointed at this with
P202_APP_STORE_LOOKUP_ORIGIN=http://127.0.0.1:<port> in the web server's
environment (a loopback origin is the only one StoreListing accepts):

  GET /lookup?id=<App Store id>          the App Store lookup service's JSON
  GET /store/apps/details?id=<package>   a Play listing page with og: tags
  GET /icon.png, /icon.gif               the icons those two name
  GET /control/requests                  every request seen, in order

Known apps (anything else answers as the store does for an unknown one:
resultCount 0, or a 404 page):

  App Store 990077001            "Summit Run", icon /icon.png
  Play com.p202.live.summit      "Summit Quest - Apps on Google Play", icon /icon.gif
  Play com.p202.live.noicon      a listing whose og:image is on another host,
                                 which StoreListing must refuse to fetch

Usage: fake_store.py --port 8331. Prints "READY <port>" once listening.
"""
import argparse
import base64
import json
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

# A 1x1 PNG and a 1x1 GIF: real image bytes, so the magic-number check passes.
PNG = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="
)
GIF = base64.b64decode("R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7")

REQUESTS = []
LOCK = threading.Lock()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):  # quiet
        pass

    def send(self, status, body, content_type):
        data = body if isinstance(body, bytes) else body.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        url = urlparse(self.path)
        query = parse_qs(url.query)
        with LOCK:
            if url.path != "/control/requests":
                REQUESTS.append(self.path)
        origin = "http://%s:%d" % self.server.server_address[:2]
        if url.path == "/control/requests":
            with LOCK:
                self.send(200, json.dumps(REQUESTS), "application/json")
            return
        if url.path == "/lookup":
            app_id = (query.get("id") or [""])[0]
            if app_id == "990077001":
                body = {"resultCount": 1, "results": [{"trackName": "Summit Run", "artworkUrl100": origin + "/icon.png"}]}
            else:
                body = {"resultCount": 0, "results": []}
            self.send(200, json.dumps(body), "application/json")
            return
        if url.path == "/store/apps/details":
            package = (query.get("id") or [""])[0]
            listings = {
                "com.p202.live.summit": ("Summit Quest - Apps on Google Play", origin + "/icon.gif"),
                "com.p202.live.noicon": ("No Icon &amp; Co - Apps on Google Play", "https://evil.example/icon.png"),
            }
            if package not in listings:
                self.send(404, "<html><body>We're sorry, the requested URL was not found on this server.</body></html>", "text/html")
                return
            title, image = listings[package]
            page = (
                '<!doctype html><html><head><meta name="description" content="x">'
                '<meta property="og:title" content="%s">'
                '<meta content="%s" property="og:image">'
                "</head><body>listing</body></html>" % (title, image)
            )
            self.send(200, page, "text/html; charset=utf-8")
            return
        if url.path == "/icon.png":
            self.send(200, PNG, "image/png")
            return
        if url.path == "/icon.gif":
            self.send(200, GIF, "image/gif")
            return
        self.send(404, "not found", "text/plain")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, required=True)
    args = parser.parse_args()
    server = ThreadingHTTPServer(("127.0.0.1", args.port), Handler)
    print("READY %d" % args.port, flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    return 0


if __name__ == "__main__":
    sys.exit(main())

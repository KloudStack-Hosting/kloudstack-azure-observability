"""Stand-in for the Application Insights ingestion endpoint.

Exists so the benchmark can control the one variable that matters: how long Azure takes to
respond. Against the real endpoint that is unknowable and unrepeatable, and the failure the gate
is designed to catch -- the visitor waiting on a slow ingestion POST -- only appears when
ingestion is slow.

Two servers:
  :8443  HTTPS, the ingestion endpoint the plugin posts to. TLS because Config rejects any
         endpoint that is not https, which is a real security property and not one to weaken
         for a test.
  :8098  Plain HTTP control plane for the benchmark driver. Not published outside localhost.
"""

import gzip
import json
import ssl
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

STATE = {
    "delay_ms": 5,
    "status": 200,
    # Number of telemetry envelopes received, so the benchmark can assert the plugin actually
    # sent something. A scenario that measures a plugin which silently sent nothing would report
    # a beautiful zero overhead and mean nothing at all.
    "requests": 0,
    "items": 0,
}

LOCK = threading.Lock()


def _count_items(body: bytes, encoding: str) -> int:
    """Count telemetry items, tolerating gzip and newline-delimited JSON."""
    try:
        if "gzip" in encoding:
            body = gzip.decompress(body)
        text = body.decode("utf-8", "replace").strip()
        if not text:
            return 0
        if text.startswith("["):
            return len(json.loads(text))
        # The ingestion endpoint also accepts newline-delimited envelopes.
        return sum(1 for line in text.splitlines() if line.strip())
    except Exception:
        return 0


class Ingestion(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):
        pass

    def do_POST(self):
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length) if length else b""
        items = _count_items(body, self.headers.get("Content-Encoding", ""))

        with LOCK:
            STATE["requests"] += 1
            STATE["items"] += items
            delay = STATE["delay_ms"]
            status = STATE["status"]

        if delay:
            time.sleep(delay / 1000.0)

        payload = json.dumps(
            {"itemsReceived": items, "itemsAccepted": items if status == 200 else 0, "errors": []}
        ).encode()

        try:
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)
        except (BrokenPipeError, ConnectionResetError):
            # Expected in the stalled-ingestion scenario: the plugin's transport times out and
            # hangs up while this thread is still sleeping. Not an error worth reporting.
            pass


class Control(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):
        pass

    def do_GET(self):
        path, _, query = self.path.partition("?")
        params = dict(
            p.split("=", 1) for p in query.split("&") if "=" in p
        )

        with LOCK:
            if path == "/set":
                if "delay_ms" in params:
                    STATE["delay_ms"] = int(params["delay_ms"])
                if "status" in params:
                    STATE["status"] = int(params["status"])
            elif path == "/reset":
                STATE["requests"] = 0
                STATE["items"] = 0
            snapshot = dict(STATE)

        payload = json.dumps(snapshot).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)


def main():
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain("/certs/sink.crt", "/certs/sink.key")

    ingestion = ThreadingHTTPServer(("0.0.0.0", 8443), Ingestion)
    ingestion.socket = context.wrap_socket(ingestion.socket, server_side=True)

    control = ThreadingHTTPServer(("0.0.0.0", 8098), Control)

    threading.Thread(target=ingestion.serve_forever, daemon=True).start()
    print("sink: ingestion on :8443 (https), control on :8098", flush=True)
    control.serve_forever()


if __name__ == "__main__":
    main()

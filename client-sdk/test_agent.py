#!/usr/bin/env python3
"""
AgentToll WP - companion test agent (v0.1, mock settlement).

Simulates an AI crawler going through the full HTTP 402 flow:

  1. GET the post with a blocked AI User-Agent   -> expect 402 + X-Payment-* headers
  2. POST to the advertised payment endpoint      -> receive a signed Bearer token
  3. GET the post again with the Bearer token     -> expect 200 + unlocked content

Usage:
    pip install requests
    python test_agent.py http://localhost:8080/hello-ai/
    python test_agent.py http://localhost:8080/wp-json/wp/v2/posts/1 --ua "ClaudeBot/1.0"
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
from html.parser import HTMLParser
from urllib.parse import urljoin

try:
    import requests
except ImportError:  # pragma: no cover
    sys.exit("This script needs the 'requests' package:  pip install requests")


class _TextExtractor(HTMLParser):
    """Tiny HTML -> text converter (ignores script/style/nav chrome)."""

    SKIP = {"script", "style", "noscript", "svg", "head"}

    def __init__(self) -> None:
        super().__init__()
        self._skip_depth = 0
        self.parts: list[str] = []

    def handle_starttag(self, tag, attrs):
        if tag in self.SKIP:
            self._skip_depth += 1

    def handle_endtag(self, tag):
        if tag in self.SKIP and self._skip_depth:
            self._skip_depth -= 1

    def handle_data(self, data):
        if not self._skip_depth and data.strip():
            self.parts.append(data.strip())


def html_to_text(html: str) -> str:
    parser = _TextExtractor()
    parser.feed(html)
    return " ".join(parser.parts)


def extract_article(response: requests.Response, limit: int) -> str:
    """Return readable text from either a REST JSON payload or an HTML page."""
    ctype = response.headers.get("Content-Type", "")
    if "json" in ctype:
        data = response.json()
        items = data if isinstance(data, list) else [data]
        chunks = []
        for item in items[:3]:
            title = html_to_text(item.get("title", {}).get("rendered", ""))
            body = html_to_text(item.get("content", {}).get("rendered", ""))
            chunks.append(f"{title}\n{body}")
        text = "\n\n".join(chunks)
    else:
        match = re.search(r"<article\b.*?</article>", response.text, re.S | re.I)
        text = html_to_text(match.group(0) if match else response.text)
    return text[:limit] + (" ..." if len(text) > limit else "")


def ms(start: float) -> float:
    return (time.perf_counter() - start) * 1000


def main() -> int:
    ap = argparse.ArgumentParser(description="AgentToll WP test agent")
    ap.add_argument("url", help="URL of a WordPress post (or /wp-json/wp/v2/posts/<id>)")
    ap.add_argument("--ua", default="GPTBot/1.0", help="User-Agent to present (default: GPTBot/1.0)")
    ap.add_argument("--chars", type=int, default=800, help="Max characters of article to print")
    ap.add_argument("--insecure", action="store_true", help="Skip TLS verification (self-signed LocalWP certs)")
    ap.add_argument("--timeout", type=float, default=15.0)
    args = ap.parse_args()

    verify = not args.insecure
    headers = {"User-Agent": args.ua, "Accept": "text/html,application/json"}
    session = requests.Session()
    t_total = time.perf_counter()

    # ---- Step 1: unauthenticated request -> 402 challenge -------------------------
    print(f"\n[1/3] GET {args.url}\n      User-Agent: {args.ua}")
    t = time.perf_counter()
    r1 = session.get(args.url, headers=headers, verify=verify, timeout=args.timeout, allow_redirects=True)
    d1 = ms(t)
    print(f"      -> HTTP {r1.status_code}  ({d1:.1f} ms)")

    if r1.status_code != 402:
        print("      Expected 402 Payment Required. Is the plugin active and the UA on the blocklist?")
        print("      (If you use a page cache, exclude AI user-agents from caching.)")
        return 1

    for name in (
        "X-Payment-Required",
        "X-Price-Amount",
        "X-Price-Currency",
        "X-Payment-Protocol",
        "X-Payment-Endpoint",
        "X-Payment-Resource",
        "Server-Timing",
    ):
        print(f"      {name}: {r1.headers.get(name, '<missing>')}")

    endpoint = r1.headers.get("X-Payment-Endpoint")
    resource = r1.headers.get("X-Payment-Resource")
    if not endpoint or not resource:
        print("      Challenge is missing payment endpoint/resource headers.")
        return 1

    try:
        terms = r1.json()
        print(f"      Terms: {terms.get('license', {}).get('terms', '')}")
    except ValueError:
        pass

    # ---- Step 2: settle (mock) and fetch token -----------------------------------
    pay_url = urljoin(args.url, endpoint)
    payload = {"resource": resource, "payment_proof": "mock-settlement"}
    print(f"\n[2/3] POST {pay_url}\n      body: {json.dumps(payload)}")
    t = time.perf_counter()
    r2 = session.post(pay_url, json=payload, headers={"User-Agent": args.ua}, verify=verify, timeout=args.timeout)
    d2 = ms(t)
    print(f"      -> HTTP {r2.status_code}  ({d2:.1f} ms)")
    if r2.status_code != 200:
        print(f"      Payment failed: {r2.text[:300]}")
        return 1

    grant = r2.json()
    token = grant["token"]
    print(f"      amount: {grant['amount']} {grant['currency']}   expires_in: {grant['expires_in']}s")
    print(f"      token:  {token[:40]}...")

    # ---- Step 3: retry with Bearer token -----------------------------------------
    print(f"\n[3/3] GET {args.url}  (Authorization: Bearer <token>)")
    t = time.perf_counter()
    r3 = session.get(
        args.url,
        headers={**headers, "Authorization": f"Bearer {token}"},
        verify=verify,
        timeout=args.timeout,
        allow_redirects=True,
    )
    d3 = ms(t)
    print(f"      -> HTTP {r3.status_code}  ({d3:.1f} ms)   X-Payment-Status: {r3.headers.get('X-Payment-Status', '-')}")
    print(f"      Server-Timing: {r3.headers.get('Server-Timing', '-')}")

    if r3.status_code != 200:
        print(f"      Still locked: {r3.text[:300]}")
        return 1

    total = ms(t_total)
    print("\n" + "=" * 72)
    print("UNLOCKED CONTENT")
    print("=" * 72)
    print(extract_article(r3, args.chars))
    print("=" * 72)
    print(f"Challenge: {d1:.1f} ms | Settlement: {d2:.1f} ms | Unlock: {d3:.1f} ms | TOTAL roundtrip: {total:.1f} ms")
    print("Plugin-side overhead is reported by the Server-Timing header (target < 15 ms).")
    return 0


if __name__ == "__main__":
    sys.exit(main())

# AgentToll WP

**HTTP 402 micropayment middleware for WordPress.** Detects AI crawlers, blocks free scraping, and requires a payment token before serving post content, using open web standards (`HTTP 402 Payment Required` + `llms.txt`) instead of proprietary middlemen.

> **Status: v0.1 MVP / protocol proof-of-concept.** Settlement is a **mock**: the verify endpoint hands out signed tokens to anyone who asks. It proves the flow, headers, token format and latency budget. It does not collect money yet. See [Limitations](#limitations-read-before-deploying).

- License: GPL-2.0-or-later
- Requires: WordPress 6.0+, PHP 8.1+

## How it works

```
AI agent                          WordPress + AgentToll
   │  GET /post  (UA: GPTBot)              │
   │ ────────────────────────────────────► │  UA on blocklist, no token
   │ ◄──────────────────────────────────── │  402 + X-Payment-* headers + JSON terms
   │  POST /wp-json/agenttoll/v1/verify    │
   │ ────────────────────────────────────► │  (mock) settle, sign HMAC token
   │ ◄──────────────────────────────────── │  { token, expires_in }
   │  GET /post  Authorization: Bearer …   │
   │ ────────────────────────────────────► │  verify HMAC (no DB), log, serve post
   │ ◄──────────────────────────────────── │  200 + content
```

Humans and non-listed bots (Googlebot, Bingbot) exit the interceptor after a header read and a short string scan.

### 402 challenge

```
HTTP/1.1 402 Payment Required
X-Payment-Required: true
X-Price-Amount: 0.005
X-Price-Currency: USD
X-Payment-Protocol: x402-mock
X-Payment-Endpoint: /wp-json/agenttoll/v1/verify
X-Payment-Resource: post:123        <- extra: what to ask the endpoint to unlock
Server-Timing: agenttoll;dur=0.4    <- plugin overhead in ms
```

The JSON body carries licensing terms, price, and payment instructions.

### Token (stateless HMAC-SHA256)

`base64url(claims) . base64url(HMAC_SHA256(payload, secret))`. Claims: `v, iss, iat, exp, res, amt, cur, jti`.
Verification checks signature (constant-time), expiry, **resource binding** (a token for post 5 won't unlock post 6) and **amount ≥ current price**. No database or network call is involved.

### What is covered

| Surface | Hook |
|---|---|
| Front-end single posts | `template_redirect` (priority 1) |
| `GET /wp-json/wp/v2/posts` and `/posts/{id}` | `rest_pre_dispatch` |
| `/llms.txt` | Served virtually (only if no real file/page exists) |

A paid REST *collection* request costs one default price per request (token resource `rest:wp/v2/posts`).

## Repository layout

```
agenttoll-wp/
├── agenttoll-wp.php               # Plugin header, bootstrap, activation
├── uninstall.php                  # Removes option + log table
├── includes/
│   ├── class-settings.php         # Option access, pricing, exemptions
│   ├── class-interceptor.php      # Crawler detection + HTTP 402 handler + /llms.txt
│   ├── class-payment-verifier.php # HMAC token issue/verify + mock /verify endpoint
│   ├── class-logger.php           # Capped custom-table log (max 500 rows)
│   └── class-admin.php            # Settings > AgentToll WP (+ logs table)
├── client-sdk/
│   ├── test_agent.py              # 3-step AI agent simulator
│   └── requirements.txt
├── docker-compose.yml             # WordPress + MySQL + WP-CLI test stack
├── .gitignore
└── README.md
```

## Quick start: LocalWP

1. Create a site in LocalWP (PHP 8.1+).
2. Put this folder at `…/app/public/wp-content/plugins/agenttoll-wp/` (or symlink it).
3. WP Admin → **Plugins** → activate **AgentToll WP**.
4. **Settings → Permalinks** → choose **Post name** (so `/wp-json/...` and the `Authorization` header pass through cleanly).
5. Publish a post, then **Settings → AgentToll WP** to review the defaults.
6. Test:

```bash
curl -i -A "GPTBot/1.0" https://yoursite.local/hello-world/ -k      # -> 402 + headers
curl -i -A "Mozilla/5.0"  https://yoursite.local/hello-world/ -k    # -> 200 (human)

pip install -r client-sdk/requirements.txt
python client-sdk/test_agent.py https://yoursite.local/hello-world/ --insecure
```

## Quick start: Docker

```bash
docker compose up -d
# wait ~15s for first boot, then:
docker compose run --rm wpcli core install --url=http://localhost:8080 \
  --title="AgentToll Test" --admin_user=admin --admin_password=admin \
  --admin_email=admin@example.com --skip-email
docker compose run --rm wpcli rewrite structure '/%postname%/' --hard
docker compose run --rm wpcli plugin activate agenttoll-wp
docker compose run --rm wpcli post create --post_title="Hello AI" \
  --post_content="This is the paid article body." --post_status=publish

python client-sdk/test_agent.py http://localhost:8080/hello-ai/
```

Admin UI: <http://localhost:8080/wp-admin> (`admin` / `admin`) → Settings → AgentToll WP.

REST variant: `python client-sdk/test_agent.py http://localhost:8080/wp-json/wp/v2/posts/1 --ua ClaudeBot/1.0`

## Verifying the < 15 ms target

Every 402 and paid response includes `Server-Timing: agenttoll;dur=<ms>`, measured from the start of the interceptor. The DB log write is deferred to `shutdown` (after `fastcgi_finish_request()` on PHP-FPM), so it stays off the response path. Expect well under 1 ms for human requests and low single-digit ms for the 402 path on typical hosting. Measure on your own stack.

## Customisation

| Need | How |
|---|---|
| Per-post price | Custom field `_agenttoll_price` |
| Exempt a post | Custom field `_agenttoll_exempt` = `1`, or filter `agenttoll_is_exempt` |
| Protect more post types | Filter `agenttoll_protected_post_types` |
| Dynamic pricing | Filter `agenttoll_price` (`$price, $post_id`) |
| **Plug in a real payment rail** | Filter `agenttoll_verify_settlement` (`$settled, $request, $resource, $price`): validate the L402 / x402 / Interledger proof and return `true` only on success |

## Limitations (read before deploying)

1. **Mock settlement.** `/verify` issues tokens without payment. Real revenue requires implementing `agenttoll_verify_settlement`.
2. **User-Agent detection is advisory.** Any scraper can spoof its UA. Treat this as a policy signal for well-behaved crawlers (OpenAI, Anthropic, Perplexity etc. publish their UAs); add IP/reverse-DNS verification and WAF rules for hard enforcement.
3. **Bearer tokens are replayable within their TTL** for the same resource (stateless by design; no revocation list). Keep TTL short.
4. **Verify endpoint is public and unthrottled.** Add rate limiting at the edge before real use.
5. **`Google-Extended` is a robots.txt control token, not a User-Agent Google sends.** Google's AI-training crawling uses Googlebot UAs, so blocking it here won't catch anything. Use robots.txt for that opt-out.
6. **Page caches.** A cache can serve a cached 200 to a bot before WordPress runs. Exclude blocked AI user-agents from your page cache / CDN rules (paid responses set `DONOTCACHEPAGE` and `Cache-Control: private`).
7. **Authorization header on some hosts.** Apache + FastCGI can strip it. WordPress's default `.htaccess` rewrite rules forward it; if not, add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`.
8. Only the `post` type and the posts REST routes are protected in v0.1 (feeds, search, and other REST routes are not).

## Roadmap

- Real settlement adapters: L402 (Lightning), x402 (stablecoin), Interledger/Open Payments
- Crawler identity verification (IP ranges, reverse DNS, signed agents)
- Per-agent allowances / prepaid balances, usage CSV export
- Edge rate limiting for `/verify`; WP-CLI commands; blocks/UI for per-post pricing

## License

GPL-2.0-or-later. Add the full text as `LICENSE` before publishing (<https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt>).

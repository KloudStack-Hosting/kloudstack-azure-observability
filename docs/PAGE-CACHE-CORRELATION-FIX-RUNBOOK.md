# Runbook — page-cache correlation fix

Covers two repositories:

- `kloudstack-azure-observability` (this repo, public, WP.org)
- `kloudstack-wordpress-observability-marketplace` (private, Partner Portal)

Nothing in here has been executed. Read first; execute after sign-off.

---

## 1. The defect

`SnippetInjector` renders the server's W3C trace context into the page body:

```php
$context = wp_json_encode([
    'operationId' => $this->correlation->operationId(),
    'parentId'    => $this->correlation->parentId(),
    'role'        => $this->azure->role(),
], JSON_UNESCAPED_SLASHES);
```

A telemetry initializer then overwrites `ai.operation.id` and `ai.operation.parentId` on **every**
browser telemetry item with those values.

That JSON is part of the cacheable HTML. On any page-cached site the ID is frozen into the cache
entry and replayed to every visitor until the entry expires.

### Evidence

Three requests to `https://kloudstack.com.au/` with different user agents, seconds apart:

```
"operationId":"11b5c5b8ddac4f3d2363b0848dec4fd5","parentId":"bc3a49000f4ed2fb"
"operationId":"11b5c5b8ddac4f3d2363b0848dec4fd5","parentId":"bc3a49000f4ed2fb"
"operationId":"11b5c5b8ddac4f3d2363b0848dec4fd5","parentId":"bc3a49000f4ed2fb"
```

Same value as a fetch ~30 minutes earlier. Confirmed in production telemetry — a Chrome 133
pageView in `kloudstackProd` carries `operation_Id = 11b5c5b8...`, `operation_ParentId =
bc3a4900...`, byte-identical to the cached HTML.

Site is W3TC **Disk: Enhanced**, which serves cache hits through nginx rules without invoking PHP.

### Two consequences

**Correlation is fabricated, not merely stale.** On a cache hit no PHP runs, so no server span
exists for that visit. Every cached pageview claims membership of one trace that ran once, when the
cache entry was built.

**Sampling is broken as a side effect.** Application Insights makes its sampling decision by hashing
`operation_Id`, deliberately, so related telemetry is kept or dropped together. With one shared ID,
33.3% ingestion sampling on `kloudstackProd` stops being per-pageview sampling and becomes a single
site-wide keep/drop decision per cache-entry lifetime (W3TC max object lifetime: 3600s). The
`itemCount` extrapolation is also invalid, because the sample is not random.

No Azure change is required for this. Once IDs are unique again, 33.3% sampling becomes statistically
valid and the existing cost saving is retained.

### Why the tests did not catch it

- `CorrelationTest::testTraceIdsAreUniquePerRequest` **passes**. It asserts the right property at the
  wrong boundary: IDs are unique per PHP request. The defect is that one request's output is replayed
  to many visitors.
- `SnippetInjector` has **no test at all** — referenced only in `tests/stubs/wordpress.php`.
- CI's "Rendered client script must parse as JavaScript" job proves the output *parses* (the guard
  added for the `})({ ({` syntax bug). Nothing asserts what it *contains*.

---

## 2. Rollback capture — do this first

```bash
# Plugin
cd kloudstack-azure-observability
git rev-parse HEAD > /tmp/rollback-plugin.txt
git tag -l | tail -5                      # current released version

# Workbook — capture live content before any redeploy
cd ../kloudstack-wordpress-observability-marketplace
git rev-parse HEAD > /tmp/rollback-workbook.txt

az resource show \
  --ids "/subscriptions/c44ffb10-91c7-49a8-99f6-ef21bb313f1d/resourceGroups/kloudstack/providers/Microsoft.Insights/workbooks/aa3da7f1-a3ff-4b57-8dd4-8831ad6640ca" \
  --query properties.serializedData -o tsv > /tmp/rollback-workbook-content.json
```

Confirm `/tmp/rollback-workbook-content.json` is non-empty before proceeding. Workbook resource names
are deterministic GUIDs derived from resource group + display name, so a redeploy updates in place —
which means rollback is a redeploy of the captured content, not a delete.

---

## 3. Plugin change

### 3.1 Primary — forward correlation, no cache detection

Remove the unconditional override in `SnippetInjector::loaderInvocation()`. Without it the SDK mints
its own unique operation id per page view, which is correct under any cache.

Correlation is then carried the other way, and this path already exists on both sides:

- The shipped SDK 3.4.3 emits `traceparent` and `Request-Id` (`distributedTracingMode` defaults to
  `AI_AND_W3C`).
- `Correlation::__construct()` already adopts inbound `traceparent` via `HTTP_TRACEPARENT`.

So every XHR/fetch — REST, admin-ajax, cart fragments, search — correlates browser → server. None of
those are page-cached, so PHP runs. This requires **zero** cache-plugin detection and behaves
identically under W3TC (any mode), WP Rocket, LiteSpeed, WP Super Cache, Redis page cache, Cloudflare
or Front Door.

`ai.cloud.role` should continue to be stamped unconditionally. It is a site-level constant, not
request state, and is safe to cache.

### 3.2 Secondary — backward stamping only when provably uncached

Emit `operationId`/`parentId` only where the response cannot be page-cached:

- `is_user_logged_in()`
- `defined('DONOTCACHEPAGE') && DONOTCACHEPAGE` (honoured by W3TC, WP Rocket, WP Super Cache,
  LiteSpeed, WP Fastest Cache, Comet Cache)
- non-GET requests
- admin and REST contexts

Otherwise omit. Default to omitting when a page-cache drop-in is present (`WP_CACHE`) and the request
is an anonymous GET — conservative is correct for the unknown-cache-plugin case.

kloudstack.com.au has W3TC "Don't cache pages for logged in users" checked, so admin and editor
traffic keeps full end-to-end correlation immediately.

### 3.3 Optional, unrelated, cheap

`$config['disablePageUnloadEvents'] = ['unload'];` in `sdkConfig()`.

Chromium now refuses `unload` listeners by Permissions Policy (Chrome rolling to all origins through
September 2026; Edge September–October 2026), logging a console violation on every visit. The SDK
falls back to `beforeunload`/`pagehide`, so nothing is lost. Verified against the failing call path:
`AISku.js` reads `coreConfig.disablePageUnloadEvents` and passes it as `excludeEvents` into
`addPageUnloadEventListener`. Include or defer — it does not interact with the correlation fix.

---

## 4. Tests

### 4.1 `SnippetInjectorTest` — new

The class currently has no coverage. Assert on the emitted context:

- omits `operationId`/`parentId` for an anonymous GET when a page cache drop-in is present
- includes them when logged in
- includes them when `DONOTCACHEPAGE` is defined
- includes them for non-GET
- always includes `role`
- `disableCookiesUsage` still honours the `cookieless` setting

### 4.2 Replay invariant — new, this is the one that generalises

Encode the property rather than enumerating cache plugins:

> Render the page, capture the output, replay it. No per-request value may differ.

Render twice with identical inputs; diff the cacheable output; any field that changes is a failure.
This needs no cache plugin installed, and it catches the whole family — it would equally have caught
this under Cloudflare, Front Door, a CDN, or a cache plugin that does not exist yet.

### 4.3 Not recommended as a CI gate

Real cache-plugin end-to-end tests. Slow, brittle, and largely redundant once 4.2 exists. Worth a
periodic smoke test on a dev stack, not a build gate.

---

## 5. Workbook change

`src/modules/workbook-content.json`, query 6 (of 9):

```kql
let srv = requests  | project operation_Id, Route = name, ['Server ms'] = duration;
let cli = pageViews | project operation_Id, Page = name, ['Browser ms'] = duration, timestamp;
cli | join kind=inner srv on operation_Id
```

**This tile is currently producing fabricated rows.** Every cached pageView shares one
`operation_Id`, so the inner join fans them all against the single server request holding that ID —
every row shows the same "Server ms" from an unrelated request. It looks plausible, which is why it
went unnoticed.

After the plugin fix it stops fabricating, but on a cached site it returns almost nothing. Proposed
replacement:

- **Primary tile** — browser and server timings as two aggregate series over time, no join. Honest,
  always populated, works on every site regardless of caching, and still answers "browser or server?"
- **Secondary tile** — true end-to-end on dynamic calls: join browser `dependencies` to server
  `requests` on `operation_Id`. This is forward correlation and should populate after the fix.

**The secondary tile is a hypothesis until measured.** Confirm in testing that browser dependency
items and the corresponding server requests actually share `operation_Id` before shipping the tile.
If they do not, ship the primary tile alone.

Review the other 8 queries in the same pass — only query 6 was inspected.

---

## 6. Test matrix

Both changes on **kloudstack.com.au** and **kloudstack.dev** before release.

kloudstack.com.au is W3TC Disk: Enhanced. W3TC config varies per site, so if kloudstack.dev differs
that is a better test, not a worse one — capture its Page Cache screen before starting and record the
mode here.

| # | Check | Method | Pass |
|---|---|---|---|
| 1 | Correlation absent from cached HTML | `curl` 3× different UAs | no `operationId` in any response |
| 2 | Browser trace ids unique | 3 real page loads, check `operation_Id` | 3 distinct values |
| 3 | Logged-in correlation preserved | load while logged in | `operationId` present, matches server span |
| 4 | Forward correlation works | trigger a REST/AJAX call | browser dependency and server request share `operation_Id` |
| 5 | Sampling behaves | pageViews over an hour | volume tracks traffic, no all-or-nothing blocks |
| 6 | Workbook primary tile | open in RG `kloudstack` | populated, no duplicated "Server ms" |
| 7 | Workbook secondary tile | same | populated or removed per §5 |
| 8 | No console regressions | Edge + Chrome + Firefox | no new errors |

### Verification queries

```kql
// distinct browser trace ids — must be > 1
pageViews | where timestamp > ago(1h) | summarize views=count() by operation_Id

// the frozen id must stop appearing on new pageviews
pageViews | where timestamp > ago(1h) | where operation_Id == "11b5c5b8ddac4f3d2363b0848dec4fd5"

// forward correlation — check 4
dependencies | where timestamp > ago(1h) | where client_Type == "Browser"
| join kind=inner (requests | where timestamp > ago(1h)) on operation_Id
| project operation_Id, browser=name, server=name1
```

Note `kloudstack-logs` retention is **30 days** (the workspace governs, not the component's 90), and
ingestion sampling is 33.3% — small-window counts will be noisy. Do not read exact totals.

---

## 7. Release path

### Plugin

1. Commit and push to `main`. CI gate runs: lint, PHPCS, PHPStan, PHPUnit, version consistency,
   translation template, rendered-JS-parses.
2. `workflow_dispatch` on `release.yml` to build a test package without tagging. Download artifact
   `PLUGIN-kloudstack-azure-observability-<version>`.
3. Upload manually to kloudstack.com.au and kloudstack.dev. Run §6.
4. On green, run the release workflow properly per `docs/WORDPRESS-ORG-DEPLOYMENT.md`.

No image-pin coordination needed — new test stacks pick up the latest plugin version on deploy.

### Workbook

1. Commit and push.
2. Deploy to RG `kloudstack` (sub `c44ffb10`). Deterministic GUID naming means this updates
   "WordPress on Azure - Observability" (bound to `kloudstackprod`) in place.
3. Verify tiles per §6.
4. Upload to Marketplace via Partner Portal.

---

## 8. Rollback

**Plugin, pre-release:** deactivate the test build, reinstall the current WP.org version. No release
has occurred, so nothing is public.

**Plugin, post-release:** `git revert`, bump version, re-run the release workflow. Do not rewrite
history — `v2.0.0` is referenced by the WordPress image, which checksum-verifies the release asset.

**Workbook:** redeploy `/tmp/rollback-workbook-content.json` to the same GUID. In-place update, no
delete.

---

## 9. Open items — not blocking

**Telemetry volume unexplained.** `kloudstackProd` shows single-digit pageViews over the 30 days that
exist, with browser telemetry always on. Ruled out: the daily cap (workspace ingests ~1–2 MB/day
against a 1 GB cap, no cap events in 30 days) and plugin-side sampling (100%). Ingestion sampling
accounts for two thirds, not the remainder. Compare GA4 sessions for `G-V9DRDK2NML` over the same
window to size whatever is left — low traffic, ad-blockers blocking Azure Monitor endpoints, or a
third defect.

**Ingestion sampling 33.3%.** Deliberate, for cost. Leave as-is; the correlation fix makes it valid.
Worth revisiting later purely on the numbers — at ~1.5 MB/day it saves cents per month while
discarding two thirds of the signal.

**GA4/UET cookie rejections on kloudstack.com.au.** Unrelated to this plugin — Site Kit's
`GT-TBNDLKMM` and GTM container `GTM-TTN9P69X`. Probably benign `cookie_domain: 'auto'` probe noise
against the `.com.au` public suffix. Confirm via DevTools → Storage → Cookies before changing
anything.

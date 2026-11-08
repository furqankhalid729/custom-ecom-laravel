# Google Merchant Center Sync — Design & Roadmap

Date: 2026-11-07
Project: custom-ecom-laravel (Bagisto 2.3.19, Laravel 11, PHP 8.2, MySQL)
Status: Draft, awaiting owner approval — **no implementation until approved**
Related: Store Policy Generator (`packages/Custom/StorePolicy`, spec `2026-11-06-store-policy-generator-design.md`)

---

## 0. Intent

The store is the single source of truth. Every product the owner creates, edits, re-prices,
restocks, sells out of, or deletes must reach Google Merchant Center (GMC) automatically and
quickly, without ever slowing down or breaking the admin's own save. The owner needs one admin
screen that shows what is synced, what is waiting, what failed and why — including Google's own
approval/disapproval verdicts — and lets them act (sync, retry, exclude, fix).

**Anti-goals** (failure even if it "works"):
- A Google outage, quota hit, or bad token making product saves slow or fail.
- Duplicate offers in GMC for one store product.
- Prices, stock or links in GMC that disagree with the storefront (GMC suspends accounts for this).
- Silent failures — a product that never reached Google with nothing on screen saying so.
- Tokens or secrets in logs, HTML, or JS.
- Edits to `packages/Webkul/*` (keep Bagisto upgradeable — same rule as the Store Policies package).

---

## 1. Existing application analysis (inspected, not assumed)

| Area | What exists | Consequence for this design |
|---|---|---|
| Framework | Bagisto 2.3.19 on Laravel 11; Concord modules; repositories; `x-admin::` Blade; Pest tests on the dev MySQL DB inside transactions | New package `packages/Custom/GoogleMerchant`, same conventions as `Custom/StorePolicy` |
| Product model | EAV: `products` (id, sku, type, parent_id, attribute_family_id), `product_attribute_values`, `product_flat` (denormalised per channel/locale) | Read via `ProductRepository` / `product_flat`; never write product tables |
| Product types in DB | 8 simple, 1 configurable, 1 grouped, 1 bundle (11 total) | Variant handling needed from day one (configurable) |
| Attributes present | `sku, name, url_key, description, short_description, price, special_price, special_price_from/to, weight, color (select), size (select), brand (select), product_number, status, visible_individually, manage_stock, meta_*` | GTIN, condition, gender, age group, material, pattern, Google category **do not exist** → must be added (§4) |
| Channels / locales / currencies | 1 channel, locale `en` only, currency **USD only** | Phase 1 targets US/en/USD. EU needs a currency decision (§14, Decision D2) |
| Inventory | 1 inventory source; `product_inventory_indices(product_id, channel_id, qty)` = salable qty, refreshed by `UpdateCreateInventoryIndex` job on order create/cancel, refund, shipment | Availability reads `product_inventory_indices`; hook the same events |
| Prices | `price`, `special_price` + dates; catalog rules indexed into `product_price_indices(min_price, regular_min_price, customer_group_id, channel_id)`; nightly `product:price-rule:index` at 00:01 **fires no events** | Sale price for Guest group from price index; nightly reconcile catches rule changes |
| Images | `product_images.path` on `public` disk → `Storage::url()` | Google must fetch them: needs public **HTTPS** domain (current `APP_URL=http://localhost`) |
| Events (admin) | `catalog.product.create.after`, `catalog.product.update.after` (also on mass status update), `catalog.product.delete.before/after` | Primary sync triggers |
| Events (stock) | `checkout.order.save.after`, `sales.order.cancel.after`, `sales.refund.save.after`, `sales.shipment.save.after`; invoice/shipment items also fire `catalog.product.update.after` | Stock-change triggers |
| Bulk import | DataTransfer importer fires only `data_transfer.imports.batch.import.after` / `linking.after` (per batch, not per product) | Listen to batch events; reconcile as backstop |
| Queue | `jobs`, `job_batches`, `failed_jobs` tables exist; **`QUEUE_CONNECTION=sync`** | Must switch to `database` + run a worker (§8) — otherwise "async" runs inside the admin request |
| Scheduler | Bagisto registers schedules via `callAfterResolving(Schedule::class)` | Same pattern for reconcile/status jobs; needs `schedule:run` cron |
| Mail | `MAIL_MAILER=log` | Email notifications work in code but go to the log until SMTP is configured |
| Admin hooks | Product edit page has `bagisto.admin.catalog.product.edit.*` render events; menu/ACL via config merge; Bouncer enforces ACL by route name | Sync-status panel on product page without editing Webkul views |
| Existing Google | `laravel/socialite` installed (used for customer social login) | Not reused for GMC OAuth — admin flow needs offline access + its own token store (§5) |
| Store Policies package | Holds business address, shipping methods/costs/times and return terms per region | Phase 4 pushes these to GMC account-level shipping & return policies |

---

## 2. Current product data → what Google needs

Product identity in GMC = `offerId` + `contentLanguage` + `feedLabel` inside one **API data source**.
Design rule: **`offerId` = Bagisto `sku`** (stable, unique, human-readable). SKU edits are treated
as "delete old offer + create new" (§7).

Per Bagisto type:

| Bagisto type | Sent to Google as | Notes |
|---|---|---|
| simple | 1 offer | — |
| configurable | 1 offer **per variant** (child product), `itemGroupId` = parent SKU; parent itself not sent | Variant inherits parent title/description/images/brand/category when its own is empty. **Variants in this store differ in price** (CP-001: 14 / 17 / 21 with sale 17), so each variant's `link` must open the product page **with that variant pre-selected** (e.g. `?variant=<sku>`). The stock storefront can't do this today → Phase 1 adds it via a small script injected through a shop `view_render_event` on the product page (no Webkul file edits; a theme view override only if the script approach proves unreliable). *(Builder-check ruling.)* |
| bundle | 1 offer, `isBundle = true`, price = price index min price | — |
| grouped | **Not sent**; its child simples are sent individually | Grouped products have no price of their own |
| virtual / downloadable / booking | **Excluded by default** (setting) | Digital goods/bookings usually ineligible for Shopping |

---

## 3. Required database changes (all new tables, prefix `gmc_`)

| Table | Purpose | Key columns |
|---|---|---|
| `gmc_accounts` | One connected Merchant Center account | `id`, `merchant_id`, `display_name`, `auth_type` (`oauth`/`service_account`), `google_email`, `access_token` *(encrypted)*, `refresh_token` *(encrypted)*, `token_expires_at`, `service_account_json` *(encrypted, nullable)*, `status` (`connected`/`needs_reauth`/`disconnected`), `last_error`, `connected_by`, timestamps |
| `gmc_targets` | One market = one API data source | `id`, `gmc_account_id`, `channel_id`, `locale`, `content_language`, `feed_label`, `countries` (json), `currency`, `data_source_name`, `enabled`, timestamps. Unique (`gmc_account_id`, `content_language`, `feed_label`) |
| `gmc_attribute_mappings` | Flexible field mapping | `id`, `google_field`, `source_type` (`attribute`/`category`/`static`/`none`), `source_value`, `gmc_target_id` (nullable = all targets) |
| `gmc_category_mappings` | Store category → Google category / product type | `category_id`, `google_product_category` (ID or path), `excluded` (bool) |
| `gmc_products` | Current sync state per product × target (the dedup/idempotency ledger) | `id`, `product_id` (nullable after delete), `gmc_target_id`, `offer_id`, `product_input_name`, `state` (`pending`/`synced`/`failed`/`invalid`/`excluded`/`deleting`/`deleted`), `payload_hash`, `last_synced_at`, `last_attempt_at`, `attempts`, `last_error`, `validation_errors` (json), `google_status` (`approved`/`disapproved`/`pending`/`partial`), `google_issues` (json), `google_checked_at`. **Unique (`gmc_target_id`, `offer_id`)**, index (`product_id`) |
| `gmc_sync_logs` | Append-only history of every API attempt | `id`, `gmc_product_id`, `product_id`, `gmc_target_id`, `operation` (`upsert`/`delete`/`status`), `status` (`success`/`error`/`skipped`), `http_status`, `response_excerpt` (redacted, ≤2 KB), `error_message`, `attempt`, `duration_ms`, `gmc_run_id` (nullable), `created_at`. Pruned after N days (setting, default 30) |
| `gmc_runs` | Bulk sync / reconcile / status-refresh runs (resumable) | `id`, `type`, `status` (`running`/`paused`/`completed`/`failed`), `total`, `processed`, `succeeded`, `failed`, `cursor` (last product id), `batch_id`, `started_by`, `started_at`, `finished_at` |

**Product-level settings** use new Bagisto attributes (so they appear on the product form, import/export, and mass-edit for free), added by a data migration to a new **"Google Shopping"** attribute group in every attribute family:

| New attribute code | Type | Purpose |
|---|---|---|
| `gmc_sync` | boolean (default yes) | Per-product include/exclude — **the single source of truth**. The dashboard's Exclude/Include actions write this attribute through `ProductRepository` and fire `catalog.product.update.after`, exactly like the admin mass-status action; no second override flag exists. This is the one product field the package writes. *(Builder-check ruling.)* |
| `gtin` | text | GTIN/UPC/EAN/ISBN (validated check digit) |
| `gmc_condition` | select: new/refurbished/used | Default "new" via mapping |
| `gmc_gender` | select: male/female/unisex | Apparel |
| `gmc_age_group` | select: adult/kids/toddler/infant/newborn | Apparel |
| `gmc_material` | text | — |
| `gmc_pattern` | text | — |
| `gmc_google_category` | text | Per-product override of category mapping |
| `gmc_identifier_exists` | boolean (default yes) | For custom/handmade goods without GTIN/MPN |
| `gmc_multipack` | text (int) | — |
| `gmc_custom_label_0..4` | text | Campaign segmentation |

`mpn` maps to the existing `product_number` attribute (mapping default; changeable).

**Global settings** use Bagisto's config system (Configure → Google Merchant Center), consistent with core:
enabled, auto-sync on save, sync delay (debounce seconds), excluded product types, exclude disabled/not-visible products, exclude out-of-stock (default no — send as OUT_OF_STOCK), reconcile interval, log retention days, notification email, notify on failure.

---

## 4. Product attribute mapping (defaults, all overridable in `gmc_attribute_mappings`)

| Google field (`productAttributes`) | Default source in this store | Rule / format |
|---|---|---|
| `offerId` (top-level) | `sku` | Max 50 chars; validated |
| `title` | `name` (target locale) | ≤150 chars; variant: parent name + " – " + variant option labels if child name empty |
| `description` | `description` → fallback `short_description` | HTML stripped, ≤5000 chars |
| `link` | `route('shop.product_or_category.index', url_key)` on channel hostname | Variant: parent URL + `?` variant selector if supported, else parent URL |
| `imageLink` | first `product_images` (variant → parent fallback) | Absolute HTTPS URL |
| `additionalImageLinks` | remaining images (≤10) | — |
| `availability` | `product_inventory_indices.qty` (channel) & `manage_stock` | `IN_STOCK` if qty>0 or manage_stock off; else `OUT_OF_STOCK` |
| `price` | `price` (bundle: price index regular_min_price) | `{amountMicros, currencyCode}` in target currency; micros = round(amount × 1,000,000) |
| `salePrice` | `special_price` if active, else Guest-group `product_price_indices.min_price` when < regular | Only when lower than `price` |
| `salePriceEffectiveDate` | `special_price_from` / `special_price_to` | Interval in channel timezone → RFC3339; omitted for catalog-rule prices (reconcile updates them) |
| `brand` | `brand` (select label) | Required for most categories |
| `gtins` | new `gtin` | Array; checksum-validated |
| `mpn` | `product_number` | — |
| `identifierExists` | new `gmc_identifier_exists` | false when no GTIN and no MPN by design |
| `condition` | new `gmc_condition` → static `NEW` | Enum |
| `googleProductCategory` | product override → category mapping → none | Google taxonomy ID |
| `productTypes` | store category path, e.g. "Men > Shirts" | Array |
| `color`, `size` | `color`, `size` select labels | Variant-level |
| `gender`, `ageGroup` | new attributes | Enums; required for apparel in some countries |
| `material`, `pattern` | new attributes | — |
| `itemGroupId` | parent SKU (variants only) | — |
| `shipping` | **not per product** — account-level shipping settings (Phase 4 syncs them from Store Policies) | Per-product override only if weight-based rules are needed later |
| `shippingWeight` | `weight` + store weight unit | Optional |
| `taxes` | not sent; US tax configured at account level in GMC; EU prices are VAT-inclusive | — |
| `multipack`, `isBundle` | new `gmc_multipack`; `isBundle` = type is bundle | — |
| `customLabel0..4` | new `gmc_custom_label_*` | — |

**Field names and enum spellings must be re-verified against the Merchant API v1 reference
at build time** (e.g. top-level `productAttributes` object name, `Price`/`Interval` sub-fields).

---

## 5. Google Merchant API integration approach

- **API**: Merchant API **v1** only (`https://merchantapi.googleapis.com/{subapi}/v1/...`).
  Content API for Shopping was sunset 18 Aug 2026 (intermittent 410s since 1 Sep 2026); Merchant
  API v1beta was shut down 28 Feb 2026. Neither is used.
- **Transport**: Laravel `Http` client (Guzzle is already installed) against the REST endpoints.
  *Decision: no `google/apiclient` or gRPC packages* — the needed surface is ~10 REST calls, and
  the official PHP libraries pull in gRPC/protobuf. Wrapped in one `MerchantApiClient` class with
  typed methods, so swapping to the official library later is local.
- **Sub-APIs used**:
  | Need | Endpoint |
  |---|---|
  | One-time developer registration | `accounts/v1 … developerRegistration:registerGcp` |
  | Create/find API data source per target | `datasources/v1 accounts.dataSources.create/list` (primaryProductDataSource with contentLanguage, feedLabel, countries) |
  | Upsert product | `products/v1 accounts.productInputs:insert?dataSource=…` (insert replaces same offerId/contentLanguage/feedLabel in that data source → idempotent) |
  | Partial update (price/stock only) | `accounts.productInputs.patch` with `updateMask` (Phase 2 optimisation) |
  | Delete | `DELETE accounts/{id}/productInputs/{base64url(contentLanguage~feedLabel~offerId)}?dataSource=…` |
  | Product status & issues | `products/v1 accounts.products.list/get` (`productStatus`: destination statuses + item-level issues) |
  | Account issues | `issueresolution`/`accounts` issues endpoints (verify exact sub-API at build) |
  | Quotas | `quota/v1 accounts.quotas.list` |
  | Shipping & returns (Phase 4) | `accounts/v1 shippingSettings`, `onlineReturnPolicies` |
- **Product name encoding**: always unpadded **base64url** of `contentLanguage~feedLabel~offerId` (Google recommends it for all IDs; required when SKU contains `/`, `%`, `~`).
- **Prerequisites the owner must do once (documented in the admin "Setup" step)**:
  1. Production GMC account with **verified & claimed website** (test accounts can't register).
  2. Google Cloud project, Merchant API enabled, OAuth client (Web) with redirect `https://<domain>/admin/google-merchant/oauth/callback`.
  3. The app calls `registerGcp` once (needs an **Admin** user on the GMC account). Calls from an unregistered project return 401.
  4. Public HTTPS domain (Google fetches `link` and `imageLink`).
- **Expiry**: API-submitted products expire if not refreshed (historically 30 days — verify at build). Reconcile re-submits every synced product at least every 25 days.

## 6. Authentication flow

Two modes, same token store; owner picks one in the Connect screen:

**A. OAuth 2.0 (brief's default)**
1. Admin clicks **Connect Google** → controller builds Google auth URL: scope `https://www.googleapis.com/auth/content`, `access_type=offline`, `prompt=consent`, `state` = random token bound to the admin session (CSRF).
2. Callback validates `state`, exchanges code at `https://oauth2.googleapis.com/token`, stores `access_token`, `refresh_token`, `expires_at` **encrypted** (`encrypted` cast, `APP_KEY`), plus the Google email (from `id_token`/userinfo, scope `openid email`).
3. Admin picks the Merchant Center account from `accounts.list` (accessible accounts); app runs developer registration if not yet done, then creates/locates the API data source for each enabled target.
4. `TokenManager::accessToken()` refreshes when <5 min to expiry (locked with `Cache::lock` so parallel jobs don't stampede). `invalid_grant` → account `needs_reauth`, all sync jobs pause (released back with delay), dashboard shows red banner + email.
5. **Disconnect** revokes the token at `https://oauth2.googleapis.com/revoke`, wipes tokens, keeps `gmc_products` history; GMC products stay live (option: "also delete my products from Google" → bulk delete run).

⚠ If the OAuth consent screen stays in **Testing** status, Google expires refresh tokens after 7 days. The owner must publish the app (scope `content` may require verification for external users) — or use mode B.

**B. Service account (recommended for a single own store)**
Owner creates a service account, adds its email as a user in Merchant Center, uploads the JSON key (stored encrypted). JWT-bearer token exchange, no refresh-token expiry, no consent screen. Same `TokenManager` interface.

**Multiple accounts**: schema supports many `gmc_accounts` and many targets per account; UI ships with one account (multi-account UI = Phase 4).

## 7. Automatic synchronization workflow

```
Admin save / order / refund / import
        │  (Bagisto event, inside request)
        ▼
GoogleMerchant listener  ── cheap: resolve affected product ids (variants↔parent, bundle/grouped parents)
        │                   upsert gmc_products row → state=pending  (DB only, ~ms)
        │                   dispatch SyncProductJob(product_id, target_id) delay=debounce (30s), unique per pair
        ▼  (queue worker, outside request)
SyncProductJob
  1. eligibility (global on, gmc_sync, type, status, visible, category not excluded) → else state=excluded
     (+ delete from Google if it was previously synced)
  2. build payload (Mapper) → validate (Validator) → invalid ⇒ state=invalid + messages, no API call
  3. payload_hash == stored hash and last_synced_at < 25 days ⇒ skip (log 'skipped')
  4. productInputs.insert → success ⇒ state=synced, hash, timestamps; log
     temporary error (429/5xx/timeout) ⇒ release with backoff; permanent (4xx) ⇒ state=failed + message
```

- **Create** = upsert. **Update** = same upsert (offerId is the key → no duplicates).
- **SKU change**: listener on `catalog.product.update.before` captures old SKU; if changed, queue `DeleteOfferJob(old offer)` then upsert new.
- **Delete**: `catalog.product.delete.before` captures offerIds of the product and its variants into `gmc_products` (state=`deleting`), because the product row is gone afterwards; `DeleteOfferJob` deletes in Google; 404 from Google counts as success.
- **Disable / exclude / out of eligibility**: delete from Google (keeps GMC clean); re-enable → upsert.
- **Inventory**: order create/cancel, refund, shipment events → sync affected products. Stock reaching 0 sends `OUT_OF_STOCK` (not delete) so the product keeps its history in Google.
- **Price**: product save → immediate; catalog-rule nightly reindex → nightly reconcile at 01:00 (after Bagisto's 00:01 rule index) picks up hash changes.
- **Imports**: on `data_transfer.imports.batch.*.after`, queue sync for the batch's product ids.
- **Failure isolation**: listener body wrapped in `try/catch(Throwable)` → logs and returns; it never throws into Bagisto's save. All API work is in jobs.

## 8. Background job architecture

| Job | Trigger | Behaviour |
|---|---|---|
| `SyncProductJob(productId, targetId)` | listeners, dashboard "Sync", bulk | `ShouldBeUnique` (uniqueId = pair) + debounce delay; `tries` 8, `backoff` [30, 120, 600, 1800, 3600…], `retryUntil` +24 h; middleware: `RateLimited('gmc-api')`, `WithoutOverlapping(pair)`, `PauseWhenDisconnected` |
| `DeleteOfferJob(gmcProductId)` | delete/exclude/SKU change | Same retry policy; idempotent (404 = done) |
| `BulkSyncRun` | "Sync all" | Creates `gmc_runs`; dispatches `Bus::batch` chunks of 100 `SyncProductJob`s ordered by product id; stores cursor → **resume** continues from cursor; progress = batch progress |
| `ReconcileRun` | schedule (daily 01:00, configurable) | Recompute payload hash for all eligible products; enqueue changed/stale (>25 d)/missing; list Google offers in each data source and delete orphans (offers with no store product) |
| `RefreshStatusesRun` | schedule (every 6 h) + "Refresh" button | `products.list` paged → update `google_status`/`google_issues`; account issues → cache |
| `NotifyDigest` | schedule daily + on threshold | Email summary (§G) |
| `PruneLogs` | daily | Delete `gmc_sync_logs` older than retention |

**Rate limiting**: `RateLimiter::for('gmc-api')` per account (start at 60 req/min, configurable); on 429 read `Retry-After` and release; quotas surfaced from `quotas.list` on the dashboard.

**Infrastructure requirement (not new software, but must run)**: the package's jobs always use their **own queue connection** (`GMC_QUEUE_CONNECTION`, default `database`, queue name `gmc`) via `onConnection()/onQueue()`, so the store's global `QUEUE_CONNECTION` stays as it is and Bagisto's own jobs (stock index, mail) keep their current behaviour. *(Builder-check ruling.)* Production must run `php artisan queue:work database --queue=gmc` under a process manager, and a cron/Task Scheduler entry for `php artisan schedule:run` every minute. The dashboard shows a warning when no worker heartbeat has been seen for 5 minutes. If the host cannot keep a worker alive (Decision D5), the fallback is a scheduled `queue:work database --queue=gmc --stop-when-empty --max-time=50` every minute (sync delay then ≥ 1 minute).

## 9. Admin dashboard UI

Menu: **Marketing → Google Merchant Center** (ACL keys `google_merchant.*`, one per route).

1. **Overview** — connection card (status, Google email, Merchant ID, data sources/targets, last successful sync, queue/worker health); counters: eligible, synced, pending, failed, invalid, excluded, Google approved/disapproved/pending; account-level issues from Google; quota usage.
2. **Products** — DataGrid (Bagisto DataGrid component): product, SKU/offerId, target, state, Google status, last sync, issues count; filters by state/status; row actions: Sync now, View log, Exclude/Include; mass actions: Sync selected, Retry, Exclude.
3. **Activity log** — DataGrid over `gmc_sync_logs` with filters; detail drawer with redacted response.
4. **Runs** — bulk/reconcile runs with progress bar, Pause/Resume, Start "Sync all".
5. **Settings** — link to Configure → Google Merchant Center (global settings); **Mapping** tab (Google field ↔ source per target); **Category mapping** tab (store category → Google category, exclude flag).
6. **Connection** — Connect (OAuth / service account), choose account, targets, Disconnect, Re-authorize, setup checklist (website verified, developer registration, HTTPS, queue worker).
7. **Product edit page panel** (via `bagisto.admin.catalog.product.edit.form.after`): sync state, Google status + top issues with fix hints, "Sync now" button.

## 10. Error handling & retries

| Class | Examples | Handling |
|---|---|---|
| Transient | 429, 500, 502, 503, 504, timeouts, connection errors | Release with exponential backoff + jitter; respect `Retry-After`; counts toward `attempts` |
| Auth | 401 with expired token | Refresh once and retry immediately; `invalid_grant`/revoked → `needs_reauth`, pause all, notify |
| Unregistered project | 401 UNAUTHENTICATED before registration | Surface setup step, don't retry |
| Permanent data | 400 INVALID_ARGUMENT | `failed` + Google message mapped to the field; no retry until product changes |
| Not found on delete | 404 | Success |
| Quota | 429 RESOURCE_EXHAUSTED (daily) | Pause queue for that account until reset hour; dashboard banner |

Idempotency: upsert by offerId; `ShouldBeUnique` + `payload_hash` prevent duplicate work; unique DB key (`gmc_target_id`, `offer_id`) prevents duplicate records. After `retryUntil`, job ends in `failed` state (visible, retryable); Laravel `failed_jobs` keeps the exception.

## 11. Security

- Tokens & service-account JSON: Eloquent `encrypted` casts; never serialised to views/JSON (`$hidden`); only `TokenManager` reads them.
- OAuth: `state` parameter tied to session (CSRF); redirect URI fixed from config; callback route behind admin auth.
- All admin routes inside Bagisto `admin` middleware; Bouncer ACL per route; POST/DELETE with CSRF tokens.
- Logs: request bodies not stored; responses truncated and scrubbed of `Authorization`, tokens, emails.
- Client id/secret in `.env` (`GMC_CLIENT_ID`, `GMC_CLIENT_SECRET`), read via package config; never in DB/UI output.
- No public webhooks in Phase 1–3 (polling only). If Google push notifications are adopted later, endpoint verifies Google-signed JWT.
- Disconnect revokes tokens at Google.

## 12. Testing strategy

All Pest, package `tests/`, Google mocked with `Http::fake()` (recorded JSON fixtures from the v1 reference) — **no real Google calls in CI**.

| Area | Tests |
|---|---|
| OAuth | auth URL params & state; callback rejects bad state; token exchange stored encrypted; refresh before expiry; `invalid_grant` → needs_reauth & jobs paused; disconnect revokes |
| Mapping | simple / configurable variant / bundle / grouped-excluded; sale price & dates; catalog-rule sale price; availability from index; micros conversion; base64url names; HTML stripping & length limits |
| Validation | missing image/brand/GTIN rules; bad GTIN checksum; identifierExists=false path |
| Sync | create → insert; update → single insert, no duplicate; unchanged → skipped; SKU change → delete old + insert new; delete → delete call, 404 ok; exclude → delete |
| Inventory/price | order placed → OUT_OF_STOCK when last unit sold; refund → back IN_STOCK; special price window |
| Resilience | 429 with Retry-After → released; 5xx backoff; permanent 400 → failed, no retry; Google down never fails product save (controller test with fake throwing) |
| Bulk | 250 products → batches of 100; resume from cursor after failure |
| Reconcile | changed hash requeued; orphan offer deleted; stale >25 d refreshed |
| Status | products.list parsing → statuses/issues |
| Admin | dashboard, DataGrids, actions, ACL |
| E2E (manual, documented checklist) | against a real GMC account with 2–3 test SKUs before enabling auto-sync for all |

## 13. Implementation phases

### Phase 1 — Core integration & automatic sync (priority: must; shippable on its own)
Develop: package skeleton & registration; tables `gmc_accounts`, `gmc_targets`, `gmc_products`, `gmc_sync_logs`, `gmc_runs`; config section; new product attributes + "Google Shopping" group (data migration); `MerchantApiClient`, `TokenManager` (auth mode(s) per D1), developer registration, data-source creation (one target: US / en / USD); `ProductMapper` with default mappings (hard defaults, no mapping UI yet); `ProductValidator` (required fields); listeners (create/update/delete/SKU change, order/refund/shipment/cancel, import batches); `SyncProductJob`, `DeleteOfferJob` with retries/rate limit; variant pre-selection on the product page (§2); dashboard Overview + Connection + Products grid (Sync now / Retry / Exclude) + product-page panel; worker health warning.
**Also in Phase 1, so Phase 1 alone never breaks an anti-goal** *(builder-check ruling)*: a minimal **"Sync all"** (batched, progress counter; pause/resume comes in Phase 2) so the existing products reach Google, and a minimal **daily refresh** at 01:00 (hash-drift resync incl. catalog-rule prices + re-submit anything older than 25 days). Phase 3 extends this into full reconciliation (orphan cleanup, configurable schedule).
Existing files touched: `composer.json`, `bootstrap/providers.php`, `config/concord.php`, `phpunit.xml`, `tests/Pest.php` (registration only); `.env.example` (`GMC_*` keys, `GMC_QUEUE_CONNECTION`). No Webkul files; global `QUEUE_CONNECTION` unchanged.

### Phase 2 — Bulk sync & product management (priority: high)
`BulkSyncRun` pause/resume from cursor; mapping UI (`gmc_attribute_mappings`) and category mapping UI (`gmc_category_mappings`); selective sync (excluded categories/types, eligibility rules); mass actions; activity-log grid; partial updates via `patch` for stock/price-only changes.

### Phase 3 — Diagnostics, reconciliation, notifications (priority: high)
`RefreshStatusesRun` (Google approval status + item issues with fix hints), account issues panel, quota panel; `ReconcileRun` (hash drift, stale refresh, orphan cleanup) on a configurable schedule; email failure alerts + daily digest; log pruning.

### Phase 4 — Multi-country & optimisation (priority: medium)
EU target(s) per Decision D2 (EUR pricing/feed labels, VAT-inclusive prices); additional languages per target; push **shipping settings and return policies from the Store Policies package** to GMC (`shippingSettings`, `onlineReturnPolicies`) so pages, structured data and GMC agree; multi-account UI; optional Google push notifications for status changes; performance tuning (batch reads, caching mapped data).

## 14. Technical challenges & solutions

| Challenge | Solution |
|---|---|
| `QUEUE_CONNECTION=sync` makes "async" run in the save request | Package jobs use their own `database` connection + `gmc` queue; global setting untouched; worker required; dashboard warning |
| Variants differ in price but storefront can't pre-select a variant from the URL | Variant pre-selection script via shop render event (Phase 1) |
| `APP_URL=http://localhost` — Google can't fetch links/images | Sync blocked (setup checklist) until a public HTTPS base URL is configured |
| Catalog-rule prices change nightly without events | Nightly reconcile after the 00:01 index |
| Imports don't fire per-product events | Listen to batch events + reconcile |
| Delete event: product row gone after delete | Capture offer ids in `delete.before` |
| SKU edits change the offer id | Capture old SKU in `update.before`; delete old offer |
| Configurable variants and parent fallbacks | Mapper resolves child → parent for missing values; `itemGroupId` = parent SKU |
| Refresh tokens expire after 7 days in OAuth "Testing" mode | Publish consent screen or use service-account mode (D1) |
| Developer registration & verified website prerequisites | Setup checklist step with live check; clear error text |
| Product expiry if not refreshed | Reconcile refreshes anything older than 25 days |
| Store has only USD but EU sells in EUR | Decision D2 |
| Missing GTIN/brand for many products | New attributes + validator + `identifierExists=false` path |
| Quota/rate limits during first bulk | Rate limiter, batches, quota panel, resumable runs |

## 15. Decisions for the owner (defaults shown; change before Phase 1)

- **D1 Authentication**: OAuth (as requested) **and** service account; recommended default for a single own store = service account (no 7-day token expiry, no consent-screen verification).
- **D2 EU pricing** (Phase 4): (a) add EUR as a real store currency with its own prices/exchange rate and checkout in EUR — most compliant; or (b) send USD and rely on Google currency conversion for EU countries — quicker, but EU shoppers see converted prices and checkout in USD.
- **D3 Variants**: send each configurable variant as its own offer grouped by `itemGroupId` (recommended) vs. parent only.
- **D4 Out of stock**: keep in Google as `OUT_OF_STOCK` (recommended) vs. remove.
- **D5 Hosting**: confirm production has a public HTTPS domain, a queue worker and cron — required for Phase 1 to work outside localhost.

# Google Merchant Center Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every product create/edit/re-price/restock/sell-out/delete in the Bagisto store reaches Google Merchant Center automatically through background jobs. Admins get one Marketing → Google Merchant Center section showing what is synced, pending or failed, plus Google's own approval verdicts, and can act on each product.

**Architecture:** Two new packages, following `packages/Custom/StorePolicy`:
- `packages/Custom/GoogleCore` holds pieces any Google integration can reuse: OAuth/service-account token exchange, an integration registry and an admin audit log. GA4, GTM, Ads and Search Console can be added later as sibling packages without touching Merchant code.
- `packages/Custom/GoogleMerchant` holds everything specific to Merchant Center: account and target tables, a REST client for the Merchant API v1, product mapping and validation, the sync ledger, listeners, jobs, scheduler and admin UI.

Bagisto events only mark rows in the ledger and dispatch debounced, unique jobs on a dedicated `gmc` queue. A scheduled `queue:work --stop-when-empty` runner means only a cron entry is needed, with no permanently running worker.

**Tech Stack:** PHP 8.2, Laravel 11, Bagisto 2.3.19 (Concord, `Webkul\Core\Eloquent\Repository`, `Webkul\DataGrid\DataGrid`, `x-admin::` Blade, system config), Laravel `Http` client (Guzzle), `openssl_sign` for service-account JWTs, Pest + `Http::fake()`, MySQL, `database` queue.

**Spec:** `docs/superpowers/specs/2026-11-07-google-merchant-center-sync-design.md` (referenced as §N below). The original brief also asks for things the spec only sketches: GoogleCore modularity (brief §9), store configuration and URL validation (brief §10), admin audit logs (brief §12), and bulk sync by category, status, date, full or incremental (brief §5). Tasks 2, 6, 17 and 13 cover these.

## Decisions assumed (owner confirms before Task 1; spec §15)

- **D1** Both auth modes ship. The Connect screen pre-selects **service account**.
- **D2** Phase 4 EUR target is blocked until the owner picks (a) a real EUR currency or (b) USD with conversion. Tasks 24–25 do not start without it.
- **D3** Each configurable variant is sent as its own offer, grouped by `itemGroupId` = parent SKU.
- **D4** Out-of-stock products stay in Google as `OUT_OF_STOCK`. Config can switch this to delete.
- **D5** No Supervisor or Redis is assumed. The default runner is `GMC_QUEUE_RUNNER=scheduler`, which only needs cron (Linux) or Task Scheduler (Windows) running `php artisan schedule:run` every minute. `daemon` is an opt-in for hosts with a process manager.

## Deliberate deviations from the spec (recorded so reviewers don't flag them)

1. `gmc_products` gets `root_product_id`, `error_kind` and `retry_cycles` columns. Jobs are keyed by *root* product (simple, bundle, configurable parent), while ledger rows are per offer (per variant). `root_product_id` lets a root's job find offers that disappeared, such as a removed variant, and delete them.
2. Bulk sync does **not** use `Bus::batch`. Unique jobs don't combine reliably with batches, so bulk uses `BulkSyncChunkJob` with its own counters on `gmc_runs` and an id cursor. This is resumable and gives the same progress numbers.
3. Concurrency between bulk and event-driven sync is controlled by a `Cache::lock` inside `SyncService`, not by `WithoutOverlapping` on the job class.
4. A **sync revision** (`gmc_products.revision` / `synced_revision`) is added on top of `ShouldBeUniqueUntilProcessing`. Every change bumps `revision`; a job records the revision it started from and re-dispatches if it moved. The latest state therefore always reaches Google, even if a dispatch is dropped or swallowed.
5. `identifierExists` is **never derived automatically** from missing GTIN/MPN. It is an explicit product setting (default Yes) that only the admin changes, and the validator enforces consistency (Task 7).
6. SKU changes are handled **inside one `syncRoot` call, delete-then-insert, under the root's lock**, not as a separate `DeleteOfferJob` racing the insert.

## Global Constraints

- Do not edit `packages/Webkul/*`. Extend only via config merge, events, `view_render_event`, container bindings and new attributes.
- No new Composer/NPM dependencies: no `google/apiclient`, gRPC or protobuf (§5).
- **Merchant API v1 only**, base `https://merchantapi.googleapis.com`. Never use Content API for Shopping or `v1beta` (§5).
- `offerId` = Bagisto `sku`, max 50 chars. Product input name = `accounts/{merchantId}/productInputs/{base64url_unpadded(contentLanguage~feedLabel~offerId)}`.
- **Identity safeguards:**
  - A SKU is **never truncated or rewritten**. A SKU longer than 50 chars, with leading/trailing whitespace, or with control characters makes the offer `invalid` ("Shorten the SKU…"), and no API call is made.
  - The ledger row keeps the `offer_id` and `product_input_name` that were actually sent. Every delete uses the **stored** `product_input_name` and never recomputes it from the current product.
  - Rows are never re-keyed. A SKU change creates a new row, and the old row ends as `deleted` history.
  - Unique (`gmc_target_id`, `offer_id`) plus the identity-claim rule in Task 8 guarantee that one Google identity belongs to at most one live store product.
- OAuth scope `https://www.googleapis.com/auth/content` plus `openid email`; `access_type=offline`, `prompt=consent`.
- Money: `amountMicros` = `(string) round(amount * 1_000_000)`, `currencyCode` = target currency.
- Every package job runs `->onConnection(config('google_merchant.queue.connection'))->onQueue(config('google_merchant.queue.name'))`, defaults `database` / `gmc`. The global `QUEUE_CONNECTION` is never changed.
- Listener bodies are wrapped in `try { … } catch (\Throwable $e) { report($e); }`. Nothing in this package may throw into a Bagisto save, order or import.
- Tokens and service-account JSON use Eloquent `encrypted` casts, are listed in `$hidden`, and are only read by `TokenManager`. Logged responses are truncated to 2 KB with `access_token`, `refresh_token`, `Authorization`, `private_key` and email addresses replaced by `[redacted]`.
- Translations: `google_core::app.*` and `google_merchant::app.*`. Admin views use `x-admin::layouts` and match StorePolicy styling.
- Route names `admin.google_merchant.*` under prefix `{admin_url}/google-merchant`. Menu key `marketing.google_merchant`. ACL keys `marketing.google_merchant.{overview,connection,products,logs,runs,mapping,diagnostics}`, each with action children.
- Tests: Pest on the dev MySQL DB with `DatabaseTransactions` (`AdminTestCase`, as StorePolicy does). All Google HTTP goes through `Http::fake()` with JSON fixtures in `packages/Custom/GoogleMerchant/tests/Fixtures/`. `Http::preventStrayRequests()` is set in the package's Pest `beforeEach`.
- Field names and enums (`productAttributes`, `availability` values such as `IN_STOCK`/`OUT_OF_STOCK`, `condition` values such as `NEW`) come from the fixtures recorded in Task 0. If Task 0 finds a difference, the fixture wins and this plan gets amended.

## Review Focus

1. **Several saves within seconds** (save, then stock edit, then mass-status). Expect exactly one queued job per root×target while one is pending. A save that lands *while the job is running* must still produce a follow-up job (`ShouldBeUniqueUntilProcessing`). Test in Task 9.
2. **SKU edited on a synced product, or a variant removed from a configurable.** The old offer is deleted from Google and the new one inserted, so Google never holds two offers. Test in Task 10.
3. **Token revoked or refresh fails (`invalid_grant`) while jobs are queued.** The account flips to `needs_reauth`, jobs release instead of failing, the dashboard shows a red banner, and reconnecting resumes everything still pending. Test in Task 5 and Task 9.
4. **`APP_URL`/channel hostname is `http://localhost` or not HTTPS.** No API call is made; products go `invalid` with "Store URL must be public HTTPS", and the setup checklist shows it. Test in Task 7.
5. **Google 429/5xx during a 1,000-product bulk run.** The chunk re-dispatches its remaining ids after `Retry-After`. Counters never double-count and the run finishes `completed`. Test in Task 13.
6. **A product is deleted and a new product reuses its SKU before the queued delete runs.** The new product claims the row, the stale delete becomes a no-op, and Google ends up holding the *new* product's offer. Test in Task 8.

---

## Phase 1 — Core integration & automatic sync (shippable alone)

### Task 0: Verify Merchant API v1 reference and record fixtures

**Files:**
- Create: `packages/Custom/GoogleMerchant/tests/Fixtures/{token.json, token-invalid-grant.json, accounts-list.json, datasources-list.json, datasource-create.json, productinput-insert.json, error-400-invalid-argument.json, error-401-unregistered.json, error-429.json, error-404.json, products-list-page1.json, products-list-page2.json, account-issues.json, quotas-list.json}`
- Create: `packages/Custom/GoogleMerchant/API-NOTES.md` (endpoint, method, request and response field names, with doc URL and date checked)

- [ ] **Step 1:** Read the official Merchant API v1 reference for each endpoint in §5's table. Record in `API-NOTES.md`: the exact path, HTTP verb, required query params (`dataSource`, `pageSize`, `pageToken`, `updateMask`), the request body field names (`offerId`, `contentLanguage`, `feedLabel`, `productAttributes.*`), the response shape of `products.list` (`productStatus.destinationStatuses`, `productStatus.itemLevelIssues`), the account-issues endpoint, and how long submitted products last before expiring.
- [ ] **Step 2:** Write each fixture from the reference's example bodies. Error fixtures use Google's `{"error":{"code","message","status","details":[{"@type":"…BadRequest","fieldViolations":[…]}]}}` shape.
- [ ] **Step 3:** If anything differs from Global Constraints or §4/§5, edit this plan before continuing.
- [ ] **Step 4: Commit:** `git commit -m "docs(gmc): record Merchant API v1 reference notes and fixtures"`

### Task 1: GoogleCore package — integration registry, token exchange, audit log

**Files:**
- Create: `packages/Custom/GoogleCore/src/Providers/{GoogleCoreServiceProvider,ModuleServiceProvider}.php`
- Create: `.../src/Contracts/{GoogleIntegration,AuditLog}.php`, `.../src/Models/{AuditLog,AuditLogProxy}.php`
- Create: `.../src/Database/Migrations/2026_11_08_000001_create_google_audit_logs_table.php`
- Create: `.../src/Integrations/IntegrationRegistry.php`
- Create: `.../src/OAuth/{GoogleOAuthClient,ServiceAccountTokenFetcher,TokenResponse,OAuthException,InvalidGrantException}.php`
- Create: `.../src/Audit/AuditLogger.php`, `.../src/Resources/lang/en/app.php`
- Modify: `composer.json` (autoload `Custom\\GoogleCore\\` and dev `Custom\\GoogleCore\\Tests\\`), `bootstrap/providers.php`, `config/concord.php`, `phpunit.xml` (testsuite), `tests/Pest.php` (`uses(AdminTestCase::class)->in('../packages/Custom/GoogleCore/tests')`)
- Test: `packages/Custom/GoogleCore/tests/Feature/{OAuthClientTest,ServiceAccountTest,AuditLoggerTest}.php`

**Interfaces:**
- Produces:
  - `interface GoogleIntegration { public function key(): string; public function label(): string; public function isConnected(): bool; public function adminRoute(): ?string; }`
  - `IntegrationRegistry` (singleton): `register(GoogleIntegration $i): void`, `all(): array<string,GoogleIntegration>`
  - `TokenResponse` (readonly): `accessToken`, `?refreshToken`, `CarbonImmutable expiresAt`, `?idToken`, `scope`
  - `GoogleOAuthClient`: `authUrl(string $clientId, string $redirectUri, array $scopes, string $state): string` (base `https://accounts.google.com/o/oauth2/v2/auth`, with `access_type=offline`, `prompt=consent`, `include_granted_scopes=true`, `response_type=code`); `exchangeCode(string $clientId, string $secret, string $redirectUri, string $code): TokenResponse`; `refresh(string $clientId, string $secret, string $refreshToken): TokenResponse` (throws `InvalidGrantException` on `error=invalid_grant`); `revoke(string $token): void` (POST `https://oauth2.googleapis.com/revoke`, ignores 400); `emailFromIdToken(string $idToken): ?string` (decodes the payload only; the token came straight from Google over TLS)
  - `ServiceAccountTokenFetcher::fetch(array $keyJson, array $scopes): TokenResponse`. Builds an RS256 JWT (`iss`=`client_email`, `scope`, `aud`=`https://oauth2.googleapis.com/token`, `iat`, `exp`=iat+3600), signs with `openssl_sign(..., OPENSSL_ALGO_SHA256)`, and posts `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer`.
  - Table `google_audit_logs`: `id`, `integration` (string 40, index), `action` (string 60), `admin_id` (unsigned int nullable, FK `admins` set null), `context` (json nullable), `ip` (string 45 nullable), `created_at`
  - `AuditLogger::record(string $integration, string $action, array $context = []): void`. Takes admin id from `auth()->guard('admin')->id()` and IP from the request; strips `token`, `secret` and `json` keys out of `$context`.

- [ ] **Step 1: Write failing tests.**
  - `it('builds an offline consent auth url')`: parse the query and assert `access_type=offline`, `prompt=consent`, `scope` contains `auth/content` and `openid email`, `state` echoed.
  - `it('exchanges a code')`: `Http::fake(['oauth2.googleapis.com/token' => fixture token.json])`, then assert `accessToken`, `refreshToken` and `expiresAt` ≈ now+3599s.
  - `it('throws InvalidGrantException on invalid_grant')`.
  - `it('signs a service account JWT')`: generate a throwaway RSA key in the test with `openssl_pkey_new`, fake the token endpoint, assert the posted `assertion` has 3 segments and its signature verifies with `openssl_verify` against the public key.
  - `it('records audit entries without secrets')`: `record('merchant','connect',['refresh_token'=>'x','merchant_id'=>'1'])`, then the row's context has `merchant_id` but not `refresh_token`.
- [ ] **Step 2:** Run `composer dump-autoload && php artisan migrate && vendor/bin/pest packages/Custom/GoogleCore`. Expected: FAIL (classes missing).
- [ ] **Step 3:** Implement the files above. Wire the provider the same way as `StorePolicyServiceProvider` (migrations, translations, Concord module).
- [ ] **Step 4:** Re-run. Expected: PASS (5 tests).
- [ ] **Step 5: Commit:** `git commit -m "feat(google-core): oauth/service-account token exchange, integration registry, audit log"`

### Task 2: GoogleMerchant skeleton — tables, models, config, menu, ACL, store settings

**Files:**
- Create: `packages/Custom/GoogleMerchant/src/Providers/{GoogleMerchantServiceProvider,ModuleServiceProvider}.php`
- Create: `.../src/Config/{google_merchant.php,menu.php,acl.php,system.php}`
- Create: `.../src/Database/Migrations/2026_11_08_1000{01..07}_create_gmc_{accounts,targets,attribute_mappings,category_mappings,products,sync_logs,runs}_table.php`
- Create: `.../src/Contracts/*`, `.../src/Models/{GmcAccount,GmcTarget,GmcAttributeMapping,GmcCategoryMapping,GmcProduct,GmcSyncLog,GmcRun}{,Proxy}.php`
- Create: `.../src/Repositories/{GmcAccountRepository,GmcTargetRepository,GmcProductRepository,GmcRunRepository}.php`
- Create: `.../src/Enums/{AccountStatus,SyncState,GoogleStatus,ErrorKind,RunType,RunStatus,Operation}.php`
- Create: `.../src/Support/StoreSettings.php`, `.../src/MerchantIntegration.php`, `.../src/Resources/lang/en/app.php`
- Modify: `composer.json`, `bootstrap/providers.php`, `config/concord.php`, `phpunit.xml`, `tests/Pest.php`, `.env.example`
- Test: `packages/Custom/GoogleMerchant/tests/Feature/SchemaTest.php`, `.../tests/Helpers.php`, `.../tests/Pest.php` hooks (`Http::preventStrayRequests()`)

**Interfaces:**
- Produces:
  - Tables exactly as in spec §3, plus:
    - `gmc_accounts`: add `developer_registered_at` (timestamp nullable)
    - `gmc_products`: add `root_product_id` (unsigned int nullable, index), `error_kind` (string 20 nullable), `retry_cycles` (unsigned tinyint default 0), `channel_id`
    - Foreign keys: `gmc_targets.gmc_account_id` cascade; `gmc_products.gmc_target_id` cascade; `gmc_products.product_id` → `products` **set null**; `gmc_sync_logs.gmc_product_id` set null; `gmc_category_mappings.category_id` → `categories` cascade, unique
    - Indexes: `gmc_products(gmc_target_id,state)`, `gmc_products(root_product_id,gmc_target_id)`, `gmc_sync_logs(created_at)`, `gmc_sync_logs(product_id)`
  - Enums (string-backed):
    - `SyncState`: `pending`, `synced`, `failed`, `invalid`, `excluded`, `deleting`, `deleted`
    - `AccountStatus`: `connected`, `needs_reauth`, `disconnected`
    - `GoogleStatus`: `approved`, `disapproved`, `pending`, `partial`
    - `ErrorKind`: `transient`, `auth`, `permanent`, `setup`, `quota`
    - `RunType`: `bulk`, `refresh`, `reconcile`, `statuses`, `delete_all`
    - `RunStatus`: `running`, `paused`, `completed`, `failed`
    - `Operation`: `upsert`, `delete`, `status`
  - `GmcAccount` casts: `access_token`, `refresh_token`, `service_account_json` → `encrypted`; `token_expires_at` → datetime. All three secrets go in `$hidden`.
  - `config('google_merchant')`: `client_id` (env `GMC_CLIENT_ID`), `client_secret` (`GMC_CLIENT_SECRET`), `redirect_uri` (`GMC_REDIRECT_URI`, default `route('admin.google_merchant.oauth.callback')`), `api_base` (`GMC_API_BASE`, default `https://merchantapi.googleapis.com`), `http_timeout` (`GMC_HTTP_TIMEOUT`, 20), `queue.connection` (`GMC_QUEUE_CONNECTION`, `database`), `queue.name` (`GMC_QUEUE`, `gmc`), `queue.runner` (`GMC_QUEUE_RUNNER`, `scheduler`), `resubmit_days` 25
  - Admin system config (Configure → Google Merchant Center). Keys `google_merchant.general.settings.{enabled (bool, false), auto_sync (bool, true), sync_delay (int, 30), excluded_types (multiselect virtual|downloadable|booking, all selected), exclude_disabled (bool, true), exclude_not_visible (bool, true), out_of_stock_action (select keep|delete, keep), rate_limit_per_minute (int, 60), log_retention_days (int, 30), notify_email (text), notify_on_failure (bool, true)}`
  - Store settings keys `google_merchant.general.store.{website_url, country (default US), language (en), currency (USD), return_policy_url, refund_policy_url, terms_url, privacy_url, shipping_note, tax_note}`
  - `StoreSettings`:
    - `websiteUrl(int $channelId): string` (config, else `https://{channel hostname}`, else `config('app.url')`)
    - `policyUrls(int $channelId): array{return,refund,terms,privacy}`. Config override first; otherwise the StorePolicy generated page URL (`url('page/'.$urlKey)`), found through `Custom\StorePolicy\Services\PolicyStatusService::forChannel()` when that class exists.
    - `isPublicHttps(string $url): bool`: scheme `https` and host not `localhost`, `*.test`, `*.local` or a private/loopback IP (`filter_var(FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)`)
  - `MerchantIntegration implements GoogleIntegration` (key `merchant_center`), registered in the provider's `boot()`
  - Menu: `marketing.google_merchant` → `admin.google_merchant.overview`. Children: `overview`, `products`, `logs`, `runs`, `diagnostics`, `mapping`, `connection`.
  - `.env.example` additions: `GMC_CLIENT_ID=`, `GMC_CLIENT_SECRET=`, `GMC_REDIRECT_URI=`, `GMC_QUEUE_CONNECTION=database`, `GMC_QUEUE=gmc`, `GMC_QUEUE_RUNNER=scheduler`
  - Test helpers in `tests/Helpers.php`:
    - `gmcAccount(array $overrides = []): GmcAccount` (connected, merchant_id `123456`, service-account mode, token valid 1 h)
    - `gmcTarget(GmcAccount $a, array $o = []): GmcTarget` (US/en/USD, feed_label `US`, `data_source_name` `accounts/123456/dataSources/999`)
    - `fixture(string $name): array`
    - `simpleProduct(array $attrs = []): Product` (uses `Webkul\Faker\Helpers\Product` and sets `gtin`, `brand` and an image)
    - `configurableProduct(int $variants = 3): Product` (via `ProductRepository::create` with `super_attributes` color/size, then sets variant prices 14/17/21)

- [ ] **Step 1: Write failing tests.**
  - `it('creates all gmc tables with the unique offer key')`: `Schema::hasTable` for all 7. Inserting two `gmc_products` rows with the same (`gmc_target_id`,`offer_id`) throws `UniqueConstraintViolationException`.
  - `it('encrypts tokens at rest')`: save an account with `refresh_token='abc'`. The raw `DB::table('gmc_accounts')->value('refresh_token')` is not `'abc'`, the model returns `'abc'`, and `toArray()` lacks the key.
  - `it('rejects non-public store urls')`: `isPublicHttps` returns false for `http://localhost`, `https://localhost`, `https://shop.test`, `https://192.168.1.4` and true for `https://example.com`.
  - `it('registers menu and acl')`: `config('menu.admin')` has key `marketing.google_merchant`; `config('acl')` has `marketing.google_merchant.products.sync`.
- [ ] **Step 2:** Run `composer dump-autoload && php artisan migrate && vendor/bin/pest packages/Custom/GoogleMerchant/tests/Feature/SchemaTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement. The provider merges `google_merchant.php`, `menu.admin`, `acl` and `core` (`system.php`). Its `boot()` loads migrations, views and translations, registers routes like StorePolicy, and registers with `IntegrationRegistry`.
- [ ] **Step 4:** Re-run. Expected: PASS.
- [ ] **Step 5: Commit:** `git commit -m "feat(gmc): package skeleton, schema, config, store settings"`

### Task 3: Product attributes for Google Shopping (data migration)

**Files:**
- Create: `packages/Custom/GoogleMerchant/src/Database/Migrations/2026_11_08_100010_add_google_shopping_attributes.php`
- Create: `.../src/Support/GoogleAttributes.php` (definitions)
- Test: `.../tests/Feature/AttributesMigrationTest.php`

**Interfaces:**
- Produces:
  - Attributes from §3's table, created via `AttributeRepository::create`. Codes: `gmc_sync` (boolean, default 1), `gtin` (text), `gmc_condition` (select new|refurbished|used), `gmc_gender` (select male|female|unisex), `gmc_age_group` (select adult|kids|toddler|infant|newborn), `gmc_material`, `gmc_pattern`, `gmc_google_category` (text), `gmc_identifier_exists` (boolean, default 1), `gmc_multipack` (text, validation numeric), `gmc_custom_label_0`…`gmc_custom_label_4`. All have `value_per_channel=0`, `value_per_locale=0`, `is_filterable=0`, `is_configurable=0`, `is_user_defined=1`.
  - Attribute group `Google Shopping` (code `google_shopping`, column 2, position after last) in **every** attribute family, with all attributes mapped into it.
  - `down()` deletes those attributes and groups.
  - Skips an attribute whose code already exists. `gtin` may be created by hand later, so it is only mapped into the group when it already exists.
- [ ] **Step 1: Write failing tests:**
  - `it('adds the google shopping group to every family')`: for each `attribute_families` row, a group with code `google_shopping` exists containing `gmc_sync` and `gtin`.
  - `it('is idempotent')`: running the migration's `up()` a second time does not throw and creates no duplicate attribute codes.
- [ ] **Step 2:** Run `php artisan migrate && vendor/bin/pest .../AttributesMigrationTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement with `GoogleAttributes::definitions(): array` as the single list (used later by the mapper defaults).
- [ ] **Step 4:** Re-run. Expected: PASS. Open the product edit page manually and check that the Google Shopping group renders.
- [ ] **Step 5: Commit:** `git commit -m "feat(gmc): google shopping product attributes"`

### Task 4: MerchantApiClient + error classification + throttle

**Files:**
- Create: `packages/Custom/GoogleMerchant/src/Api/{MerchantApiClient,ErrorClassifier,ApiThrottle,ResponseRedactor}.php`
- Create: `.../src/Api/Exceptions/{GmcApiException,TransientApiException,AuthException,PermanentApiException,NotFoundException,QuotaExhaustedException,UnregisteredProjectException}.php`
- Create: `.../src/Support/ProductInputName.php`
- Test: `.../tests/Feature/MerchantApiClientTest.php`, `.../tests/Unit/ProductInputNameTest.php`

**Interfaces:**
- Consumes: `TokenManager::accessToken(GmcAccount): string` (Task 5). The client calls it through the container, so tests bind a fake returning `'test-token'`.
- Produces:
  - `ProductInputName::encode(string $merchantId, string $contentLanguage, string $feedLabel, string $offerId): string` → `accounts/{id}/productInputs/` + `rtrim(strtr(base64_encode("$lang~$label~$offer"), '+/', '-_'), '=')`
  - `MerchantApiClient` (every method takes `GmcAccount $account` first; returns decoded arrays):
    - `listAccounts(): array` (accessible accounts)
    - `registerGcp(string $developerEmail): array`
    - `listDataSources(): array`, `createDataSource(array $body): array`
    - `insertProductInput(string $dataSource, array $productInput): array`
    - `patchProductInput(string $name, string $dataSource, array $productInput, array $updateMask): array`
    - `deleteProductInput(string $name, string $dataSource): void`
    - `listProducts(?string $pageToken = null, int $pageSize = 250): array{products: array, nextPageToken: ?string}`
    - `listAccountIssues(): array`, `listQuotas(): array`
    - A private `send(GmcAccount, string $method, string $path, array $query = [], ?array $body = null): array`. It calls `ApiThrottle::hit($account)` first, uses `Http::withToken()->timeout()->acceptJson()`, and on a 401 refreshes the token once (`TokenManager::forceRefresh`) and retries.
  - `ErrorClassifier::classify(\Illuminate\Http\Client\Response|\Throwable $r): GmcApiException`:
    - 429 with `RESOURCE_EXHAUSTED` and a quota message → `QuotaExhaustedException`; any other 429 → `TransientApiException`
    - 500/502/503/504 and `ConnectionException` → `TransientApiException`
    - 401 whose message mentions "not registered" → `UnregisteredProjectException`; other 401/403 → `AuthException`
    - 404 → `NotFoundException`
    - other 4xx → `PermanentApiException`
  - `TransientApiException::$retryAfter` (int seconds): from the `Retry-After` header, else 60
  - `PermanentApiException::$fieldViolations`: `array<string field, string message>`
  - `GmcApiException::$httpStatus`, `$excerpt` (redacted JSON ≤ 2048 chars, via `ResponseRedactor::redact(string): string`)
  - `ApiThrottle::hit(GmcAccount)`: `RateLimiter::attempt("gmc-api:{$id}", rate_limit_per_minute, …)`. When over the limit it throws `TransientApiException` with `retryAfter = RateLimiter::availableIn()`.

- [ ] **Step 1: Write failing tests:**
  - `ProductInputNameTest`: `encode('123','en','US','AB/1~x')` returns `accounts/123/productInputs/` + the base64url of `en~US~AB/1~x`, with no `=`, `+` or `/` in the last segment.
  - `it('inserts a product input with bearer token and dataSource query')`: `Http::assertSent` checks URL `…/products/v1/accounts/123456/productInputs:insert?dataSource=accounts%2F123456%2FdataSources%2F999`, header `Authorization: Bearer test-token`, and body `offerId`.
  - `it('classifies errors')`, a dataset: 429+`Retry-After: 120` → `TransientApiException` with retryAfter 120; 503 → transient; 400 fixture → `PermanentApiException` with fieldViolations `['productAttributes.gtins' => '…']`; 404 → `NotFoundException`; unregistered fixture → `UnregisteredProjectException`.
  - `it('refreshes once on 401 then succeeds')`: a sequence fake of 401 then 200; `forceRefresh` called once.
  - `it('redacts secrets in excerpts')`: an excerpt containing `"access_token":"ya29.x"` and `a@b.com` comes out without both.
  - `it('throttles per account')`: with `rate_limit_per_minute=2`, the third call throws `TransientApiException`.
- [ ] **Step 2:** Run `vendor/bin/pest packages/Custom/GoogleMerchant/tests --filter="MerchantApiClient|ProductInputName"`. Expected: FAIL.
- [ ] **Step 3:** Implement. Paths and verbs come from `API-NOTES.md` (Task 0).
- [ ] **Step 4:** Re-run. Expected: PASS.
- [ ] **Step 5: Commit:** `git commit -m "feat(gmc): merchant api v1 client with error classification and throttle"`

### Task 5: TokenManager, connection flow (OAuth + service account), account & data-source setup

**Files:**
- Create: `packages/Custom/GoogleMerchant/src/Auth/TokenManager.php`
- Create: `.../src/Services/{ConnectionService,DataSourceProvisioner,SetupChecklist}.php`
- Create: `.../src/Http/Controllers/Admin/ConnectionController.php`, `.../src/Http/Requests/ServiceAccountRequest.php`
- Create: `.../src/Routes/admin-routes.php` (connection routes), `.../src/Resources/views/admin/connection/index.blade.php`, `.../src/Resources/views/admin/partials/status-banner.blade.php`
- Test: `.../tests/Feature/{TokenManagerTest,ConnectionTest}.php`

**Interfaces:**
- Consumes: Task 1 `GoogleOAuthClient`, `ServiceAccountTokenFetcher`, `AuditLogger`; Task 4 client.
- Produces:
  - `TokenManager::accessToken(GmcAccount $a): string`. Returns the stored token if it expires more than 5 min from now. Otherwise it takes `Cache::lock("gmc:token:{$a->id}", 30)->block(10)`, re-reads the account, refreshes (OAuth `refresh()` or `ServiceAccountTokenFetcher::fetch()`), and saves `access_token`/`token_expires_at`. On `InvalidGrantException` it sets `status=needs_reauth`, `last_error`, and throws `AuthException`.
  - `TokenManager::forceRefresh(GmcAccount $a): string`
  - `ConnectionService`:
    - `startOAuth(): string`. Stores `session('gmc_oauth_state')` = `Str::random(40)` and returns the auth URL.
    - `completeOAuth(string $state, string $code): GmcAccount`. Compares the state with `hash_equals` (throws `OAuthException('state_mismatch')`), clears it from the session, upserts the single account row (status `connected`, `auth_type=oauth`, `google_email`) and audits `connect`.
    - `connectServiceAccount(array $keyJson): GmcAccount`. Validates `type=service_account` and that `client_email`/`private_key` exist, fetches a token to prove the key works, stores the JSON encrypted.
    - `selectMerchant(GmcAccount, string $merchantId): void`. Requires the id in `listAccounts()`; calls `registerGcp` if `developer_registered_at` is null; calls `DataSourceProvisioner::ensure()`; audits `select_merchant`.
    - `disconnect(GmcAccount, bool $deleteProducts = false): void`. Revokes OAuth tokens (ignoring failures), nulls the secrets, sets `status=disconnected`, starts a `delete_all` run if asked (Task 13), audits.
  - `DataSourceProvisioner::ensure(GmcTarget $t): string`. Lists data sources and reuses one whose `primaryProductDataSource` has matching contentLanguage, feedLabel and countries. Otherwise it creates one with displayName `"Bagisto {$t->feed_label}/{$t->content_language}"`. Saves and returns `data_source_name`. If no target exists, it creates the default target from `StoreSettings` (country, language, currency; `feed_label` = country).
  - `SetupChecklist::run(?GmcAccount): array<string, array{ok: bool, message: string}>`. Keys: `credentials_configured`, `connected`, `merchant_selected`, `developer_registered`, `data_source`, `public_https` (website URL plus a real image URL via `StoreSettings::isPublicHttps`), `policy_urls` (all four present and HTTPS), `queue_runner` (heartbeat < 10 min, Task 11), `scheduler` (cache key `gmc:schedule:last` < 5 min).
  - Routes (all under the `admin` middleware):
    - `GET connection` → `admin.google_merchant.connection.index`
    - `POST connection/oauth` → `.oauth.start`
    - `GET oauth/callback` → `.oauth.callback`
    - `POST connection/service-account` → `.service_account`
    - `POST connection/merchant` → `.merchant.select`
    - `DELETE connection` → `.disconnect`
- [ ] **Step 1: Write failing tests:**
  - `TokenManagerTest`: returns the stored token when the expiry is 1 h away (no HTTP sent); refreshes when the expiry is 2 min away (token endpoint called once, new expiry saved); `invalid_grant` → account `needs_reauth` and `AuthException`; the service-account path calls the jwt-bearer grant.
  - `ConnectionTest`:
    - `it('redirects to google with a session state')`: admin POSTs `oauth.start` → 302 to `accounts.google.com`, and session `gmc_oauth_state` equals the URL's state.
    - `it('rejects a callback with the wrong state')`: GET callback `?state=bad&code=x` → redirect with an error flash, no account created, no HTTP to the token endpoint.
    - `it('stores encrypted tokens on a valid callback')`
    - `it('selects merchant, registers once and provisions the data source')`: the fakes for accounts list, registerGcp and datasource-create are each hit exactly once; calling again sends neither registerGcp nor create.
    - `it('disconnects and revokes')`: the revoke endpoint is hit, tokens are null, status `disconnected`, and a `google_audit_logs` row has action `disconnect`.
    - `it('forbids admins without the connection permission')`: an admin role without `marketing.google_merchant.connection` gets 401/403 on POST oauth.start.
- [ ] **Step 2:** Run `vendor/bin/pest .../TokenManagerTest.php .../ConnectionTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement. The connection view shows: an auth-mode tab (service account first), the setup checklist, the account card (email, merchant id, status), the merchant picker, and Disconnect with a "delete my products from Google" checkbox. All forms post with `@csrf`.
- [ ] **Step 4:** Re-run. Expected: PASS.
- [ ] **Step 5: Commit:** `git commit -m "feat(gmc): oauth and service-account connection, token refresh, data source provisioning"`

### Task 6: ProductMapper (simple, configurable variants, bundle, grouped) with default mappings

**Files:**
- Create: `packages/Custom/GoogleMerchant/src/Mapping/{ProductDataResolver,ProductMapper,MappedOffer,MappingRegistry,CategoryResolver}.php`
- Create: `.../src/Support/{Micros,Text}.php`
- Test: `.../tests/Feature/ProductMapperTest.php`, `.../tests/Unit/MicrosTest.php`

**Interfaces:**
- Consumes: Task 3 attribute codes; `gmc_attribute_mappings` and `gmc_category_mappings` (empty in Phase 1, so defaults apply).
- Produces:
  - `MappedOffer` (readonly): `int $productId`, `int $rootProductId`, `string $offerId`, `?string $itemGroupId`, `array $productInput` (the full API body: `offerId`, `contentLanguage`, `feedLabel`, `productAttributes`), `string $hash` (`sha1(json_encode($productInput))` with keys sorted recursively)
  - `ProductMapper::map(Product $root, GmcTarget $target): MappedOffer[]`
    - simple → 1 offer
    - configurable → one offer per child variant (parent not sent)
    - bundle → 1 offer with `isBundle=true` and `price` from the `product_price_indices` `regular_min_price` (Guest group, channel)
    - grouped / virtual / downloadable / booking → `[]` (grouped children are separate roots)
  - Default field rules exactly as §4's table. Pinned values:
    - `title` ≤150 chars. An empty variant name becomes `"{$parentName} – {$optionLabels joined ' / '}"`.
    - `description` = `Text::plain(description ?: short_description, 5000)` (strip tags, decode entities, collapse whitespace)
    - `link` = `StoreSettings::websiteUrl()` + `/` + url_key. Variants get `?variant={urlencoded sku}`.
    - `imageLink` = first image as an absolute URL (variant images first, then the parent's); `additionalImageLinks` = up to 10 more
    - `availability`: `IN_STOCK` if `manage_stock` is off or the `product_inventory_indices.qty` for the channel is > 0, else `OUT_OF_STOCK`
    - `salePrice` only when < `price`: an active `special_price` (from/to window checked in the channel timezone), else the Guest `min_price` < regular. `salePriceEffectiveDate` is only set for special_price with dates, `{startTime, endTime}` RFC3339.
    - `brand` = brand option label → config `default_brand` → omitted
    - `gtins` = `[gtin]` when valid (Task 7), `mpn` = `product_number`
    - `identifierExists`: sent as `false` **only** when the admin set `gmc_identifier_exists` = No. Otherwise the field is omitted (Google's default is true). It is never inferred from missing GTIN/MPN/brand.
    - `condition` = `strtoupper(gmc_condition ?: 'new')`
    - `googleProductCategory` = the product's `gmc_google_category` → the first mapped category of its categories → omitted
    - `productTypes` = category paths `"A > B"`
    - `color`/`size` (variants: the child's option labels), `gender`, `ageGroup` (uppercase enums per fixture), `material`, `pattern`
    - `itemGroupId` = parent sku for variants
    - `shippingWeight` = `{value: weight, unit: config weight unit}` when weight > 0
    - `multipack`, `customLabel0..4`
  - Each field is read through `MappingRegistry::sourceFor(string $googleField, GmcTarget $t): array{type: 'attribute'|'category'|'static'|'none', value: ?string}`. Defaults are hard-coded in `MappingRegistry::DEFAULTS`; Phase 2 adds DB overrides.
  - `Micros::fromAmount(float|string $amount): string`
- [ ] **Step 1: Write failing tests:**
  - `MicrosTest`: `fromAmount(14) === '14000000'`, `fromAmount('19.99') === '19990000'`, `fromAmount(0.1) === '100000'`.
  - `it('maps a simple product')`: assert `offerId`=sku, `contentLanguage`=`en`, `feedLabel`=`US`, `price.amountMicros`, `currencyCode`=`USD`, `availability`=`IN_STOCK`, `condition`=`NEW`, absolute `imageLink` starting `https://`, and description has no `<`.
  - `it('maps a configurable product to one offer per variant')`: with `configurableProduct(3)` → 3 offers, all `itemGroupId` = parent sku, prices 14/17/21, each `link` ending `?variant={sku}`, variant-specific `color`/`size`.
  - `it('falls back to parent fields for variants')`: a variant with no name or image gets the parent's image and title "Parent – Red / M".
  - `it('emits sale price only when lower')`: special_price 17 < price 21 → `salePrice` 17000000 with an effective date; special_price ≥ price → no `salePrice`.
  - `it('marks out of stock when salable qty is 0')`: update `product_inventory_indices.qty=0` → `OUT_OF_STOCK`; `manage_stock=0` → `IN_STOCK`.
  - `it('sends bundle as one offer with isBundle')`, `it('skips grouped products')`.
  - `it('hash is stable and changes with price')`: mapping twice gives the same hash; changing the price changes it.
- [ ] **Step 2:** Run `vendor/bin/pest --filter="ProductMapper|Micros"`. Expected: FAIL.
- [ ] **Step 3:** Implement. `ProductDataResolver` does every DB read (product + flat for the channel/locale, images, inventory index, price index, categories) with eager loading. `ProductMapper` is pure transformation over the resolver's output.
- [ ] **Step 4:** Re-run. Expected: PASS.
- [ ] **Step 5: Commit:** `git commit -m "feat(gmc): product mapper for simple, variants, bundle"`

### Task 7: Validator and eligibility

**Files:**
- Create: `packages/Custom/GoogleMerchant/src/Validation/{OfferValidator,Gtin}.php`, `.../src/Sync/Eligibility.php`
- Test: `.../tests/Unit/GtinTest.php`, `.../tests/Feature/{OfferValidatorTest,EligibilityTest}.php`

**Interfaces:**
- Produces:
  - `Gtin::isValid(string $gtin): bool`: digits only, length 8/12/13/14, GS1 mod-10 check digit, and not all zeros
  - `OfferValidator::validate(MappedOffer $o, GmcTarget $t): array<string googleField, string message>`. Rules:
    - `offerId` present and ≤50
    - `title` present
    - `link` and `imageLink` present and `StoreSettings::isPublicHttps` (message `google_merchant::app.validation.public-https`)
    - `price` > 0
    - `availability` present
    - identifiers, when Identifier exists = **Yes** (default): require a valid `gtins` **or** (`brand` **and** `mpn`). Otherwise `invalid` with the hint "Add a GTIN, or a brand and MPN. If this item is custom-made, vintage or has no manufacturer identifiers, set Google Shopping → Identifier exists to No."
    - identifiers, when Identifier exists = **No**: a GTIN on the product makes it `invalid` ("This product has a GTIN — set Identifier exists to Yes or remove the GTIN"), because Google rejects that contradiction. A brand and MPN are still sent when present.
    - `brand` is required for every offer except when Identifier exists = No and no brand is entered
    - `gtin` checksum (an invalid gtin is reported, not silently dropped)
    - `description` present
    - apparel (googleProductCategory id 1604 or a descendant per `config('google_merchant.apparel_category_ids')`) in target country US requires `color`, `size`, `gender`, `ageGroup`
    - Messages are translated, admin-readable sentences naming the store field to fix, e.g. "Add a GTIN in Google Shopping → GTIN, or set Identifier exists to No."
  - `Eligibility::reason(Product $root, GmcTarget $t): ?string`. Returns null when eligible, otherwise one of `disabled_globally`, `sync_off` (`gmc_sync`=0), `type_excluded`, `product_disabled`, `not_visible`, `category_excluded`, `out_of_stock_delete` (when `out_of_stock_action=delete` and every offer is out of stock).
- [ ] **Step 1: Write failing tests:**
  - `GtinTest` dataset: `'4006381333931'` true, `'4006381333932'` false, `'012345678905'` true, `'00000000'` false, `'abc'` false.
  - `OfferValidatorTest`:
    - a valid simple → `[]`
    - missing image → key `imageLink`
    - `http://localhost` link → key `link` with the public-https message
    - no brand and Identifier exists Yes → `brand`
    - Identifier exists Yes, no gtin and no mpn → `gtins` (and the mapped payload has **no** `identifierExists` key, so it is not auto-set)
    - brand + mpn without gtin → `[]`
    - bad checksum → `gtins`
    - Identifier exists No with no gtin/brand → `[]` and the payload has `identifierExists=false`
    - Identifier exists No **with** a gtin → `gtins` contradiction error
    - 51-char SKU → `offerId` error and the mapped `offerId` is the full SKU, not truncated
  - `EligibilityTest`: `gmc_sync=0` → `sync_off`; status 0 → `product_disabled`; a virtual type → `type_excluded`; config `enabled=false` → `disabled_globally`.
- [ ] **Step 2:** Run the filter. Expected: FAIL. **Step 3:** Implement. **Step 4:** Re-run. Expected: PASS.
- [ ] **Step 5: Commit:** `git commit -m "feat(gmc): offer validation and eligibility rules"`

### Task 8: SyncService and sync ledger (the core operation)

**Files:**
- Create: `packages/Custom/GoogleMerchant/src/Sync/{SyncService,SyncOutcome,SyncLogger}.php`
- Modify: `.../src/Repositories/GmcProductRepository.php`
- Test: `.../tests/Feature/SyncServiceTest.php`

**Interfaces:**
- Consumes: Tasks 4, 6, 7.
- Produces:
  - `SyncOutcome` enum: `synced`, `skipped`, `invalid`, `excluded`, `failed`, `deleted`
  - `SyncService::syncRoot(int $rootProductId, int $targetId, bool $force = false, ?int $runId = null): SyncOutcome`. Holds `Cache::lock("gmc:sync:{$targetId}:{$rootProductId}", 120)->block(30)`. Algorithm:
    1. Load the target and account. If the account isn't connected → throw `AuthException` (the job middleware handles it).
    2. Load the root. If missing, delete every ledger row with that `root_product_id` (step 6) → `deleted`.
    3. `Eligibility::reason()` not null → for each ledger row of the root in `synced|failed|pending`: delete in Google if `last_synced_at` is set, mark `excluded` with the reason → `excluded`.
    4. Read `$startRevision = max(revision)` of the root's rows. `offers = ProductMapper::map()`. For each offer, **claim** the ledger row by (`target`,`offer_id`) using the identity-claim rule below.
    5. **First**, ledger rows of this root whose `offer_id` is not in `offers` (removed variant, old SKU after a SKU change) → `deleteProductInput(stored product_input_name)` (`NotFoundException` = ok) → `deleted`. Deletes happen before inserts, inside the same lock.
    6. **Then**, per offer: validation errors → `invalid` + `validation_errors`, no API call. If `!$force` and `payload_hash` matches and `last_synced_at` is within `resubmit_days` → log `skipped`. Otherwise `insertProductInput` → `synced`, save the hash and `product_input_name`, reset `attempts`/`error_kind`/`retry_cycles`.
    7. Set `synced_revision = $startRevision` on the processed rows. Return the worst outcome in the order failed > invalid > synced > skipped, plus `bool $dirty = (current max(revision) > $startRevision)`. The return type becomes `SyncResult{outcome: SyncOutcome, dirty: bool}`.
  - **Identity-claim rule** (`GmcProductRepository::claim(int $targetId, string $offerId, int $productId, int $rootId): GmcProduct|IdentityConflict`), done in a `DB::transaction` with `lockForUpdate` on the (`target`,`offer_id`) row:
    - no row → create it
    - row has the same `product_id` → reuse it
    - row is `deleting`/`deleted`, or its `product_id` is null or points to a product that no longer exists → **reclaim**: set the new `product_id`/`root_product_id`, state `pending`. A queued delete for it then becomes a no-op (see `deleteOffer`).
    - row belongs to another **existing** product → return `IdentityConflict`. The offer becomes `invalid` with "SKU {sku} is already used in Google by product #{id}", and no API call is made.
  - `SyncService::deleteOffer(int $gmcProductId)` only calls Google when the row is still `deleting` and unclaimed. A reclaimed row → `skipped`.
  - `SyncService::deleteOffer(int $gmcProductId): SyncOutcome` (404 → `deleted`)
  - Exceptions: `PermanentApiException` → row `failed`, `error_kind=permanent`, field messages kept; the method continues with the other offers. `TransientApiException`, `QuotaExhaustedException` and `AuthException` are **rethrown** after logging, so the job can back off. `UnregisteredProjectException` → `failed`, `error_kind=setup`.
  - `SyncLogger::log(GmcProduct $row, Operation $op, string $status, ?GmcApiException $e = null, int $durationMs = 0, ?int $runId = null): void` writes a `gmc_sync_logs` row (attempt = row `attempts`)
  - `GmcProductRepository`:
    - `markPending(int $rootId, int $targetId): void`: upserts a placeholder row keyed by the root's sku if none exist; sets existing rows of the root to `pending` unless `deleting`; **always** runs `revision = revision + 1` on the root's rows (one `UPDATE … WHERE root_product_id`)
    - `forRoot(int $rootId, int $targetId): Collection`
    - `counts(int $targetId): array<state,int>`
    - `claim()` (above)
  - Migration (this task): `gmc_products.revision` and `synced_revision` (unsigned int, default 0), index (`gmc_target_id`, `revision`, `synced_revision`). "Dirty" means `revision > synced_revision`.
- [ ] **Step 1: Write failing tests** (Http::fake per test, using fixtures):
  - `it('inserts a new product and marks synced')`: 1 insert request; row `synced`, hash stored, 1 log `success`.
  - `it('skips an unchanged product')`: second call → no new request, log `skipped`.
  - `it('resubmits after resubmit_days even if unchanged')`: set `last_synced_at` to now-26d → insert sent.
  - `it('does not call google for invalid offers')`: remove the image → `invalid`, `validation_errors.imageLink`, `Http::assertNothingSent()`.
  - `it('records permanent errors as failed with field message')`: 400 fixture → `failed`, `error_kind=permanent`, `last_error` contains the human message.
  - `it('rethrows transient errors')`: 503 → `TransientApiException`; row stays `pending`, `attempts` incremented.
  - `it('deletes offers no longer produced')`: sync a configurable(3), remove 1 variant, sync again → 1 DELETE to that variant's name; row `deleted`.
  - `it('deletes from google when product becomes excluded')`: synced, then `gmc_sync=0` → DELETE sent, row `excluded`.
  - `it('treats 404 on delete as success')`.
  - `it('changes sku with delete before insert')`: synced sku A, update the sku to B, `syncRoot` → `Http::recorded()` order is DELETE(A's stored name) then insert(B). A's row `deleted`, B's row `synced`.
  - `it('deletes using the stored product input name')`: tamper the target's `feed_label` after syncing → the DELETE URL still uses the originally stored name.
  - `it('lets a new product reclaim a deleted sku and voids the stale delete')`: product P synced as X, delete P (row `deleting`), create Q with sku X, `syncRoot(Q)` then `deleteOffer(row)` → exactly 1 insert and 0 DELETEs; the row's `product_id` = Q, state `synced`.
  - `it('refuses an identity owned by another live product')`: force a row (X → product P, which exists), `syncRoot` for product R mapping to offer X → `invalid` with the conflict message, `Http::assertNothingSent()`.
  - `it('reports dirty when revision moves during sync')`: an Http fake callback bumps the root's `revision` mid-request → result `dirty=true`.
- [ ] **Step 2:** Run `vendor/bin/pest .../SyncServiceTest.php`. Expected: FAIL. **Step 3:** Implement. **Step 4:** Re-run. Expected: PASS.
- [ ] **Step 5: Commit:** `git commit -m "feat(gmc): sync service with ledger, hashing, deletes and error handling"`

### Task 9: Jobs, middleware, retries, debouncing

**Files:**
- Create: `packages/Custom/GoogleMerchant/src/Jobs/{SyncProductJob,DeleteOfferJob,HeartbeatJob}.php`, `.../src/Jobs/Middleware/EnsureAccountReady.php`
- Create: `.../src/Sync/SyncDispatcher.php`
- Test: `.../tests/Feature/{SyncProductJobTest,SyncDispatcherTest}.php`

**Interfaces:**
- Consumes: `SyncService` (Task 8).
- Produces:
  - `SyncProductJob(int $rootProductId, int $targetId, bool $force = false) implements ShouldQueue, ShouldBeUniqueUntilProcessing`
    - `uniqueId()` = `"{$targetId}:{$rootProductId}"`, `uniqueFor` = 3600, `tries` = 0 (unlimited, bounded by `retryUntil()` = now+24h), `maxExceptions` = 5
    - `handle()` calls `syncRoot`. If the result is `dirty`, it dispatches a fresh `SyncProductJob` for the same pair with delay `sync_delay`; uniqueness already allows this because the lock was released at processing start. A `TransientApiException`/`QuotaExhaustedException` is caught and `$this->release($e->retryAfter + random_int(0, 15))`. `AuthException` → release 900.
    - Dispatched with `->afterCommit()`, so a job never runs before Bagisto's save transaction commits.
    - `failed(Throwable)` → ledger rows of the root that are still `pending` become `failed`, `error_kind=transient`
  - `backoff(): array` = `[30, 120, 600, 1800, 3600]`, used for unexpected exceptions
  - `DeleteOfferJob(int $gmcProductId)`: same retry policy, unique per id
  - `EnsureAccountReady` middleware: account `connected` → next; `needs_reauth` → `release(900)`; missing or `disconnected` → `$job->delete()` (ledger stays `pending` for the reconnect sweep)
  - `SyncDispatcher`:
    - `queue(array $productIds, string $reason, ?int $delay = null, bool $force = false): int`. Resolves roots with `rootsFor()`, then for each enabled target calls `markPending` and dispatches `SyncProductJob` with delay = `$delay ?? sync_delay`. Returns the number of jobs dispatched. Returns 0 without doing anything when `enabled=false`, `auto_sync=false` (unless `$reason==='manual'`) or no connected account.
    - `rootsFor(array $productIds): int[]`: variant → its parent; grouped → its children; any product that belongs to a bundle → also that bundle (`product_bundle_option_products`); de-duplicated
    - `queueDelete(int $gmcProductId): void`
  - `HeartbeatJob`: `Cache::put('gmc:worker:heartbeat', now()->timestamp, 3600)`
- [ ] **Step 1: Write failing tests:**
  - `it('dispatches one unique job per root and target')`: `Queue::fake()`; `queue([$id, $id, $id], 'update')` → `Queue::assertPushed(SyncProductJob::class, 1)` on connection `database`, queue `gmc`, with delay 30.
  - `it('resolves variants to their parent')`: queueing a variant id pushes a job for the parent id only.
  - `it('does nothing when disabled or disconnected')`.
  - `it('drops duplicates while pending but allows one after processing starts')`: with the real `database` queue, dispatch twice → 1 row in `jobs`. Then release the unique lock the way the worker does (`app(UniqueLock::class)->release($job)`) and dispatch again → 2 rows.
  - `it('releases with retry-after on transient errors')`: a job with a fake `SyncService` throwing `TransientApiException(120)` → `$job->job` mocked; `release` called with a value between 120 and 135.
  - `it('releases while needs_reauth and deletes when disconnected')`.
  - `it('marks rows failed when the job finally fails')`.
  - `it('re-dispatches when the product changed during the job')`: a fake `SyncService` returns `dirty=true` → one new `SyncProductJob` pushed for the same pair; `dirty=false` → none.
  - `it('catches up a change whose dispatch was swallowed')`: bump `revision` without dispatching (simulates a listener failure), run `gmc:refresh` (Task 11) → a job is queued because `revision > synced_revision`.
- [ ] **Step 2:** Run. Expected: FAIL. **Step 3:** Implement. **Step 4:** Re-run. Expected: PASS.
- [ ] **Step 5: Commit:** `git commit -m "feat(gmc): queued sync jobs with debounce, uniqueness, backoff and account gating"`

### Task 10: Event listeners (product, SKU change, delete, inventory, import)

**Files:**
- Create: `packages/Custom/GoogleMerchant/src/Listeners/{ProductListener,InventoryListener,ImportListener}.php`, `.../src/Sync/SkuChangeTracker.php`
- Modify: `.../src/Providers/GoogleMerchantServiceProvider.php` (an `EventServiceProvider`-style `$listen` map registered in `boot()`)
- Test: `.../tests/Feature/{ProductEventsTest,InventoryEventsTest,ImportEventsTest,SaveResilienceTest}.php`

**Interfaces:**
- Consumes: `SyncDispatcher` (Task 9).
- Produces (event → handler):
  - `catalog.product.update.before($id)` → `ProductListener::beforeUpdate(int $id)`: `SkuChangeTracker::remember($id, current sku)` (a scoped singleton)
  - `catalog.product.create.after($product)` / `catalog.product.update.after($product)` → `ProductListener::afterSave(Product $p)`: `queue([$p->id], 'save')`. When the remembered sku ≠ the new sku, nothing extra is dispatched: the old row stays attached to the root, and `syncRoot` deletes it before inserting the new one (Task 8, step 5). The tracker is still used to log a `sku_changed` entry (old → new) in `gmc_sync_logs`.
  - `catalog.product.delete.before($id)` → `ProductListener::beforeDelete(int $id)`: every ledger row with `product_id` or `root_product_id` = $id → state `deleting`, `queueDelete` each; also `queue(rootsFor([$id]) minus $id)` so a parent configurable or bundle is refreshed
  - `checkout.order.save.after($order)`, `sales.order.cancel.after($order)` → `InventoryListener::fromOrder($order)`: product ids of the items, including children
  - `sales.refund.save.after($refund)`, `sales.shipment.save.after($shipment)` → `InventoryListener::fromItems($entity)`
  - `data_transfer.imports.batch.import.after($batch)` → `ImportListener::batchImported($batch)`: when the import type is `products`, collect `sku` values from `$batch->data`, look up ids, `queue($ids, 'import', delay: 60)`
  - Every handler body sits in a try/catch(`Throwable`) that calls `report()`.
- [ ] **Step 1: Write failing tests** (`Queue::fake()`):
  - `it('queues sync when an admin creates a product')`: POST `admin.catalog.products.store` → `SyncProductJob` pushed.
  - `it('queues sync on update and mass status update')`.
  - `it('queues one sync when sku changes')`: ledger row for sku A synced, update the sku to B → exactly 1 `SyncProductJob` and **no** `DeleteOfferJob`; A's row is still attached to the root; a `sku_changed` log entry exists.
  - `it('captures offers before product delete')`: delete a configurable → its variant rows become `deleting`, with N `DeleteOfferJob`s.
  - `it('queues stock sync after an order is placed')`: place an order through Bagisto's checkout test helpers (or dispatch `checkout.order.save.after` with a factory order) → job for the ordered product.
  - `it('queues sync for imported batch skus')`.
  - `SaveResilienceTest` — `it('never fails a product save when gmc throws')`: bind `SyncDispatcher` to a mock throwing `RuntimeException`, PUT product update → 302 success, product saved, exception reported.
  - `it('turns stock to OUT_OF_STOCK when the last unit sells and back after refund')`: run the queued job synchronously with Http::fake and assert the inserted body's `availability` goes `OUT_OF_STOCK`, then `IN_STOCK`.
- [ ] **Step 2:** Run. Expected: FAIL. **Step 3:** Implement. **Step 4:** Re-run. Expected: PASS.
- [ ] **Step 5: Commit:** `git commit -m "feat(gmc): event-driven sync for products, sku changes, deletes, stock and imports"`

### Task 11: Scheduler, queue runner, daily refresh, auto-retry, artisan commands

**Files:**
- Create: `packages/Custom/GoogleMerchant/src/Console/Commands/{SyncCommand,RefreshCommand,RetryFailedCommand,QueueRunCommand}.php`
- Create: `.../src/Jobs/RefreshRunJob.php`
- Modify: `.../src/Providers/GoogleMerchantServiceProvider.php` (`commands([...])`, `callAfterResolving(Schedule::class, …)`)
- Test: `.../tests/Feature/{ScheduleTest,RetryFailedTest,RefreshRunTest}.php`

**Interfaces:**
- Produces:
  - Schedule (registered only when `enabled`):
    - every minute: `Cache::put('gmc:schedule:last', now()->timestamp)`
    - every minute, when runner=`scheduler`: `gmc:queue-run` → `queue:work {connection} --queue={name} --stop-when-empty --max-time=50 --sleep=3`, `->withoutOverlapping(2)->runInBackground()`
    - every 5 min: dispatch `HeartbeatJob`
    - 01:00 daily: `gmc:refresh`
    - hourly: `gmc:retry-failed`
  - `gmc:sync {--product=*} {--all} {--force}`: `--product` → `SyncDispatcher::queue(ids, 'manual', 0, force)`; `--all` → starts a bulk run (Task 13)
  - `gmc:refresh` → dispatches `RefreshRunJob`. It walks eligible root ids in chunks of 200 by id cursor, recomputes each offer's hash with `ProductMapper`, and queues roots whose hash ≠ ledger hash, whose ledger row is missing, `pending` or dirty (`revision > synced_revision`), or whose `last_synced_at` is older than `resubmit_days` − 1. This catches catalog-rule prices from the 00:01 reindex and missed events. It records a `gmc_runs` row of type `refresh`.
  - `gmc:retry-failed`: rows `failed` with `error_kind` in (`transient`,`auth`,`quota`) and `retry_cycles` < 5 → `retry_cycles++`, `queue(..., 'retry', 0)`. Permanent failures are retried only when the product changes or an admin clicks Retry.
  - When the account goes from `needs_reauth`/`disconnected` to `connected` (the `ConnectionService` hook), queue every root with `pending` rows.
- [ ] **Step 1: Write failing tests:**
  - `ScheduleTest`: resolve `Schedule`, assert events exist with expressions `* * * * *` (queue-run, when runner=scheduler; absent when runner=daemon), `0 1 * * *` (gmc:refresh), `0 * * * *` (retry-failed).
  - `RefreshRunTest`: 3 synced products, change one price directly in the DB with no event → refresh queues exactly 1 job; a row with `last_synced_at` now-25d is queued; the run row is `completed` with `total=3`.
  - `RetryFailedTest`: a transient-failed row is queued and `retry_cycles=1`; a permanent-failed row is not queued; `retry_cycles=5` is not queued.
- [ ] **Step 2:** Run. Expected: FAIL. **Step 3:** Implement. **Step 4:** Re-run. Expected: PASS.
- [ ] **Step 5: Commit:** `git commit -m "feat(gmc): scheduler runner, daily refresh, failed retry sweep, commands"`

### Task 12: Admin overview, products grid, product-page panel, variant pre-selection

**Files:**
- Create: `packages/Custom/GoogleMerchant/src/Http/Controllers/Admin/{OverviewController,ProductSyncController}.php`
- Create: `.../src/DataGrids/GmcProductDataGrid.php`, `.../src/Services/DashboardStats.php`
- Create: `.../src/Resources/views/admin/{overview,products/index}.blade.php`, `.../admin/partials/{counters,product-panel}.blade.php`, `.../shop/variant-preselect.blade.php`
- Modify: `.../src/Routes/admin-routes.php`, the provider (view-render listeners)
- Test: `.../tests/Feature/{AdminOverviewTest,ProductSyncAdminTest,VariantPreselectTest}.php`

**Interfaces:**
- Consumes: `GmcProductRepository::counts`, `SetupChecklist`, `SyncDispatcher`, `AuditLogger`.
- Produces:
  - `DashboardStats::forTarget(GmcTarget): array{eligible, synced, pending, failed, invalid, excluded, approved, disapproved, google_pending, last_success_at, worker_ok, scheduler_ok}`
  - Routes:
    - `GET /` → `admin.google_merchant.overview`
    - `GET products` → `.products.index` (DataGrid JSON on ajax)
    - `POST products/{gmcProduct}/sync` → `.products.sync` (force)
    - `POST products/{gmcProduct}/retry` → `.products.retry` (resets `retry_cycles`)
    - `POST products/{gmcProduct}/exclude` and `/include` → write `gmc_sync` via `ProductRepository::update` + `Event::dispatch('catalog.product.update.after')`; audited
    - `DELETE products/{gmcProduct}/google` → `.products.remove` (sets `gmc_sync=0`, which drives the delete through the normal path)
    - `POST products/mass-sync`, `products/mass-retry`, `products/mass-exclude` (`indices[]`)
    - `POST product/{product}/sync` → `.product.sync` (from the product page)
  - `GmcProductDataGrid` columns:
    - product name, `offer_id`, target `feed_label`, `state` (badge), `google_status`, `last_synced_at`, `last_error` (truncated)
    - filters: state, google_status, target
    - actions: Sync, Retry, Exclude/Include, Remove from Google, View log
    - mass actions: sync, retry, exclude
  - Product edit panel via `bagisto.admin.catalog.product.edit.form.after`: per-offer state, Google status, up to 3 issues with hints, and a "Sync now" button (shown when the admin has the permission)
  - Variant pre-selection: a shop `view_render_event` on the product page (the event name confirmed against `packages/Webkul/Shop/src/Resources/views/products/view.blade.php`; use the `.after` event of the configurable options block). It injects a script that reads `?variant=` and selects that child's super-attribute options in the existing Vue component. Exact selector approach: dispatch `change` on the matching option inputs, using the `config` JSON already rendered for the configurable component.
  - Red banner partial (`status-banner`) on every GMC page when the account is `needs_reauth`, the worker heartbeat is stale, or the checklist has failures
- [ ] **Step 1: Write failing tests:**
  - `AdminOverviewTest`: GET overview → 200 showing the merchant id and counters (seed 2 synced and 1 failed → the text "2" under Synced); `needs_reauth` → the banner text `google_merchant::app.banner.reauth`.
  - `ProductSyncAdminTest`: POST sync → `SyncProductJob` pushed with `force=true`; exclude → `gmc_sync` attribute value 0 and an audit row; mass-retry with 2 ids → 2 jobs; an admin without `marketing.google_merchant.products.sync` → blocked; DataGrid ajax returns the rows with `state`.
  - `VariantPreselectTest`: GET a configurable product page with `?variant=SKU` → response contains the `gmc-variant-preselect` script and the child id for that sku.
- [ ] **Step 2:** Run. Expected: FAIL. **Step 3:** Implement. **Step 4:** Re-run. Expected: PASS. Then check manually in the browser: the overview, the grid and the product panel render in the admin theme, and `?variant=` selects the right options and price on the storefront.
- [ ] **Step 5: Commit:** `git commit -m "feat(gmc): admin overview, products grid, product panel, variant pre-selection"`

### Task 13: Bulk sync ("Sync all" with filters, full/incremental, progress)

**Files:**
- Create: `packages/Custom/GoogleMerchant/src/Jobs/{StartBulkRunJob,BulkSyncChunkJob}.php`, `.../src/Services/BulkSyncService.php`
- Create: `.../src/Http/Controllers/Admin/RunController.php`, `.../src/Resources/views/admin/runs/index.blade.php`
- Test: `.../tests/Feature/BulkSyncTest.php`

**Interfaces:**
- Produces:
  - `BulkSyncService::start(array $filters, string $mode, ?int $adminId): GmcRun`
    - `$filters`: `{target_id?: int (default: every enabled target, one run per target), category_ids?: int[], product_status?: 'enabled'|'disabled'|'any', updated_from?: date, updated_to?: date, product_ids?: int[], sync_state?: SyncState[]}`
    - `$mode`: `full` (force, ignore hash) | `incremental` (hash-changed, missing, pending, failed only)
    - Stores the filters and mode in a new `gmc_runs.options` JSON column (added in this task's migration), status `running`, and dispatches `StartBulkRunJob`
  - `StartBulkRunJob(int $runId)`: counts `total` with one query, then walks root ids `> cursor` in pages of 500 and dispatches `BulkSyncChunkJob($runId, $ids50)` for every 50 ids, updating `cursor`. It never loads models (ids only). It stops when the run is `paused`.
  - `BulkSyncChunkJob(int $runId, array $ids)`: for each id it calls `SyncService::syncRoot($id, $target, force: mode==='full', runId)`. It increments `processed` and `succeeded`/`failed` with `DB::increment` once per id. On `TransientApiException` it dispatches a new `BulkSyncChunkJob($runId, $remainingIds)` with delay `retryAfter` and returns, so no id is counted twice. It marks the run `completed` when `processed >= total`.
  - `BulkSyncService::pause(GmcRun)` / `resume(GmcRun)` (resume re-dispatches `StartBulkRunJob` from `cursor`), `startDeleteAll(GmcAccount)` (type `delete_all`, used by disconnect)
  - Routes: `GET runs` → `.runs.index`, `POST runs` → `.runs.store`, `POST runs/{run}/pause`, `POST runs/{run}/resume`, `GET runs/{run}` (JSON progress `{total, processed, succeeded, failed, pending: total-processed, current_cursor, status, started_at, finished_at}`; the view polls every 5 s)
- [ ] **Step 1: Write failing tests** (`Http::fake` of insert success; jobs run inline by calling `handle()`):
  - `it('chunks 250 products into 5 chunk jobs of 50')`: `Queue::fake()` then run `StartBulkRunJob` → 5 `BulkSyncChunkJob`, `total=250`.
  - `it('filters by category, status and date')`: 3 products in category X and 2 outside; filter `category_ids=[X]` → total 3. `updated_from` excludes older products.
  - `it('incremental skips unchanged, full forces')`: after a full run, an incremental run sends 0 inserts and a full run sends N.
  - `it('resumes a chunk after 429 without double counting')`: a sequence fake returns 200, 429 (`Retry-After: 30`), 200… → the remainder is re-dispatched with delay 30; after running it, `processed == total` and `succeeded == total`.
  - `it('pauses and resumes from cursor')`.
  - `it('reports progress json')`.
- [ ] **Step 2:** Run. Expected: FAIL. **Step 3:** Implement. **Step 4:** Re-run. Expected: PASS.
- [ ] **Step 5: Commit:** `git commit -m "feat(gmc): resumable bulk sync with filters, full/incremental modes and progress"`

**Phase 1 exit check:** `vendor/bin/pest packages/Custom/GoogleCore packages/Custom/GoogleMerchant` all green, and `vendor/bin/pest` (the whole suite) shows no new failures compared with the baseline run before Task 1 (record the baseline counts in Task 1 Step 2).

---

## Phase 2 — Management depth

### Task 14: Activity log grid

**Files:** Create `.../src/DataGrids/GmcSyncLogDataGrid.php`, `.../src/Http/Controllers/Admin/LogController.php`, `.../views/admin/logs/index.blade.php`. Test: `.../tests/Feature/LogAdminTest.php`.

**Interfaces:**
- Routes: `GET logs` → `.logs.index`; `GET logs/{log}` → `.logs.show` (JSON for the drawer)
- Columns: created_at, product, offer_id, operation, status, http_status, error_message, attempt, duration_ms. Filters: status, operation, date range, product id.
- `ErrorExplainer::explain(GmcSyncLog|GmcProduct): array{title: string, hint: string}` maps Google reason codes and HTTP status to admin sentences. The table lives in `src/Support/error-hints.php`, keyed by Google issue `code` / `fieldViolations.field`. Unknown codes fall back to Google's message.
- [ ] Tests: the grid lists rows newest first; filter `status=error` hides successes; `show` returns the redacted excerpt with no `access_token`; `ErrorExplainer` on the gtin 400 fixture returns a hint mentioning "GTIN".
- [ ] Run → FAIL. Implement. Run → PASS. **Commit:** `git commit -m "feat(gmc): activity log grid with readable error explanations"`

### Task 15: Attribute mapping and category mapping UI

**Files:** Create `.../src/Http/Controllers/Admin/MappingController.php`, `.../src/Http/Requests/{AttributeMappingRequest,CategoryMappingRequest}.php`, `.../views/admin/mapping/{attributes,categories}.blade.php`, `.../src/Resources/data/google-taxonomy-en-US.txt` (Google's published taxonomy file, saved in the repo; Task 0 records the source URL). Modify `MappingRegistry` (DB overrides), `CategoryResolver`. Test: `.../tests/Feature/MappingTest.php`.

**Interfaces:**
- `MappingRegistry::sourceFor()` now checks `gmc_attribute_mappings` (target-specific row → global row → `DEFAULTS`). The result is cached per request.
- The allowed `google_field` values are the keys of `MappingRegistry::DEFAULTS`. `source_type=attribute` requires an existing attribute code; `static` takes a literal (e.g. `condition` = `used`); `none` omits the field.
- Category mapping page: the category tree with a Google category autocomplete (served from the taxonomy file through `GET mapping/taxonomy?q=` → `[{id, path}]`, max 20) and an `excluded` toggle.
- Saving a mapping queues a `gmc:refresh` run (dispatching `RefreshRunJob`), because hashes change. Audited.
- Routes: `GET|PUT mapping/attributes`, `GET|PUT mapping/categories`, `GET mapping/taxonomy`.
- [ ] Tests: a static `condition=used` mapping → mapper outputs `USED`; `brand` mapped to attribute `gmc_material` → the brand equals that value; category X mapped to 1604 → `googleProductCategory` `1604`; category `excluded` → eligibility `category_excluded`; a mapping to an unknown attribute code → 422; taxonomy search `shirt` returns entries containing "Shirts".
- [ ] Run → FAIL. Implement. Run → PASS. **Commit:** `git commit -m "feat(gmc): attribute and category mapping UI with google taxonomy"`

### Task 16: Partial updates for stock/price-only changes

**Files:** Modify `.../src/Sync/SyncService.php`, `.../src/Mapping/MappedOffer.php` (add `array $fieldHashes`), migration adding `gmc_products.field_hashes` (json). Test: `.../tests/Feature/PartialUpdateTest.php`.

**Interfaces:**
- `MappedOffer::$fieldHashes`: `array<googleField, sha1>`
- When the only changed fields are within `['availability','price','salePrice','salePriceEffectiveDate']` and the row is `synced` and resubmission is not due → `patchProductInput` with `updateMask` = `productAttributes.{field}` joined by `,`. Otherwise a full insert.
- [ ] Tests: a stock-only change → a PATCH with `updateMask=productAttributes.availability`, no insert; a title change → insert; a PATCH 404 (the offer vanished in Google) → falls back to insert.
- [ ] Run → FAIL. Implement. Run → PASS. **Commit:** `git commit -m "feat(gmc): partial patch updates for stock and price changes"`

### Task 17: Store configuration screen and URL validation report

**Files:** Create `.../views/admin/settings/store.blade.php`, `.../src/Http/Controllers/Admin/StoreSettingsController.php`, `.../src/Services/UrlProbe.php`. Test: `.../tests/Feature/StoreSettingsTest.php`.

**Interfaces:**
- A page under Connection showing the brief's §10 fields (country, currency, language, target market = the target's countries, shipping and tax notes, the four policy URLs, website URL). Values are stored in Bagisto core config `google_merchant.general.store.*` (Task 2) and the default `gmc_targets` row. The language must be an existing channel locale and the currency an existing channel currency, otherwise a 422.
- `UrlProbe::check(string $url): array{ok: bool, status: ?int, message: string}`: `Http::timeout(5)->withoutRedirecting()->head()`, falling back to GET on 405. It accepts 200–299, and 301/308 only when the target stays on the same host. It runs only on Save and from the checklist, never during sync.
- Changing country, language or currency re-provisions the data source (`DataSourceProvisioner::ensure`) and queues a full bulk run after admin confirmation (an `confirm_resync` checkbox). Audited.
- [ ] Tests: a currency not in the channel → 422; a policy URL `http://…` → validation error "must be HTTPS"; `UrlProbe` with a faked 404 → `ok=false`; changing the feed country calls datasource create and starts a run when confirmed.
- [ ] Run → FAIL. Implement. Run → PASS. **Commit:** `git commit -m "feat(gmc): store configuration and url validation"`

---

## Phase 3 — Diagnostics, reconciliation, notifications

### Task 18: Product statuses and issues from Google

**Files:** Create `.../src/Jobs/RefreshStatusesJob.php`, `.../src/Diagnostics/{StatusParser,IssueHints}.php`. Test: `.../tests/Feature/StatusRefreshTest.php`.

**Interfaces:**
- `RefreshStatusesJob(int $targetId, ?string $pageToken = null, ?int $runId = null)`: one `listProducts` page per job. It re-dispatches itself with `nextPageToken`, so no job runs long. Rows are matched by `offerId` + `contentLanguage` + `feedLabel`.
- `StatusParser::parse(array $product): array{google_status: GoogleStatus, issues: array<array{code, severity, attribute, description, detail, documentation, resolution}>}`:
  - `disapproved` if any destination is disapproved for every country
  - `partial` if disapproved in some countries or destinations
  - `pending` if any is pending and none are approved
  - else `approved`
- Saved to `google_status`, `google_issues` and `google_checked_at`.
- `IssueHints::for(string $code, ?string $attribute): string` gives a fix suggestion that names the store field (e.g. `image_link` → "Upload a larger product image (at least 100×100, no watermark) under Images"). Unknown codes → Google's `resolution`/`detail`.
- Schedule: every 6 h, plus the "Refresh statuses" button on Diagnostics.
- [ ] Tests: two pages of fixtures → 2 jobs, all rows updated; a disapproved fixture → `disapproved` with an issue description equal to Google's text; a partial fixture → `partial`; an unknown offer in Google's list is ignored here (reconcile handles orphans).
- [ ] Run → FAIL. Implement. Run → PASS. **Commit:** `git commit -m "feat(gmc): google product status and issue refresh"`

### Task 19: Diagnostics page (product, account, quota)

**Files:** Create `.../src/Http/Controllers/Admin/DiagnosticsController.php`, `.../views/admin/diagnostics/index.blade.php`, `.../src/Diagnostics/AccountIssues.php`. Test: `.../tests/Feature/DiagnosticsTest.php`.

**Interfaces:**
- `AccountIssues::get(GmcAccount): array`: the `listAccountIssues` result cached 6 h (`Cache::remember("gmc:account-issues:{$id}")`), refreshable from the page
- The page groups product issues by issue code, with a count, Google's description, the hint and a link to the filtered products grid. Groups shown: disapproved, pending, missing attributes (`validation_errors` + Google `missing_*`), price, image, identifier, policy. It also shows account-level issues and quota usage (`listQuotas`, cached 1 h).
- Routes: `GET diagnostics` → `.diagnostics.index`, `POST diagnostics/refresh` → dispatches `RefreshStatusesJob` + clears the caches.
- A submitted product is only labelled "Approved" after `google_status=approved`. `synced` + no status = "Submitted – awaiting Google review".
- [ ] Tests: seeded issues render grouped with counts; the account-issues fixture text appears; a `synced` row with null `google_status` shows "awaiting Google review", not approved; the refresh button dispatches the job and is ACL-protected.
- [ ] Run → FAIL. Implement. Run → PASS. **Commit:** `git commit -m "feat(gmc): diagnostics for product, account and quota issues"`

### Task 20: Full reconciliation with orphan cleanup

**Files:** Create `.../src/Jobs/ReconcileJob.php`. Modify `RefreshRunJob` (now part of reconcile), the schedule (configurable `reconcile_time`, default `01:00`, and `reconcile_frequency` daily|twicedaily). Test: `.../tests/Feature/ReconcileTest.php`.

**Interfaces:**
- `ReconcileJob(int $targetId, ?string $pageToken, int $runId)`: pages through `listProducts` for the target's data source. Each Google offer with no ledger row in `synced|pending|failed|invalid` and no store product with that sku is an **orphan** → `deleteProductInput`, logged with operation `delete` and context `orphan`. Google offers from *other* data sources are never touched (filter by the product's `dataSource`).
- After the last page it runs the Task 11 hash-drift/stale pass. The run type is `reconcile`.
- [ ] Tests: a Google list with offer `GHOST-1` and no store product → 1 DELETE; an offer from a different dataSource → no DELETE; a product changed without an event → requeued; the run completes with counts.
- [ ] Run → FAIL. Implement. Run → PASS. **Commit:** `git commit -m "feat(gmc): scheduled reconciliation with orphan cleanup"`

### Task 21: Notifications and log pruning

**Files:** Create `.../src/Mail/{SyncFailureDigest,ReauthRequired}.php` + views, `.../src/Jobs/{SendDigestJob,PruneLogsJob}.php`. Test: `.../tests/Feature/NotificationsTest.php`.

**Interfaces:**
- `ReauthRequired` is sent once when an account switches to `needs_reauth` (dedupe with `Cache::add("gmc:mail:reauth:{$id}", 1, 86400)`).
- `SendDigestJob` runs daily at 08:00 and sends only when there are new failed, invalid or disapproved items in the last 24 h. Contents: counts, the top 10 problems with hints, and a link to the dashboard. Recipient `notify_email`, falling back to the super-admin email.
- `PruneLogsJob` runs daily at 03:00 and deletes `gmc_sync_logs` older than `log_retention_days` in chunks of 1,000.
- [ ] Tests: `Mail::fake()`. A reauth switch sends once even when triggered twice; the digest isn't sent when nothing failed and is sent with the correct counts otherwise; prune deletes 40-day-old rows and keeps 10-day-old rows.
- [ ] Run → FAIL. Implement. Run → PASS. **Commit:** `git commit -m "feat(gmc): failure digest, reauth alerts, log pruning"`

---

## Phase 4 — Multi-market & account-level policies

### Task 22: Push shipping settings and return policy from Store Policies

**Files:** Create `.../src/Services/AccountPolicySync.php`, `.../src/Jobs/PushAccountPoliciesJob.php`. Modify `MerchantApiClient` (`getShippingSettings`, `insertShippingSettings`, `listOnlineReturnPolicies`, `createOnlineReturnPolicy`, `updateOnlineReturnPolicy`, using paths from API-NOTES). Test: `.../tests/Feature/AccountPolicySyncTest.php`.

**Interfaces:**
- `AccountPolicySync::shippingBody(GmcTarget): array` is built from `PolicySettingRepository::forChannel()->regions[region]['shipping']`. The region is chosen by the target's country (US → `US`, EU countries → `EU`). Each method becomes a service with `deliveryCountries`, `currencyCode`, a flat rate `cost`, `minHandlingTime`/`maxHandlingTime`, `minTransitTime`/`maxTransitTime` and a free-over threshold.
- `AccountPolicySync::returnPolicyBody(GmcTarget): array`: `countries`, `policy.days` = `window_days`, `returnMethods`, `restockingFee`, `itemConditions`, and `returnPolicyUri` = `StoreSettings::policyUrls()['return']`.
- Triggered by a manual "Push to Google" button on Store settings and by the StorePolicy settings save (listen for Eloquent `saved` on `PolicySetting`). Never automatic in a request: always a job. Audited.
- [ ] Tests: the US region with one method builds the expected JSON (the fixture comparison asserts field names); the return policy uses the HTTPS policy URL; a missing policy URL → the job marks a setup error and makes no API call; the StorePolicy save dispatches the job.
- [ ] Run → FAIL. Implement. Run → PASS. **Commit:** `git commit -m "feat(gmc): sync shipping settings and return policy to merchant center"`

### Task 23: Multiple targets (markets/languages) in UI and sync

**Files:** Create `.../views/admin/settings/targets.blade.php`, `.../src/Http/Controllers/Admin/TargetController.php`. Modify `SyncDispatcher`, `DashboardStats` and the grids to iterate over or filter by target. Test: `.../tests/Feature/MultiTargetTest.php`.

**Interfaces:**
- CRUD for `gmc_targets` (locale must be a channel locale; currency must be a channel currency; countries are ISO-2; `feed_label` defaults to the first country). Create → `DataSourceProvisioner::ensure`; disable → keeps the ledger and stops dispatching; delete → `delete_all` run for that target.
- The mapper uses `$target->locale` for translated fields and converts prices with `core()->convertPrice($amount, $target->currency)` **only** when the owner chose D2(b). With D2(a) (EUR configured as a real currency with its own prices) it reads the EUR price directly.
- [ ] Tests: two targets (US/en/USD, DE/en/EUR) → one product produces 2 ledger rows and 2 inserts with different `feedLabel`s and currencies; disabling DE → only the US job is dispatched.
- [ ] Run → FAIL. Implement. Run → PASS. **Commit:** `git commit -m "feat(gmc): multiple market targets"`

### Task 24: Integration hook points for future Google services (GA4, GTM, Ads, Search Console)

**Files:** Create `packages/Custom/GoogleCore/src/Resources/views/admin/integrations.blade.php`, `packages/Custom/GoogleCore/src/Http/Controllers/IntegrationsController.php`, `packages/Custom/GoogleCore/README.md` (how to add a sibling package). Test: `packages/Custom/GoogleCore/tests/Feature/IntegrationsPageTest.php`.

**Interfaces:**
- `GET {admin}/google` → `admin.google.integrations` lists `IntegrationRegistry::all()` with connected state and a link. Menu `marketing.google` sits above `marketing.google_merchant`.
- README documents the contract, how to reuse `GoogleOAuthClient` with other scopes, the `google_audit_logs` usage, and the rule that each service lives in its own package with its own tables and listeners. It also lists the storefront event names a future GA4/GTM package would hook (`bagisto.shop.layout.head.after`, `checkout.order.save.after`) **without** implementing them.
- [ ] Tests: the page lists "Merchant Center" as connected when an account is connected; a fake integration registered in the test appears.
- [ ] Run → FAIL. Implement. Run → PASS. **Commit:** `git commit -m "feat(google-core): integrations hub for future google services"`

---

### Task 25: Operations documentation and final verification

**Files:** Create `packages/Custom/GoogleMerchant/README.md`.

- [ ] **Step 1:** Write README sections:
  - **Environment variables:** the `GMC_*` list from Task 2.
  - **Google Cloud Console:** create a project; enable the Merchant API; OAuth consent screen (publish it, otherwise refresh tokens die after 7 days); create a Web OAuth client with redirect `https://<domain>/admin/google-merchant/oauth/callback`; or create a service account, a JSON key, and add its email as a user in Merchant Center.
  - **Merchant Center:** verify and claim the website, have an admin user, set business info. The Connect screen runs developer registration automatically.
  - **Queue/scheduler:**
    - Linux cron: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`
    - Windows Task Scheduler: `php artisan schedule:run` every minute
    - Optional daemon: `php artisan queue:work database --queue=gmc --tries=0 --max-time=3600` under Supervisor/systemd with `GMC_QUEUE_RUNNER=daemon`
  - **Deployment:** `composer dump-autoload`, `php artisan migrate`, `php artisan optimize:clear`, `php artisan queue:restart`
  - **First-run checklist:** connect, pick the merchant, test with 2–3 SKUs via `gmc:sync --product=`, check Diagnostics after Google review, enable auto-sync, then Sync all.
  - **Manual end-to-end checklist** (§12 E2E)
- [ ] **Step 2:** Run `vendor/bin/pest packages/Custom/GoogleCore packages/Custom/GoogleMerchant`. Expected: all pass. Then `vendor/bin/pest`: no new failures compared with the Task 1 baseline. Then `vendor/bin/pint packages/Custom`: clean.
- [ ] **Step 3: Commit:** `git commit -m "docs(gmc): setup, deployment and operations guide"`

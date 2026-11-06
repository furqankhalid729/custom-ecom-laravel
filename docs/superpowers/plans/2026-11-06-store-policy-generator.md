# Store Policy Generator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin enters store facts once (CMS → Store Policies); one click generates EU + US–ready policy CMS pages, footer links and Merchant Center structured data, with hand-edit protection.

**Architecture:** New package `packages/Custom/StorePolicy` (namespace `Custom\StorePolicy\`). Settings stored as JSON groups in one row per channel. Blade templates per locale render HTML into existing Bagisto CMS pages via `PageRepository`. A generations table stores hashes to detect out-of-date and hand-edited pages, plus the previous HTML for restore.

**Tech Stack:** PHP 8.2, Laravel 11, Bagisto 2.3.19 (Concord modules, `Webkul\Core\Eloquent\Repository`, `x-admin::` Blade components), Pest, MySQL.

**Spec:** `docs/superpowers/specs/2026-11-06-store-policy-generator-design.md`

## Global Constraints

- Do not edit files under `packages/Webkul/*`. Extend via service-container bindings, config merging and `view_render_event` listeners.
- No new Composer/NPM dependencies.
- All admin UI strings go through `store_policy::app.*` translations (`src/Resources/lang/en/app.php`).
- Templates: `src/Resources/views/templates/{locale}/{key}.blade.php`; only `en` ships.
- EU region: `returns.accepted` must be true; `returns.window_days` ≥ 14; `returns.refund_days` ≤ 14.
- Regions at launch: `EU` (27 member states, default all; currency `EUR`) and `US` (`['US']`, currency `USD`).
- Generated HTML is wrapped in `<div class="static-container"><div class="mb-5">…</div></div>` and passed through Bagisto's `clean_content()` **before** saving and hashing (the CMS editor applies `clean_content()` on save; doing it here keeps an unchanged re-save from looking like a hand edit).
- Hash for hand-edit detection: `sha1(preg_replace('/\s+/', ' ', trim($html)))`.
- Never overwrite a page whose status is `edited` or `out_of_date_edited` unless its template key is in the request's `confirm_overwrite[]`.
- Footer link sync: add missing links only; never remove, reorder or duplicate (match by URL path `/page/{url_key}`).
- Tests run against the seeded dev DB inside `DatabaseTransactions` (as Bagisto's own tests do).

## Review Focus

1. **Page deleted or `url_key` renamed in CMS after generation** → status shows `not_generated` and Generate recreates it; generation row for the old page id is ignored. Test in Task 5.
2. **Settings never saved (fresh install) and admin presses Generate** → generation refuses with a validation message listing missing required fields; no pages touched. Test in Task 5.
3. **A region disabled (e.g. US off)** → its sections disappear from all pages and product JSON-LD; pages depending on `regions.*.enabled` become out of date. Test in Task 4 (hash) and Task 7 (JSON-LD).
4. **Footer `footer_links` customization missing for the channel or with only one column** → sync creates `column_2` (or the customization) rather than crashing. Test in Task 6.
5. **Admin opens a generated page in CMS editor and saves without changes** → status stays `up_to_date`. Test in Task 5.

---

### Task 0: Version control for this project

The project folder is untracked inside a repo rooted at `D:/`. Commit steps in this plan assume the project has its own repository.

- [ ] **Step 1:** If the owner approved it, run in project root: `git init && git add -A && git commit -m "chore: baseline before store policy generator"` (check `.gitignore` excludes `vendor/`, `node_modules/`, `.env`, `storage/*` first). If not approved, skip every "Commit" step below.

---

### Task 1: Package skeleton, data model, settings repository with defaults

**Files:**
- Create: `packages/Custom/StorePolicy/src/Providers/StorePolicyServiceProvider.php`, `.../Providers/ModuleServiceProvider.php`
- Create: `.../src/Database/Migrations/2026_11_06_000001_create_store_policy_settings_table.php`, `..._000002_create_store_policy_generations_table.php`
- Create: `.../src/Contracts/{PolicySetting,PolicyGeneration}.php`, `.../src/Models/{PolicySetting,PolicySettingProxy,PolicyGeneration,PolicyGenerationProxy}.php`
- Create: `.../src/Repositories/PolicySettingRepository.php`
- Create: `.../src/Support/Regions.php`, `.../src/Support/Defaults.php`
- Create: `.../src/Resources/lang/en/app.php`
- Modify: `composer.json` (autoload `Custom\\StorePolicy\\` → `packages/Custom/StorePolicy/src`; autoload-dev `Custom\\StorePolicy\\Tests\\` → `packages/Custom/StorePolicy/tests`)
- Modify: `bootstrap/providers.php` (add `StorePolicyServiceProvider::class`), `config/concord.php` (add module provider)
- Modify: `phpunit.xml` (testsuite "StorePolicy Feature Test" → `packages/Custom/StorePolicy/tests/Feature`), `tests/Pest.php` (`uses(AdminTestCase::class)->in('../packages/Custom/StorePolicy/tests');`)
- Test: `packages/Custom/StorePolicy/tests/Feature/SettingsRepositoryTest.php`

**Interfaces:**
- Produces:
  - Table `store_policy_settings`: `id`, `channel_id` (unique, FK channels cascade), JSON columns `business`, `payment`, `privacy`, `terms`, `regions`, timestamps. Model casts all JSON columns to `array`.
  - Table `store_policy_generations`: `id`, `cms_page_id` (FK cms_pages cascade), `locale`, `template_key`, `generated_html_hash` (char 40), `settings_hash` (char 40), `previous_html` (longText nullable), `generated_at` (timestamp), `generated_by` (unsigned int nullable), unique(`cms_page_id`,`locale`).
  - `Regions::EU_COUNTRIES` (array of 27 ISO-2 codes: AT BE BG HR CY CZ DK EE FI FR DE GR HU IE IT LV LT LU MT NL PL PT RO SK SI ES SE), `Regions::definitions(): array` → `['EU' => ['countries' => EU_COUNTRIES, 'currency' => 'EUR'], 'US' => ['countries' => ['US'], 'currency' => 'USD']]`.
  - `Defaults::forChannel(\Webkul\Core\Contracts\Channel $channel): array` returning the full settings shape below.
  - `PolicySettingRepository::forChannel(int $channelId): PolicySetting` — existing row, or unsaved model filled from `Defaults`.
  - `PolicySettingRepository::saveForChannel(int $channelId, array $data): PolicySetting` (updateOrCreate).
  - `PolicySetting::toSettingsArray(): array` → `['business'=>…, 'payment'=>…, 'privacy'=>…, 'terms'=>…, 'regions'=>…]`.

Settings shape (exact keys, used by every later task):

```php
'business' => ['legal_name','trading_name','logo_url','address' => ['street','city','region','postcode','country'],
               'email','phone','hours','registration_number','vat_number','website_url'],
'payment'  => ['methods' => [/* titles */], 'charged_at' => 'order'|'shipment'],
'privacy'  => ['data_collected' => [], 'third_parties' => [], 'uses_cookies' => bool,
               'retention' => string, 'contact_email', 'eu_representative'],
'terms'    => ['governing_law', 'minimum_age' => int, 'effective_date' => 'Y-m-d'|null],
'regions'  => ['EU' => REGION, 'US' => REGION],
// REGION:
['enabled' => bool, 'countries' => [], 'currency' => string,
 'returns' => ['accepted' => bool, 'window_days' => int, 'conditions' => [/* unused|original_packaging|tags_attached */],
               'conditions_note', 'non_returnable', 'method' => 'mail'|'store'|'both',
               'return_shipping_paid_by' => 'customer'|'store'|'free', 'restocking_fee_percent' => int,
               'refund_method' => 'original'|'store_credit'|'customer_choice', 'refund_days' => int,
               'exchanges' => bool, 'defect_report_days' => int, 'defect_resolution'],
 'shipping' => ['handling_min_days' => int, 'handling_max_days' => int, 'cutoff_time' => 'HH:MM',
                'methods' => [['name','carrier','cost' => float,'free_over' => ?float,'min_days' => int,'max_days' => int]],
                'tracking' => bool, 'po_boxes' => bool, 'excluded_areas', 'lost_damaged_note']]
```

Defaults: `legal_name`/`trading_name` = channel name; `logo_url` = `$channel->logo_url()`; `website_url` = `'https://'.$channel->hostname` (or `config('app.url')` if no hostname); `payment.methods` = titles from `app(\Webkul\Payment\Payment::class)->getPaymentMethods()`; `privacy.data_collected` = `['Name','Email address','Phone number','Billing and shipping address','Order history','Device and usage data']`; `privacy.third_parties` = `['Payment processors','Shipping carriers','Analytics providers']`; `uses_cookies` = `(bool) core()->getConfigData('general.gdpr.cookie.enabled')`; `contact_email` = null; `terms.minimum_age` = 18; both regions `enabled` = true; EU returns `accepted` true, `window_days` 14, `refund_days` 14; US returns `accepted` true, `window_days` 30, `refund_days` 10; shipping `methods` = `[]`.

- [ ] **Step 1: Write failing tests** in `SettingsRepositoryTest.php`:
  - `it('prefills defaults from the default channel')`: `$s = app(PolicySettingRepository::class)->forChannel(core()->getDefaultChannel()->id)`; `expect($s->exists)->toBeFalse(); expect($s->business['legal_name'])->toBe(core()->getDefaultChannel()->name); expect($s->regions['EU']['countries'])->toHaveCount(27); expect($s->regions['EU']['returns']['window_days'])->toBe(14); expect($s->regions['US']['currency'])->toBe('USD'); expect($s->regions['EU']['shipping']['methods'])->toBe([]);`
  - `it('persists settings per channel')`: `saveForChannel($id, $data)` then `forChannel($id)->exists` true and `business['email']` equals saved value.
- [ ] **Step 2:** Run `composer dump-autoload && php artisan migrate && vendor/bin/pest packages/Custom/StorePolicy/tests/Feature/SettingsRepositoryTest.php` — Expected: FAIL (class not found) until code exists.
- [ ] **Step 3:** Implement files above. Service provider `boot()`: `loadMigrationsFrom`, `loadTranslationsFrom(..., 'store_policy')`, `loadViewsFrom(..., 'store_policy')`, register `ModuleServiceProvider`. Module provider lists both models (Concord pattern, see `packages/Webkul/Sitemap/src/Providers/ModuleServiceProvider.php`).
- [ ] **Step 4:** Re-run Step 2 command — Expected: PASS (2 tests).
- [ ] **Step 5: Commit** — `git commit -m "feat(store-policy): package skeleton, settings model and defaults"`

---

### Task 2: Settings validation (EU rules)

**Files:**
- Create: `packages/Custom/StorePolicy/src/Http/Requests/PolicySettingsRequest.php`
- Create: `packages/Custom/StorePolicy/src/Support/RequiredFields.php`
- Test: `packages/Custom/StorePolicy/tests/Feature/SettingsValidationTest.php`

**Interfaces:**
- Consumes: settings shape (Task 1).
- Produces:
  - `PolicySettingsRequest` (FormRequest) — rules for every key in the shape. Required: `business.legal_name`, `business.address.{street,city,postcode,country}`, `business.email` (email), `business.phone`, `terms.governing_law`, and for each enabled region: `returns.window_days` (int ≥0), `returns.refund_days`, `shipping.handling_min_days` ≤ `handling_max_days`, `shipping.methods` (min 1; each `name`, `cost` ≥0, `min_days` ≤ `max_days`). EU extra (only if `regions.EU.enabled`): `regions.EU.returns.accepted` `accepted`; `window_days` `min:14`; `refund_days` `max:14`. `regions.EU.countries.*` `in:` `Regions::EU_COUNTRIES`.
  - `RequiredFields::missing(array $settings): array<string>` — dotted keys of required fields that are empty (same list as above, used by generator and readiness without a request).

- [ ] **Step 1: Write failing tests** (validate with `Validator::make($data, (new PolicySettingsRequest)->rules())` using a `validSettings()` helper defined in `tests/Feature/Helpers.php` that returns a complete valid shape with one shipping method per region — later tasks reuse it):
  - `it('accepts a complete valid settings payload')` → passes.
  - `it('rejects EU return window under 14 days')` → `regions.EU.returns.window_days = 13` → error key `regions.EU.returns.window_days`.
  - `it('rejects EU returns not accepted')` → `accepted=false` → error on `regions.EU.returns.accepted`.
  - `it('rejects EU refund days over 14')` → `refund_days=15` → error.
  - `it('allows US window under 14 days')` → `regions.US.returns.window_days = 7` → passes.
  - `it('skips region rules when region disabled')` → `regions.US.enabled=false`, `regions.US.shipping.methods=[]` → passes.
  - `it('lists missing required fields')` → `RequiredFields::missing(Defaults::forChannel($channel))` contains `business.email`, `business.phone`, `regions.EU.shipping.methods`.
- [ ] **Step 2:** `vendor/bin/pest packages/Custom/StorePolicy/tests/Feature/SettingsValidationTest.php` — Expected: FAIL.
- [ ] **Step 3:** Implement. Register `tests/Feature/Helpers.php` via `require_once` at top of each test file that needs it (or autoload-dev `files`).
- [ ] **Step 4:** Re-run — Expected: PASS (7 tests).
- [ ] **Step 5: Commit** — `feat(store-policy): settings validation with EU consumer rules`

---

### Task 3: Templates and renderer

**Files:**
- Create: `packages/Custom/StorePolicy/src/Config/templates.php`
- Create: `.../src/Services/PolicyRenderer.php`
- Create: `.../src/Resources/views/templates/en/{return-policy,refund-policy,shipping-policy,payment-policy,privacy-policy,terms-conditions,about-us}.blade.php`
- Create: `.../src/Resources/views/templates/en/partials/{contact-block,refund-section,eu-withdrawal-form,last-updated}.blade.php`
- Test: `packages/Custom/StorePolicy/tests/Feature/PolicyRendererTest.php`

**Interfaces:**
- Consumes: settings array (Task 1), `validSettings()` helper (Task 2).
- Produces:
  - Config `store_policy.templates` (merged in provider): `key => ['url_key', 'title', 'meta_description', 'depends' => [dotted paths]]`. Keys and depends:
    - `return-policy` → `business`, `regions.*.enabled`, `regions.*.countries`, `regions.*.returns`, `terms.effective_date`
    - `refund-policy` → `business`, `regions.*.enabled`, `regions.*.returns`, `terms.effective_date`
    - `shipping-policy` → `business`, `regions.*.enabled`, `regions.*.countries`, `regions.*.currency`, `regions.*.shipping`, `terms.effective_date`
    - `payment-policy` → `business`, `payment`, `regions.*.enabled`, `regions.*.currency`, `terms.effective_date`
    - `privacy-policy` → `business`, `privacy`, `regions.*.enabled`, `terms.effective_date`
    - `terms-conditions` → `business`, `terms`, `regions.*.enabled`
    - `about-us` → `business`
    - `url_key` equals the key; titles: "Return Policy", "Refund Policy", "Shipping Policy", "Payment Policy", "Privacy Policy", "Terms & Conditions", "About Us".
  - `PolicyRenderer::render(string $templateKey, array $settings, string $locale = 'en'): string` — renders `store_policy::templates.{locale}.{key}` with `$s` (settings), `$regions` (enabled regions only, keyed by code, each with added `label` "European Union"/"United States"), `$lastUpdated` (`terms.effective_date` or today); returns HTML wrapped in the static-container markup and passed through `clean_content()`. Throws `InvalidArgumentException` for unknown key or missing locale view.

Content each template must contain (wording is the builder's; facts must come from settings):
- Every page: H1 title, `partials.contact-block` (legal name, full address, email, phone, hours), `partials.last-updated`.
- `return-policy`: one section per enabled region headed "Customers in the {label}". EU section: 14-day right of withdrawal stated with `window_days`, how to withdraw (email to `business.email`), refund within 14 days incl. original standard delivery cost, may withhold until goods received, return cost borne per `return_shipping_paid_by`, statutory exceptions + `non_returnable`, 2-year legal guarantee for faulty goods, `partials.eu-withdrawal-form` (model form, Annex I(B) of Directive 2011/83/EU text). US section: `accepted` false → "All sales are final" + defect handling; else window, conditions, method, who pays, restocking fee (if >0), exchanges. Both include `partials.refund-section`.
- `refund-policy`: `partials.refund-section` per enabled region (method, `refund_days`, restocking fee).
- `shipping-policy`: per region: countries list (EU shows "all EU member states" when all 27, else names), handling time, cut-off time, HTML table of methods (name, carrier, cost formatted with region currency, free-over, delivery days), tracking, PO boxes, excluded areas, lost/damaged note.
- `payment-policy`: methods list, when charged, currency per region.
- `privacy-policy`: data collected, purposes, third parties, cookies (if `uses_cookies`), retention, contact; EU section if EU enabled (GDPR legal bases, rights: access, rectification, erasure, restriction, portability, objection; complaint to supervisory authority; international transfers; `eu_representative` if set); US section if US enabled ("Your California privacy rights": know, delete, correct, opt out of sale/sharing; statement that data is not sold; Do Not Track).
- `terms-conditions`: seller identity, ordering, pricing/currency, payment, shipping & returns links (`/page/shipping-policy`, `/page/return-policy`), `minimum_age`, governing law; if EU enabled: EU consumers keep mandatory protections of their country of residence + EU ODR platform note.
- `about-us`: trading name, logo `<img>` if `logo_url`, contact block, registration/VAT numbers if set.

- [ ] **Step 1: Write failing tests** (all use `validSettings()`):
  - `it('renders return policy with EU and US sections')` → html contains `Customers in the European Union`, `Customers in the United States`, `14 days`, the US `window_days` value, the `business.email`, `static-container`.
  - `it('omits a disabled region')` → US disabled → not contains `Customers in the United States`.
  - `it('renders shipping methods table with region currency')` → contains method name and `€` (EU) and `$` (US) formatted cost.
  - `it('renders all sales final when US returns not accepted')` → contains `All sales are final`.
  - `it('includes California rights only when US enabled')` on `privacy-policy`.
  - `it('renders every configured template')` → loop over `config('store_policy.templates')` keys, each html non-empty and contains `business.legal_name`.
  - `it('throws for unknown template')`.
- [ ] **Step 2:** `vendor/bin/pest packages/Custom/StorePolicy/tests/Feature/PolicyRendererTest.php` — Expected: FAIL.
- [ ] **Step 3:** Implement config, renderer, templates. Currency formatting: `core()->formatPrice($amount, $currencyCode)`.
- [ ] **Step 4:** Re-run — Expected: PASS (7 tests).
- [ ] **Step 5: Commit** — `feat(store-policy): EU/US policy templates and renderer`

---

### Task 4: Settings hasher and page status

**Files:**
- Create: `packages/Custom/StorePolicy/src/Services/SettingsHasher.php`
- Create: `packages/Custom/StorePolicy/src/Enums/PageStatus.php`
- Create: `packages/Custom/StorePolicy/src/Services/PolicyStatusService.php`
- Test: `packages/Custom/StorePolicy/tests/Feature/PolicyStatusTest.php`

**Interfaces:**
- Consumes: `store_policy.templates` config (Task 3), `PolicySettingRepository` (Task 1), `PolicyGeneration` model.
- Produces:
  - `SettingsHasher::hash(array $settings, array $paths): string` — `sha1(json_encode(recursively-ksorted [path => data_get($settings, $path)]))`.
  - `SettingsHasher::htmlHash(string $html): string` — the global-constraint html hash.
  - `enum PageStatus: string { NotGenerated='not_generated'; UpToDate='up_to_date'; OutOfDate='out_of_date'; Edited='edited'; OutOfDateEdited='out_of_date_edited'; }` with `needsConfirmation(): bool` (true for Edited, OutOfDateEdited).
  - `PolicyStatusService::forChannel(int $channelId, string $locale = 'en'): array<string, array{key:string, title:string, page_id:?int, status:PageStatus, generated_at:?Carbon, can_restore:bool}>` keyed by template key. Page lookup: `cms_page_translations` where `url_key` = template url_key and `locale` = $locale. No page, or no generation row for (page_id, locale) → `NotGenerated`.
  - `PolicyStatusService::outOfDateKeys(int $channelId): array<string>` — keys with status OutOfDate or OutOfDateEdited.

- [ ] **Step 1: Write failing tests:**
  - `it('hash ignores unrelated settings')` → hash with `['regions.*.shipping']` unchanged after editing `privacy.retention`; changed after editing `regions.US.shipping.handling_max_days`.
  - `it('hash changes when a region is disabled')` with path `regions.*.enabled`.
  - `it('reports not generated for seeded placeholder pages')` → all 7 keys `NotGenerated`.
  - `it('reports edited when page html differs from generated hash')` → insert a generation row for `privacy-policy` page with hash of `'<p>a</p>'`, page html `'<p>b</p>'`, settings hash current → `Edited`.
  - `it('reports out_of_date when dependent settings changed')` → generation row whose html hash matches page html but `settings_hash = 'x'` → `OutOfDate`.
- [ ] **Step 2:** `vendor/bin/pest packages/Custom/StorePolicy/tests/Feature/PolicyStatusTest.php` — Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Re-run — Expected: PASS (5 tests).
- [ ] **Step 5: Commit** — `feat(store-policy): out-of-date and hand-edit detection`

---

### Task 5: Generator and restore

**Files:**
- Create: `packages/Custom/StorePolicy/src/Services/PolicyGenerator.php`
- Create: `packages/Custom/StorePolicy/src/Exceptions/MissingSettingsException.php`
- Test: `packages/Custom/StorePolicy/tests/Feature/PolicyGeneratorTest.php`

**Interfaces:**
- Consumes: `PolicyRenderer::render`, `SettingsHasher`, `PolicyStatusService::forChannel`, `RequiredFields::missing`, `PolicySettingRepository::forChannel`, `Webkul\CMS\Repositories\PageRepository`, `FooterLinkSync::ensureLinks` (Task 6 — **execute Task 6 before Task 5**; it has no dependency on Task 5).
- Produces:
  - `PolicyGenerator::generate(int $channelId, array $templateKeys, array $confirmOverwrite = [], ?int $adminId = null): array{generated: string[], skipped: string[]}` — throws `MissingSettingsException` (with `->missing` array) if `RequiredFields::missing` non-empty, before touching anything. In one `DB::transaction`: for each key, skip (add to `skipped`) if status `needsConfirmation()` and key ∉ `$confirmOverwrite`. Otherwise render; if page exists → `PageRepository::update(['en' => ['url_key','page_title','html_content','meta_title','meta_description'], 'channels' => existing channel ids ∪ [$channelId], 'locale' => 'en'], $pageId)`; else `PageRepository::create([... flat attributes ..., 'channels' => [$channelId]])`. Upsert generation row (`previous_html` = old page html or null, hashes, `generated_at` now, `generated_by`). If `terms.effective_date` empty, set it to today and save settings first. After loop, call `FooterLinkSync::ensureLinks($channelId, $generatedKeys)`.
  - `PolicyGenerator::restore(int $channelId, string $templateKey): void` — puts `previous_html` back into the page's `en` html, sets `previous_html` null; throws `RuntimeException` if nothing to restore. Does not change hashes (page then shows as `Edited`).

- [ ] **Step 1: Write failing tests** (save `validSettings()` first unless noted):
  - `it('refuses to generate when required settings are missing')` → no saved settings → throws `MissingSettingsException`; `privacy-policy` html unchanged.
  - `it('generates selected pages and marks them up to date')` → generate `['return-policy','shipping-policy']` → page html contains `Customers in the European Union`; statuses `UpToDate`; others still `NotGenerated`.
  - `it('creates a missing page assigned to the channel')` → delete `about-us` page, generate it → page exists with `url_key` `about-us` and channel attached.
  - `it('skips hand-edited pages without confirmation')` → generate, then change page html directly → generate again → key in `skipped`, html still the manual one.
  - `it('overwrites hand-edited pages when confirmed and keeps previous html')` → `confirmOverwrite` includes key → `generated`; generation `previous_html` equals manual html.
  - `it('restores previous version')` → after previous test flow, `restore` → page html equals manual html.
  - `it('stays up to date after an unchanged CMS editor save')` → generate `privacy-policy`, then `putJson(route('admin.cms.update', $pageId), ['en' => [...current translation values...], 'channels' => [$channelId], 'locale' => 'en'])` as admin → status `UpToDate`.
  - `it('regenerates after page deleted')` → generate, delete page, status `NotGenerated`, generate again succeeds.
- [ ] **Step 2:** `vendor/bin/pest packages/Custom/StorePolicy/tests/Feature/PolicyGeneratorTest.php` — Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Re-run — Expected: PASS (8 tests).
- [ ] **Step 5: Commit** — `feat(store-policy): page generator with overwrite protection and restore`

---

### Task 6: Footer link sync

**Files:**
- Create: `packages/Custom/StorePolicy/src/Services/FooterLinkSync.php`
- Test: `packages/Custom/StorePolicy/tests/Feature/FooterLinkSyncTest.php`

**Interfaces:**
- Consumes: `store_policy.templates` (url_key, title), `Webkul\Theme\Repositories\ThemeCustomizationRepository`, channel `theme`.
- Produces: `FooterLinkSync::ensureLinks(int $channelId, array $templateKeys): void` and `FooterLinkSync::missingLinks(int $channelId, array $templateKeys): array<string>` (keys whose `/page/{url_key}` isn't in any column for locale `en`).

Rules: find `type=footer_links`, `channel_id`, `theme_code = channel->theme` (status any). If none, create one (status 1, name "Footer Links") with `en` options `['column_1' => [], 'column_2' => []]`. For locale `en` translation options: for each missing key append `['url' => config('app.url').'/page/'.$url_key, 'title' => $title, 'sort_order' => max existing in that column + 1]` to `column_2` (create `column_2` if absent). Match existing by `parse_url($url, PHP_URL_PATH)` ending with `/page/{url_key}`. Write only the `en` translation.

- [ ] **Step 1: Write failing tests:**
  - `it('does not duplicate seeded footer links')` → `ensureLinks($ch, all keys)` → count of links with path `/page/privacy-policy` in en options is 1; total link count increased only by keys not seeded (`about-us` is already in column_1 → 0 added). Assert options before == after for existing entries (order preserved).
  - `it('adds a missing link to column_2')` → remove `return-policy` link from options, sync → present in `column_2` with `sort_order` = previous max + 1.
  - `it('creates footer customization when missing')` → delete the footer_links customization, sync → exists with links for given keys.
- [ ] **Step 2:** `vendor/bin/pest packages/Custom/StorePolicy/tests/Feature/FooterLinkSyncTest.php` — Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Re-run — Expected: PASS (3 tests).
- [ ] **Step 5: Commit** — `feat(store-policy): ensure policy links in footer`

---

### Task 7: Structured data (Organization + product offers)

**Files:**
- Create: `packages/Custom/StorePolicy/src/Seo/ProductSeo.php` (extends `Webkul\Product\Helpers\SEO`)
- Create: `packages/Custom/StorePolicy/src/Seo/MerchantJsonLd.php`
- Create: `packages/Custom/StorePolicy/src/Resources/views/shop/organization-json-ld.blade.php`
- Modify: `StorePolicyServiceProvider` — `$this->app->bind(\Webkul\Product\Helpers\SEO::class, ProductSeo::class)`; `Event::listen('bagisto.shop.layout.head.after', fn ($e) => $e->addTemplate('store_policy::shop.organization-json-ld'))`
- Test: `packages/Custom/StorePolicy/tests/Feature/StructuredDataTest.php`

**Interfaces:**
- Consumes: `PolicySettingRepository::forChannel`.
- Produces:
  - `MerchantJsonLd::returnPolicies(array $settings): array` — one `MerchantReturnPolicy` per enabled region: `applicableCountry` (countries), `returnPolicyCategory` (`https://schema.org/MerchantReturnFiniteReturnWindow` or `MerchantReturnNotPermitted`), `merchantReturnDays`, `returnMethod` (`ReturnByMail`/`ReturnInStore`), `returnFees` (`FreeReturn` if `free`, `ReturnFeesCustomerResponsibility` if `customer`, `FreeReturn` if `store`), `refundType` (`FullRefund`/`StoreCreditRefund`).
  - `MerchantJsonLd::shippingDetails(array $settings): array` — one `OfferShippingDetails` per method per enabled region: `shippingRate` (`MonetaryAmount` value/currency), `shippingDestination` (`DefinedRegion` per country `addressCountry`), `deliveryTime` (`ShippingDeliveryTime`: `handlingTime` and `transitTime` as `QuantitativeValue` minValue/maxValue, unitCode `DAY`).
  - `MerchantJsonLd::organization(array $settings): array` — `Organization` with name, url, logo, email, telephone, `address` (`PostalAddress`), `contactPoint`, `hasMerchantReturnPolicy`.
  - `ProductSeo::getProductOffers($product)` — `parent::getProductOffers($product)` + `shippingDetails` + `hasMerchantReturnPolicy` **only if** settings row exists for current channel (unsaved defaults → parent output unchanged).
  - Organization view renders `<script type="application/ld+json">` only if a settings row exists.

- [ ] **Step 1: Write failing tests** (save `validSettings()`):
  - `it('adds shipping details and return policies to product offers')` → `$offers = app(\Webkul\Product\Helpers\SEO::class)->getProductOffers($product)` for any seeded/factory product → `$offers['hasMerchantReturnPolicy']` count 2, EU one has `merchantReturnDays` 14 and `applicableCountry` count 27; `$offers['shippingDetails'][0]['shippingRate']['currency']` in `['EUR','USD']`.
  - `it('leaves offers untouched without saved settings')` → no settings → result equals parent output keys only.
  - `it('drops disabled region from structured data')` → US disabled → no `applicableCountry` equal `['US']`.
  - `it('renders organization json-ld on the storefront')` → `get(route('shop.home.index'))` contains `"@type":"Organization"` and `legal_name`.
- [ ] **Step 2:** `vendor/bin/pest packages/Custom/StorePolicy/tests/Feature/StructuredDataTest.php` — Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Re-run — Expected: PASS (4 tests).
- [ ] **Step 5: Commit** — `feat(store-policy): Merchant Center structured data`

---

### Task 8: Readiness checker

**Files:**
- Create: `packages/Custom/StorePolicy/src/Services/ReadinessChecker.php`
- Test: `packages/Custom/StorePolicy/tests/Feature/ReadinessCheckerTest.php`

**Interfaces:**
- Consumes: `RequiredFields::missing`, `PolicyStatusService::forChannel`, `FooterLinkSync::missingLinks`, `PolicySettingRepository`.
- Produces: `ReadinessChecker::check(int $channelId): array<array{key:string, label:string, passed:bool, detail:?string}>` with keys (labels via `store_policy::app.readiness.*`):
  - `business_contact` — no missing `business.*` required fields.
  - `return_policy` — `return-policy` status `UpToDate` or `Edited` **and** page attached to channel.
  - `shipping_policy` — same for `shipping-policy` and every enabled region has ≥1 method with cost and max_days.
  - `other_pages` — privacy, terms, payment generated (status ≠ NotGenerated).
  - `footer_links` — `missingLinks` empty for the 7 keys.
  - `structured_data` — `core()->getConfigData('catalog.rich_snippets.products.show_offers')` truthy (detail tells where to switch it on: Configure → Catalog → Rich Snippets).
  - `other_locales` — for each generated page, translations in locales ≠ `en` whose `html_content` doesn't equal en html; `detail` = "Still has placeholder text in: ar" listing locales.

- [ ] **Step 1: Write failing tests:**
  - `it('fails everything on a fresh install')` → all `passed` false except possibly `structured_data`/`footer_links` (seed-dependent: assert specifically `business_contact`, `return_policy`, `shipping_policy`, `other_pages` false).
  - `it('passes after settings saved and all pages generated')` → save `validSettings()`, generate all, enable show_offers config (`core_config` insert) → `business_contact`, `return_policy`, `shipping_policy`, `other_pages`, `footer_links`, `structured_data` true.
  - `it('flags other locales with placeholder text')` → add an `ar` translation row to `privacy-policy` with different html, after generation → `other_locales` false and detail contains `ar`.
- [ ] **Step 2:** `vendor/bin/pest packages/Custom/StorePolicy/tests/Feature/ReadinessCheckerTest.php` — Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Re-run — Expected: PASS (3 tests).
- [ ] **Step 5: Commit** — `feat(store-policy): Merchant Center readiness checklist`

---

### Task 9: Admin screen, routes, ACL and menu

**Files:**
- Create: `packages/Custom/StorePolicy/src/Http/Controllers/Admin/StorePolicyController.php`
- Create: `packages/Custom/StorePolicy/src/Routes/admin-routes.php`
- Create: `packages/Custom/StorePolicy/src/Config/{menu.php,acl.php}`
- Create: `packages/Custom/StorePolicy/src/Resources/views/admin/index.blade.php` (+ partials per settings tab: `business`, `regions` (EU/US sub-tabs: returns, shipping with repeatable method rows), `payment`, `privacy`, `terms`, `status-panel`, `readiness`)
- Modify: `StorePolicyServiceProvider` — load routes inside `Route::middleware(['web','admin', NoCacheMiddleware::class])->prefix(config('app.admin_url'))`; `mergeConfigFrom` menu → `menu.admin`, acl → `acl`.
- Modify: `src/Resources/lang/en/app.php` (all labels)
- Test: `packages/Custom/StorePolicy/tests/Feature/StorePolicyAdminTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces routes:
  - `GET  cms/store-policies` → `index` — name `admin.cms.store_policies.index`
  - `PUT  cms/store-policies` → `update` (uses `PolicySettingsRequest`) — `admin.cms.store_policies.update`; on success flash success and, if `PolicyStatusService::outOfDateKeys` non-empty, flash `out_of_date` (array of keys) which the view uses to show the notice "These pages are out of date: …" and pre-tick them.
  - `POST cms/store-policies/generate` → `generate` (`templates[]`, `confirm_overwrite[]`) — `admin.cms.store_policies.generate`; on `MissingSettingsException` redirect back with error listing missing fields; on success flash generated + skipped (skipped shown as "needs confirmation").
  - `POST cms/store-policies/{template}/restore` → `restore` — `admin.cms.store_policies.restore`.
- Menu (`menu.php`): `cms.pages` (name "Pages", route `admin.cms.index`, sort 1) and `cms.store_policies` (name "Store Policies", route `admin.cms.store_policies.index`, sort 2) so the existing CMS item gets two children.
- ACL (`acl.php`): `cms.store_policies` (route index), `cms.store_policies.edit` (update), `cms.store_policies.generate` (generate + restore). Controller checks `bouncer()->hasPermission(...)` like other admin controllers.
- View: status panel rows show title, view link (`/page/{url_key}`), edit link (`admin.cms.edit`), status badge, last generated, checkbox `templates[]`; rows needing confirmation show an extra checkbox `confirm_overwrite[]` labeled "Replace my manual changes"; "Restore previous version" button when `can_restore`. Shipping tab shows the notice: "These must match what checkout charges — Merchant Center compares them." Footer of page: disclaimer "Generated text is a starting point, not legal advice."

- [ ] **Step 1: Write failing tests** (`$this->loginAsAdmin()`):
  - `it('shows the store policies screen')` → `get(route('admin.cms.store_policies.index'))` OK, sees `trans('store_policy::app.index.title')` and the 7 page titles.
  - `it('saves settings and flags out of date pages')` → save valid, generate all, then `put` with changed `regions.EU.shipping.handling_max_days` → redirect, session `out_of_date` contains `shipping-policy` and not `about-us`.
  - `it('rejects EU window under 14 via http')` → `put` with 13 → session errors `regions.EU.returns.window_days`.
  - `it('generates selected pages via http')` → `post(generate, ['templates' => ['privacy-policy']])` → redirect; page html contains legal name.
  - `it('reports missing settings on generate')` → no settings → redirect with error session.
  - `it('restores via http')`.
  - `it('adds store policies to the admin menu')` → `get(route('admin.cms.index'))` sees "Store Policies".
- [ ] **Step 2:** `vendor/bin/pest packages/Custom/StorePolicy/tests/Feature/StorePolicyAdminTest.php` — Expected: FAIL.
- [ ] **Step 3:** Implement. Base the view on an existing admin settings form (e.g. `packages/Webkul/Admin/src/Resources/views/settings/channels/edit.blade.php`) for `x-admin::layouts`, `x-admin::form`, `x-admin::form.control-group` usage.
- [ ] **Step 4:** Re-run — Expected: PASS (7 tests).
- [ ] **Step 5: Manual check** — `php artisan serve`, log in to admin, open CMS → Store Policies, fill settings, Generate all, visit `/page/return-policy` and `/page/shipping-policy`, check footer links and view-source JSON-LD on a product page. Expected: content matches settings, both region sections present.
- [ ] **Step 6: Run full package suite** — `vendor/bin/pest packages/Custom/StorePolicy/tests` — Expected: all pass. Also `vendor/bin/pest packages/Webkul/Admin/tests/Feature/Cms` — Expected: still passing (menu/route changes didn't break CMS).
- [ ] **Step 7: Commit** — `feat(store-policy): admin screen, routes, ACL and menu`

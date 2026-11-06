# Store Policy Generator — Design

Date: 2026-11-06
Project: custom-ecom-laravel (Bagisto 2.3.19, Laravel 11, PHP 8.2)
Status: Draft, awaiting approval

## 1. Intent

The store is new and must pass Google Merchant Center review before products can be listed.
Merchant Center checks that the website clearly shows business identity, contact details, a
return/refund policy, shipping terms, payment information, privacy policy, and terms — and that
these agree with each other.

Today Bagisto ships generic placeholder CMS pages (`return-policy`, `shipping-policy`, ...) that
must be rewritten by hand. The owner wants to enter the store's facts **once** in admin
(business name, logo, address, return window, shipping times, etc.) and have complete,
consistent policy pages **generated from templates** — while still being able to hand-edit any
page afterwards.

This is project 1 of 2. Project 2 (one-click Google Merchant Center product sync) is designed
separately afterwards and will reuse the settings captured here (business info, shipping,
returns).

## 2. Goals

- One admin screen holding all facts the policies need.
- One click generates/updates the policy pages from built-in templates.
- Generated pages are ordinary Bagisto CMS pages — editable in Admin → CMS as today.
- When settings change, admin is told which pages are now out of date and asked whether to
  regenerate. Hand-edited pages are never overwritten without an explicit confirmation.
- Output satisfies Merchant Center's website requirements (see §9 checklist).

## 3. Non-goals (for now)

- Languages other than English (structure must allow adding Arabic later without rework).
- Selling regions beyond the EU and USA (structure must allow adding more later without rework).
- More than one Bagisto channel (settings are keyed by channel so it's possible later, but
  generated pages are shared store-wide; see §6.4).
- Syncing shipping costs with Bagisto's checkout carriers (owner enters them by hand).
- Admin-editable templates (templates live in code).
- Google Merchant Center API connection / product sync (project 2).
- Legal review. Generated text is a sensible starting point, not legal advice.

## 4. Anti-goals (failure even if it "works")

- A click silently wiping out the owner's manual edits.
- Pages that contradict each other (e.g. 14-day returns on one page, 30 on another), or that
  contradict the product structured data.
- Policy pages that exist but are not linked from the storefront (Merchant Center can't find them).
- Hard-coding single-country/English assumptions so deeply that adding a country or Arabic
  means a rewrite.
- Modifying Bagisto core packages (`packages/Webkul/*`) beyond what is unavoidable — keep the
  feature in its own package so Bagisto upgrades stay manageable.

## 5. Decisions made with the owner

| Topic | Decision |
|---|---|
| Where it lives | Inside this existing Laravel/Bagisto project |
| Editing | Generated pages must stay hand-editable |
| Settings change | Admin is asked to regenerate affected pages (not automatic) |
| Language | English only at launch |
| Selling regions | EU and USA at launch; more later |
| Shipping costs/times | Entered by hand in policy settings — not read from or written to checkout carriers |

## 6. Design

### 6.1 Package

New package `packages/Custom/StorePolicy` (namespace `Custom\StorePolicy\`), registered in
`composer.json` PSR-4, `bootstrap/providers.php`, and `config/concord.php` the same way
Webkul packages are. *(My call: separate vendor folder so it's obvious what is ours vs Bagisto.)*

Contains: migrations, models, repository, admin controller + routes, admin Blade views, policy
templates, a generator service, ACL + admin menu entries, tests.

### 6.2 Admin location

New menu item **CMS → Store Policies** (ACL keys `cms.store_policies`, `.edit`, `.generate`).
*(My call: next to the pages it generates, rather than Configure → General, because the
generate/status workflow doesn't fit Bagisto's config form.)*

The screen has two parts:

1. **Settings form** (tabs/sections listed in §6.3).
2. **Pages status panel** — one row per policy page: page name, link to view, link to edit in
   CMS, status (`Not generated`, `Up to date`, `Out of date`, `Edited by hand`,
   `Out of date + edited by hand`), last generated date, and a checkbox to select it for
   generation. A **Generate selected** button and a **Merchant Center readiness** checklist (§9).

### 6.3 Settings captured

Scope: per **channel** (one channel in practice). Region-specific groups (Returns, Shipping,
currency) are additionally keyed by **selling region**.

A **selling region** is a named list of countries with its own currency:
- `EU` — the EU member states the store ships to (multi-select, default all 27), currency EUR.
- `US` — United States, currency USD.

The UI shows these two regions; the data model allows adding regions (e.g. UK) later.

Defaults are prefilled from existing Bagisto data where possible (channel name, logo, hostname,
channel currencies, enabled payment methods). Shipping methods are **not** prefilled — entered
by hand.

**Business**
- Legal business name *(default: channel name)*, trading/display name
- Logo *(default: channel logo)*
- Registered address: street, city, state/region, postal code, country
- Support email, phone, business hours
- Company registration no. / VAT/tax no. (optional)
- Website URL *(default: channel hostname)*

**Selling regions**
- Enabled regions (EU, US); for EU, which member states; currency per region

**Returns & refunds** (per region)
- Returns accepted (yes/no) — **EU: cannot be "no"**; the statutory right of withdrawal
  always applies
- Return window (days) — **EU: minimum 14 days** after delivery (validation enforces it)
- Item conditions (unused / original packaging / tags attached — multi-select + free text)
- Non-returnable items (free text, e.g. "final sale, personalised items")
- Return method (by mail / in store / both)
- Who pays return shipping (customer / store / free returns)
- Restocking fee (none / % value)
- Refund method (original payment / store credit / customer choice)
- Refund processing time (days after item received)
- Exchanges offered (yes/no)
- Damaged/defective items: report-within days, resolution offered

**Shipping** (per region, entered by hand)
- Order processing/handling time (min–max business days), daily order cut-off time
- Shipping methods — repeatable rows: name, carrier, cost, free-over amount, delivery
  min–max business days
- Tracking provided (yes/no)
- Ships to P.O. boxes (yes/no), regions not served (free text)
- Lost/damaged-in-transit handling (free text)

**Payment**
- Accepted payment methods *(default: enabled Bagisto payment methods; editable)*
- When the customer is charged (at order / at shipment)
- Billing currency

**Privacy**
- Data collected (prefilled list, editable)
- Third parties shared with (payment processors, shipping carriers, analytics — editable)
- Uses cookies (links to existing GDPR cookie settings)
- Data retention period
- Privacy contact email *(default: support email)*
- EU representative / data protection officer contact (optional; needed if the business is
  outside the EU or requires a DPO)

**Terms**
- Governing law / jurisdiction *(default: business country; template adds that EU consumers
  keep the protection of their own country's mandatory law)*
- Minimum age to purchase
- Policy effective date *(auto-set on first generation, editable)*

Required fields are validated server-side; the readiness checklist shows what's missing.

### 6.4 Pages generated

Generator writes into the **existing** CMS pages by `url_key` (creates them if missing,
assigned to the channel):

| url_key | Content |
|---|---|
| `return-policy` | Full Returns & Refunds policy |
| `refund-policy` | Refunds section (shared partial with the above, so they can't disagree) |
| `shipping-policy` | Shipping policy incl. methods table, processing + delivery times |
| `payment-policy` | Accepted methods, when charged, currency |
| `privacy-policy` | Privacy policy |
| `terms-conditions` | Terms & conditions |
| `about-us` | Business identity + full contact block |

Every generated page ends with a common **contact block** (business name, address, email, phone)
and a "Last updated" date. Page title, meta title and meta description are generated too.

Pages not in this table (`terms-of-use`, `customer-service`, `whats-new`) are left untouched.

**One page per policy, with a section per region.** Bagisto's `url_key` is unique store-wide, so
there is one `return-policy`, one `shipping-policy`, etc. Where terms differ by region the page
has clearly headed sections ("Customers in the EU", "Customers in the USA"). *(My call: avoids
a URL scheme change, and Merchant Center accepts one page per policy as long as each country's
terms are clear.)*

**Region-specific content the templates must include:**
- **EU** — Return policy: the 14-day right of withdrawal, how to exercise it (clear
  statement, by email), the model withdrawal form, refund within 14 days of withdrawal
  (may be withheld until goods are received), refund of original standard delivery cost, who
  bears return cost, and statutory exceptions (e.g. personalised or sealed hygiene goods).
  Also the 2-year legal guarantee for faulty goods. Privacy: GDPR legal bases, data-subject
  rights (access, rectification, erasure, portability, objection), right to complain to a
  supervisory authority, international transfers. Terms: ODR / consumer dispute note.
- **US** — Return policy: store's own terms as set. Privacy: "Your California privacy rights"
  (CCPA/CPRA: right to know, delete, correct, opt out of sale/sharing; no sale of data
  unless configured) and a do-not-track statement.

### 6.4a Other locales

Generation writes the `en` translation only. If a generated page has translations in other
installed locales, the readiness checklist flags them as "still has placeholder text in: ar"
so the owner can fix or hide them. *(My call.)*

### 6.5 Templates

- Blade templates per locale: `src/Resources/views/templates/{locale}/{template}.blade.php`,
  plus shared partials (contact block, refund section).
- Only `en` ships now. Generator looks up the template by the page's locale; adding Arabic =
  adding an `ar/` folder.
- Templates use plain variables from the settings and Blade conditionals
  (e.g. "Returns not accepted" vs the full return terms; free returns vs customer-paid).
- Each template declares the **settings groups it depends on** (e.g. `shipping-policy` →
  `business`, `shipping`) — used for out-of-date detection.
- Output HTML wrapped in the same `static-container` markup the seeded pages use, so the
  storefront styling is unchanged.

### 6.6 Generation, out-of-date and hand-edit detection

Table `store_policy_generations`: `cms_page_id`, `locale`, `template_key`,
`generated_html_hash`, `settings_hash` (hash of the settings groups the template depends on),
`previous_html` (longtext — content that was replaced), `generated_at`, `generated_by`.

- **Edited by hand** = current page HTML hash ≠ `generated_html_hash`.
- **Out of date** = current hash of the template's dependent settings ≠ `settings_hash`.
- **After saving settings**: redirect back with a notice listing the now out-of-date pages and
  a "Regenerate these" action (pre-ticks them in the status panel).
- **Generating a page that is edited by hand** requires a per-page confirmation in the dialog
  ("Shipping Policy has manual changes that will be replaced").
- **Undo safety**: before overwriting, the old HTML is stored in `previous_html`; the status
  panel offers **Restore previous version** for the last generation. *(My call: cheap insurance
  against the main anti-goal.)*
- Generation runs synchronously (a handful of pages) inside a DB transaction.

### 6.7 Footer links

On generation, ensure each generated page is linked in the channel's `footer_links` theme
customization (Admin → Settings → Themes). Add missing links; never duplicate, reorder or
remove existing links.

Verified: the storefront is the stock Bagisto `default` shop theme
(`packages/Webkul/Shop`), whose footer renders `footer_links`
(`components/layouts/footer/index.blade.php`). If a custom theme replaces it later, it must
keep rendering `footer_links`.

### 6.8 Structured data (Merchant Center)

*(My call — Merchant Center cross-checks these against the policy pages.)*

- Add site-wide `Organization` JSON-LD (name, logo, url, address, contactPoint, and
  `hasMerchantReturnPolicy`) from the settings.
- Extend product `Offer` JSON-LD (`Webkul\Product\Helpers\SEO::getProductOffers`) with
  `shippingDetails` (one `OfferShippingDetails` per region's shipping method, with
  `shippingDestination` countries) and `hasMerchantReturnPolicy` (one per region, with
  `applicableCountry`). Do this by binding a subclass of the helper from our package
  (`app()->bind`/`extend`), not editing the Webkul file.

**Consistency with checkout:** shipping costs are typed by hand, so they can drift from what
Bagisto checkout charges. The settings screen shows a reminder next to shipping methods:
"These must match what checkout charges — Merchant Center compares them." No automatic check.

## 7. Constraints

- Bagisto conventions: Concord modules, repositories, Proxy models, admin Blade components
  (`x-admin::...`), ACL + menu config merging, translation files for all admin UI strings.
- No new Composer/NPM dependencies expected.
- Admin UI strings in `en` lang file only for now (package lang structure ready for `ar`).

## 8. Testing

Pest feature tests (package `tests/`, matching Bagisto's Admin tests style):
- Settings save + validation; defaults prefilled from channel.
- Generate creates/updates CMS pages with expected facts (e.g. return days appear).
- Hand-edit detection, out-of-date detection after a settings change.
- Edited page not overwritten without confirmation flag; restore previous version works.
- Footer links added once, existing links untouched.
- JSON-LD contains return policy + shipping details matching settings.

## 9. Merchant Center readiness checklist (shown in admin)

Green/red items:
- Business name, address, email, phone filled
- Return policy page generated and published to the channel, with terms for every enabled region
- Shipping policy generated with at least one method per enabled region incl. cost + delivery time
- No generated page has placeholder text left in another locale (§6.4a)
- Privacy, terms, payment pages generated
- All above linked in footer
- Structured data enabled (catalog rich snippets "show offers" on)

## 10. Left to the builder

- Exact admin layout and field widgets within Bagisto's admin component library.
- Exact policy wording in templates (clear, plain English, consistent with settings).
- Storage shape for settings (one JSON column per group vs columns) as long as it is keyed by
  channel (+ country for returns/shipping).
- Migration/class naming.

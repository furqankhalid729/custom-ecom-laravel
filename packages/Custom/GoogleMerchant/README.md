# Custom/GoogleMerchant: Google Merchant Center sync

This package keeps the Bagisto catalog in sync with Google Merchant Center through the Merchant
API v1. It covers products, prices, stock, shipping settings and the return policy. It sends
updates as the catalog changes, reconciles every night, pulls Google review statuses, and
supports more than one market (countries + language + currency).

In the admin it lives under **Marketing → Google Merchant Center**. Shared OAuth, audit and
hub plumbing is in [`../GoogleCore`](../GoogleCore/README.md).

---

## 1. Environment variables

Put these in `.env`. Never put them in `.env.example`, and never commit secrets.

| Key | Required | Default | Meaning |
|---|---|---|---|
| `GMC_CLIENT_ID` | for OAuth | — | OAuth 2.0 Web client ID from Google Cloud |
| `GMC_CLIENT_SECRET` | for OAuth | — | Its secret |
| `GMC_REDIRECT_URI` | no | `route('admin.google_merchant.oauth.callback')` | Set only if the public URL differs from `APP_URL` (proxies, CDN) |
| `GMC_QUEUE_CONNECTION` | no | `database` | Queue connection for sync jobs |
| `GMC_QUEUE` | no | `gmc` | Queue name |
| `GMC_QUEUE_RUNNER` | no | `scheduler` | `scheduler`: the scheduler drains the queue every minute (shared hosting). `daemon`: you run `queue:work` yourself |
| `GMC_API_BASE` | no | `https://merchantapi.googleapis.com` | Change only for testing |
| `GMC_HTTP_TIMEOUT` | no | `20` | Seconds per Google call |

If you connect with a service account, `GMC_CLIENT_ID` and `GMC_CLIENT_SECRET` are not needed.
You upload the JSON key on the Connection screen, and it is stored encrypted.

## 2. Google Cloud Console (one-time)

1. Create a project, or reuse one, at <https://console.cloud.google.com>.
2. **APIs & Services → Library → "Merchant API" → Enable.**
3. **OAuth consent screen:**
   - User type: External.
   - Add the scope `https://www.googleapis.com/auth/content`.
   - **Publish the app** (Publishing status → *In production*). While the app is in *Testing*,
     only listed test users can sign in ("Error 403: access_denied"), and **refresh tokens
     expire after 7 days**, so the connection silently drops every week.
4. **Credentials → Create credentials → OAuth client ID → Web application.**
   - Authorized redirect URI: `https://<your-domain>/admin/google-merchant/oauth/callback`.
     Replace `admin` if you changed `APP_ADMIN_URL`.
   - Copy the client ID and secret into `.env`.
5. **Alternative: service account.** Use this for headless or agency setups.
   - Go to IAM & Admin → Service accounts → Create, then Keys → Add key → JSON.
   - In Merchant Center → Settings → People and access, add the service account's email as a
     user with **Admin** access.
   - Upload the JSON on the Connection screen.

## 3. Merchant Center (one-time)

- **Verify and claim the website** (Business info → Website) for the same domain as
  *Store details → Website URL*. Offers for an unclaimed domain are disapproved.
- The Google account that connects must be an **Admin** user of the Merchant Center account.
- Fill in **Business info**: address, customer service contact, and so on.
- Developer registration (`developerRegistration:registerGcp`) runs **automatically** when you pick
  the merchant on the Connection screen. You don't need to do anything for it.

## 4. Queue and scheduler

Every Google call goes through the queue, so saving a product never waits on Google. The scheduler
must run every minute.

**Linux / shared hosting (cron, including Hostinger hPanel → Advanced → Cron Jobs):**

```cron
* * * * * cd /path/to/site && php artisan schedule:run >> /dev/null 2>&1
```

**Windows (Task Scheduler):** add a task that runs every minute, indefinitely:
`php C:\path\to\site\artisan schedule:run`, starting in the site folder.

With `GMC_QUEUE_RUNNER=scheduler` (the default), that cron is enough. Each minute it runs
`gmc:queue-run`, which works the `gmc` queue for about 50 seconds.

**Optional dedicated worker (VPS):** set `GMC_QUEUE_RUNNER=daemon` and keep this running under
Supervisor or systemd:

```bash
php artisan queue:work database --queue=gmc --tries=0 --max-time=3600
```

`--tries=0` is intentional. The jobs manage their own retries and back-off, including
Google's `Retry-After`.

### What the scheduler runs

| Task | When | Purpose |
|---|---|---|
| `gmc:queue-run` | every minute (scheduler runner only) | Drain the `gmc` queue |
| heartbeats | every 1 / 5 min | Feed the "scheduler / worker running" checks on the Overview |
| `gmc:retry-failed` | hourly | Retry transient failures |
| `gmc:statuses` | every 6 h | Pull Google review status and issues per product |
| `gmc:reconcile` | nightly, default 01:00 (Configure → Google Merchant Center) | Resend drifted or stale offers, delete orphan offers in Google |
| prune logs | 03:00 | Remove sync logs older than the retention setting (default 30 days) |
| failure digest | 08:00 | Email a summary of yesterday's failures, if notifications are on |

Reconcile runs after Bagisto's 00:01 catalog-rule price reindex, because that reindex fires no
events.

### Artisan commands

```bash
php artisan gmc:sync --product=12 --product=13   # sync specific store products now
php artisan gmc:sync --all                        # bulk-sync the catalog (one run per market)
php artisan gmc:sync --product=12 --force         # resend even if unchanged
php artisan gmc:statuses                          # pull review statuses now
php artisan gmc:reconcile                         # nightly reconcile, on demand
php artisan gmc:refresh                           # resend stale/drifted offers only
php artisan gmc:retry-failed                      # retry failed rows now
php artisan gmc:taxonomy                          # (re)import the Google product taxonomy
```

## 5. Deployment

After each deploy:

```bash
composer dump-autoload
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart        # only matters with a daemon worker
```

If you use a CDN or host cache (for example Hostinger's CDN), purge it after the first deploy
so the store pages, not a cached old site, are what Google crawls.

## 6. First-run checklist

1. **Configure → Google Merchant Center → Store details:** website URL (https), country, language,
   currency, and the return, refund, terms and privacy URLs. The URL report must be all ✓.
2. **Connection:** *Connect with Google* (or upload a service account), then **pick the merchant
   account**.
3. **Field mapping:** check brand, GTIN, MPN and condition sources, and map your top categories to
   Google categories.
4. **Test with 2–3 SKUs:**
   ```bash
   php artisan gmc:sync --product=<id> --product=<id>
   ```
   Then open **Products**. Each should show *Synced*. Errors appear on the row with a hint.
5. Wait for Google's review (minutes to a few hours), then open **Diagnostics** → *Refresh*.
   Fix the issues it lists before going further.
6. **Store details → Push to Google:** send shipping settings and the return policy.
7. Turn on **auto-sync** (Configure → Google Merchant Center → Settings).
8. **Bulk sync → Sync all.** Watch progress under *Bulk sync*.
9. Optional: **Markets → Add market** for each extra country, language or currency. Every new
   market gets its own data source and a first full sync. A currency other than the base
   currency needs an exchange rate under *Settings → Exchange Rates*.

## 7. Manual end-to-end checklist (real Merchant Center account)

Automated tests mock Google entirely. Run this checklist once against a real account, using 2–3
test SKUs, before turning on auto-sync for the whole catalog.

- [ ] **OAuth:** connect, merchant list loads, pick merchant → Overview shows *Connected*.
- [ ] **Disconnect / reconnect:** disconnect revokes the token. Reconnecting works without
      re-registering.
- [ ] **Create:** a new enabled product with an image, price and brand appears in Merchant Center
      → Products within a few minutes.
- [ ] **Update:** change the price and title → one updated offer, no duplicate.
- [ ] **Sale price:** set a special price with dates → `salePrice` and its effective dates show
      in Merchant Center.
- [ ] **Stock:** sell the last unit → offer goes *Out of stock*. Refund it → back *In stock*.
- [ ] **SKU change:** the old offer is removed and the new one created.
- [ ] **Exclude / disable / delete:** the offer is deleted from Google.
- [ ] **Configurable product:** each variant is its own offer with an `itemGroupId`.
- [ ] **Validation:** a product without an image is *Invalid* locally and never sent.
- [ ] **Statuses:** after review, Diagnostics shows Google's status and issues for the test SKUs.
- [ ] **Policies:** *Push to Google* → shipping service and return policy visible in Merchant
      Center. Existing services for other countries are untouched.
- [ ] **Markets:** add a second market. The same SKUs appear under its feed label with the
      converted price. Pausing it stops updates. Removing it deletes its offers.
- [ ] **Reauth:** revoke the app at <https://myaccount.google.com/permissions> → status becomes
      *Needs reconnecting*, jobs pause, and one email is sent.
- [ ] **Google down:** with a wrong `GMC_API_BASE`, saving a product in the admin still succeeds.
      The sync shows as failed and retries later.

## 8. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| "Access blocked … Error 403: access_denied" at Google sign-in | Consent screen still in *Testing*: publish it, or add the account as a test user |
| Connection drops after a week | Consent screen in *Testing*: refresh tokens expire after 7 days. Publish it and reconnect |
| Overview: "Scheduler not running" | The cron above is missing or points to the wrong path |
| Products stuck in *Pending* | Queue not processed: scheduler runner without cron, or daemon not running |
| Offers disapproved: "Unclaimed website" | Claim the domain in Merchant Center (section 3) |
| Wrong prices in a non-base-currency market | Check the exchange rate under Settings → Exchange Rates, then run `gmc:sync --all` |

## 9. Tests

```bash
vendor/bin/pest packages/Custom/GoogleMerchant packages/Custom/GoogleCore
```

No test calls Google. Every request is faked with `Http::fake()` against recorded v1 fixtures in
`tests/Fixtures`. Verified API details are in [`API-NOTES.md`](API-NOTES.md).

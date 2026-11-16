# Custom/GoogleCore

Shared plumbing for every Google service connected to this store. It holds no
service-specific logic. Merchant Center lives in `packages/Custom/GoogleMerchant`.
Future services (GA4, Tag Manager, Ads, Search Console) each get a sibling package
of their own.

What this package provides:

| Piece | Class | Purpose |
|---|---|---|
| OAuth 2.0 (web flow) | `OAuth\GoogleOAuthClient` | `authUrl()`, `exchangeCode()`, `refresh()`, `revoke()`, `emailFromIdToken()` |
| Service account | `OAuth\ServiceAccountTokenFetcher` | `fetch(array $keyJson, array $scopes)`, a JWT-bearer token |
| Audit log | `Audit\AuditLogger` | `record($integration, $action, $context)`, stored in `google_audit_logs` |
| Integration registry | `Integrations\IntegrationRegistry` | Lists every connected Google service on the hub page |
| Hub page | `GET {admin}/google` (`admin.google.integrations`) | Marketing → Google, permission `marketing.google` |

## The rules for a sibling package

1. **One service = one package.** It gets its own namespace (`Custom\GoogleAnalytics`), its own
   service provider, its own tables (prefixed, e.g. `ga4_*`), its own settings, routes, ACL
   keys, menu entries and listeners. It never reads or writes another service's tables.
2. **Depend on GoogleCore, never on a sibling.** If two services need the same thing, move it here.
3. **Store your own tokens.** GoogleCore does not keep credentials. Encrypt refresh tokens and
   service-account JSON in your own table (the `encrypted` cast is enough), as `gmc_accounts` does.
4. **Audit through `AuditLogger`.** Use your service key as `$integration`. Keys whose name
   contains `token`, `secret`, `json`, `password` or `private_key` are stripped automatically,
   but pass only what an admin needs to see.
5. **Register with the hub** so the service shows up on Marketing → Google.

## Adding a service, step by step

### 1. Implement the contract

```php
namespace Custom\GoogleAnalytics;

use Custom\GoogleCore\Contracts\GoogleIntegration;

class AnalyticsIntegration implements GoogleIntegration
{
    public function key(): string { return 'ga4'; }                       // also your audit key
    public function label(): string { return trans('google_analytics::app.title'); }
    public function isConnected(): bool { /* read your own table */ }
    public function adminRoute(): ?string { return 'admin.google_analytics.index'; }
}
```

### 2. Register it from your provider's `boot()`

```php
$this->app->make(\Custom\GoogleCore\Integrations\IntegrationRegistry::class)
    ->register($this->app->make(AnalyticsIntegration::class));
```

Put the menu entry under `marketing.google.*` or next to `marketing.google_merchant`. Make sure
every route has an entry in your `acl.php`. Bagisto's Bouncer only checks routes listed there.

### 3. Reuse OAuth with your own scopes

The web flow is the same for every Google API. Only the scopes and the place you keep the
tokens change.

```php
$oauth = app(\Custom\GoogleCore\OAuth\GoogleOAuthClient::class);

// Start: put $state in the session and check it in the callback.
return redirect($oauth->authUrl($clientId, route('admin.google_analytics.oauth.callback'), [
    'https://www.googleapis.com/auth/analytics.readonly',
    'openid', 'email',
], $state));

// Callback
$tokens = $oauth->exchangeCode($clientId, $secret, $redirectUri, $request->query('code'));
// $tokens->refreshToken → store encrypted in YOUR table; $tokens->accessToken / expiresIn → cache

// Later
$fresh = $oauth->refresh($clientId, $secret, $refreshToken); // throws InvalidGrantException when revoked
```

Each service needs its own redirect URI in the Google Cloud OAuth client. It can share the
client ID and secret with Merchant Center (`GMC_CLIENT_ID` / `GMC_CLIENT_SECRET`), or use
its own env keys. Adding a scope to a published OAuth app can mean another Google
verification, so ask only for the scopes the service needs.

For server-to-server access, use the service-account flow:
`app(ServiceAccountTokenFetcher::class)->fetch($keyJson, $scopes)`.

## Storefront hooks a future GA4 / GTM package would use

These hooks are **not implemented** here. They are the documented attachment points, so a
tracking package never has to edit a theme file.

| Hook | Kind | Use |
|---|---|---|
| `bagisto.shop.layout.head.after` | view render event | Inject `gtag.js` or the GTM `<script>` into every storefront page's `<head>` |
| `bagisto.shop.layout.body.after` | view render event | The GTM `<noscript>` iframe |
| `checkout.order.save.after` | event (`$order`) | Server-side `purchase` event (GA4 Measurement Protocol, Ads offline conversion) |

Register a view render event from your provider:

```php
Event::listen('bagisto.shop.layout.head.after', fn ($viewRenderEventManager) =>
    $viewRenderEventManager->addTemplate('google_analytics::shop.gtag'));
```

Register the order event with `Event::listen('checkout.order.save.after', SendPurchase::class)`.
Queue the listener, because checkout must never wait on Google.

## Tests

`vendor/bin/pest packages/Custom/GoogleCore`

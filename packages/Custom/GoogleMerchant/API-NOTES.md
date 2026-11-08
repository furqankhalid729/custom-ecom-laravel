# Merchant API v1 — notes (checked 2026-11-08 against developers.google.com/merchant/api/reference/rest)

Base: `https://merchantapi.googleapis.com`. Scope: `https://www.googleapis.com/auth/content`.

| Call | Verb + path | Query | Body / response |
|---|---|---|---|
| List accounts | `GET /accounts/v1/accounts` | `pageSize` (≤500), `pageToken`, `filter` | → `{accounts[], nextPageToken}`; Account: `name` (`accounts/{id}`), `accountId`, `accountName` |
| Developer registration | `POST /accounts/v1/accounts/{id}/developerRegistration:registerGcp` | — | `{developerEmail}`; email must be a Google Account (NOT a service account). Account must be production with a verified website; caller directly added to the account. |
| List data sources | `GET /datasources/v1/accounts/{id}/dataSources` | `pageSize`, `pageToken` | → `{dataSources[], nextPageToken}` |
| Create data source | `POST /datasources/v1/accounts/{id}/dataSources` | — | `{displayName, primaryProductDataSource:{contentLanguage, feedLabel, countries[]}}` → DataSource (`name`, `dataSourceId`, `input`) |
| Insert product input | `POST /products/v1/accounts/{id}/productInputs:insert` | `dataSource` (required, API data source) | ProductInput `{offerId, contentLanguage, feedLabel, productAttributes{…}}`; same offerId+contentLanguage(+feedLabel) in the data source is replaced → idempotent |
| Patch product input | `PATCH /products/v1/{name}` | `dataSource`, `updateMask` | (Phase 2) |
| Delete product input | `DELETE /products/v1/accounts/{id}/productInputs/{productInput}` | `dataSource` (required) | empty |
| List products (status) | `GET /products/v1/accounts/{id}/products` | `pageSize` (≤1000), `pageToken` | → `{products[], nextPageToken}` (Phase 3 — verify productStatus fields then) |

ProductInput fields: `name`, `product` (output), `offerId`, `contentLanguage`, `feedLabel`, `productAttributes`, `customAttributes`, `versionNumber`, `legacyLocal`.
`{productInput}` id = `contentLanguage~feedLabel~offerId`, recommended as **unpadded base64url** (required if a part has `/`, `%`, `~`). Example: `en~US~sku/123` → `ZW5-VVN-c2t1LzEyMw`.

ProductAttributes used (exact JSON names): `title, description, link, imageLink, additionalImageLinks[], availability, price, salePrice, salePriceEffectiveDate, brand, gtins[], mpn, identifierExists, condition, googleProductCategory, productTypes[], color, size, gender, ageGroup, material, pattern, itemGroupId, shippingWeight{value, unit}, multipack (string int64), isBundle, customLabel0..customLabel4`.
Enums: availability `IN_STOCK|OUT_OF_STOCK|PREORDER|LIMITED_AVAILABILITY|BACKORDER`; condition `NEW|USED|REFURBISHED`; gender `MALE|FEMALE|UNISEX`; ageGroup `ADULT|KIDS|TODDLER|INFANT|NEWBORN`.
Price (shared type): `{amountMicros: string(int64), currencyCode}`. Interval: `{startTime, endTime}` RFC3339.

Errors: standard Google `{"error":{"code","message","status","details":[…]}}`; `BadRequest.fieldViolations[{field, description}]`.
Unregistered GCP project → 401 with message containing "not registered".
Product expiry: API products must be refreshed periodically (30 days historically) → plan resubmits at 25 days.
Insert/update/delete take several minutes to show in `products.list`.

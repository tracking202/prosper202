# v3 API routes served (1.9.81)

Every route `api/v3/index.php` serves on the 1.9.81 branch, read by the repository's own `tests/Api/V3/RouteInventory.php` (the reader `RoutesAreDocumentedTest` holds equal to `docs/openapi.yaml`). Path parameters are written `{}`. The page inventories in this folder cite routes from this list.

325 operations.
- `GET /`

## /ad-connections

- `GET /ad-connections`
- `POST /ad-connections`
- `DELETE /ad-connections/{}`
- `GET /ad-connections/{}`
- `PATCH /ad-connections/{}`
- `GET /ad-connections/{}/ad-groups`
- `GET /ad-connections/{}/ad-groups/{}`
- `POST /ad-connections/{}/ad-groups/{}/activate`
- `POST /ad-connections/{}/ad-groups/{}/pause`
- `POST /ad-connections/{}/ad-groups/{}/template`
- `GET /ad-connections/{}/ads`
- `GET /ad-connections/{}/ads/{}`
- `POST /ad-connections/{}/ads/{}/activate`
- `POST /ad-connections/{}/ads/{}/pause`
- `POST /ad-connections/{}/ads/{}/template`
- `GET /ad-connections/{}/campaigns`
- `GET /ad-connections/{}/campaigns/{}`
- `POST /ad-connections/{}/campaigns/{}/activate`
- `POST /ad-connections/{}/campaigns/{}/event-settings`
- `POST /ad-connections/{}/campaigns/{}/pause`
- `POST /ad-connections/{}/campaigns/{}/template`
- `POST /ad-connections/{}/check`
- `POST /ad-connections/{}/setup`
- `GET /ad-connections/{}/spend`

## /aff-networks

- `GET /aff-networks`
- `POST /aff-networks`
- `POST /aff-networks/bulk-upsert`
- `DELETE /aff-networks/{}`
- `GET /aff-networks/{}`
- `PUT /aff-networks/{}`
- `GET /aff-networks/{}/uses`

## /apps

- `GET /apps`
- `POST /apps`
- `GET /apps/installs`
- `POST /apps/installs`
- `POST /apps/installs/{}/events`
- `GET /apps/notifications`
- `GET /apps/postbacks`
- `GET /apps/postbacks/{}`
- `GET /apps/report`
- `GET /apps/schema`
- `GET /apps/skan-encodings`
- `POST /apps/skan-encodings`
- `DELETE /apps/skan-encodings/{}`
- `GET /apps/skan-encodings/{}`
- `PUT /apps/skan-encodings/{}`
- `POST /apps/verify`
- `DELETE /apps/{}`
- `GET /apps/{}`
- `PUT /apps/{}`
- `POST /apps/{}/app-token/rotate`
- `GET /apps/{}/install-token`
- `GET /apps/{}/installs`
- `GET /apps/{}/installs/{}`
- `GET /apps/{}/integrity`
- `DELETE /apps/{}/integrity-credential`
- `PUT /apps/{}/integrity-credential`
- `GET /apps/{}/store-link`

## /attribution

- `GET /attribution/conversions/{}/journey`
- `GET /attribution/exports`
- `POST /attribution/exports`
- `DELETE /attribution/exports/{}`
- `GET /attribution/exports/{}`
- `GET /attribution/exports/{}/download`
- `POST /attribution/exports/{}/retry`
- `GET /attribution/models`
- `POST /attribution/models`
- `DELETE /attribution/models/{}`
- `GET /attribution/models/{}`
- `PUT /attribution/models/{}`
- `GET /attribution/queue`
- `POST /attribution/queue/retry`
- `GET /attribution/reports/breakdown`
- `GET /attribution/reports/journeys`

## /audit

- `GET /audit/sync-jobs`
- `GET /audit/sync-jobs/{}`

## /campaigns

- `GET /campaigns`
- `POST /campaigns`
- `POST /campaigns/bulk-upsert`
- `DELETE /campaigns/{}`
- `GET /campaigns/{}`
- `PUT /campaigns/{}`
- `GET /campaigns/{}/uses`

## /capabilities

- `GET /capabilities`

## /changes

- `GET /changes/{}`

## /clicks

- `GET /clicks`
- `POST /clicks/cpc`
- `GET /clicks/{}`
- `GET /clicks/{}/conversions`
- `POST /clicks/{}/not-fraud`

## /connect

- `GET /connect`
- `POST /connect/revoke`
- `POST /connect/token`

## /conversions

- `GET /conversions`
- `POST /conversions`
- `GET /conversions/inbound-log`
- `POST /conversions/inbound-log/{}/record`
- `GET /conversions/match-methods`
- `GET /conversions/offline-export`
- `GET /conversions/offline-export/summary`
- `GET /conversions/postback-auth`
- `PUT /conversions/postback-auth`
- `GET /conversions/postback-auth/readiness`
- `POST /conversions/postback-auth/rotate`
- `GET /conversions/postback-code`
- `GET /conversions/shopify-refunds`
- `POST /conversions/shopify-refunds/{}/close`
- `POST /conversions/shopify-refunds/{}/record`
- `POST /conversions/subids`
- `POST /conversions/subids/delete`
- `POST /conversions/subids/reset`
- `GET /conversions/uploads`
- `POST /conversions/uploads`
- `POST /conversions/uploads/{}/undo`
- `DELETE /conversions/{}`
- `GET /conversions/{}`

## /destinations

- `GET /destinations`
- `POST /destinations`
- `DELETE /destinations/{}`
- `GET /destinations/{}`
- `PATCH /destinations/{}`
- `POST /destinations/{}/backfill`
- `GET /destinations/{}/deliveries`
- `POST /destinations/{}/deliveries/resend`
- `GET /destinations/{}/google-ads/accounts`
- `GET /destinations/{}/google-ads/conversion-actions`
- `POST /destinations/{}/google-ads/pick`
- `POST /destinations/{}/oauth/start`
- `POST /destinations/{}/test`

## /events

- `POST /events`

## /forecast-events

- `GET /forecast-events`
- `POST /forecast-events`
- `POST /forecast-events/bulk-upsert`
- `DELETE /forecast-events/{}`
- `GET /forecast-events/{}`
- `PUT /forecast-events/{}`

## /goals

- `GET /goals`
- `POST /goals`
- `POST /goals/evaluate`
- `POST /goals/validate`
- `DELETE /goals/{}`
- `GET /goals/{}`
- `PUT /goals/{}`
- `GET /goals/{}/campaigns`
- `DELETE /goals/{}/campaigns/{}`
- `PUT /goals/{}/campaigns/{}`
- `GET /goals/{}/outcomes`
- `GET /goals/{}/reevaluation`
- `POST /goals/{}/reevaluation`
- `GET /goals/{}/versions`
- `GET /goals/{}/versions/{}`

## /landing-pages

- `GET /landing-pages`
- `POST /landing-pages`
- `POST /landing-pages/bulk-upsert`
- `DELETE /landing-pages/{}`
- `GET /landing-pages/{}`
- `PUT /landing-pages/{}`
- `POST /landing-pages/{}/check`
- `GET /landing-pages/{}/code`
- `GET /landing-pages/{}/uses`

## /ltv

- `GET /ltv/abm`
- `GET /ltv/abm/company`
- `GET /ltv/breakdown`
- `GET /ltv/cohorts`
- `GET /ltv/companies`
- `POST /ltv/companies`
- `DELETE /ltv/companies/{}`
- `PATCH /ltv/companies/{}`
- `POST /ltv/companies/{}/merge`
- `GET /ltv/customers`
- `POST /ltv/customers`
- `DELETE /ltv/customers/{}`
- `GET /ltv/customers/{}`
- `PATCH /ltv/customers/{}`
- `POST /ltv/customers/{}/aliases`
- `DELETE /ltv/customers/{}/aliases/{}`
- `GET /ltv/customers/{}/engagement`
- `POST /ltv/customers/{}/merge`
- `GET /ltv/customers/{}/next-offer`
- `POST /ltv/customers/{}/next-offer/impression`
- `POST /ltv/events`
- `GET /ltv/fields`
- `POST /ltv/fields`
- `DELETE /ltv/fields/{}`
- `PATCH /ltv/fields/{}`
- `GET /ltv/integrations`
- `POST /ltv/integrations`
- `DELETE /ltv/integrations/{}`
- `GET /ltv/mrr`
- `GET /ltv/predict`
- `GET /ltv/products`
- `POST /ltv/products`
- `DELETE /ltv/products/{}`
- `PATCH /ltv/products/{}`
- `POST /ltv/revenue`
- `GET /ltv/subscriptions`
- `POST /ltv/subscriptions`
- `POST /ltv/subscriptions/{}/events`
- `GET /ltv/summary`
- `GET /ltv/webhooks`
- `POST /ltv/webhooks`
- `DELETE /ltv/webhooks/{}`
- `PUT /ltv/webhooks/{}`
- `GET /ltv/webhooks/{}/deliveries`
- `POST /ltv/webhooks/{}/rotate-secret`

## /me

- `GET /me`

## /offline-conversions

- `GET /offline-conversions/{}`

## /ppc-accounts

- `GET /ppc-accounts`
- `POST /ppc-accounts`
- `POST /ppc-accounts/bulk-upsert`
- `DELETE /ppc-accounts/{}`
- `GET /ppc-accounts/{}`
- `PUT /ppc-accounts/{}`
- `GET /ppc-accounts/{}/pixels`
- `POST /ppc-accounts/{}/pixels`
- `DELETE /ppc-accounts/{}/pixels/{}`
- `PUT /ppc-accounts/{}/pixels/{}`
- `GET /ppc-accounts/{}/uses`

## /ppc-networks

- `GET /ppc-networks`
- `POST /ppc-networks`
- `POST /ppc-networks/bulk-upsert`
- `POST /ppc-networks/chatgpt-ads`
- `DELETE /ppc-networks/{}`
- `GET /ppc-networks/{}`
- `PUT /ppc-networks/{}`
- `GET /ppc-networks/{}/uses`
- `GET /ppc-networks/{}/variables`
- `POST /ppc-networks/{}/variables`
- `DELETE /ppc-networks/{}/variables/{}`
- `PUT /ppc-networks/{}/variables/{}`

## /reports

- `GET /reports/breakdown`
- `GET /reports/daypart`
- `GET /reports/groups`
- `GET /reports/payout`
- `GET /reports/summary`
- `GET /reports/timeseries`
- `GET /reports/weekpart`

## /rotators

- `GET /rotators`
- `POST /rotators`
- `DELETE /rotators/{}`
- `GET /rotators/{}`
- `PUT /rotators/{}`
- `GET /rotators/{}/rules`
- `POST /rotators/{}/rules`
- `PUT /rotators/{}/rules`
- `DELETE /rotators/{}/rules/{}`
- `PUT /rotators/{}/rules/{}`
- `GET /rotators/{}/stats`

## /spend-ledger

- `GET /spend-ledger`
- `POST /spend-ledger`
- `GET /spend-ledger/conversions`
- `GET /spend-ledger/sync`
- `PUT /spend-ledger/sync/{}`
- `POST /spend-ledger/sync/{}/run`

## /staged-changes

- `GET /staged-changes`
- `GET /staged-changes/{}`
- `POST /staged-changes/{}/apply`
- `POST /staged-changes/{}/discard`

## /sync

- `GET /sync/history`
- `POST /sync/jobs`
- `GET /sync/jobs/{}`
- `POST /sync/jobs/{}/cancel`
- `GET /sync/jobs/{}/events`
- `POST /sync/jobs/{}/run`
- `POST /sync/plan`
- `POST /sync/re-sync`
- `GET /sync/status`
- `POST /sync/worker/run`

## /system

- `GET /system/cron`
- `GET /system/dataengine`
- `GET /system/db-stats`
- `GET /system/errors`
- `GET /system/health`
- `GET /system/info`
- `GET /system/integrations`
- `POST /system/integrations/record-held`
- `GET /system/isp-lookup`
- `PUT /system/isp-lookup`
- `GET /system/login-log`
- `GET /system/metrics`
- `GET /system/retention`
- `PUT /system/retention`
- `POST /system/retention/delete-before`
- `GET /system/traffic-source-owners`
- `POST /system/traffic-source-owners/repair`
- `POST /system/traffic-source-owners/split`
- `GET /system/version`

## /text-ads

- `GET /text-ads`
- `POST /text-ads`
- `POST /text-ads/bulk-upsert`
- `DELETE /text-ads/{}`
- `GET /text-ads/{}`
- `PUT /text-ads/{}`
- `GET /text-ads/{}/uses`

## /trackers

- `GET /trackers`
- `POST /trackers`
- `POST /trackers/bulk-upsert`
- `DELETE /trackers/{}`
- `GET /trackers/{}`
- `PUT /trackers/{}`
- `GET /trackers/{}/url`

## /users

- `GET /users`
- `POST /users`
- `GET /users/roles`
- `DELETE /users/{}`
- `GET /users/{}`
- `PUT /users/{}`
- `GET /users/{}/api-keys`
- `POST /users/{}/api-keys`
- `POST /users/{}/api-keys/replace`
- `DELETE /users/{}/api-keys/{}`
- `GET /users/{}/click-filter`
- `PUT /users/{}/click-filter`
- `POST /users/{}/click-filter/entries`
- `DELETE /users/{}/click-filter/entries/{}`
- `PUT /users/{}/click-filter/entries/{}`
- `GET /users/{}/click-filter/test`
- `GET /users/{}/identity-key`
- `POST /users/{}/identity-key/rotate`
- `GET /users/{}/preferences`
- `PUT /users/{}/preferences`
- `POST /users/{}/roles`
- `DELETE /users/{}/roles/{}`

## /versions

- `GET /versions`

# 03 — Architecture Decision Records

Concise ADRs for the load-bearing choices. Each records the decision, why, and the
rejected alternative.

---

## ADR-001: Separate edge/ingest, control, and analytics planes

**Decision.** Split the monolith along its three natural workloads (capture/redirect,
CRUD, OLAP) into independently deployable services.

**Why.** They have opposite performance profiles. Forcing latency-critical redirects,
transactional CRUD, and billion-row aggregation through one MySQL instance is the root
cause of the current pain. Separation lets each plane use the right runtime and scale
independently.

**Rejected.** Keep a single app and just optimize MySQL — caps out fast; the redirect hot
path and OLAP reporting actively fight each other for the same resources.

---

## ADR-002: ClickHouse for analytics *(high conviction)*

**Decision.** Store click/conversion events in ClickHouse; serve all `/reports/*` from it.

**Why.** Reporting is OLAP aggregation over append-only events across many dimensions —
the textbook columnar use case. The existing report shapes (summary, breakdown,
timeseries, daypart, weekpart) map directly and run orders of magnitude faster at scale.
ClickHouse is **self-hostable** (single binary), preserving data ownership, with managed
options (ClickHouse Cloud, Tinybird, CF Analytics Engine) for the cloud flavor.

**Rejected.** Stay on row-oriented MySQL with rollup tables — what exists today; rollups
are brittle, can't answer ad-hoc breakdowns cheaply, and the 2008-era reporting classes
prove the maintenance cost.

---

## ADR-003: Queue-based, fire-and-forget ingest

**Decision.** Redirects emit click events to a durable queue and return immediately; a Go
worker consumes, enriches, and batch-writes to ClickHouse.

**Why.** Removes the transactional DB from the hot path (today: synchronous inserts across
~8 tables), giving lower latency and resilience — clicks aren't lost if the analytics
store is briefly down. The stream is replayable, which also powers backfill/shadow
validation and real-time attribution. Portable default Redpanda/Kafka; CF Queues optional.

**Rejected.** Synchronous writes (today) — couples redirect latency and availability to DB
health, the exact fragility the current `dl.php` memcache-fallback hack works around.

---

## ADR-004: Postgres for control-plane metadata *(RLS + JSONB)*

**Decision.** Store transactional metadata (users, campaigns, trackers, networks,
rotators, attribution defs) in Postgres.

**Why.** Two concrete pulls: (1) **Row-Level Security** enforces multi-tenant `tenant_id`
isolation *in the database*, replacing today's fragile app-layer `WHERE user_id = ?`
filtering — the current top data-isolation risk; (2) **JSONB** (with GIN indexes and
constraints) cleanly models semi-structured rotator-rule, attribution-model, and
network-integration configs. Best-in-class Go migration tooling (Atlas, sqlc).

**Rejected — MySQL 8 (recorded, not chosen).** Would reuse the existing schema and 15
years of team ops knowledge with zero retraining, and MySQL 8 has *partial* substitutes
(definer views, CHECK constraints, JSON). Rejected because it lacks true RLS — the single
strongest reason for the switch — and tenant isolation is a day-one requirement.
*Note: this only governs low-volume metadata; analytics is ClickHouse regardless, so the
performance difference here is negligible. A team that weights retraining cost very high
could revisit this without affecting the rest of the design.*

---

## ADR-005: Go + TypeScript polyglot

**Decision.** Go for high-throughput tracking/ingest/API services; TypeScript for the edge
Worker variant and the React frontend.

**Why.** The team already ships a Go CLI (`go-cli/`), so Go is known. Go fits the
high-QPS, low-latency redirect and ingest workloads. A single TypeScript surface covers
both the optional Cloudflare Worker and the SPA, sharing types/validation with the API
contract.

**Rejected.** (a) Modernize PHP (Laravel/Symfony) — lowest retraining but keeps a runtime
poorly suited to the edge/high-concurrency hot path. (b) TypeScript end-to-end — simplest
mental model but worse fit than Go for the throughput-critical services and discards
existing Go investment.

---

## ADR-006: Keep the v3 API contract as the migration seam

**Decision.** Treat the existing v3 resource contract as the spec; reimplement behind it
in Go route-by-route rather than redesigning the API.

**Why.** It's recent, well-structured (scoped bearer auth, bulk-upsert, idempotency,
etags), and already consumed by the Go CLI and clients. Preserving it makes the rewrite
invisible to callers and enables incremental cutover.

**Rejected.** Design a new API alongside — doubles surface area, breaks the CLI/clients,
and discards good recent work.

---

## ADR-007: Hybrid/portable component model

**Decision.** Every cloud dependency sits behind a thin interface (`ConfigStore`,
`EventSink`, `AnalyticsWriter`, `BlobStore`) with a self-hostable OSS default and an
optional managed/edge implementation.

**Why.** Prosper202's identity is self-hosted data ownership. We want world-class
cloud/edge capability without forcing it. One build runs on a single Docker box *or* fans
out to Cloudflare/managed services.

**Rejected.** (a) Cloud-only — abandons the self-host user base and the product's core
value. (b) Self-host-only — forfeits edge latency and managed-scale options that define
"world-class" today.

---

## ADR-008: Argon2id + OIDC, scoped API keys retained

**Decision.** Hash passwords with Argon2id, add OIDC as a login option, and keep the
existing scoped-API-key model.

**Why.** Drops the still-accepted MD5 fallback (current risk) via rehash-on-login;
OIDC meets modern SSO expectations; the v3 scope model already works and clients depend on
it.

**Rejected.** Keep `password_hash` defaults with MD5 fallback — leaves weak hashes valid
indefinitely.

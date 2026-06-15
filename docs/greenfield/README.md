# Prosper202 — Greenfield Redesign

> *"What would we change if Prosper202 were built today, fully greenfield, to be world-class modern software?"*

This is a **design + migration roadmap**, not a rewrite that has happened. It describes
the target architecture (the "North Star") and an incremental, reversible path to get
there from today's PHP monolith — without ever breaking the live tracking links,
pixels, and postbacks that existing self-hosted installs depend on.

## How to read this

| Doc | What it covers |
|-----|----------------|
| [`01-architecture.md`](01-architecture.md) | The target architecture: the three workloads, the Go + TypeScript stack, the hybrid/portable component model, and data-flow diagrams. |
| [`02-migration-roadmap.md`](02-migration-roadmap.md) | The strangler-fig phases (0–5), what each one ships, and — critically — the transition for existing self-hosted installs. |
| [`03-decisions.md`](03-decisions.md) | ADR-style records justifying the load-bearing choices (ClickHouse, Postgres, Go+TS, queue-based ingest, multi-tenancy). |

## The one-paragraph version

An affiliate click-tracker is really **three workloads jammed into one MySQL monolith**:
a latency-critical **edge/ingest** plane (capture + redirect), a transactional
**control** plane (campaign/tracker CRUD), and an OLAP **analytics** plane
(reporting + attribution over billions of click events). The greenfield design
**separates them**: redirects run as a stateless **Go** service (with an optional
TypeScript Cloudflare Worker for global edge latency) that resolves config from a KV
cache and emits click events to a **durable queue** — never touching a transactional
DB on the hot path. A Go ingest worker enriches and batch-writes those events into
**ClickHouse** (columnar, the big reporting win). The control plane is a Go API that
keeps the **existing v3 contract** and stores metadata in **Postgres** (multi-tenant
row-level security). The UI becomes a **React/TypeScript** SPA. Every cloud component
has a self-hostable OSS default, so Prosper202's data-ownership ethos survives.

## What changes, at a glance

| Concern | Today | Greenfield |
|---------|-------|-----------|
| Redirect / click capture | `record_simple.php`, `record_adv.php`, `dl.php` — raw SQL string concat, synchronous multi-table MySQL inserts | Stateless Go `tracker-edge` (optional TS Worker), KV config lookup, emit to queue |
| Click storage / reporting | Row-oriented `202_clicks*` + 2008-era `class-dataengine.php` / `ReportBasicForm.class.php` | ClickHouse columnar store, real-time aggregation |
| Attribution | Hourly cron snapshots | In-stream, real-time |
| API | v3 (custom router, mysqli) — **good, kept as the contract** | Same contract, reimplemented in Go |
| Metadata DB | MySQL, generated `202-config.php`, ad-hoc SQL migrations | Postgres + RLS + JSONB, real migration tool |
| Frontend | PHP-rendered pages + jQuery + Bootstrap 3, no build | React/Next + TypeScript + Vite, real-time dashboards |
| Tenancy | Single-tenant, app-layer `WHERE user_id` | Multi-tenant, DB-enforced row-level security |
| Background work | File-locked HTTP cron | Durable queue + workers |
| Auth | `password_hash` w/ MD5 fallback still accepted | Argon2id + OIDC option; scoped API keys retained |
| Observability | error table + logs | OpenTelemetry traces/metrics/logs |

## Guiding principles

1. **The v3 API contract is the seam.** It's recent and well-designed; the Go CLI and
   clients already speak it. We swap implementations behind it route-by-route.
2. **The public URL contract is frozen.** Existing tracking links, pixels, and postbacks
   must keep working with zero user action.
3. **Hybrid but portable.** Cloud/edge-ready when available; self-hostable on a single
   Docker box always. No hard vendor lock-in.
4. **Strangler fig, never big-bang.** Each phase ships independently, runs in parallel
   with the old path, and is reversible.

# Architecture

This document describes the structure, data model, request flow, security model
and **extension points for the future**. The design aims for maximum operational simplicity
on shared PHP/MySQL hosting while keeping clean modularity for later extensions
(admin, work assignment over bounding boxes).

## Overview

```
 Browser: SvelteKit SPA + OpenLayers (static build)
        │  fetch JSON (GeoJSON), CSRF header
        ▼
 api.php  ── Router ──> Controllers ──> Services ──> Repositories ──> PDO ──> MySQL/MariaDB
        ▲                                   │
        └── login.php / logout.php          └── GeometryService (GeoJSON⇄WKT), FieldService (validation)
```

The frontend never talks to the DB directly; all logic lives on the server. The browser
sends and receives **GeoJSON**, the server stores **WKB** (native `GEOMETRY`).

## Backend layers

| File | Responsibility |
|------|----------------|
| `app/bootstrap.php` | Autoloader, configuration, session. |
| `app/Config.php` | Configuration with dot notation. |
| `app/Database.php` | Single PDO connection (prepared statements). |
| `app/Request.php` / `Response.php` / `Router.php` | API HTTP layer. |
| `app/Auth.php` / `Csrf.php` | Authentication, roles, CSRF, attempt limiting. |
| `app/GeometryService.php` | GeoJSON ⇄ WKT, validation of coordinates and shapes. |
| `app/FieldService.php` | Dynamic fields: definitions + attribute validation/normalization. |
| `app/MapLayerService.php` | Map layers: validation + CRUD (managed by the admin). |
| `app/UserService.php` | User accounts: validation + management (admin), password hashing, safeguards against removing the last admin. |
| `app/FeatureRepository.php` | CRUD on `features`, spatial queries. |
| `app/FeatureRevisionRepository.php` | Record version history: change snapshots, listing, basis for restore. |
| `app/AuditLog.php` | Lightweight audit (basis for an admin overview). |
| `app/controllers/*` | Thin controllers mapping a request → services. |

Each domain (auth, features, fields, layers) is a separate controller + service.
Adding a domain (e.g. `Assignments`) is purely additive – a new controller, service,
routes in `api.php`, tables in the DB.

## Data model

### `features` – the core

- `geom GEOMETRY NOT NULL` – native type, stored as **WKB** on disk, the most compact storage.
- **SRID 0**, WGS-84 coordinates (X = longitude, Y = latitude). SRID 0 behaves identically
  in MySQL and MariaDB and has no axis-order issues (unlike SRID 4326 in MySQL 8).
- **Deliberately without a `SPATIAL INDEX`**: the R-tree requires predicate locks, which InnoDB
  refuses under `READ UNCOMMITTED` (error 1207) – and shared hosting gives sessions exactly
  `READ UNCOMMITTED` and cannot even keep an explicit `SET SESSION` (the proxy swaps
  connections mid-request). Viewport queries (`MBRIntersects`) are evaluated
  row by row, which at realistic volumes (hundreds to thousands of records) takes a few
  ms and works under any isolation level. Details in `db/migrate_drop_spatial_index.sql`;
  if the data grows by orders of magnitude, the solution is bbox columns with a BTREE index, not an R-tree.
- `attributes JSON` – dynamic descriptive data (see below).
- `deleted_at` – **soft delete**, data is never physically deleted (important for a research project).
- `status` – prepared for a future approval workflow (`draft`/`submitted`/`reviewed`).

### Dynamic fields (`field_definitions` + JSON)

The field set is **not hard-coded**. The `field_definitions` table is a metadata registry:
key, label, data type, options, required flag, validation, restriction to geometry
type, order. From it:

1. the frontend generates the form (`components/DynamicForm.svelte`),
2. the server validates and normalizes attributes (`FieldService::validateAttributes`).

Values are stored in `features.attributes` (JSON). **Adding/changing a field =
INSERT/UPDATE in `field_definitions`, no schema migration.** Unknown keys are
dropped on save, so the model tolerates the field set evolving over time.

The administrator manages the field set through the UI (`FieldsManager`, account menu → "Metadata
management"): add/edit/remove (soft delete = `is_active`, can be restored) and
reorder by drag and drop (`sort_order`). The `nazev` (name) field is **protected** (`FieldService::PROTECTED_KEY`)
– always required and first, it cannot be edited or removed (also enforced on the server).

Why JSON + registry (and not EAV): simpler queries and writes, one row = one
feature, and still fully dynamic. For future reports, `JSON_EXTRACT` and indexed
generated columns can be used on the JSON column.

### Map layers (`map_layers`)

Layers are data in the `map_layers` table, fully managed by the administrator
(add/edit/delete from the account menu → "Manage layers"). Default layers are seeded
(`db/seed.sql`, or `db/migrate_map_layers.sql` on an existing DB) and the admin can
change them freely. `GET /api/layers` reads from the DB; if the table is empty/missing,
it falls back to `config/layers.php`, so the map is never left without a basemap.
Only **public** services are stored (no credentials); private WMS is still
handled via `config/layers.php` + `wms-proxy`.

## Request flow (example: saving a new geometry)

1. The user draws a shape (OpenLayers Draw) → the new record form opens.
2. `POST /api/features` `{ geometry: <GeoJSON>, geometry_type, attributes }`
   with the `X-CSRF-Token` header.
3. `api.php` verifies CSRF, router → `FeatureController::store`.
4. `GeometryService` validates the GeoJSON and converts it to WKT.
5. `FieldService` validates the attributes against `field_definitions`.
6. `FeatureRepository` inserts `ST_GeomFromText(?, 0)` + JSON attributes.
7. The saved feature is returned as a GeoJSON Feature → the frontend renders it.

## Coordinates and conversions

- Frontend/GeoJSON: `[lon, lat]` (EPSG:4326). OpenLayers uses EPSG:3857 internally; conversion is handled by `ol/format/GeoJSON` (dataProjection 4326 / featureProjection 3857).
- SQL: WKT `POINT(lon lat)` via `ST_GeomFromText`/`ST_AsText`.
- Storage: WKB (native `GEOMETRY`).

## Security model

- **Authentication**: PHP session, password via `password_hash` (bcrypt/argon2), session ID
  regeneration after login, `HttpOnly` + `SameSite=Lax` + (on HTTPS) `Secure` cookie.
- **Authorization**: roles `collector`/`admin`. A collector edits/deletes only their own records;
  the admin everything. Enforced on the server (`FeatureController::assertCanEdit`). Other users' records
  are shown in the frontend in a separate layer without editing interaction (read-only).
- **CSRF**: token in the session; pages via a hidden field, the API via the `X-CSRF-Token` header.
- **SQL injection**: prepared statements only (PDO, `emulate_prepares=false`).
- **XSS**: Svelte escapes content automatically; server-side PHP pages escape (`Page::e`),
  JSON with `JSON_HEX_*`. CSP: SvelteKit generates hashes for its own inline scripts,
  server-side pages use a CSP with nonce (`Page`).
- **Brute force**: `login_attempts` table + attempt limit per user/IP.
- **WMS proxy**: forwards only to URLs from the configuration (no open proxy), key
  parameters (`LAYERS`/`FORMAT`/`VERSION`) are enforced by the server, credentials
  for a private WMS never leave the server.
- **Mobile blocking**: collecting is desktop-only (checks for missing `any-pointer: fine`/`any-hover: hover`
  and presence of `pointer: coarse` + `hover: none`; window width is only a soft warning).

## Frontend (SvelteKit + TypeScript + OpenLayers)

A static SPA (adapter-static), built locally (`npm run build`) and uploaded to the
web root next to the PHP API. It communicates with the backend via `fetch` (`/api/*`).

| Module | Role |
|--------|------|
| `routes/+page.svelte` | State orchestration, wiring of map and panel, panel tiles (Layers / Add record / Records), mobile blocking. |
| `components/MapView.svelte` | OpenLayers: WMS/XYZ/WMTS layers in a single user-defined order (drawn bottom-up), tile-loading tracking for the panel indicator, drawing (Draw) and smooth editing (Modify); `focusFeature` centers on a feature. |
| `components/TopBar.svelte` | Header: logo, title, login chip, logout. |
| `components/LayersCard.svelte` | A single list of all layers: toggling, opacity slider and local drag-and-drop ordering (first = bottom, acting as the basemap; no special "basemap" kind); tile-loading indicator (spinner only while downloading; other states cannot be truthfully detected client-side, see LayerStatus in types.ts). State is remembered in localStorage. |
| `components/NewRecordCard.svelte` | New record: type → drawing → form. |
| `components/RecordsCard.svelte` | List of records in the current viewport (newest first); "mine only" filter, pagination, GeoJSON export; clicking centers the map and opens the detail. |
| `components/DetailCard.svelte` | Detail of an existing feature (attribute editing, deletion); "takes over" the panel when selected. |
| `components/LayersManager.svelte` | Admin modal (from the account menu): map layer management – add / edit / delete. |
| `components/FieldsManager.svelte` | Admin modal (from the account menu): metadata field management – add / edit / remove / drag to reorder. The `nazev` field is locked. |
| `components/UsersManager.svelte` | Admin modal (from the account menu): account management – create / edit role, password, activation. |
| `components/HistoryManager.svelte` | Admin modal: change history (overview + record revisions), **git-style attribute diff** (− old / + new value per key) and restore / undelete. |
| `components/GeometryDiffMap.svelte` | Mini-map in the history: geometry *before* (grey) and *after* (colored) for a visual comparison of shape/position changes. |
| `components/DynamicForm.svelte` | Form generated from field definitions. |
| `components/ui/*` | Shared elements (Button, Card, Note). |
| `lib/api.ts` | Typed REST API client (CSRF, session expiry). |
| `lib/types.ts` | Shared TypeScript types matching the API. |
| `lib/feature.ts` | Helper functions for a feature (name, last change time, relative time). |
| `lib/i18n.ts` | UI texts and help (Czech / English: `i18n.cs.ts`, `i18n.en.ts`). |

Shapes can be edited after completion: the `Modify` interaction allows dragging vertices,
adding a vertex on an edge and removing it (Alt). The geometry is saved automatically
when the edit finishes (`modifyend` event → `PUT /api/features/{id}`).

## API – overview

| Method | Path | Description | Access |
|--------|------|-------------|--------|
| GET | `/api/me` | Current user + CSRF | logged in |
| GET/POST | `/api/users` | List / create accounts | **admin** |
| PUT | `/api/users/{id}` | Edit account (role, activation, password) | **admin** |
| GET | `/api/layers` | Layer list (from DB, config fallback) | logged in |
| GET | `/api/wms-proxy` | Tile proxy for a private WMS | logged in |
| GET | `/api/map-layers` | Layers for management | **admin** |
| POST/PUT/DELETE | `/api/map-layers[/{id}]` | Layer management | **admin** |
| GET | `/api/fields` | Active field definitions (`?all=1` = all, admin) | logged in |
| POST/PUT/DELETE | `/api/fields[/{id}]` | Definition management | **admin** |
| GET | `/api/features` | List (`?bbox=&type=&mine=1&limit=`) | logged in |
| POST | `/api/features/export` | Export selected records (`{ids}`) to GeoJSON | logged in |
| GET | `/api/features/{id}/revisions` | Record version history (incl. geometry + attributes for diff) | **admin** |
| POST | `/api/features/{id}/restore` | Restore to a revision (incl. undelete) | **admin** |
| GET | `/api/changes` | Recent changes across records (audit) | **admin** |
| GET | `/api/features/{id}` | Detail | logged in |
| POST | `/api/features` | Create | logged in |
| PUT | `/api/features/{id}` | Update | author/admin |
| DELETE | `/api/features/{id}` | Delete (soft) | author/admin |

## Extension points

1. **Admin module** – the `admin` role and field write endpoints already exist. It is enough
   to add a user and field management screen (new controller/UI). `AuditLog` provides history.
2. **Work assignment over bounding boxes** – a draft of the `work_areas` and
   `assignments` tables is in `db/future_extensions.sql`. Features in an assigned area can be
   counted/filtered via `MBRContains` (row by row – a spatial index cannot be used on shared
   hosting, see `features` above; at large volumes use bbox columns + BTREE).
   The `GET /api/features?bbox=` endpoint already filters spatially.
3. **Approval workflow** – the `features.status` column is ready.
4. **Geometry versioning** – ✅ done: the `feature_revisions` table stores a snapshot
   (geometry + attributes) on every change; in "Change history" the admin can view
   revisions and revert a record (including restoring a deleted one). History is never deleted.
5. **Multiple projects/collection layers** – add `project_id` to `features` and a filter in the API.

None of these extensions requires changing existing behavior – only adding code.

# Moovit — server-side ideas

Discussion doc (not tickets yet) — companion to `ideas.md`, which covers the
Android app. Each idea lists the why, the shape, and a rough size (S/M/L).
Everything here is phrased against the current API contract (plan.md §2:
`api/item`, `api/status`, `api/location`, `api/labels`, `api/authenticate`)
as consumed by the Android client — several of these come from friction
observed while building the local-first sync layer (MV-014) and the scan
features (MV-049/MV-050). If one is picked, it gets a proper ticket before
implementation (client + server).

> **Status (2026-09):** ideas are being turned into `tickets/API-0XX-*` tickets
> and implemented. The current API contract is pinned by feature tests
> (`tests/Feature/*ApiTest.php`, SQLite in-memory) — any contract change must
> update those tests consciously. Host constraint: **PATCH routes are not
> supported** (load balancer); new write endpoints use POST.

---

## 1. `updated_at` on every list payload *(smallest, do first)*

> **Verified (2026-09, API-002)** — the idea is stale against this codebase: all list
> payloads (`api/item`, `api/status`, `api/location`, `api/labels`) already return full
> models including `updated_at`/`created_at` in ISO-8601 UTC. Now pinned by
> `tests/Feature/UpdatedAtContractTest.php` (lists + single item + history `changed_at`),
> so the fields cannot silently regress. Client follow-ups (MV-047/MV-044) can rely on
> it once a server release is deployed.

**Why** — `GET api/item` returns items *without* `updated_at` (only
`GET api/item/:id` carries it). The Android cache therefore cannot order by
recency (the dashboard's "recently updated" works off partial data, MV-047)
and the CSV export leaves the column blank unless the item was fetched
individually. One field on one response fixes three client behaviors.

**Shape** — add `updated_at` (ISO-8601, same format as the single-item
response) to every row of `GET api/item`. Touches one serializer; existing
web SPA ignores extra fields.

**Size** S.

---

## 2. Delta sync: `?since=` + deletions feed

> **Implemented (2026-09, API-006)** — `GET api/item?since=<ISO-8601>` returns
> `{"changed": [...], "deleted_ids": [...]}`: full rows (same shape as the plain
> list) with `updated_at > since` (creations included, tombstones excluded — a
> deleted box only ever surfaces in `deleted_ids`), plus the team-scoped ids
> soft-deleted after `since`. Without `since` the plain array is unchanged;
> `X-Revision` rides on both shapes; garbage `since` → 422. Timestamps have
> second precision — pair with the API-003 counter and re-pull when it moves.
> Pinned by `tests/Feature/ItemDeltaSyncTest.php`. Status/location/labels delta
> stays out of scope (tiny tables).

> **Prerequisite done (2026-09, API-005)** — items are now **soft-deleted**
> (`items.deleted_at` + index, `SoftDeletes` on the model). Deletions survive as
> tombstone rows while every existing query/route behaves as before. Pinned by
> `tests/Feature/ItemSoftDeleteTest.php`. Interim policy (superseded by API-008):
> children of a deleted box briefly kept their `parent_id` — **API-008 has since
> decided: children are detached to root**.

**Why** — every client refresh downloads the *entire* items table. With a
handful of PDAs syncing on MV-014's background schedule (plus the web SPA),
each poll is a full payload and a full Room clobber. Deltas make sync cheap,
faster on warehouse Wi-Fi, and reduce battery.

**Shape**
- `GET api/item?since=<timestamp>` → `{ changed: [...], deleted_ids: [...] }`
  (or keep the plain array when `since` is absent — backward compatible).
- Same for `api/status` / `api/location` / `api/labels` (tiny tables; lower
  priority).
- Requires soft deletes (or a tombstone table) so deletions survive as ids.
- Client keeps its Room cache and applies the diff — no more wipe-and-refill
  (the `replaceAll` transaction disappears over time).

**Size** M (server side needs soft deletes; client has a follow-up ticket).

---

## 3. Bulk operations endpoint

> **Implemented (2026-09, API-007)** — `POST api/item/bulk-move`
> `{ ids: [], parent_id|null }` and `POST api/item/bulk-assign`
> `{ ids: [], status_id, location_id }` (1–500 ids). Response:
> `{"results": [{id, ok, updated_at?|error?}, ...]}` in request order —
> `not_found` / `foreign_team` / `cycle` per failing row, HTTP 200 even with
> partial failures. One transaction, one history row per actually-changed
> field, one revision bump per request per affected team. Batch rule: a
> `parent_id` inside `ids` fails the whole batch as `cycle` (nothing
> applied); trashed parent → 404. Pinned by `tests/Feature/ItemBulkTest.php`.

**Why** — the Transport flow assigns status/location by PATCHing items one
at a time; an MV-049 scan session queues one `MOVE` per scanned box. The
sync engine replays them as N requests. A 50-item transport is 50 PATCHes
(and 50 history rows) where one request would do — flaky warehouse Wi-Fi
turns that into 50 chances to fail.

**Shape**
- `POST api/item/bulk-move` — `{ ids: [], parent_id }`.
- `POST api/item/bulk-assign` — `{ ids: [], status_id, location_id }` (the
  Transport payload).
- Response: per-id result (`[{ id, ok, error? }]`) so partial failures can be
  reported per row, matching the app's per-row session model.
- History: one change row per item (keep granularity) but created in one
  transaction.

**Size** M.

---

## 4. Sparse PATCH / intent-based verbs (anti-clobber)

> **Implemented (2026-09, API-004)** — intent verbs shipped as **POST** (the host does
> not support PATCH): `POST api/item/{item}/move {parent_id|null}`, `POST
> api/item/{item}/assign {status_id, location_id}`, `POST api/item/{item}/rename
> {name}`. Each runs in a transaction, touches exactly its field(s) and records exactly
> its history rows; `move` rejects cycles (422) and cross-team parents (404). Pinned by
> `tests/Feature/ItemIntentVerbsTest.php`. Note: the legacy `PATCH api/item/:id` is
> *already sparse* (only sent fields applied, history only for real changes) — pinned by
> `ItemApiTest` — so both paths are safe against sibling clobbering. `If-Match`
> preconditions remain future work.

**Why** — `PATCH api/item/:id` semantics are "the full item object with the
modified field(s)". Two clients editing the same item concurrently
last-write-wins the *whole object*: a rename from the web can silently undo
a move made from a PDA (and MV-014's queued replays make the window much
wider than a normal SPA).

**Shape** (either, or both)
- Truly sparse PATCH: only the sent fields are applied
  (`{ "parent_id": "b2" }` moves without touching `name`/`status_id`).
  Requires dropping the "send the whole object" contract — coordinate with
  the web SPA.
- Intent verbs that never clobber siblings:
  `POST api/item/:id/move { parent_id }`, `POST api/item/:id/assign
  { status_id, location_id }`, `PATCH api/item/:id/rename { name }`. Each
  touches exactly its field and records exactly its history row.
- Optional precondition for true conflict detection:
  `If-Match: <updated_at>` → `409 Conflict` when stale (needs idea 1).

**Size** M (L if combined with the ops-queue conflict rework client-side).

---

## 5. Deletion semantics for non-empty boxes

> **Decided + implemented (2026-09, API-008)** — the recommended option shipped:
> deleting a box **re-parents its direct children to root** and reports them in the
> additive response field `{"success": "success", "detached_ids": [...]}`. One
> history row per detached child (`parent_id: <box id> → null`); grandchildren keep
> their own parents; everything in one transaction (mid-failure rolls back the
> whole delete). Detached children's `updated_at` moves, so API-006 deltas report
> them as `changed` while the box lands in `deleted_ids`. Pinned by
> `tests/Feature/ItemDeletionPolicyTest.php`.

**Why** — `DELETE api/item/:id` on a box that still has children: today the
client never learns the policy (the Transfer flow simply hides the item on
success). Delete-or-cascade is exactly the kind of thing a warehouse
discovers the hard way.

**Shape**
- Decide + document: deleting a box re-parents children to `null` (roots) —
  recommended — or refuses.
- Enforce server-side: either cascade-and-return the moved child ids
  (`{ detached_ids: [...] }`) or `409` with the child count until
  `?detatch_children=1` is passed.
- One history row per detached child ("removed from deleted box X").

**Size** S–M (mostly a decision + a transaction).

---

## 6. Team-level activity feed

> **Implemented (2026-09, API-009)** — `GET api/activity`: the same change rows as
> item history, newest first (`changed_at` desc, id tiebreaker), paginated (default
> 50, max 200). Filters: `since`, `item_id`, `type` (= `field_name`), and
> `location_id` (= moves *into* that location:
> `field_name='location_id' AND new_value=<id>`). Row shape:
> `{id, item_id, item_name, user_id, user_name, field_name, old_value, new_value,
> changed_at}` — `*_id` values stay raw UUIDs (client resolves names, same as item
> history). Team-scoped through the owning item (trashed items' histories excluded,
> consistent with the item-history 404). `histories.changed_at` now indexed. Pinned
> by `tests/Feature/TeamActivityFeedTest.php`.

**Why** — history exists only per item (`api/item/:id/history`). Everything
the Android app added recently — timeline upgrade (MV-048), dashboards
(MV-047), location audit (MV-050) — has to derive "what happened in this
team/location" from the full items table, which is inference, not fact.

**Shape**
- `GET api/team/activity?since=&item_id=&location_id=&type=` → the same
  change rows as item history, plus the `item_id` they belong to, newest
  first, paginated.
- Powers: a real team timeline, "recent moves in location X", and
  post-audit traces.
- Item history can then be a filtered view of the same table.

**Size** M.

---

## 7. Audit/stocktake acceptance endpoint

> **Implemented (2026-09, API-010)** — `POST api/location/:id/audits` stores the
> exact MV-050 report (`{found_ids, extra, unknown_codes}`) plus `missing_count`
> (declared client input); `found_count`/`extra_count` are computed server-side.
> Referenced ids are validated for team membership only — snapshot semantics
> (trashed rows accepted, current location not checked). `GET
> api/location/:id/audits` lists a location's audits newest-first, paginated.
> Audits appear in `GET api/activity` as one synthetic row per audit with the
> same keys as history rows and `field_name: "audit"`, summary in `new_value`
> ("3 found, 2 missing declared, 1 extra"), `location_id`/`location_name` —
> filterable with `type=audit`, `location_id`, `since`. Each audit bumps the
> team revision once (API-003). Client follow-up: the MV-050 "submit" button.

**Why** — MV-050 produces a found/missing/extra report *locally*; the server
never learns an audit happened. A stocktake that isn't recorded is an audit
that can't be traced, compared, or repeated.

**Shape**
- `POST api/location/:id/audits` — `{ found_ids: [], extra: [{ id }],
  unknown_codes: [] }` → stores a snapshot + result, emits one activity row
  ("audit of Location X: 42/45 found").
- `GET api/location/:id/audits` — history of audits (client can diff two
  audits; web can show the same).
- The Android audit screen gains a "submit" action (MV-050 follow-up) — the
  report is already structured for exactly this payload.

**Size** M.

---

## 8. Barcode registry separate from item ids

> **Implemented (2026-09, API-011)** — `item_barcodes` table (`item_id`, `code`,
> `type?`, unique `(team_id, code)` enforced at the DB level). `POST
> api/item/:id/barcodes` `{code, type?}` attaches (409 on duplicate within the
> team — including codes held by soft-deleted items: a trashed item's codes
> stay reserved until hard delete); `DELETE api/item/:id/barcodes/{barcode}`
> detaches by row id, by code in the path, or `?code=`. Codes are stored
> verbatim (trimmed, case-sensitive). Item `show()` payloads carry a
> `barcodes` array; index/delta payloads stay lean (documented — clients
> re-pull an item to see its codes). Registry writes bump the team revision
> but not the item's `updated_at`. **The client-side resolution contract
> stands**: `ScanCodeResolver` gains the barcode index; item ids remain the
> primary scan target (MV-027 unchanged).

**Why** — scans resolve by *raw item id* today (MV-027), which works only
because the team's stickers embed the ids. Real EAN-128/Code128 carrier
labels can't be adopted, and there is no uniqueness validation if a sticker
collides with another item's id.

**Shape**
- Add `barcodes: [string]` (or a `code` + type) to items; uniqueness scoped
  to the team, validated server-side.
- Resolution stays client-side (`ScanCodeResolver` gains a barcode index);
  no new endpoint needed beyond the field on item payloads.
- Optional later: `GET api/item?barcode=<code>` for server-side resolution.

**Size** S–M.

---

## 9. Cheap change detection (revision counter / ETag)

> **Implemented (2026-09, API-003)** — `GET api/revision` → `{"revision": n}` and an
> `X-Revision` header on `GET api/item`. The per-team counter (`teams.revision`) is
> bumped atomically on every team-scoped write — items/statuses/locations/labels CRUD
> (observers) and label attach/detach (explicit, pivot writes fire no model events).
> No-op writes (nothing dirty) do not bump. Pinned by `tests/Feature/RevisionApiTest.php`.

**Why** — the sync icon polls the full list to learn "did anything change?"
(§4.14). Even with idea 2, the client still asks a question the server could
answer in one integer.

**Shape**
- `GET api/revision` (or an `ETag`/`X-Revision` header on every GET) — a
  per-team counter bumped on any write.
- The background worker polls the counter (a few bytes) and only pulls
  deltas when it moved; the sync dot stops spinning on no-op polls.
- Upgrade path: the same counter feeds FCM/websocket push later, replacing
  polling entirely.

**Size** S (the counter) + client follow-up.

---

## 10. Device tokens for the PDA fleet

> **Implemented (2026-09, API-012)** — `POST api/device-tokens` `{name}` mints a
> Sanctum token named after the device with a restricted ability set (see table
> below); the plain text token is returned exactly once, whole (no `id|token`
> split like the legacy `api/authenticate`). `GET api/device-tokens` lists the
> user's fleet metadata-only (`id, name, last_used_at, created_at` — Sanctum
> stamps `last_used_at` on every use). `DELETE api/device-tokens/{id}` revokes;
> a revoked token gets 401 on next use, the password keeps working. Any valid
> token (device tokens included) may mint/revoke siblings — acceptable for a
> single-warehouse team; revisit with API-017's enrollment codes.
>
> | ability | granted |
> |---|---|
> | item:read / item:write | ✓ |
> | status:read / status:write | ✓ |
> | location:read / location:write | ✓ |
> | label:read / label:write | ✓ |
> | account/settings abilities | ✗ |
>
> **Enrollment codes (2026-09, API-017)** — the password-free enrollment path:
> `POST api/enrollment-codes` (authenticated, any valid token) mints a one-time
> code for the calling user — 8 chars from an unambiguous alphabet (no
> 0/O/1/I/L, hand-typeable when scanning fails), lives 15 minutes, single-use
> (`used_at` + `used_by_token_id` claimed by a guarded conditional update, so
> concurrent redeemers cannot both win); the issuer's stale (used or expired)
> codes are pruned at mint time — no scheduler. `POST api/enroll`
> (**unauthenticated**, next to register/authenticate — the code IS the proof)
> `{code, name}` exchanges it for a device token belonging to the code's
> issuer with exactly the ability table above → `{id, name, token}` 201 (same
> shape as `POST api/device-tokens`). Errors: unknown or expired code → 422
> with a `code` error; already-used code → 410 Gone.
>
> Client follow-up: the scanner-login QR carries the enrollment code instead
> of the base64 `email:password`.

**Why** — login on PDAs uses the human's email/password (and the web's
scanner-login QR is base64 `email:password` — plan.md §1). Every PDA stores
the owner's real credentials; losing a device means a password rotate across
the fleet.

**Shape**
- `POST api/device-tokens` — exchange credentials for a named, revocable
  device token (scopes: items/team read-write, no settings delete, no
  password change).
- `GET/DELETE api/device-tokens` — the profile screen lists and revokes
  devices ("Bluebird #3, last seen 2 h ago").
- The app's scanner-login QR can then carry a one-time enrollment code
  instead of the password.

**Size** M.

---

## 11. Image attachments on items

> **Implemented (2026-09, API-013)** — `attachments` table (uuid, item FK
> cascade, team, uploader, `disk` default `local`, unique `path`,
> `original_name`, `mime_type`, `size`, `caption?`). Multipart `POST
> api/item/:id/attachments` (`file`: jpeg/png/webp/heic ≤ 10 MB, mime sniffed
> from contents; optional `caption`) stores under
> `attachments/{team_id}/{item_id}/{uuid}.{ext}` and returns the metadata (201).
> `GET api/item/:id/attachments` lists metadata newest-first; `GET
> api/attachment/:id` streams the binary (authenticated, stored mime); `DELETE
> api/attachment/:id` removes file + row (`{"success": "success"}`). Every
> payload shape flows through `Attachment::metadata()` — `{id, original_name,
> mime_type, size, caption, user_id, url, created_at}` — **filesystem paths
> never leave the server**; `url` requires a token, it is not a public link.
> Item `show()` gains an additive `attachments` metadata array (old clients
> ignore it); the list/delta payloads stay lean. Writes bump the team revision
> (API-003) without touching the item row. Authorization: `item:write` on the
> owning item's team for upload/delete, `item:read` for reads (device tokens
> from API-012 qualify). Client follow-up: the Android capture/upload UI.

---

## 12. Cross-team item transfer

> **Implemented (2026-09, API-016)** — `POST api/item/:id/transfer`
> (`{team_id, location_id?, status_id?}`) moves an item — and its **whole
> subtree** (children, grandchildren…; a parent link never crosses teams, so
> nothing living is left behind) — to another team, with **`item:write` on
> both sides** (source = the item's team, destination = explicit membership;
> team permission **and** token ability; read-only members and narrow tokens
> are 403).
>
> Semantics: `team_id` must exist and differ from the source (422); optional
> `location_id`/`status_id` belong to the **root only**, must belong to the
> destination team (422 otherwise) and are applied in the same transaction as
> the move; when omitted the current values carry over (a follow-up
> bulk-assign can re-point them) — descendants always keep their
> location/status. The root is detached from its source-team parent to root
> (one history row, API-008 semantics; reported as `detached_parent`);
> intra-subtree parent links are preserved. Trashed descendants are
> tombstones — they stay behind.
>
> Side effects, all in one transaction (any failure rolls back everything):
> one `team_id` history row per transferred item (+ the root's parent detach
> and location/status rows); source-team labels detached from every moved
> item and reported as `detached_label_ids` (destination labels are never
> auto-attached); barcodes and attachments follow their item (`team_id`
> rewritten); team revision bumped on **both** teams (API-003) — the
> destination's delta feed carries all moved rows as `changed`, the source's
> never does (the rows left its scope; its bumped revision sends clients
> re-pulling). Per-team barcode-registry uniqueness (API-011) is enforced
> across the whole subtree first: any code already held by the destination
> team on an item outside the moved subtree refuses the transfer with 409
> and **nothing changes**.
>
> Response: the root item in the `show()` shape plus additive metadata
> `detached_label_ids: [...]` and `detached_parent: bool`.

---

## 13. Savepoint backups

> **Implemented (2026-09, API-018)** — `POST api/backups` snapshots the
> effective team (API-015; team-level, not item-addressed) into one zip on
> the `local` disk under `backups/{team_id}/{backup_id}.zip`: a CSV per
> entity — `items.csv` (all columns, header line, **soft-deleted tombstones
> included** with their `deleted_at` — a savepoint is a full snapshot),
> `locations.csv`, `statuses.csv`, `labels.csv`. The `backups` row records
> the creator (`user_id` — "this backup belongs to that user", provenance
> not an ACL) and per-entity counts. `GET api/backups` lists the team's
> metadata newest-first (`{id, user_id, size, item_count, location_count,
> status_count, label_count, url, created_at}` — every shape flows through
> `Backup::metadata()`, filesystem paths never leave the server); `GET
> api/backup/:id` streams the zip (`application/zip`, filename
> `backup-{team}-{timestamp}.zip`); `DELETE api/backup/:id` removes row +
> file (`{"success": "success"}`). Authorization: `item:write` for
> create/delete, `item:read` for list/download — team permission **and**
> token ability (resource-addressed verbs check the backup's own team).
> Retention: after each create only the **last 7 backups of the team** are
> kept — older rows deleted with their files (a manual DELETE frees a
> slot). Create and delete bump the team revision once each (API-003).
>
> **Comparison (2026-09, API-019)** — `GET api/backup/:id/compare/:other`
> diffs two savepoints of the same team (`item:read`; cross-team pair or
> stranger → 403; unknown id → 404): `:id` is the base, `:other` the target.
> Flat response: `{"generated_at", "items", "locations", "statuses",
> "labels"}` where each type carries `added`/`removed` (full parsed CSV
> rows, identity = row id), `changed` (only rows with ≥1 field difference,
> as `{id, diff: {field: {from, to}}}`) and `counts` `{added, removed,
> unchanged, changed}`. Fields compare as trimmed strings, null ≡ empty.
> Note: dumps include soft-deleted tombstones, so a soft-delete between
> backups surfaces as `changed` on `deleted_at` — only rows that truly
> left the table are `removed`. The inverse direction reports the inverse
> sets.
>
> **Auto-backup schedules (2026-09, API-020)** — one schedule per team
> (`backup_schedules`, unique `team_id`): `frequency` daily/weekly/monthly/
> yearly, `enabled` (default true), `user_id` = configurator (provenance),
> `last_run_at`/`next_run_at`. Configured from the **server UI** (web,
> session-auth + verified): `GET/POST/DELETE /teams/{team}/backups/schedule`
> (POST upserts — no PATCH on this host; `item:read` for the page,
> `item:write` to save/delete; membership + Jetstream permission + tokenCan,
> TransientToken covers session auth). Saving with `enabled` computes
> `next_run_at` from **now at save time**; disabling clears it. The
> `moovit:auto-backups` command (scheduled every 5 min,
> `withoutOverlapping()`) processes due schedules (`enabled` +
> `next_run_at <= now`) ordered by `next_run_at`: one savepoint per schedule
> through the same `BackupService::createForTeam` as the manual API
> (retention included), attributed to the schedule's `user_id`, then
> `advance()` stamps `last_run_at` and recomputes `next_run_at` from the
> run time. One DB transaction per schedule — a failing team is reported
> and left unadvanced (retried next tick) without blocking the others.
> Month edges follow Carbon's default `addMonth()` **overflow** (Jan 31
> monthly → Mar 3, not Feb 28) — pinned by tests.
>
> **S3 offload (2026-09, API-021)** — per-team S3 credentials in
> `team_s3_configs` (uuid, unique `team_id`, `secret_key` encrypted at rest
> via the `encrypted` cast and never serialized; `prefix` defaults to
> `backups/{team_id}`). `POST/GET/DELETE api/team/s3` upsert-read-drop the
> config (`item:write`); the POST probes the payload with a real HeadBucket
> before persisting — dead credentials → 422 on `bucket` with the SDK
> message, nothing stored. On create, `BackupService` copies the zip to the
> bucket after the local put (one attempt; a failed copy → 502 with no row
> and no local file; success records `backups.remote = true` — a row-level
> flag, `metadata()` unchanged so the API-018 contract stays additive).
> `GET api/backups/bucket` lists the team's bucket objects newest-first
> (key/size/last_modified; `item:read`; 422 when unconfigured, 502 upstream).
> `GET api/team/s3/rules` computes the applicable retention: `local_retention:
> 7` always, `s3_configured`, `s3_retention: "per bucket lifecycle"|null`,
> `lifecycle` from the bucket when the store answers (`null` when not —
> not an error). Rotation stays local-only: local copies rotate at 7 with
> or without S3; bucket copies are never pruned (the lifecycle is the
> user's domain). The SDK never leaks: `S3BackupClientFactory::forConfig`
> builds an `S3BackupClient` (headBucket/put/listObjects/getLifecycle) from
> the DB row per call — the raw `Aws\S3\S3Client` is used (flysystem has no
> lifecycle API).

---

## Suggested order

1. **Idea 1** (`updated_at` on lists) — one field, unblocks three client
   behaviors.
2. **Idea 9** (revision counter) — tiny, stops the sync pipeline from
   hammering the full list.
3. **Idea 4** (sparse PATCH / intent verbs) — removes the whole-object
   clobber class of bugs the ops queue amplifies.
4. **Idea 2** (delta sync) — the big sync win once 1+9 are in.
5. **Idea 3** (bulk ops) — pairs with the Transport/session flows.
6. Ideas 5–8 and 10 as operational need appears (5 before the first deleted
   box incident, ideally).

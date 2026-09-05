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

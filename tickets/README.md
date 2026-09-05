# Moovit API — Ticketing & Agent Workflow

This directory contains the work tickets for the Moovit backend server
(Laravel 8, this repository). Each ticket is written so that an **autonomous
coding agent** can pick it up, implement it, test it, document it, and ship it
as a **single, traceable commit**.

Sources of truth:

- [`../server.md`](../server.md) — the server-side ideas/spec. Each ticket
  references it by section (`server.md §N`). An agent MUST read the referenced
  sections before writing any code.
- [`~/AndroidStudioProjects/Moovit/plan.md`](../../../AndroidStudioProjects/Moovit/plan.md)
  (in the Android repo) — §2 defines the API contract as consumed by the
  Android client, §4.14 the local-first sync rule. **The server must never
  break that contract**: existing clients (Android app, web SPA, kanban pages)
  keep working unless a ticket explicitly says otherwise. Additive changes
  (new fields, new endpoints, new query params) are the default.

Tickets are numbered `API-0XX` (the Android app's client-side tickets live in
`~/AndroidStudioProjects/Moovit/tickets/` with `MV-0XX` ids — cross-references
use those ids verbatim).

**Hard server constraints (apply to every ticket):**

- **No PATCH routes.** The host's load balancer does not support PATCH
  (see commit `66207c4`). Every *new* write endpoint MUST use `POST` (deletes:
  `DELETE`; reads: `GET`). The `PATCH`/`PUT` methods registered by the existing
  `Route::resource` lines are legacy and must not be relied upon by new work —
  though tests may keep pinning that they currently exist.
- **Additive-only contract changes.** Old app versions must keep working
  untouched: response fields are added, never renamed or removed; new features
  (e.g. attachments) must be *optional* for clients.

---

## 1. Directory layout

```
tickets/
├── README.md                  ← this file (workflow + rules)
├── templates/
│   ├── TICKET_TEMPLATE.md     ← mandatory template for every ticket
│   └── COMMIT_TEMPLATE.md     ← mandatory commit message + push format
└── API-0XX-slug.md            ← one file per ticket
```

## 2. Ticket ↔ server.md mapping

| Ticket   | server.md idea                                            | Title |
|----------|-----------------------------------------------------------|-------|
| API-001  | — (prerequisite: test infra)                              | SQLite test suite + feature tests pinning the current API contract |
| API-002  | §1 `updated_at` on every list payload                     | Verify & pin `updated_at` on list payloads |
| API-003  | §9 Cheap change detection                                 | Per-team revision counter (`GET api/revision`) |
| API-004  | §4 Sparse PATCH / intent-based verbs                      | Intent verbs for anti-clobber writes |
| API-005  | §2 prerequisite (soft deletes)                            | Soft deletes for items |
| API-006  | §2 Delta sync                                             | `?since=` delta + deletions feed |
| API-007  | §3 Bulk operations                                        | Bulk move / bulk assign endpoints |
| API-008  | §5 Deletion semantics                                     | Deletion semantics for non-empty boxes |
| API-009  | §6 Team activity feed                                     | Team-level activity feed endpoint |
| API-010  | §7 Audit acceptance                                       | Audit/stocktake acceptance endpoint |
| API-011  | §8 Barcode registry                                       | Barcode registry separate from item ids |
| API-012  | §10 Device tokens                                         | Named, revocable device tokens for the PDA fleet |
| API-013  | — (new idea: image attachments, Matthieu 2026-09)         | Image attachments on items (upload/list/download/delete) |

Numbering follows the *Suggested order* in server.md (small wins first, then
the sync work, then operational features); API-013 is appended by request.

## 3. Ticket lifecycle

A ticket file carries a `status` field in its YAML front-matter:

| Status        | Meaning                                                        |
|---------------|----------------------------------------------------------------|
| `ready`       | Ticket is actionable (dependencies are `done`)                  |
| `in-progress` | An agent has claimed it (agent sets this **before** coding)     |
| `blocked`     | Agent cannot proceed → set status + explain in ticket, stop     |
| `in-review`   | Code committed & pushed, awaiting human review                  |
| `done`        | Human accepted the work (only a human sets this)                |

Agents MUST work on **one ticket at a time**, chosen among `ready` tickets whose
`depends_on` are all `done`, in ascending ticket ID order unless instructed otherwise.

## 4. Agent workflow (mandatory, in order)

1. **Pick** a ticket: lowest-ID `ready` ticket with all `depends_on` satisfied.
2. **Claim**: set `status: in-progress` in the ticket file (commit this change together with
   the implementation commit, see §6).
3. **Read**: the ticket, `server.md` (at minimum the referenced `§` sections), and the
   code touched by `depends_on` tickets. Never modify code you haven't read.
4. **Implement**: follow the ticket's *Scope* (must-have checklist) and *Technical notes*.
   Respect the backward-compatibility rule (existing Android client, web SPA, kanban).
5. **Test**: create/extend feature tests per the ticket's *Tests* section.
   `vendor/bin/phpunit` (or `php artisan test`) must pass before committing. Schema changes
   ship as migrations; `php artisan migrate:fresh` must succeed on a fresh database.
6. **Document**: PHPDoc on every new public class/method; update `server.md`
   (mark the idea implemented) and any affected docs; user-visible JSON field names are
   part of the contract — never rename a response field.
7. **Commit**: message exactly per [`templates/COMMIT_TEMPLATE.md`](templates/COMMIT_TEMPLATE.md),
   including the ticket ID (`API-0XX`). Stage the ticket file status change in the same commit.
8. **Report**: append the *Implementation report* section to the ticket file (see template)
   — changes summary, files touched, test results, commit hash — and set `status: in-review`.
9. **Push**: push branch `api-0XX-short-slug` to the remote. If the remote doesn't exist,
   report it in the ticket instead of inventing one.

## 5. Global Definition of Done (applies to every ticket)

- [ ] All *Scope — Must have* checkboxes are done; nothing from *Out of scope* leaked in.
- [ ] `vendor/bin/phpunit` passes; required tests exist and pass (ticket's *Tests* section);
      no skipped tests.
- [ ] New/changed endpoints keep the existing clients working (Android per plan.md §2,
      web SPA, kanban pages); response fields are only ever **added**, not renamed/removed.
- [ ] Schema changes ship as migrations; `php artisan migrate:fresh` succeeds; the
      `.env.example`-style defaults still boot the app.
- [ ] PHPDoc on all new public APIs; no TODO left in shipped code.
- [ ] Authorization follows the existing pattern (`hasTeamPermission` against the
      **resource's** team + `tokenCan` for `api/*` requests).
- [ ] Commit message conforms to the commit template; ticket updated + status `in-review`.
- [ ] Work pushed to the ticket branch.

## 6. Commit rules (summary — full format in template)

- One ticket = one commit (small follow-up fixups allowed as separate commits with the same
  `Refs:` line, before review).
- Branch: `api-0XX-short-slug` (e.g. `api-006-delta-sync`).
- Subject: `API-0XX(type): imperative summary ≤ 72 chars` — type ∈ feat|fix|chore|docs|test|refactor.
- Body must include: what changed & why, `Refs: tickets/API-0XX-....md`, `Spec: server.md §…`,
  and the test command + result.
- Never commit secrets, `.env`, tokens, or `vendor/` / `node_modules/` / `storage/` outputs.

## 7. Escalation

If the ticket is ambiguous or blocked (unclear contract with the Android client, failing
dependency, environment unavailable): set `status: blocked`, write the exact blocking
question in the ticket under *Implementation report → Blockers*, commit nothing (or push
work-in-progress on the branch clearly marked `WIP:`), and stop. Do not guess API
contracts; flag them — especially anything that would change an existing response shape.

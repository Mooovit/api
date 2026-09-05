<!--
Template for every ticket. Copy this file to tickets/API-0XX-slug.md and fill every section.
Do not delete sections; if one doesn't apply, write "N/A — reason".
The implementing agent fills: status (to in-progress/in-review), Implementation report.
-->

---
id: API-0XX
title: "<short imperative title>"
type: feature | fix | chore | infra | docs
priority: P0 | P1 | P2
status: ready            # ready | in-progress | blocked | in-review | done
depends_on: []           # e.g. [API-001, API-005]
spec: "server.md §N"     # primary spec section(s); client contract: plan.md §2 (Android repo)
---

# API-0XX — <Title>

## Context
Why this ticket exists, where it sits in the product (1–3 sentences), and what previous
tickets built that this one builds on. Reference `server.md` sections with §, the Android
client's expectations with `plan.md §…` (repo: `~/AndroidStudioProjects/Moovit/plan.md`),
and existing client tickets with `MV-0XX`.

## Scope — Must have
- [ ] ...
- [ ] ...

## Out of scope
- Explicitly list what this ticket must NOT do (guards against scope creep), and which
  ticket will do it later.

## Acceptance criteria
Testable, binary conditions — an agent or reviewer can verify each without ambiguity:
- [ ] ...
- [ ] ...

## Technical notes
Pointers that save the agent exploration time: files to create/modify, exact routes,
validation rules, gotchas (load-balancer PATCH quirk, SQLite FK quirks, tokenCan checks),
links to related code.

## Tests
What the agent must create (feature tests under `tests/Feature/` / unit tests under
`tests/Unit/`) and how to run them (`vendor/bin/phpunit --filter ...`). If no automated
test is possible, say exactly what to verify manually.

## Documentation requirements
What must be documented (PHPDoc targets, `server.md` idea status, README/docs updates,
request/response examples for new or changed endpoints).

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- (or "none")

### Changes
-

### Files touched
-

### Tests run
```
<command>          → <result>
```

### Commits
- `<hash> <subject>`

### Notes for reviewer
-

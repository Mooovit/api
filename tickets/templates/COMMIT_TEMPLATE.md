# Commit & Push Template (mandatory for every agent)

One ticket → one branch → one main commit (fixups before review allowed, same `Refs:` line).

## 1. Branch naming

```
api-0XX-short-slug          e.g. api-006-delta-sync
```

## 2. Commit message format

```
API-0XX(<type>): <imperative summary, ≤ 72 chars>

<Optional body: 1–5 bullet lines, what changed and why.>

Refs: tickets/API-0XX-slug.md
Spec: server.md §N[, plan.md §2]
Tests: vendor/bin/phpunit --filter <X>  → PASS/FAIL, N tests

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>
```

Rules:
- `<type>` ∈ `feat | fix | chore | docs | test | refactor`.
- Subject line imperative, no trailing period, ticket ID mandatory.
- `Refs:` and `Spec:` lines mandatory. `Tests:` line mandatory (state the exact command).
- Use a HEREDOC to preserve formatting: `git commit -m "$(cat <<'EOF' ... EOF)"`.

### Example

```
API-006(feat): add ?since= delta sync with tombstones to GET api/item

- since=<ISO-8601> switches response to {changed, deleted_ids} envelope
- plain array preserved when since is absent (Android plan.md §2 compat)
- index on items.updated_at; tombstones read from soft-deleted rows

Refs: tickets/API-006-delta-sync.md
Spec: server.md §2
Tests: vendor/bin/phpunit --filter ItemDeltaSyncTest  → PASS, 8 tests
```

## 3. What to stage

- Implementation code + migrations + tests + documentation.
- The ticket file itself: `status` transition (`in-progress` at start → `in-review` at end)
  and the completed *Implementation report* — stage it in the ticket's commit (or the initial
  claim commit when setting `in-progress`).

## 4. Push

```
git push -u origin api-0XX-short-slug
```

- If the remote is missing, do NOT invent one; note it in the ticket report and stop.
- Push description = the commit body (identical text), so the ticket, the commit and the
  push tell the same story.

## 5. Optional PR (if a remote + review flow exists)

Title: `API-0XX — <ticket title>`
Body:

```
## Summary
- <1–3 bullets>

## Test plan
- [ ] <steps / phpunit commands>

Refs: tickets/API-0XX-slug.md
```

## 6. Never commit

Secrets, `.env`, tokens, `vendor/`, `node_modules/`, `storage/` outputs, `.DS_Store`.

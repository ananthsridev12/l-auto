# Knowledge Base — 9-Block Expansion

Tracks progress on expanding the app's per-workspace Knowledge Base to
the 9-block ISE pattern (Company Identity, Verticals, Services, ICPs,
Personas, Tone & Voice, Senders, Proof Points, Documents), scoped
identically for both Personal and Company workspaces. Each block is
additive — existing behavior is never changed, only extended.

## Status

| Phase | Block(s) | Status | Migration file |
|---|---|---|---|
| 0 | Company Identity + Tone (enrich `workspaces`) | **Done** | `migrations/0001_kb_workspace_identity_tone.sql` |
| 1 | Senders (new table, wired into AI prompt) | Not started | — |
| 2 | Personas (enrich existing table) | Not started | — |
| 3 | Verticals (new table) | Not started | — |
| 4 | Services (new table) | Not started | — |
| 5 | ICPs (new table) | Not started | — |
| 6 | Link Personas ↔ Verticals/Services | Not started | — |
| 7 | Proof Points (new table) | Not started | — |
| 8 | Documents polish (optional) | Not started | — |
| 9 | Wire Service/ICP/Proof into AI generation | Not started | — |
| 10 | Signal matching + KB completeness (optional stretch) | Not started | — |

## Scoping model

Every KB row belongs to exactly one `workspaces.id` (the tenant
equivalent). `workspaces.type` (`personal` | `company`) never changes
which blocks apply — both kinds of workspace get the same 9 blocks,
just filled in independently. Label wording adapts per type (e.g.
"About the author" vs "About the company" in `workspace_context_text()`)
but the underlying tables/columns are identical.

## Prompt assembly order (per the design doc, applied where wired)

1. Sender identity (Block 7) — who is writing
2. Company credibility (Block 1) — who we are
3. Service being pitched (Block 3) — what we offer
4. Tone rules (Block 6) — how to write
5. Target context — persona/pillar/ICP signals
6. Proof point (Block 8) — if a service match exists
7. Touch/memory context — recent posts to avoid repeating (existing `content_memory` system)

Missing blocks are always silently omitted — this already matches how
`includes/ai_generate.php::build_context_block()` works today.

## Files touched across phases

- `schema.sql` — master schema, always updated in the same commit as a migration file
- `migrations/NNNN_*.sql` — one numbered file per phase (see `migrations/README.md`)
- `includes/post_helpers.php` — `fetch_*()` / `fetch_*_one()` per new block
- `pages/settings.php` — one list+add+delete Settings section per new block
- `includes/workspace.php` — `workspace_context_text()`
- `includes/ai_generate.php` — `build_context_block()`, `build_generation_prompt()`
- `includes/kb_seed.php` — starter data, extended per-phase where useful

## Full phase plan

See the plan this was built from for exact column lists per phase —
summarized per-phase in the commit that lands it, and in this file's
status table above once complete.

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
| 1 | Senders (new table, wired into AI prompt) | **Done** | `migrations/0002_kb_senders.sql` |
| 2 | Personas (enrich existing table) | **Done** | `migrations/0003_kb_personas_enrichment.sql` |
| 3 | Verticals (new table) | **Done** | `migrations/0004_kb_verticals.sql` |
| 4 | Services (new table) | **Done** | `migrations/0005_kb_services.sql` |
| 5 | ICPs (new table) | **Done** | `migrations/0006_kb_icps.sql` |
| 6 | Link Personas ↔ Verticals/Services | **Done** | `migrations/0007_kb_personas_vertical_service_links.sql` |
| 7 | Proof Points (new table) | **Done** | `migrations/0008_kb_proof_points.sql` |
| 8 | Documents polish (optional) | Not started | — |
| 9 | Wire Service/ICP/Proof into AI generation | **Done** | — (code only, no schema change) |
| 10 | Signal matching + KB completeness (optional stretch) | Not started | — |

Phase 12 (KB round 2): the entire Knowledge Base UI moved out of
Settings into its own page, `pages/knowledge.php` — 12 tabs (Company,
Verticals, Services, ICPs, Personas, Tone & Voice, Senders, Proof
Points, Documents, plus this app's own Content Pillars/CTA
Library/Tag Directory extras beyond the ISE 9-block pattern). Settings
now only has Account/Brand (branding+design)/Integrations. The old
single "Knowledge Hub" workspace-profile form was split into two
independent forms/handlers (`workspace_profile_company` /
`workspace_profile_tone`) so saving one tab never blanks the other's
fields. No schema change, no data migration — same tables, same
columns, purely a UI reorganization.

Phase 9 detail: `build_context_block()`/`build_generation_prompt()`/
`generate_creative_via_ai()` gained a trailing `?array $service = null`
param (backward-compatible — every existing call site still works
unchanged). New Post's AI panel gets a "Service being pitched" picker
(sends `service_id`, resolved via `fetch_service()`); Calendar Batch has
no per-post picker, so it matches a service by keyword overlap between
the pillar/topic and `services.signal_keywords`
(`match_service_by_keywords()`). Either way, a matching public Proof
Point is auto-attached (`fetch_matching_proof_point()`) rather than
needing its own selector — ICP is intentionally not wired into the
prompt directly in this pass. Content Studio's CSV path and News/blog
generation are unchanged (no service context there yet).

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

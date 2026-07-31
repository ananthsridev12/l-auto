# Migrations

Numbered, one-file-per-change SQL migrations for incremental schema
updates on an *existing* deployed database (e.g. postpilot.easi7.in).

This app doesn't use a migration framework — `schema.sql` is the single
source of truth for a fresh install (it's applied top-to-bottom via
`CREATE TABLE IF NOT EXISTS` / `ALTER TABLE` and already contains every
change described here). This folder exists so each individually
reviewable change also has a **standalone, numbered, applicable-once
file** for updating a database that's already running in production,
without re-running the entire `schema.sql`.

## Convention

- Filename: `NNNN_short_description.sql`, zero-padded, sequential,
  never reused or renumbered once committed.
- Every statement is `ADD COLUMN IF NOT EXISTS` / `CREATE TABLE IF NOT
  EXISTS` where the MySQL/MariaDB version supports it, so re-running a
  migration that already applied is a safe no-op.
- The same SQL is also appended to `schema.sql` (for fresh installs) in
  the same commit — the two are always kept in sync.
- One migration file per plan phase (see `docs/KNOWLEDGE_BASE.md`), not
  one per individual `ALTER` statement — a phase is the reviewable unit.

## Applying a migration on the live server

```
mysql -u <user> -p <database> < migrations/0001_kb_workspace_identity_tone.sql
```

## Log

| File | Phase | Summary |
|---|---|---|
| `0001_kb_workspace_identity_tone.sql` | 0 | Richer Company Identity + Tone & Voice fields on `workspaces` |
| `0002_kb_senders.sql` | 1 | New `senders` table, wired into AI prompt context |
| `0003_kb_personas_enrichment.sql` | 2 | Richer Persona fields (title, seniority, pain points, decision role, etc.), wired into AI prompt |
| `0004_kb_verticals.sql` | 3 | New `verticals` table (business units / focus areas) |
| `0005_kb_services.sql` | 4 | New `services` table (offerings + signal-matching fields) |
| `0006_kb_icps.sql` | 5 | New `icps` table (company-level ideal customer profiles) |
| `0007_kb_personas_vertical_service_links.sql` | 6 | Optional `vertical_id`/`service_id` FKs on `personas` |
| `0008_kb_proof_points.sql` | 7 | New `proof_points` table (client outcomes / case studies) |
| `0009_user_theme_preference.sql` | 11 | `users.theme` — light/dark preference for the app-wide theme toggle |
| `0010_kb_documents_polish.sql` | 13 (KB Phase 8) | `knowledge_documents` — doc_type/use_case/vertical_id/service_id/is_public |
| `0011_brand_palette_text_overrides.sql` | — | `brand_palettes` — optional body_color/accent_text_color/badge_text_color/cta_text_color overrides for the previously always-derived text colors |
| `0012_organizations.sql` | — | New `plans`/`organizations`/`workspace_members`/`organization_invites` tables + `users.organization_id`/`org_role`/`is_superadmin` — team accounts, per-page access grants, a superadmin role, and plan/module gating. Run `scripts/migrate_organizations.php` right after to backfill a personal org for every existing user. |

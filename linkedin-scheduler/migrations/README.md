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
| `0013_organizations_seed_and_backfill.sql` | — | SQL-only equivalent of `scripts/migrate_organizations.php`, for deployments with DB-console-only access (phpMyAdmin/Adminer, no shell). Run after `0012_organizations.sql` instead of the PHP script — seeds the 3 starter plans and backfills a personal org for every existing user. |
| `0014_family_wishes.sql` | — | New `family_wish_requests` table — logs each birthday/anniversary card image generated for the external "family app" integration (see `api/family_wish.php`), keyed by that app's own reference id for idempotent retries. |
| `0015_engagement.sql` | — | New `target_posts` (admin-curated LinkedIn posts to engage with) and `engagement_actions` (Like/Comment log — per-account daily rate-limit source and future points-feature data source) tables — see `includes/engagement.php`, `pages/engagement.php`. |
| `0016_engagement_repost_self_report.sql` | — | `engagement_actions.action_type` gains `'repost'`; new `verification` column (`self_reported` vs `api`) — Like/Comment turned out to need LinkedIn's partner-gated Community Management API, so they became an unverifiable "redirect + self-report" flow, while Repost (just a post with `reshareContext` set) stays a real, verified API call. |
| `0017_grav_pillar_routing.sql` | — | New `content_pillars.grav_route_prefix`/`grav_template` (per-pillar Grav routing override, NULL falls back to the workspace's own) and `blog_posts.content_pillar_id` (blog posts never had one before) — see `includes/grav_api.php`, `pages/blog_studio.php`. |
| `0018_news_region_and_grounded_blog.sql` | — | New `workspaces.news_region` (per-workspace override of the global Google News language/country constants) and `news_items.description` (short RSS snippet, HTML-stripped/truncated — powers "Write Blog Post"'s new Grounded Rewrite mode) — see `includes/news_fetch.php`, `includes/blog_generate.php`, `pages/news_studio.php`. |
| `0019_content_types_grav_unpublish_sitemap.sql` | — | New `blog_posts.content_type` (which structural template generated the post — Listicle/How-To/Comparison/News Roundup/Case Study/Checklist/Analysis, see `includes/blog_generate.php`), `blog_posts.status` gains `'unpublished'` (a Grav page soft-hidden via `header.published = false` rather than deleted, see `includes/grav_api.php`), and new `workspaces.sitemap_url` + `sitemap_links` table (manually-fetched sitemap URLs for internal linking beyond this app's own posts, see `includes/sitemap.php`). |
| `0020_news_keyword_search_approach.sql` | — | New `news_topics.search_approach` (`'seo'`/`'ai_mode'`) + `ai_expanded_queries` (JSON) — a Google-News keyword can now be searched literally (SEO, unchanged default) or AI-expanded into several related natural-language queries via the configured AI provider (AI Mode), mirroring how Google's own AI Mode surfaces broader topics than a plain keyword match. See `includes/news_fetch.php` `news_generate_ai_expansion()`. |
| `0021_content_collections_and_stock_images.sql` | — | New `content_collections` table (group related LinkedIn posts + blog posts into a named set, e.g. a product launch — see `includes/collections.php`, `pages/knowledge.php`) plus `posts.collection_id`/`blog_posts.collection_id` FKs, and `users.unsplash_access_key` (Unsplash stock-photo search for New Post's "Stock/AI Photo" panel — see `includes/stock_images.php`). |
| `0022_link_tracking.sql` | — | New `tracked_links` table — a short redirect link (`APP_URL/go?s={slug}`) for any URL, with a click counter, so a link pasted into a post/blog post can have its clicks measured (LinkedIn's API gives no read-back engagement data). See `includes/link_tracking.php`, `go.php`, Knowledge Hub's "Link Tracking" tab. |
| `0023_news_items_independent_draft_blog_tracking.sql` | — | New `news_items.blog_post_id` FK — a News Studio headline can now be turned into a LinkedIn draft AND a blog post independently (previously doing either one flipped the headline to `status='used'`, hiding it and taking the other action's button down with it). See `includes/news_fetch.php` `news_generate_draft()`, `pages/news_studio.php`. |
| `0024_grav_table_wrapper.sql` | — | New `workspaces.grav_table_wrap_html`/`grav_table_class` — a per-workspace wrapper template and CSS class applied to any `<table>` in Comparison-type blog post content, but only in the copy sent to Grav on publish (the app's own stored `content_html` stays theme-agnostic). NULL/empty = tables publish as plain `<table>`, unaffected. See `includes/grav_api.php` `grav_apply_table_style()`, `pages/settings.php`'s Grav section. |

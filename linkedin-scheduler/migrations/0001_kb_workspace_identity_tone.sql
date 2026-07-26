-- KB expansion Phase 0 — richer Company Identity + Tone on `workspaces`.
-- See docs/KNOWLEDGE_BASE.md. All columns nullable/optional — safe to
-- apply on a live database with existing workspace rows.

ALTER TABLE workspaces ADD COLUMN tagline VARCHAR(500) NULL;
ALTER TABLE workspaces ADD COLUMN founded_year VARCHAR(10) NULL;
ALTER TABLE workspaces ADD COLUMN company_size VARCHAR(100) NULL;
ALTER TABLE workspaces ADD COLUMN hq_location VARCHAR(255) NULL;
ALTER TABLE workspaces ADD COLUMN mission TEXT NULL;
ALTER TABLE workspaces ADD COLUMN vision TEXT NULL;
ALTER TABLE workspaces ADD COLUMN story TEXT NULL;
ALTER TABLE workspaces ADD COLUMN credibility_statement TEXT NULL;
ALTER TABLE workspaces ADD COLUMN notable_clients TEXT NULL;
ALTER TABLE workspaces ADD COLUMN awards TEXT NULL;

ALTER TABLE workspaces ADD COLUMN tone_descriptors VARCHAR(500) NULL;
ALTER TABLE workspaces ADD COLUMN anti_tone VARCHAR(500) NULL;
ALTER TABLE workspaces ADD COLUMN words_always TEXT NULL;
ALTER TABLE workspaces ADD COLUMN words_never TEXT NULL;
ALTER TABLE workspaces ADD COLUMN post_opening_style TEXT NULL;
ALTER TABLE workspaces ADD COLUMN hook_style TEXT NULL;
ALTER TABLE workspaces ADD COLUMN hashtag_strategy TEXT NULL;
ALTER TABLE workspaces ADD COLUMN post_frequency VARCHAR(100) NULL;
ALTER TABLE workspaces ADD COLUMN cta_linkedin TEXT NULL;
ALTER TABLE workspaces ADD COLUMN paragraph_style ENUM('one-liners','full-paragraphs','bullet-heavy') NULL;
ALTER TABLE workspaces ADD COLUMN good_example TEXT NULL;
ALTER TABLE workspaces ADD COLUMN bad_example TEXT NULL;

ALTER TABLE workspaces ADD COLUMN custom_instructions TEXT NULL;

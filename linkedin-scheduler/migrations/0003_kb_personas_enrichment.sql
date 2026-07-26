-- KB expansion Phase 2 — richer Personas. See docs/KNOWLEDGE_BASE.md.
-- All nullable/optional; existing name+description-only rows unaffected.
ALTER TABLE personas ADD COLUMN title VARCHAR(255) NULL;
ALTER TABLE personas ADD COLUMN department VARCHAR(255) NULL;
ALTER TABLE personas ADD COLUMN seniority ENUM('C-Suite','VP','Director','Manager','Individual Contributor') NULL;
ALTER TABLE personas ADD COLUMN reporting_to VARCHAR(255) NULL;
ALTER TABLE personas ADD COLUMN goals TEXT NULL;
ALTER TABLE personas ADD COLUMN pain_points TEXT NULL;
ALTER TABLE personas ADD COLUMN objections TEXT NULL;
ALTER TABLE personas ADD COLUMN kpis VARCHAR(500) NULL;
ALTER TABLE personas ADD COLUMN decision_role ENUM('Economic Buyer','Champion','Technical Buyer','End User','Influencer','Blocker') NULL;
ALTER TABLE personas ADD COLUMN communication_style TEXT NULL;
ALTER TABLE personas ADD COLUMN preferred_content VARCHAR(500) NULL;
ALTER TABLE personas ADD COLUMN watering_holes VARCHAR(500) NULL;
ALTER TABLE personas ADD COLUMN content_hook TEXT NULL;

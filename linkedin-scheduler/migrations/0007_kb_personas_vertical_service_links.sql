-- KB expansion Phase 6 — link Personas to Verticals/Services. See docs/KNOWLEDGE_BASE.md.
ALTER TABLE personas ADD COLUMN vertical_id INT NULL;
ALTER TABLE personas ADD COLUMN service_id INT NULL;
ALTER TABLE personas ADD CONSTRAINT fk_personas_vertical FOREIGN KEY (vertical_id) REFERENCES verticals(id) ON DELETE SET NULL;
ALTER TABLE personas ADD CONSTRAINT fk_personas_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL;

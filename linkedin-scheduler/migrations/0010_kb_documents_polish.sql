-- KB round 2, Phase 13 (KB Phase 8 — Documents polish, docs/KNOWLEDGE_BASE.md).
-- All nullable/defaulted and additive — existing knowledge_documents rows
-- behave exactly as before.
ALTER TABLE knowledge_documents ADD COLUMN doc_type ENUM('case_study','whitepaper','brochure','deck','one_pager','roi_calculator','video','other') NOT NULL DEFAULT 'other';
ALTER TABLE knowledge_documents ADD COLUMN use_case TEXT NULL;
ALTER TABLE knowledge_documents ADD COLUMN vertical_id INT NULL;
ALTER TABLE knowledge_documents ADD COLUMN service_id INT NULL;
ALTER TABLE knowledge_documents ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE knowledge_documents ADD CONSTRAINT fk_kb_documents_vertical FOREIGN KEY (vertical_id) REFERENCES verticals(id) ON DELETE SET NULL;
ALTER TABLE knowledge_documents ADD CONSTRAINT fk_kb_documents_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL;

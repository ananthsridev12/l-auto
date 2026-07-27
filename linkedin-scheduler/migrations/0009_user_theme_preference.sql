-- KB round 2, Phase 11 — Light/dark theme preference.
-- NULL = follow the browser's prefers-color-scheme automatically; an
-- explicit 'light'/'dark' always wins over that.
ALTER TABLE users ADD COLUMN theme VARCHAR(10) DEFAULT NULL;

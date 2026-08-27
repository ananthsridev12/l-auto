-- Per-workspace Grav table styling — Comparison-type blog posts can
-- include a raw <table>, and different Grav sites/themes need
-- different wrapper markup/classes for it to render styled rather
-- than overflow unstyled. See includes/grav_api.php
-- grav_apply_table_style(), pages/settings.php's Grav section.
-- NULL/empty on both columns = tables published as-is (today's
-- behavior, unaffected).
ALTER TABLE workspaces ADD COLUMN grav_table_wrap_html TEXT NULL;
ALTER TABLE workspaces ADD COLUMN grav_table_class VARCHAR(255) NULL;

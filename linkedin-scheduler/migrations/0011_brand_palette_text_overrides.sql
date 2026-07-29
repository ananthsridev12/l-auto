-- Optional per-palette overrides for the derived text colors that were
-- previously always computed (body: text blended 35% toward bg;
-- accent_text/badge_text/cta_text: best-contrast auto-pick) — see
-- includes/image_renderer.php render_derive_palette_colors(). NULL
-- keeps the existing auto-derived behavior; a set hex is used literally,
-- same optional/auto-generate pattern as accent_color/cta_color/
-- signature_color. Only meaningful on custom palettes; the 4 built-in
-- presets (render_palettes()) are fully fixed and unaffected.
ALTER TABLE brand_palettes
  ADD COLUMN body_color VARCHAR(7) DEFAULT NULL,
  ADD COLUMN accent_text_color VARCHAR(7) DEFAULT NULL,
  ADD COLUMN badge_text_color VARCHAR(7) DEFAULT NULL,
  ADD COLUMN cta_text_color VARCHAR(7) DEFAULT NULL;

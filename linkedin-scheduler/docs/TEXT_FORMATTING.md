# Text formatting markers

Headline, subheading, body, and points text (on both AI-generated and
manually typed slides) support 4 inline markers for styling individual
words or short phrases. They work on every design template.

| Marker | Effect |
|---|---|
| `**word**` | Accent color |
| `++word++` | Highlight color |
| `*word*` | Italic |
| `__word__` | Bold |

Example:

```
Headline: 60% faster **ESG reporting**
Body: We cut audit prep from ++three weeks++ to *three days*.
Points: __Zero__ downtime during the migration
```

The marker characters themselves never appear in the rendered image —
only the styled text does.

## Rules and limitations

- **One marker per span, no nesting or combining.** `**__word__**`
  does not produce bold-and-colored text — the parser reads the outer
  `**...**` span only and leaves the inner `__` characters as literal
  text. To combine effects (e.g. bold *and* colored), style adjacent
  words separately: `**bold** __word__`.
- **Markers span whole words**, split on whitespace inside the marker.
  `**quote cycle**` marks both "quote" and "cycle".
- **Headline text is already always bold** — the `__bold__` marker has
  no visible effect there, but is harmless.
- **Colors are chosen automatically, not literally.** `**word**` and
  `++word++` don't let you pick an arbitrary hex color — they swap to
  one of the palette's pre-verified, guaranteed-legible text colors
  (so the result is always readable against that slide's background).
  You control *that* a word stands out, not the exact shade.
- **Boxed body text and numbered-card points are an exception.** Body
  text is drawn inside a solid accent-colored box on Bold layout
  (always) and Classic layout (Hook/Single slides only); points are
  drawn inside a solid accent-colored numbered card on both Classic
  and Bold layouts (Minimal and Divider point styles are not boxed).
  Only one color is verified legible on that specific fill, so `**`
  and `++` are inert there (no visible color change) — `*italic*` and
  `__bold__` still work normally in those spots.
- **CTA banner text does not support markers.** The banner's button/
  pill sizing math depends on measuring one plain line of text, so
  marker syntax is left as literal characters if used there. Avoid
  markers in the CTA field.
- **Italic with a custom Brand Font.** If your workspace has a custom-
  uploaded Brand Font active, `*italic*` still renders — but by
  falling back to the bundled Liberation Sans/Serif italic, since
  Brand Font uploads only store Regular + Bold weights today. The
  italic word will look like a different font family than the rest of
  the slide's text.

## Where to type them

- **AI-generated posts**: the AI is instructed to add markers sparingly
  on its own; you can also add or edit them by hand in the review step
  before generating the image.
- **Manually typed posts** (New Post → "Write content directly"): type
  the markers directly into the Headline, Subheading, Body, or Points
  fields.

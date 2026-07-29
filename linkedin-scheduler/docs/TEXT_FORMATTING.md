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

### Combining markers

Wrap one marker inside another to combine effects on the same word or
phrase:

```
**__bold and colored__**   → bold + accent color
*++italic and highlighted++*   → italic + highlight color
```

Order doesn't matter (`**__word__**` and `__**word**__` do the same
thing) — each marker in the stack adds its own effect on top of the
others. This works with any pair (or all four at once).

## Rules and limitations

- **Markers span whole words**, split on whitespace inside the marker.
  `**quote cycle**` marks both "quote" and "cycle".
- **Headline text is already always bold.** No bundled font ships a
  heavier weight to step up to, so `__bold__` there instead triggers a
  "faux bold" effect (the word is stamped a few extra times at 1px
  offsets to thicken the strokes) — a visible, genuinely heavier look,
  though slightly less crisp than a true Black-weight font would be.
  Works with any active font (default, serif, or a custom Brand Font),
  since it's a drawing technique, not a font file. Everywhere else
  (subheading, body, points), `__bold__` is a normal regular→bold
  weight change, same as before.
- **Colors are chosen automatically, not literally — unless you opt out.**
  By default, `**word**` and `++word++` don't let you pick an arbitrary
  hex color — they swap to one of the palette's pre-verified,
  guaranteed-legible text colors (so the result is always readable
  against that slide's background). You control *that* a word stands
  out, not the exact shade. If your palette's accent color needs to
  show up as literal text (e.g. a brand red for `**word**`), check
  "Use accent color literally for `**bold**` text" wherever you're
  editing the post (New Post, the saved-post re-edit card, or Calendar
  Batch review). This is **not contrast-checked** against the
  background — only enable it if you've confirmed your accent color is
  actually readable on your background. It only affects `**word**`
  (headline/subheading/body/minimal- and divider-style points); `++`
  still uses the guaranteed-legible highlight color, and boxed
  body/points are unaffected either way (see below).
- **Boxed body text and numbered-card points are an exception.** Body
  text is drawn inside a solid accent-colored box on Bold layout
  (always) and Classic layout (Hook/Single slides only); points are
  drawn inside a solid accent-colored numbered card on both Classic
  and Bold layouts (Minimal and Divider point styles are not boxed).
  Only one color is verified legible on that specific fill, so `**`
  and `++` are inert there (no visible color change) — `*italic*` and
  `__bold__` still work normally in those spots, including as part of
  a combined marker (e.g. `**__word__**` still bolds, just without the
  color change).
- **Wrapping the same marker type around itself isn't meaningful**
  (e.g. `**a **b** c**`) — it's parsed left to right as two separate
  spans ("a" colored, "b" plain, "c" colored), not as true nesting.
  Combining only makes sense across *different* marker types.
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

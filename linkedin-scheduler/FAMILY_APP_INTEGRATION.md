# PostPilot ↔ Family App Integration — Birthday & Anniversary Wish Images

This document is the complete contract for integrating your app ("the Family App") with PostPilot's wish-image generation backend. Hand this file to whoever implements the Family App side.

## 1. What this integration does

- The Family App owns birthdays/anniversaries for its own users and knows when one is coming up.
- When one is coming up, the Family App calls PostPilot's API with the person's details.
- PostPilot generates a greeting-card image and returns a URL to it.
- The Family App downloads/displays that image and sends it to the end user via WhatsApp or email — **PostPilot never contacts the recipient directly.** PostPilot is purely a backend image-generation service for this integration; it has no concept of the Family App's users, contacts, or send channels.

```
Family App                          PostPilot
-----------                         ---------
detects upcoming birthday
   |
   |--- POST /api/family_wish ----->  validates, renders image,
   |     (name, occasion, ...)        stores it, returns a URL
   |
   |<---- { image_url } -------------|
   |
downloads/uses image_url
sends via WhatsApp/email
(Family App's own logic —
 PostPilot is not involved)
```

## 2. Endpoint

```
POST https://postpilot.easi7.in/api/family_wish
Content-Type: application/json
```

**Important — do not include `.php` in the URL.** The site rewrites `/api/family_wish.php` → `/api/family_wish` with a redirect, and 301 redirects on POST requests are unreliable across HTTP clients (some drop the body/method on redirect). Always call the extensionless path directly: `/api/family_wish`.

## 3. Authentication

A single shared API key, sent one of two ways:

**Preferred — request header:**
```
X-Api-Key: <the shared key>
```

**Alternative — JSON body field** (useful if your HTTP client can't easily set custom headers):
```json
{ "api_key": "<the shared key>", ... }
```

Either is accepted; the header is checked first. There is no OAuth/session — this is a server-to-server shared secret. **The key will be provided to you out-of-band (not in this document, not in the repo).** Treat it as a secret: store it in your server's environment/secrets manager, never in client-side code, logs, or version control.

A missing or incorrect key returns `401` (see §6).

## 4. Request

### Fields

| Field | Type | Required | Max length | Notes |
|---|---|---|---|---|
| `external_ref` | string | **Yes** | 191 chars | Your own unique ID for this specific wish (e.g. `"birthday-<contact_id>-<year>"`). Used for idempotency — see §7. Must be unique per wish instance; reusing it returns the previously generated image instead of making a new one. |
| `occasion` | string | **Yes** | — | Must be exactly `"birthday"` or `"anniversary"` (lowercase). Any other value is rejected. |
| `name` | string | **Yes** | 255 chars | The recipient's name as it should appear on the card, e.g. `"Priya"` or `"Raj & Meera"` for a couple. Used verbatim in the headline ("Happy Birthday, {name}!"). |
| `relation` | string | No | 100 chars | E.g. `"sister"`, `"mom"`, `"friend"`. Rendered as a subheading under the headline, capitalized automatically. Omit or send empty to leave it off the card. |
| `message` | string | No | 2000 chars | A personal message to print on the card. If omitted, a sensible default is used ("Wishing you a wonderful day and a fantastic year ahead." for birthdays, an equivalent line for anniversaries). |
| `photo_url` | string | No | — | **Accepted but not yet used.** Reserved for a future version that will overlay a photo on the card. Safe to start sending this now (e.g. a URL to the recipient's profile photo) so no request-shape change is needed later — it is currently ignored by the server. |

### Example request

```bash
curl -X POST https://postpilot.easi7.in/api/family_wish \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: <shared key>" \
  -d '{
    "external_ref": "birthday-contact482-2026",
    "occasion": "birthday",
    "name": "Priya",
    "relation": "sister",
    "message": "Have the best birthday yet!"
  }'
```

## 5. Response

### Success — `200 OK`

```json
{
  "success": true,
  "external_ref": "birthday-contact482-2026",
  "image_url": "https://postpilot.easi7.in/uploads/family_wishes/2026/08/birthday-contact482-2026/slide_01.png?v=1786451056"
}
```

- `image_url` is a **directly, publicly fetchable** URL — no auth needed to download the image itself (same convention PostPilot uses for all of its generated post images). Fetch it with a plain GET/download; no headers required.
- The `?v=...` query string is a cache-busting timestamp. Keep it as part of the URL you store/use; it's harmless to include and ensures you always get the current version if an image is ever regenerated.
- The image is a **square PNG**, suitable for direct attachment/sharing on WhatsApp or as an email inline image.

### Error responses

All errors follow the same shape:
```json
{ "success": false, "error": "human-readable message" }
```

| HTTP status | Meaning | When |
|---|---|---|
| `400` | Invalid request | Wrong HTTP method (not POST) |
| `401` | Invalid or missing API key | `X-Api-Key` header / `api_key` body field missing or incorrect |
| `422` | Validation error | A required field is missing/empty, too long, or `occasion` isn't `"birthday"`/`"anniversary"`. The `error` message names the specific field/rule that failed. |
| `500` | Server error | Image rendering failed unexpectedly on PostPilot's side. Safe to retry (see §7 — retrying with the same `external_ref` will not create a duplicate once it does succeed). |

## 6. Idempotency (safe retries)

`external_ref` is a unique key on PostPilot's side. If you POST the same `external_ref` again — whether because you're explicitly retrying after a timeout/error, or your birthday-detection job runs more than once for the same event — PostPilot will **not** re-render the image. It looks up the existing record and returns the same `image_url` from the first successful call, with a `200` response.

**Practical implication:** it is always safe to retry a request that failed or timed out, as long as you reuse the same `external_ref`. Do not generate a new `external_ref` on retry, or you'll get a second (duplicate, wasted) render.

Choose an `external_ref` scheme that's naturally unique per wish instance and stable across retries — e.g. `"{occasion}-{your_contact_id}-{year}"`. Do not reuse the same `external_ref` for a person's birthday one year and their birthday the next year — those should be two different wishes with two different images (include the year, as in the example above).

## 7. Rate limits / volume

There is currently no explicit rate limit enforced on this endpoint. That said, it's designed for **on-demand, one-at-a-time calls** (e.g. triggered by a daily cron job checking "whose birthday is today/tomorrow"), not bulk/batch generation of hundreds of images in a tight loop. If you expect to send a burst of many requests at once (e.g. backfilling a large contact list), please reach out first so we can plan for it — this hasn't been load-tested for that pattern.

## 8. What the card looks like

- A single square image, festive color palette (cream background, dark green text), serif-style bold headline.
- Layout: large headline ("Happy Birthday, {name}!" / "Happy Anniversary, {name}!"), an optional relation subheading below it, and the message in a highlighted box beneath that.
- There is currently **one fixed visual style** — no per-request template/color choice. If you need visual variants (e.g. different palettes per occasion, or a "kids" vs "formal" style), let us know; that would need to be scoped as a follow-up.

## 9. Known limitations (by design, for this first version)

Flagging these explicitly so they can be planned around on your side if needed:

1. **No photo support yet.** `photo_url` is accepted in the request (so you can start sending it now without a future contract change) but is currently ignored — the card is text-only. Photo overlay is a planned but not-yet-built enhancement.
2. **Single shared API key**, not per-integration or per-caller keys. This is fine for a single Family App instance calling in; if you have multiple environments (staging/production) or expect to need per-caller attribution/revocation later, mention it — that would need a small follow-up change (per-key auth instead of one shared constant).
3. **PostPilot does not send anything to end recipients.** It only generates and returns an image URL. All WhatsApp/email delivery logic, opt-outs, delivery tracking, etc. remain entirely the Family App's responsibility.
4. **One fixed card template** (see §8) — no per-request styling options yet.
5. Generated images are **not automatically deleted**. They persist on PostPilot's server indefinitely at the returned URL. If you need them purged after a retention period, that would need to be a separate request/feature.

## 10. Quick integration checklist

- [ ] Store the shared API key securely (env var / secrets manager) on your server — never client-side.
- [ ] Call `POST https://postpilot.easi7.in/api/family_wish` (no `.php`) with `Content-Type: application/json`.
- [ ] Send `X-Api-Key` header (or `api_key` body field).
- [ ] Generate a stable, unique `external_ref` per wish instance (include the year for recurring annual events).
- [ ] Handle `401`/`422`/`500` per §6; retries with the same `external_ref` are always safe.
- [ ] Download/use `image_url` from the `200` response — it needs no auth to fetch.
- [ ] Do your own WhatsApp/email send using that image — PostPilot's job ends at returning the URL.

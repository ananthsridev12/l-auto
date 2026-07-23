# PostPilot — LinkedIn Content & Scheduling Platform

A PHP web app that helps a person or team plan, generate, design, and schedule LinkedIn content (personal profile and company pages) — with AI writing/image generation, a visual calendar, and multi-brand/workspace support.

## What it does

- **Schedule & post to LinkedIn** — connect a personal profile and/or company pages via OAuth, compose posts (Text, Single Image, or Carousel), schedule them, and auto-post via a cron job.
- **AI content generation** — write captions/slide copy and generate matching images from a topic, using a configurable AI provider (per-user API key), grounded in the user's brand brief, personas, content pillars, and CTA library.
- **Custom image rendering** — a from-scratch image renderer (no design tool needed) turns text into on-brand LinkedIn graphics: multiple layout templates, brand color palettes, custom fonts, logos/footer photos, and adjustable text size/position.
- **Content Calendar** — auto-generates a batch of on-brand post ideas across a date range, which the user reviews/edits/approves in stages (content → images → schedule).
- **Bulk import** — upload a CSV or ZIP of planned posts and turn them into scheduled content in bulk.
- **News & trends** — pulls in Google News and Reddit trends as raw material for timely posts.
- **Blog Studio** — generate long-form blog content and publish directly to WordPress.
- **Workspaces** — segregate content, branding, and knowledge base per brand/client, each with its own settings.
- **Knowledge base & memory** — stores brand info, past posts, and content so new generations stay on-brand and avoid repeating themselves.

## How it's built

- **Stack**: Plain PHP (no framework), MySQL/MariaDB, vanilla JS on the frontend, GD for image rendering.
- **Structure**:
  - `pages/` — the app's screens (New Post, Content Studio, Calendar, Settings, etc.)
  - `api/` — JSON endpoints the frontend JS calls (generate, preview, approve, schedule, render…)
  - `includes/` — core logic: auth, LinkedIn API client, AI generation, image renderer, helpers
  - `assets/` — CSS/JS for each page
  - `cron/` — scheduled jobs (auto-poster, daily news fetch)
  - `schema.sql` — full database schema
- **Deployment**: hosted on shared cPanel hosting (`postpilot.easi7.in`).

## Who it's for

Solo creators or small teams who want to plan and publish a steady stream of on-brand LinkedIn content without hand-designing every image or writing every caption from scratch.

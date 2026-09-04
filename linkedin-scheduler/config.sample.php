<?php
// Template only — never edit this file with real credentials.
// .cpanel.yml copies this to config.php on the FIRST deploy only, and
// never touches config.php again on later deploys, so your real values
// below are safe to fill in directly on the server (via cPanel File
// Manager or SSH) after that first deploy. config.php is git-ignored
// and denied from direct web access via .htaccess — do not remove either.

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

define('APP_URL',    'https://postpilot.easi7.in');
define('APP_SECRET', 'random-32-char-string-for-sessions');

// One app-wide LinkedIn Developer App is used for every connected user
// (personal profiles and company pages alike) — see PHP_APP_SPEC.md /
// the implementation plan for why this is the right model for a
// multi-tenant, Buffer/Hootsuite-style product.
define('LI_CLIENT_ID',     'your-linkedin-app-client-id');
define('LI_CLIENT_SECRET', 'your-linkedin-app-client-secret');

// LinkedIn deprecates each version ~12 months after release — if
// posting starts failing with HTTP 426 "NONEXISTENT_VERSION", bump
// this to a current YYYYMM value (check LinkedIn's developer docs for
// the latest).
define('LI_VERSION',      '202606');
define('LI_REDIRECT_URI', APP_URL . '/auth/linkedin_callback.php');
define('LI_API_BASE',     'https://api.linkedin.com');
define('LI_AUTH_URL',     'https://www.linkedin.com/oauth/v2/authorization');
define('LI_TOKEN_URL',    'https://www.linkedin.com/oauth/v2/accessToken');

// Requested separately per connection type, not combined — LinkedIn
// rejects the *entire* authorization request if it includes a scope your
// Developer App isn't approved for. w_organization_social specifically
// requires LinkedIn's Advertising API or Community Management API
// approval, which is a separate (non-instant) approval process from the
// default "Sign In with LinkedIn" / "Share on LinkedIn" products — so it's
// only requested when the user clicks "Add Company Page(s)", not for a
// plain personal-profile connection.
define('LI_SCOPES_PERSONAL', 'openid profile w_member_social');
define('LI_SCOPES_COMPANY',  'openid profile w_member_social w_organization_social');

// Facebook Pages + Instagram Business both run through one Meta
// Developer App and one Facebook Login flow (Instagram's Graph API is
// authenticated via the linked Page's own access token — see
// includes/meta_oauth.php). Requires Meta App Review before these
// scopes work for anyone but the app's own Meta developer/testers —
// that approval is a manual process only the app owner can complete,
// same "one app-wide Developer App per platform" model as LinkedIn
// above. Leave blank to keep "Connect Facebook/Instagram" disabled
// with a clear "not configured" message instead of a broken redirect.
define('FB_APP_ID',       '');
define('FB_APP_SECRET',   '');
define('META_REDIRECT_URI', APP_URL . '/auth/meta_callback.php');
define('META_GRAPH_API_VERSION', 'v21.0');
define('META_GRAPH_BASE', 'https://graph.facebook.com');
define('META_OAUTH_DIALOG_URL', 'https://www.facebook.com/v21.0/dialog/oauth');
define('META_TOKEN_URL', 'https://graph.facebook.com/v21.0/oauth/access_token');

// Pinterest API v5 — standard OAuth2 authorization-code flow. Free at
// the Trial tier; higher per-category rate limits require Pinterest's
// Trial-to-Standard review, which is again on the app owner to request.
define('PINTEREST_CLIENT_ID',     '');
define('PINTEREST_CLIENT_SECRET', '');
define('PINTEREST_REDIRECT_URI',  APP_URL . '/auth/pinterest_callback.php');
define('PINTEREST_API_BASE',      'https://api.pinterest.com/v5');
define('PINTEREST_AUTH_URL',      'https://www.pinterest.com/oauth/');
define('PINTEREST_TOKEN_URL',     'https://api.pinterest.com/v5/oauth/token');

// Google Business Profile — standard Google OAuth2 (a Google Cloud
// Console project, not a "Google Business Profile" app per se). Free
// at the API level but gated behind Google's manual one-time approval,
// requiring a Business Profile that's been verified and active for
// 60+ days plus a live business website — again, only the account
// owner can complete that. See includes/gbp_oauth.php for the account/
// location discovery calls this feeds.
define('GOOGLE_CLIENT_ID',     '');
define('GOOGLE_CLIENT_SECRET', '');
define('GOOGLE_REDIRECT_URI',  APP_URL . '/auth/gbp_callback.php');
define('GOOGLE_AUTH_URL',      'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_URL',     'https://oauth2.googleapis.com/token');

// SOCIAL_API_OVERRIDE — define this in a LOCAL config.php only (never
// committed, same seam as LI_ENGAGEMENT_API_OVERRIDE/PDF_ENGINE_OVERRIDE
// elsewhere) to short-circuit every new-platform publish/OAuth HTTP
// call during local verification, e.g.
// define('SOCIAL_API_OVERRIDE', 'fake');       // or 'fake_fail'
// Real developer-app approval + credentials are required before any of
// Facebook/Instagram/Pinterest/Google Business Profile can actually
// post — leave undefined in production.

define('UPLOAD_DIR', __DIR__ . '/uploads');

// AI generation in Content Studio / New Post (see includes/ai_generate.php)
// supports 3 providers. The MODEL for each is always this admin/config-
// level constant — never user-editable, so you control quality/cost per
// provider for the whole site. The API KEY is normally per-user (each user
// sets their own in Settings — get_gemini_api_key() etc. in
// includes/helpers.php) since that's realistic for Gemini's free tier.
// Claude and OpenAI have no ongoing free tier, so if you want this site
// usable without every new signup needing their own paid key, optionally
// set a shared *_API_KEY_DEFAULT below — resolve_ai_config() falls back to
// it only for users who haven't set their own key and whose preferred
// provider matches AI_PROVIDER_DEFAULT. Leave the _DEFAULT keys blank to
// require every user to bring their own key for paid providers.
define('AI_PROVIDER_DEFAULT', 'gemini'); // 'gemini' | 'claude' | 'openai'

define('GEMINI_MODEL', 'gemini-2.5-flash');
define('CLAUDE_MODEL', 'claude-sonnet-5');
define('OPENAI_MODEL', 'gpt-4o-mini');
// Embeddings for Memory & Context (includes/embeddings.php) — Claude has no
// embeddings endpoint, so this only ever activates for Gemini/OpenAI users;
// see ai_generate_embedding()'s null-on-unsupported-provider behavior.
define('GEMINI_EMBEDDING_MODEL', 'text-embedding-004');
define('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small');
// Raw (non-branded) image generation — New Post's "Stock/AI Photo"
// panel (includes/ai_generate.php ai_generate_image()). Claude has no
// image generation API, so there's no CLAUDE_IMAGE_MODEL.
define('GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image');
define('OPENAI_IMAGE_MODEL', 'dall-e-3');

define('CLAUDE_API_KEY_DEFAULT', '');
define('OPENAI_API_KEY_DEFAULT', '');

// Shared secret for the "family app" integration (api/family_wish.php)
// — a separate app calls in here to generate birthday/anniversary card
// images and gets a URL back; it authenticates with this key (header
// "X-Api-Key", or "api_key" in the JSON body) instead of a session,
// since there's no logged-in PostPilot user on that side of the call.
// Leave blank to keep the endpoint disabled (it fails closed).
define('FAMILY_APP_API_KEY', '');

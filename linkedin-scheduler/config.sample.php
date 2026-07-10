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

define('LI_VERSION',      '202506');
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

define('UPLOAD_DIR', __DIR__ . '/uploads');

// Model used for AI generation in Content Studio / New Post (see
// includes/ai_generate.php) — this is the only Gemini setting that's
// app-wide. The API key itself is per-user, set by each user in
// Settings (get/set_gemini_api_key() in includes/helpers.php), not here
// — every user brings their own free-tier key at
// https://aistudio.google.com/apikey rather than sharing one app key.
define('GEMINI_MODEL', 'gemini-2.5-flash');

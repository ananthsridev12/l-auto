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

// Requested once for both personal and company-page connections — LinkedIn
// only reveals which orgs a member administers *after* a token exists
// (via the organizationAcls endpoint), so both scopes are requested up
// front regardless of which "type" of connection the user is starting.
define('LI_SCOPES', 'openid profile w_member_social w_organization_social');

define('UPLOAD_DIR', __DIR__ . '/uploads');

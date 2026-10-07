<?php
// Root-level fallback — some MCP clients try /register at the site
// root when the host firewall resets requests to /.well-known/* before
// they ever see the real issuer-scoped endpoints under /oauth. See
// MCP_SETUP_GUIDE's host-specific problems #1-#2 and
// includes/oauth_server.php.
require __DIR__ . '/oauth/register.php';

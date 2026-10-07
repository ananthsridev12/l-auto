-- Every existing plan row's default_modules predates the 'mcp' module
-- key (includes/modules.php) — without this, module_enabled('mcp')
-- stays false for every organization already on one of these plans,
-- and the MCP connector (see includes/oauth_server.php) would be
-- invisible to existing users even after deploying the code for it.
-- Idempotent: only appends when not already present.
UPDATE plans SET default_modules = CONCAT(default_modules, ',mcp') WHERE NOT FIND_IN_SET('mcp', default_modules);

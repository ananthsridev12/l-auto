<?php
// JSON-RPC 2.0 dispatch for the /mcp endpoint (mcp.php). Stateless —
// no SSE, no server-side sessions — which is what makes this workable
// on shared PHP hosting. Requires includes/mcp_tools.php for the actual
// tool registry/handlers.
require_once __DIR__ . '/mcp_tools.php';

const MCP_SUPPORTED_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

// Uses the built-in Exception::$code (via getCode()) for the JSON-RPC
// error code, rather than a new property — Exception already declares
// $code itself, and redeclaring it with a different type is a fatal
// error.
class McpProtocolError extends RuntimeException
{
    public function __construct(int $jsonRpcCode, string $message)
    {
        parent::__construct($message, $jsonRpcCode);
    }
}

function mcp_instructions(): string
{
    return "PostPilot is a social media scheduling tool covering LinkedIn, Facebook, Instagram, "
        . "Pinterest, and Google Business Profile. Dates are YYYY-MM-DD. Call `today` first if you "
        . "need to know the current date or what's due. Use `list_posts`/`get_post` to look up real "
        . "post ids before calling `update_post` or `delete_post` — never guess one. Always get the "
        . "user's explicit confirmation before calling `delete_post`.\n\n"
        . "`create_post` behaves differently depending on what the post contains:\n"
        . "- A plain text post with no image, no carousel, and no link in the caption is PUBLISHED "
        . "IMMEDIATELY when created — its status becomes \"posted\" right away, no separate "
        . "confirmation step.\n"
        . "- A post with an image, a carousel (multiple slides), or a link in the caption is saved "
        . "as a DRAFT ONLY. The response includes `preview_urls` (the rendered image/slide URLs) "
        . "and/or the detected `link`. Show these to the user so they can look at the actual image/"
        . "link, and only call `post_now` with the returned post id after they explicitly confirm it "
        . "looks right. Never call `post_now` on an image/carousel/link post without that confirmation.";
}

function mcp_handle_initialize(array $params): array
{
    $clientVersion = $params['protocolVersion'] ?? null;
    $version = in_array($clientVersion, MCP_SUPPORTED_PROTOCOL_VERSIONS, true) ? $clientVersion : MCP_SUPPORTED_PROTOCOL_VERSIONS[0];
    return [
        'protocolVersion' => $version,
        'capabilities'    => ['tools' => ['listChanged' => false]],
        'serverInfo'      => ['name' => 'postpilot-mcp', 'version' => '1.0.0'],
        'instructions'    => mcp_instructions(),
    ];
}

function mcp_handle_tools_call(array $params, int $userId): array
{
    $name = $params['name'] ?? '';
    $args = $params['arguments'] ?? [];
    if (!is_array($args)) {
        throw new McpProtocolError(-32602, 'arguments must be an object.');
    }
    $tools = mcp_tool_registry();
    if (!isset($tools[$name])) {
        throw new McpProtocolError(-32602, "Unknown tool: \"{$name}\".");
    }

    try {
        $data = $tools[$name]['handler']($args, $userId);
        return [
            'content'           => [['type' => 'text', 'text' => json_encode($data)]],
            'structuredContent' => $data,
            'isError'           => false,
        ];
    } catch (McpToolError $e) {
        return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
    } catch (Throwable $e) {
        error_log('[mcp tool error] ' . $name . ': ' . $e->getMessage());
        return ['content' => [['type' => 'text', 'text' => 'Something went wrong running this tool. Please try again.']], 'isError' => true];
    }
}

function mcp_dispatch_method(string $method, array $params, int $userId): array
{
    switch ($method) {
        case 'initialize':
            return mcp_handle_initialize($params);
        case 'ping':
            return [];
        case 'tools/list':
            return ['tools' => array_values(array_map(fn ($t) => $t['definition'], mcp_tool_registry()))];
        case 'tools/call':
            return mcp_handle_tools_call($params, $userId);
        case 'resources/list':
            return ['resources' => []];
        case 'prompts/list':
            return ['prompts' => []];
        default:
            throw new McpProtocolError(-32601, "Method not found: \"{$method}\".");
    }
}

// A message with no 'id' key is a JSON-RPC notification — no response
// is ever sent for it, successful or not.
function mcp_handle_message(array $message, int $userId): ?array
{
    $isNotification = !array_key_exists('id', $message);
    $id = $message['id'] ?? null;
    $method = (string) ($message['method'] ?? '');
    $params = is_array($message['params'] ?? null) ? $message['params'] : [];

    try {
        $result = mcp_dispatch_method($method, $params, $userId);
    } catch (McpProtocolError $e) {
        return $isNotification ? null : ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $e->getCode(), 'message' => $e->getMessage()]];
    }

    return $isNotification ? null : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

// Top-level entry point for mcp.php. Returns ['status' => int, 'body' =>
// ?string] — body is null exactly when every message in the request was
// a notification, which must be answered with HTTP 202 and no body.
function mcp_handle_request_body(string $raw, int $userId): array
{
    $data = json_decode($raw, true);
    if ($data === null && trim($raw) !== 'null') {
        return ['status' => 400, 'body' => json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error: invalid JSON.']])];
    }

    $isBatch = is_array($data) && array_is_list($data);
    $messages = $isBatch ? $data : [$data];

    $responses = [];
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        $response = mcp_handle_message($message, $userId);
        if ($response !== null) {
            $responses[] = $response;
        }
    }

    if (empty($responses)) {
        return ['status' => 202, 'body' => null];
    }
    return ['status' => 200, 'body' => json_encode($isBatch ? $responses : $responses[0])];
}

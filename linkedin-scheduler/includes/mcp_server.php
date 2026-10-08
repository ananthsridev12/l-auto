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
        . "looks right. Never call `post_now` on an image/carousel/link post without that confirmation.\n\n"
        . "For an image: use `image_urls` only when you have a public http(s) URL to it. For an image "
        . "you already have the bytes for instead (one attached in this conversation, a generated "
        . "image, anything not already hosted at a public URL), use `image_base64` — a data URI per "
        . "entry, e.g. \"data:image/png;base64,...\". Never claim you can't attach an image just "
        . "because it has no public URL; encode it and use image_base64 instead, or tell the user "
        . "plainly if encoding it isn't possible on your end.\n\n"
        . "Limits to know in advance, so you can warn the user instead of just hitting an error: each "
        . "image is max 15MB (PNG/JPEG only); a post can have at most 20 images total (1 = Single "
        . "Image, 2-20 = Carousel); Instagram requires at least 1 image; Pinterest and Google Business "
        . "Profile allow at most 1 image each. Video is LinkedIn-only, MP4 only, max 200MB via "
        . "`video_url` or 50MB via `video_base64`. If a file exceeds these, or is simply too large to "
        . "attach through this chat at all (a chat client's own attachment handling can cap out well "
        . "below these limits even for a smaller file, since base64 inflates size ~33%), tell the user "
        . "to upload it directly at the app's \"Upload for AI\" page (linked from Accounts, under the "
        . "Claude/ChatGPT section) and give you the resulting link to use as image_urls/video_url — "
        . "don't just report the limit as a dead end.\n\n"
        . "To schedule a post for later rather than posting/drafting it now: create it first (it will "
        . "either auto-post or sit as a draft per the rules above), then call `update_post` with "
        . "`scheduled_date` (YYYY-MM-DD) and, for a specific time, `scheduled_time` (24-hour HH:MM, "
        . "e.g. \"18:30\" for 6:30pm) — omitting scheduled_time defaults to 9am. Never tell the user a "
        . "post was scheduled or published unless the tool result actually confirms it — report the "
        . "real status back, including any error.\n\n"
        . "Video is supported on LinkedIn only (`video_url`/`video_base64` on `create_post`, same two "
        . "supply paths as images — `video_base64` for a video you already have the bytes for, e.g. "
        . "one generated or attached in this chat). A video post ALWAYS requires confirmation before "
        . "`post_now`, even with a plain caption and no link — this is a hard rule, not conditional "
        . "like images/links. Show the user `video_preview_url` and wait for explicit confirmation "
        . "every time. Video processing on LinkedIn's side can take a little while and can fail after "
        . "upload succeeds — if `post_now` reports a processing/failure error, tell the user plainly "
        . "and suggest trying again shortly rather than assuming it will resolve itself. GIFs and "
        . "other video formats besides MP4, and video on any platform besides LinkedIn, are not "
        . "supported — say so rather than attempting a workaround.\n\n"
        . "If you'll want to reuse the same image/video across more than one `create_post` call "
        . "(different captions, a retry, scheduling it twice) rather than resending its bytes each "
        . "time, call `upload_media` once first — it takes the same kind of data URI as image_base64/"
        . "video_base64 and returns a `media_url` you can then pass as an image_urls entry or as "
        . "video_url on any later create_post call.";
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

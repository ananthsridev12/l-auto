<?php
// Embeddings for Memory & Context (includes/content_memory.php). Only
// Gemini and OpenAI have embeddings endpoints — Anthropic/Claude does
// not — so ai_generate_embedding() returns null rather than throwing
// when the configured provider can't embed; every caller treats null as
// "memory unavailable this call" and degrades silently (skips
// retrieval/storage, generation proceeds without it) rather than
// failing the whole request over a feature that's inherently optional.
// Requires includes/ai_generate.php to already be loaded (reuses its
// ai_http_post_json() HTTP helper).

function ai_generate_embedding(string $text, array $aiConfig): ?array
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    return match ($aiConfig['provider'] ?? '') {
        'gemini' => ai_embed_gemini($text, $aiConfig['api_key']),
        'openai' => ai_embed_openai($text, $aiConfig['api_key']),
        default  => null, // claude (no embeddings API) or unconfigured
    };
}

function ai_embed_gemini(string $text, string $apiKey): ?array
{
    $model = GEMINI_EMBEDDING_MODEL;
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:embedContent?key=" . urlencode($apiKey);
    $body = ['content' => ['parts' => [['text' => $text]]]];
    [$status, $response, $curlErr] = ai_http_post_json($url, $body, ['Content-Type: application/json']);
    if ($response === false || $status < 200 || $status >= 300) {
        error_log("Gemini embedding request failed ({$status}): " . ($curlErr ?: substr((string) $response, 0, 300)));
        return null;
    }
    $data = json_decode($response, true);
    $values = $data['embedding']['values'] ?? null;
    return is_array($values) ? array_map('floatval', $values) : null;
}

function ai_embed_openai(string $text, string $apiKey): ?array
{
    $body = ['model' => OPENAI_EMBEDDING_MODEL, 'input' => $text];
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
    [$status, $response, $curlErr] = ai_http_post_json('https://api.openai.com/v1/embeddings', $body, $headers);
    if ($response === false || $status < 200 || $status >= 300) {
        error_log("OpenAI embedding request failed ({$status}): " . ($curlErr ?: substr((string) $response, 0, 300)));
        return null;
    }
    $data = json_decode($response, true);
    $values = $data['data'][0]['embedding'] ?? null;
    return is_array($values) ? array_map('floatval', $values) : null;
}

// Standard cosine similarity, [-1, 1] (in practice ~[0, 1] for these
// embedding models). Vectors from the same model are always equal
// length; a length mismatch (e.g. after switching AI provider mid-use)
// is treated as "not comparable" rather than a fatal error.
function embedding_cosine_similarity(array $a, array $b): float
{
    $n = count($a);
    if ($n === 0 || $n !== count($b)) {
        return 0.0;
    }
    $dot = 0.0;
    $magA = 0.0;
    $magB = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dot += $a[$i] * $b[$i];
        $magA += $a[$i] * $a[$i];
        $magB += $b[$i] * $b[$i];
    }
    if ($magA <= 0.0 || $magB <= 0.0) {
        return 0.0;
    }
    return $dot / (sqrt($magA) * sqrt($magB));
}

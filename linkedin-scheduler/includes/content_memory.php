<?php
// Memory & Context: lets AI generation see a compact digest of a
// workspace's own past content so it can avoid repeating itself and
// continue a topic naturally, without sending the full history to the
// model on every call (the brief's stated goal — "keeps token usage
// low while maintaining consistency"). No separate summarization AI
// call: a post's caption/title is already short by AI_STYLE_RULES
// (includes/ai_generate.php), so it doubles as its own summary.
// Requires includes/embeddings.php to already be loaded.

// Stores one post's embedding + summary for future retrieval. Called
// right after a post's real content is saved in every generation flow
// (New Post, Content Studio, Calendar, News Studio). Silently does
// nothing if embeddings aren't available for the configured provider
// (Claude-only accounts) — Memory & Context just doesn't activate for
// them rather than blocking post creation.
function save_content_memory(int $workspaceId, int $postId, string $embedText, string $summary, array $aiConfig): void
{
    $embedding = ai_generate_embedding($embedText, $aiConfig);
    if ($embedding === null) {
        return;
    }
    db()->prepare(
        'INSERT INTO content_memory (workspace_id, post_id, content_type, summary, embedding, embedding_model)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $workspaceId, $postId, 'linkedin',
        mb_substr(trim($summary), 0, 500),
        json_encode($embedding),
        $aiConfig['provider'] . ':' . ($aiConfig['provider'] === 'gemini' ? GEMINI_EMBEDDING_MODEL : OPENAI_EMBEDDING_MODEL),
    ]);
}

// Same idea as save_content_memory() above but for blog_posts (Phase F)
// — a separate function rather than overloading save_content_memory()'s
// signature, since every existing call site (New Post, Content Studio,
// Calendar, News Studio) already depends on it always writing post_id +
// content_type='linkedin'.
function save_blog_content_memory(int $workspaceId, int $blogPostId, string $embedText, string $summary, array $aiConfig): void
{
    $embedding = ai_generate_embedding($embedText, $aiConfig);
    if ($embedding === null) {
        return;
    }
    db()->prepare(
        'INSERT INTO content_memory (workspace_id, blog_post_id, content_type, summary, embedding, embedding_model)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $workspaceId, $blogPostId, 'blog',
        mb_substr(trim($summary), 0, 500),
        json_encode($embedding),
        $aiConfig['provider'] . ':' . ($aiConfig['provider'] === 'gemini' ? GEMINI_EMBEDDING_MODEL : OPENAI_EMBEDDING_MODEL),
    ]);
}

// Top-N most similar past posts in this workspace to $queryEmbedding,
// as [['summary' => ..., 'created_at' => ...], ...] ready for
// build_context_block(). Brute-force cosine similarity in PHP — a
// workspace's lifetime post count is realistically dozens to low
// hundreds, so no vector index/DB is warranted. $contentType filters to
// 'linkedin' or 'blog' memory (a blog shouldn't dedupe against LinkedIn
// captions and vice versa — different content shapes).
function content_memory_find_related(int $workspaceId, array $queryEmbedding, int $limit = 5, string $contentType = 'linkedin'): array
{
    $stmt = db()->prepare(
        'SELECT summary, embedding, created_at FROM content_memory WHERE workspace_id = ? AND content_type = ?'
    );
    $stmt->execute([$workspaceId, $contentType]);
    $scored = [];
    foreach ($stmt->fetchAll() as $row) {
        $vec = json_decode($row['embedding'], true);
        if (!is_array($vec)) {
            continue;
        }
        $scored[] = [
            'summary'    => $row['summary'],
            'created_at' => $row['created_at'],
            'score'      => embedding_cosine_similarity($queryEmbedding, $vec),
        ];
    }
    usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, $limit);
}

// One-call convenience wrapping "embed the topic, then retrieve" for
// the common case at the top of a generation flow. Returns [] (not
// null) when embeddings are unavailable, so callers can pass the result
// straight into build_context_block() without a separate null check.
function content_memory_related_for_topic(int $workspaceId, string $topicText, array $aiConfig, string $contentType = 'linkedin'): array
{
    $embedding = ai_generate_embedding($topicText, $aiConfig);
    if ($embedding === null) {
        return [];
    }
    return content_memory_find_related($workspaceId, $embedding, 5, $contentType);
}

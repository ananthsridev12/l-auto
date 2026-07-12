<?php
// Knowledge Hub reference documents — PDF/DOCX/TXT/MD uploads per
// workspace whose extracted text feeds AI generation context (see
// includes/workspace.php workspace_documents_text(), consumed by
// includes/ai_generate.php build_context_block()). No Composer/vendor
// directory exists in this app and shared-hosting cPanel accounts
// commonly disable exec()/shell_exec(), so PDF text extraction below is
// a small self-contained parser rather than a vendored library or a
// shelled-out tool — it handles the common case (FlateDecode or
// uncompressed content streams, literal-string Tj/TJ text operators,
// which covers most PDFs from Word/Google Docs/LibreOffice/browsers)
// but won't recover text from scanned/image-only PDFs or PDFs using
// custom font encodings with no literal-ASCII mapping. Docs that yield
// no text are still stored — extracted_text stays NULL and the UI says
// so — never a hard failure.

const MAX_DOCUMENT_SIZE_BYTES = 10 * 1024 * 1024; // 10MB
const MAX_DOCUMENT_TEXT_CHARS = 100000;            // stored-text cap per doc

// Magic-byte + light structural sniff, mirroring
// includes/zip_import.php zip_sniff_image_mime()'s pattern. DOCX is a
// ZIP, so PK-signature alone isn't enough — confirm word/document.xml
// exists before calling it a DOCX (an unrelated .zip would otherwise
// misdetect). $originalName only disambiguates plain text from
// Markdown, both of which are otherwise byte-identical to sniff.
function sniff_document_kind(string $contents, string $originalName): ?string
{
    if (substr($contents, 0, 4) === '%PDF') {
        return 'pdf';
    }
    if (substr($contents, 0, 2) === 'PK') {
        $tmp = tempnam(sys_get_temp_dir(), 'kbdoc');
        file_put_contents($tmp, $contents);
        $zip = new ZipArchive();
        $isDocx = $zip->open($tmp) === true && $zip->locateName('word/document.xml') !== false;
        if ($isDocx) {
            $zip->close();
        }
        @unlink($tmp);
        return $isDocx ? 'docx' : null;
    }
    // Plain text: reject anything with NUL bytes or a high proportion of
    // non-printable bytes (a crude but effective "is this actually text"
    // check for an arbitrary uploaded file).
    if (str_contains($contents, "\x00")) {
        return null;
    }
    $sample = substr($contents, 0, 4096);
    $printable = preg_match_all('/[\x09\x0A\x0D\x20-\x7E]|[\xC2-\xF4][\x80-\xBF]+/', $sample);
    if ($sample !== '' && $printable / max(1, strlen($sample)) < 0.85) {
        return null;
    }
    return preg_match('/\.md$/i', $originalName) ? 'md' : 'txt';
}

// ── PDF text extraction ──────────────────────────────────────────────

function pdf_extract_text(string $bytes): string
{
    if (!preg_match_all('/stream\r?\n(.*?)endstream/s', $bytes, $matches)) {
        return '';
    }
    $texts = [];
    foreach ($matches[1] as $raw) {
        $raw = rtrim($raw, "\r\n");
        $content = str_contains($raw, 'Tj') || str_contains($raw, 'TJ')
            ? $raw                       // already-uncompressed content stream
            : @gzuncompress($raw);       // FlateDecode-compressed content stream
        if ($content === false || $content === '') {
            continue;
        }
        if (!str_contains($content, 'Tj') && !str_contains($content, 'TJ')) {
            continue; // not a text-showing stream (image/font/etc data)
        }
        $extracted = pdf_extract_text_from_content_stream($content);
        if ($extracted !== '') {
            $texts[] = $extracted;
        }
    }
    return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $texts)));
}

// Pulls literal-string text out of one decoded PDF content stream:
// "(text) Tj" (single show) and "[(a) -120 (b)] TJ" (kerned array show,
// numbers between strings are inter-glyph spacing and discarded — an
// explicit $inArray flag tracks bracket context so those numbers can't
// be mistaken for the end of the show operation). Text-positioning
// operators (Td/TD/T*) become newlines so paragraphs/lines don't run
// together.
function pdf_extract_text_from_content_stream(string $content): string
{
    $out = '';
    $len = strlen($content);
    $i = 0;
    $inArray = false;
    $arrayBuf = [];
    while ($i < $len) {
        $ch = $content[$i];
        if ($ch === '(') {
            [$str, $i] = pdf_read_literal_string($content, $i);
            if ($inArray) {
                $arrayBuf[] = $str;
                continue;
            }
            $j = $i;
            while ($j < $len && ctype_space($content[$j])) {
                $j++;
            }
            if (substr($content, $j, 2) === 'Tj') {
                $out .= pdf_unescape_string($str);
            }
            continue;
        }
        if ($ch === '[') {
            $inArray = true;
            $arrayBuf = [];
            $i++;
            continue;
        }
        if ($ch === ']') {
            $inArray = false;
            $j = $i + 1;
            while ($j < $len && ctype_space($content[$j])) {
                $j++;
            }
            if (substr($content, $j, 2) === 'TJ') {
                foreach ($arrayBuf as $s) {
                    $out .= pdf_unescape_string($s);
                }
            }
            $arrayBuf = [];
            $i++;
            continue;
        }
        if ($ch === 'T' && preg_match('/\GT[dD*]/', $content, $m, 0, $i)) {
            $out .= "\n";
            $i += strlen($m[0]);
            continue;
        }
        $i++;
    }
    return trim(preg_replace('/[ \t]{2,}/', ' ', $out));
}

// Reads one PDF literal string starting at $content[$i] === '(', honoring
// backslash escapes and balanced nested parens (both legal in the PDF
// string spec). Returns [rawInnerBytes, indexAfterClosingParen].
function pdf_read_literal_string(string $content, int $i): array
{
    $start = $i + 1;
    $depth = 1;
    $i++;
    $len = strlen($content);
    while ($i < $len && $depth > 0) {
        if ($content[$i] === '\\') {
            $i += 2;
            continue;
        }
        if ($content[$i] === '(') {
            $depth++;
        } elseif ($content[$i] === ')') {
            $depth--;
        }
        $i++;
    }
    return [substr($content, $start, $i - $start - 1), $i];
}

function pdf_unescape_string(string $s): string
{
    return (string) preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3}|\r\n|\n)/', function ($m) {
        $c = $m[1];
        return match (true) {
            $c === 'n' => "\n",
            $c === 'r' => "\r",
            $c === 't' => "\t",
            $c === 'b' => "\x08",
            $c === 'f' => "\x0C",
            $c === '(' => '(',
            $c === ')' => ')',
            $c === '\\' => '\\',
            $c === "\n" || $c === "\r\n" => '', // line-continuation escape
            default => chr(octdec($c) & 0xFF),
        };
    }, $s);
}

// ── DOCX text extraction ─────────────────────────────────────────────

function docx_extract_text(string $filePath): ?string
{
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return null;
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        return null;
    }
    // Paragraph/tab/line breaks -> whitespace before stripping tags, so
    // words from adjacent runs don't get glued together.
    $xml = str_replace(['</w:p>', '<w:tab/>', '<w:br/>', '<w:br />'], ["\n", "\t", "\n", "\n"], $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    return trim((string) preg_replace(['/[ \t]{2,}/', '/\n{3,}/'], [' ', "\n\n"], $text));
}

// ── Dispatch + storage ───────────────────────────────────────────────

function extract_document_text(string $filePath, string $kind): ?string
{
    $text = match ($kind) {
        'pdf'   => pdf_extract_text((string) file_get_contents($filePath)),
        'docx'  => docx_extract_text($filePath) ?? '',
        default => (string) file_get_contents($filePath), // txt/md
    };
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    return mb_substr($text, 0, MAX_DOCUMENT_TEXT_CHARS);
}

function fetch_knowledge_documents(int $workspaceId): array
{
    $stmt = db()->prepare(
        'SELECT id, filename, kind, uploaded_at,
                (extracted_text IS NOT NULL) AS has_text,
                (summary IS NOT NULL) AS has_summary
         FROM knowledge_documents WHERE workspace_id = ? ORDER BY uploaded_at DESC'
    );
    $stmt->execute([$workspaceId]);
    return $stmt->fetchAll();
}

function fetch_knowledge_document(int $workspaceId, int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM knowledge_documents WHERE id = ? AND workspace_id = ?');
    $stmt->execute([$id, $workspaceId]);
    return $stmt->fetch() ?: null;
}

function delete_knowledge_document(int $workspaceId, int $id): void
{
    $doc = fetch_knowledge_document($workspaceId, $id);
    if (!$doc) {
        return;
    }
    @unlink($doc['filepath']);
    db()->prepare('DELETE FROM knowledge_documents WHERE id = ? AND workspace_id = ?')->execute([$id, $workspaceId]);
}

// One AI call condensing a document's extracted text into a compact,
// prompt-friendly summary — stored once, then preferred over the raw
// text by workspace_documents_text() so a large document doesn't eat
// the whole context budget on every single generation call afterward.
function ai_summarize_document(string $text, array $aiConfig): string
{
    $input = mb_substr($text, 0, 15000);
    $prompt = <<<PROMPT
Summarize the following reference document into compact bullet points
capturing facts, figures, terminology, and claims a LinkedIn content
writer could reuse — company/product details, data points, customer
proof, positioning. Skip boilerplate and filler. Plain text bullets
only, no markdown headers, under 400 words.

DOCUMENT:
\"\"\"
{$input}
\"\"\"
PROMPT;

    return trim(match ($aiConfig['provider'] ?? 'gemini') {
        'claude' => ai_call_claude($prompt, $aiConfig['api_key'], $aiConfig['model']),
        'openai' => ai_call_openai($prompt, $aiConfig['api_key'], $aiConfig['model']),
        default  => ai_call_gemini_plain_text($prompt, $aiConfig['api_key'], $aiConfig['model']),
    });
}

// ai_call_gemini() (includes/ai_generate.php) forces responseMimeType
// application/json for the creative-generation use case — summaries are
// plain prose, so this is a copy of that call without the JSON coercion.
function ai_call_gemini_plain_text(string $prompt, string $apiKey, string $model): string
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($apiKey);
    $body = ['contents' => [['parts' => [['text' => $prompt]]]]];
    [$status, $response, $curlErr] = ai_http_post_json($url, $body, ['Content-Type: application/json']);
    if ($response === false) {
        throw new RuntimeException("Gemini request failed: {$curlErr}");
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Gemini request failed ({$status}): " . substr($response, 0, 300));
    }
    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($text === null) {
        throw new RuntimeException($data['promptFeedback']['blockReason'] ?? 'Gemini returned an unexpected response shape.');
    }
    return $text;
}

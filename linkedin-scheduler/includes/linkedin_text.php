<?php
// LinkedIn's Posts API "commentary" field uses their "Little Text Format",
// which reserves \ | { } @ [ ] ( ) < > # * _ ~ for structural syntax
// (mentions, hashtags, bold/italic). ANY occurrence of these characters
// in ordinary post text must be backslash-escaped, or LinkedIn silently
// TRUNCATES the post at the first unescaped one — no error, no warning,
// just missing content from that point on. This is a real, documented
// failure mode (an unescaped "(" alone has cut ~1 in 5 posts in the
// wild), so this must run on every commentary string before it's sent,
// exactly once, at the publish boundary.
const LI_RESERVED_CHARS_PATTERN = '/([\\\\|{}@\[\]()<>#*_~])/';

function li_escape_commentary(string $text): string
{
    return preg_replace(LI_RESERVED_CHARS_PATTERN, '\\\\$1', $text);
}

// Converts a caption written with the app's "@[Name]" tagging syntax
// (inserted by the "Tag a Page" toolbar button) into LinkedIn's real
// mention format "@[Name](urn:li:organization:...)", while safely
// escaping every other reserved character in the surrounding text.
//
// $mentionCandidates maps exact display name => target URN (typically
// the poster's own connected linkedin_accounts). Only an "@[Name]" whose
// Name exactly matches a candidate becomes a real, clickable mention —
// anything else is left as ordinary text (and gets escaped like any
// other bracket/@ characters, so it still renders safely, just not as a
// link).
function li_build_commentary(string $raw, array $mentionCandidates): string
{
    $placeholders = [];
    $i = 0;
    $withPlaceholders = preg_replace_callback('/@\[([^\]]+)\]/', function ($m) use ($mentionCandidates, &$placeholders, &$i) {
        $name = $m[1];
        if (isset($mentionCandidates[$name])) {
            $token = "\x01" . $i . "\x01";
            $placeholders[$token] = '@[' . $name . '](' . $mentionCandidates[$name] . ')';
            $i++;
            return $token;
        }
        return $m[0];
    }, $raw);

    $escaped = li_escape_commentary($withPlaceholders);
    return strtr($escaped, $placeholders);
}

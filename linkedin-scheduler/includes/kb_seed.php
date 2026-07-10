<?php
// Generic, industry-agnostic starter Content Knowledge Base — every new
// signup gets this automatically (see includes/auth.php register_user()),
// and existing accounts can load it manually from Settings. Idempotent:
// each insert uses ON DUPLICATE KEY UPDATE against the same unique-name
// constraints the manual Settings "Add" forms use, so calling this
// multiple times never creates duplicates. Brand Brief / Self Brief are
// deliberately left blank — they're inherently individual/company-
// specific and can't be meaningfully generic (see pages/settings.php).

function seed_default_knowledge_base(int $userId): void
{
    $personas = [
        ['Decision Maker', 'The economic buyer — cares about ROI, budget justification, and risk. Wants proof before committing.'],
        ['Practitioner / End User', 'The hands-on person who will actually use what you offer day to day. Cares about ease of use and real-world fit.'],
        ['Peer in Your Industry', 'A fellow professional in your field. Values expertise, honest takes, and things they can apply or debate.'],
        ['Recruiter / Future Employer', 'Someone assessing your track record and skills through your posts, not a customer at all.'],
    ];
    $personaStmt = db()->prepare(
        'INSERT INTO personas (user_id, name, description) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE description = description'
    );
    foreach ($personas as [$name, $desc]) {
        $personaStmt->execute([$userId, $name, $desc]);
    }

    $pillars = [
        ['Case Study / Results', 'A real (or realistic) example of results delivered — numbers, before/after, a specific customer or project.', 'company'],
        ['Educational / How-To', 'Teach something useful and specific related to your field — a tip, a framework, a common mistake to avoid.', 'company'],
        ['Industry News & Opinion', 'React to something happening in your industry with a clear point of view, not just a summary.', 'company'],
        ['Product / Service Highlight', 'A direct but non-salesy look at what you offer and the specific problem it solves.', 'company'],
        ['Behind the Scenes at Work', 'How things actually get done on your team — process, tools, culture, a day in the life.', 'company'],
        ['Personal Milestone', 'A career or life milestone worth sharing — an anniversary, a launch, a goal reached.', 'personal'],
        ['Lessons Learned', 'Something you learned the hard way, told honestly, with the actual takeaway.', 'personal'],
        ['Personal Opinion / Hot Take', 'A genuine, slightly contrarian opinion on something in your field or work life.', 'personal'],
    ];
    $pillarStmt = db()->prepare(
        'INSERT INTO content_pillars (user_id, name, description, category) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE description = description'
    );
    foreach ($pillars as [$name, $desc, $category]) {
        $pillarStmt->execute([$userId, $name, $desc, $category]);
    }

    $ctas = [
        ["What's your take — agree or disagree?", 'Awareness'],
        ['Follow for more like this.', 'Awareness'],
        ['Curious how this could apply to your team? Let\'s talk.', 'Consideration'],
        ['Save this for later.', 'Consideration'],
        ['Book a call to see how we can help.', 'Decision'],
        ['DM me to learn more.', 'Decision'],
        ['Which of these resonates most with you?', 'Retention'],
        ['Tag someone who needs to see this.', 'Retention'],
    ];
    // cta_library has no unique key (multiple identical CTAs are harmless
    // and users may legitimately want near-duplicates), so guard
    // idempotency here explicitly instead.
    $existingStmt = db()->prepare('SELECT COUNT(*) FROM cta_library WHERE user_id = ?');
    $existingStmt->execute([$userId]);
    if ((int) $existingStmt->fetchColumn() === 0) {
        $ctaStmt = db()->prepare('INSERT INTO cta_library (user_id, text, funnel_stage) VALUES (?, ?, ?)');
        foreach ($ctas as [$text, $stage]) {
            $ctaStmt->execute([$userId, $text, $stage]);
        }
    }
}

<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (current_user_id()) {
    redirect('dashboard.php');
}

$pageTitle = 'Easi7 PostPilot — Schedule and generate LinkedIn content';
require __DIR__ . '/includes/public_layout_top.php';
?>
<section class="hero">
  <h1>Plan, generate, and post LinkedIn content — without the daily scramble</h1>
  <p class="hero-subtitle">Easi7 PostPilot schedules your posts, writes drafts with AI in your own
    voice, and keeps every page your team manages organized in one place.</p>
  <div class="hero-actions">
    <a href="<?= h(app_path('signup.php')) ?>" class="btn-primary">Get started free</a>
    <a href="<?= h(app_path('login.php')) ?>" class="btn-secondary">Log in</a>
  </div>
</section>

<section class="feature-grid">
  <div class="card feature-card">
    <h3>Schedule with confidence</h3>
    <p class="muted">Plan posts on a calendar, queue drafts, and let auto-posting publish them on
      time — text, single image, or carousel.</p>
  </div>
  <div class="card feature-card">
    <h3>AI-generated content</h3>
    <p class="muted">Generate on-brand captions and images from a topic or a full content brief,
      powered by your choice of AI provider.</p>
  </div>
  <div class="card feature-card">
    <h3>Content Calendar</h3>
    <p class="muted">Plan a whole batch of posts at once, mixed across your content pillars, then
      review and approve before anything goes out.</p>
  </div>
  <div class="card feature-card">
    <h3>One page or many</h3>
    <p class="muted">Keep a personal profile and every company page you manage in its own
      workspace, each with its own brand voice and knowledge base.</p>
  </div>
  <div class="card feature-card">
    <h3>Built for teams</h3>
    <p class="muted">Invite teammates and grant access to specific pages — everyone works from the
      same content, nobody sees pages they shouldn't.</p>
  </div>
</section>

<section class="cta-band">
  <h2>Ready to plan your next month of content?</h2>
  <a href="<?= h(app_path('signup.php')) ?>" class="btn-primary">Get started free</a>
</section>
<?php require __DIR__ . '/includes/public_layout_bottom.php'; ?>

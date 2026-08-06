<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$pageTitle = 'Privacy Policy — Easi7 PostPilot';
require __DIR__ . '/includes/public_layout_top.php';
?>
<section class="page-header">
  <h1>Privacy Policy</h1>
</section>
<div class="redirect-notice" style="max-width:640px;">
  <strong>Placeholder — replace before launch.</strong> This page is a structural stand-in, not
  legal advice or a real Privacy Policy. Replace this content with your actual policy before the
  site goes live.
</div>
<div class="card" style="max-width:640px;">
  <h3>Data We Collect</h3>
  <p class="muted">Describe what account, content, and usage data is collected.</p>
  <h3 style="margin-top:var(--space-5);">How We Use It</h3>
  <p class="muted">Describe how collected data is used, including any third-party services
    (e.g. LinkedIn, AI providers) content is sent to.</p>
  <h3 style="margin-top:var(--space-5);">Contact</h3>
  <p class="muted">Provide a way for users to reach you with privacy questions.</p>
</div>
<?php require __DIR__ . '/includes/public_layout_bottom.php'; ?>

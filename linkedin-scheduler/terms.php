<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$pageTitle = 'Terms of Service — Easi7 PostPilot';
require __DIR__ . '/includes/public_layout_top.php';
?>
<section class="page-header">
  <h1>Terms of Service</h1>
</section>
<div class="redirect-notice" style="max-width:640px;">
  <strong>Placeholder — replace before launch.</strong> This page is a structural stand-in, not
  legal advice or a real Terms of Service. Replace this content with your actual terms before
  the site goes live.
</div>
<div class="card" style="max-width:640px;">
  <h3>Use of Service</h3>
  <p class="muted">Describe what using Easi7 PostPilot entitles a user to, and any restrictions
    on that use.</p>
  <h3 style="margin-top:var(--space-5);">Accounts</h3>
  <p class="muted">Describe account creation, responsibility for credentials, and termination.</p>
  <h3 style="margin-top:var(--space-5);">Content</h3>
  <p class="muted">Describe ownership of content a user creates or schedules through the
    service, and how it's handled.</p>
</div>
<?php require __DIR__ . '/includes/public_layout_bottom.php'; ?>

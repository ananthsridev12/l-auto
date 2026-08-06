<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$plans = fetch_public_plans();

$pageTitle = 'Pricing — Easi7 PostPilot';
require __DIR__ . '/includes/public_layout_top.php';
?>
<section class="page-header">
  <h1>Simple, straightforward pricing</h1>
</section>
<p class="muted" style="max-width:560px;">Every account starts on the Free plan — no credit card
  required. Need more room for your team or your pages? Contact us to move up a tier.</p>

<section class="pricing-grid">
  <?php foreach ($plans as $plan): ?>
    <div class="card pricing-card">
      <h3><?= h($plan['name']) ?></h3>
      <ul class="pricing-limits">
        <li><?= $plan['max_users'] !== null ? (int) $plan['max_users'] . ' user' . ((int) $plan['max_users'] === 1 ? '' : 's') : 'Unlimited users' ?></li>
        <li><?= $plan['max_workspaces'] !== null ? (int) $plan['max_workspaces'] . ' page' . ((int) $plan['max_workspaces'] === 1 ? '' : 's') : 'Unlimited pages' ?></li>
        <li><?= $plan['max_posts_per_month'] !== null ? (int) $plan['max_posts_per_month'] . ' posts / month' : 'Unlimited posts / month' ?></li>
      </ul>
      <a href="<?= h(app_path('signup.php')) ?>" class="btn-primary">Sign up free</a>
    </div>
  <?php endforeach; ?>
</section>
<?php require __DIR__ . '/includes/public_layout_bottom.php'; ?>

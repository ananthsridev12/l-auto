<?php
/** @var array $acct */
/** @var string $token */
$expiringSoon = $acct['expires_at'] && strtotime($acct['expires_at']) < strtotime('+7 days');
$needsReconnect = $acct['status'] === 'expired' || $expiringSoon;
?>
<div class="account-row">
  <div class="account-info">
    <form method="post" class="inline-form">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="action" value="rename">
      <input type="hidden" name="id" value="<?= (int) $acct['id'] ?>">
      <input type="text" name="display_name" value="<?= h($acct['display_name']) ?>" class="nickname-input">
      <button type="submit" class="btn-tiny">Save</button>
    </form>
    <span class="muted"><?= h($acct['linkedin_name'] ?: $acct['target_urn']) ?></span>
    <?php if ($needsReconnect): ?>
      <span class="badge badge-warning">Reconnect needed</span>
    <?php else: ?>
      <span class="badge badge-active">Active</span>
    <?php endif; ?>
  </div>
  <form method="post" class="inline-form" onsubmit="return confirm('Remove this connected account?');">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="action" value="revoke">
    <input type="hidden" name="id" value="<?= (int) $acct['id'] ?>">
    <button type="submit" class="btn-tiny btn-danger">Remove</button>
  </form>
</div>

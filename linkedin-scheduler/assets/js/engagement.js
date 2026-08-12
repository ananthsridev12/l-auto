// Like/Comment buttons on pages/engagement.php — mirrors postNow()'s
// fetch/toggle-button pattern in app.js, but id-parameterized since this
// page renders a list of target posts rather than a single one.
//
// Self-reported (see includes/engagement.php): the click opens the real
// post on LinkedIn in a new tab AND logs the action as done in the same
// gesture — window.open() is called synchronously, first thing, before
// any await, so browsers don't treat it as a blocked popup (that only
// happens for window.open() calls made outside the direct click
// handler, e.g. after an awaited fetch).

async function engagementLike(targetPostId, accountId, permalinkUrl) {
  const btn = document.getElementById(`like-btn-${targetPostId}`);
  const status = document.getElementById(`like-status-${targetPostId}`);
  if (!btn || !status) return;

  if (permalinkUrl) {
    window.open(permalinkUrl, '_blank', 'noopener');
  }

  btn.disabled = true;
  const originalText = btn.textContent;
  btn.textContent = 'Liking…';
  status.style.display = 'none';

  try {
    const r = await fetch(window.ENGAGEMENT_LIKE_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ target_post_id: targetPostId, linkedin_account_id: accountId }),
    });
    const d = await r.json();
    if (d.success) {
      status.className = 'post-status success';
      status.textContent = 'Marked as liked.';
      btn.textContent = 'Liked ✓';
    } else {
      throw new Error(d.error || 'Unknown error');
    }
  } catch (e) {
    status.className = 'post-status error';
    status.textContent = e.message;
    btn.disabled = false;
    btn.textContent = originalText;
  }
  status.style.display = 'block';
}

async function engagementComment(targetPostId, accountId, permalinkUrl) {
  const textarea = document.getElementById(`comment-text-${targetPostId}`);
  const btn = document.getElementById(`comment-btn-${targetPostId}`);
  const status = document.getElementById(`comment-status-${targetPostId}`);
  if (!textarea || !btn || !status) return;

  const commentText = textarea.value.trim();
  if (!commentText) {
    status.className = 'post-status error';
    status.textContent = 'Enter a comment first.';
    status.style.display = 'block';
    return;
  }

  if (permalinkUrl) {
    window.open(permalinkUrl, '_blank', 'noopener');
  }

  btn.disabled = true;
  const originalText = btn.textContent;
  btn.textContent = 'Posting…';
  status.style.display = 'none';

  try {
    const r = await fetch(window.ENGAGEMENT_COMMENT_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ target_post_id: targetPostId, linkedin_account_id: accountId, comment_text: commentText }),
    });
    const d = await r.json();
    if (d.success) {
      status.className = 'post-status success';
      status.textContent = 'Marked as commented.';
      textarea.value = '';
      btn.textContent = originalText;
    } else {
      throw new Error(d.error || 'Unknown error');
    }
  } catch (e) {
    status.className = 'post-status error';
    status.textContent = e.message;
    btn.textContent = originalText;
  }
  btn.disabled = false;
  status.style.display = 'block';
}

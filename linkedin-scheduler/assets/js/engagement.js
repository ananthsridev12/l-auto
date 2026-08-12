// Like/Comment buttons on pages/engagement.php — mirrors postNow()'s
// fetch/toggle-button pattern in app.js, but id-parameterized since this
// page renders a list of target posts rather than a single one.

async function engagementLike(targetPostId, accountId) {
  const btn = document.getElementById(`like-btn-${targetPostId}`);
  const status = document.getElementById(`like-status-${targetPostId}`);
  if (!btn || !status) return;

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
      status.textContent = 'Liked.';
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

async function engagementComment(targetPostId, accountId) {
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
      status.textContent = 'Comment posted.';
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

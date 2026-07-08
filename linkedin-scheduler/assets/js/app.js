// Shared behavior for the post-editing screens (today.php, post.php):
// slide carousel navigation, caption character counter, and the
// "Post to LinkedIn" AJAX action.

let currentSlide = 0;

function prevSlide() {
  if (currentSlide > 0) { currentSlide--; updateSlide(); }
}
function nextSlide() {
  if (window.SLIDES && currentSlide < window.SLIDES.length - 1) { currentSlide++; updateSlide(); }
}
function updateSlide() {
  const img = document.getElementById('slideImg');
  const counter = document.getElementById('slideCounter');
  if (img) img.src = window.SLIDES[currentSlide];
  if (counter) counter.textContent = `${currentSlide + 1} / ${window.SLIDES.length}`;
}

(function initCharCount() {
  const ta = document.getElementById('caption');
  const cc = document.getElementById('charCount');
  if (!ta || !cc) return;
  const update = () => {
    cc.textContent = ta.value.length;
    cc.style.color = ta.value.length > 2800 ? '#c0392b' : '';
  };
  ta.addEventListener('input', update);
  update();
})();

async function postNow(postId) {
  const btn = document.getElementById('postBtn');
  const status = document.getElementById('postStatus');
  if (!btn || !status) return;

  btn.disabled = true;
  btn.textContent = 'Posting…';
  status.style.display = 'none';

  try {
    const r = await fetch(window.POST_NOW_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ post_id: postId }),
    });
    const d = await r.json();
    if (d.success) {
      status.className = 'post-status success';
      status.textContent = 'Posted successfully to LinkedIn.';
      btn.textContent = 'Posted ✓';
    } else {
      throw new Error(d.error || 'Unknown error');
    }
  } catch (e) {
    status.className = 'post-status error';
    status.textContent = e.message;
    btn.disabled = false;
    btn.textContent = 'Post to LinkedIn';
  }
  status.style.display = 'block';
}

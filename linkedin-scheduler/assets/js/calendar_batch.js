(function () {
  'use strict';

  var status = window.CALENDAR_BATCH_STATUS;
  var batchId = window.CALENDAR_BATCH_ID;

  function post(url, fields) {
    var fd = new FormData();
    fd.append('csrf', window.CALENDAR_CSRF);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }

  function selectedCardIds(container) {
    return Array.prototype.slice.call(container.querySelectorAll('.review-card')).filter(function (card) {
      var cb = card.querySelector('.approve-checkbox');
      return cb && cb.checked;
    }).map(function (card) { return card.dataset.postId; });
  }

  // ── content_review ─────────────────────────────────────────────────
  if (status === 'content_review') {
    var contentCards = document.getElementById('contentCards');
    var contentStatus = document.getElementById('contentStatus');
    var generateMissingBtn = document.getElementById('generateMissingBtn');
    var approveContentBtn = document.getElementById('approveContentBtn');

    function runQueue(ids, urlFn, onDone) {
      var i = 0, ok = 0, fail = 0;
      function next() {
        if (i >= ids.length) { onDone(ok, fail); return; }
        var id = ids[i];
        contentStatus.textContent = 'Working ' + (i + 1) + ' / ' + ids.length + '...';
        urlFn(id).then(function () { ok++; i++; next(); }).catch(function () { fail++; i++; next(); });
      }
      next();
    }

    contentCards.addEventListener('click', function (e) {
      if (e.target.classList.contains('generate-one-btn') || e.target.classList.contains('regenerate-btn')) {
        var card = e.target.closest('.review-card');
        var postId = card.dataset.postId;
        e.target.disabled = true;
        post(window.CALENDAR_GENERATE_ONE_URL, { post_id: postId }).then(function (data) {
          e.target.disabled = false;
          if (data.success) {
            window.location.reload();
          } else {
            alert('Generation failed: ' + data.error);
          }
        });
      }
    });

    generateMissingBtn.addEventListener('click', function () {
      var missing = Array.prototype.slice.call(contentCards.querySelectorAll('.generate-one-btn')).map(function (btn) {
        return btn.closest('.review-card').dataset.postId;
      });
      if (!missing.length) {
        contentStatus.textContent = 'Nothing missing.';
        return;
      }
      generateMissingBtn.disabled = true;
      runQueue(missing, function (id) { return post(window.CALENDAR_GENERATE_ONE_URL, { post_id: id }); }, function (ok, fail) {
        contentStatus.textContent = ok + ' generated' + (fail ? ', ' + fail + ' failed' : '') + '.';
        window.location.reload();
      });
    });

    approveContentBtn.addEventListener('click', function () {
      var postsData = [];
      contentCards.querySelectorAll('.review-card').forEach(function (card) {
        var cb = card.querySelector('.approve-checkbox');
        if (!cb || !cb.checked) return;
        var titleInput = card.querySelector('.title-input');
        var captionInput = card.querySelector('.caption-input');
        if (!titleInput || !captionInput) return; // no content generated yet, nothing to approve
        var slides = [];
        card.querySelectorAll('.slide-fieldset').forEach(function (fs) {
          slides.push({
            slide_number: parseInt(fs.dataset.slideIndex, 10) + 1,
            headline: fs.querySelector('.headline-input').value,
            body: fs.querySelector('.body-input').value,
            points: fs.querySelector('.points-input').value.split('\n').map(function (p) { return p.trim(); }).filter(function (p) { return p !== ''; }),
          });
        });
        postsData.push({ post_id: card.dataset.postId, title: titleInput.value, caption: captionInput.value, slides: slides });
      });
      if (!postsData.length) {
        contentStatus.textContent = 'Select at least one post to approve.';
        return;
      }
      approveContentBtn.disabled = true;
      contentStatus.textContent = 'Approving...';
      post(window.CALENDAR_APPROVE_CONTENT_URL, { batch_id: batchId, posts_json: JSON.stringify(postsData) }).then(function (data) {
        approveContentBtn.disabled = false;
        if (!data.success) {
          contentStatus.textContent = 'Error: ' + data.error;
          return;
        }
        contentStatus.textContent = data.batch_advanced ? 'All approved — moving to image review...' : (data.approved + ' approved, ' + data.remaining + ' still pending.');
        window.location.reload();
      });
    });
  }

  // ── image_review ────────────────────────────────────────────────────
  if (status === 'image_review') {
    var imageCards = document.getElementById('imageCards');
    var imageStatus = document.getElementById('imageStatus');
    var generateImagesBtn = document.getElementById('generateImagesBtn');
    var approveImagesBtn = document.getElementById('approveImagesBtn');

    generateImagesBtn.addEventListener('click', function () {
      var missing = Array.prototype.slice.call(imageCards.querySelectorAll('.review-card')).filter(function (card) {
        return !card.querySelector('img');
      }).map(function (card) { return card.dataset.postId; });
      if (!missing.length) {
        imageStatus.textContent = 'Nothing to generate.';
        return;
      }
      generateImagesBtn.disabled = true;
      var i = 0, ok = 0, fail = 0;
      function next() {
        if (i >= missing.length) {
          imageStatus.textContent = ok + ' rendered' + (fail ? ', ' + fail + ' failed' : '') + '.';
          window.location.reload();
          return;
        }
        var id = missing[i];
        imageStatus.textContent = 'Rendering ' + (i + 1) + ' / ' + missing.length + '...';
        post(window.CALENDAR_RENDER_ONE_URL, { post_id: id }).then(function (data) {
          if (data.success) ok++; else fail++;
          i++; next();
        }).catch(function () { fail++; i++; next(); });
      }
      next();
    });

    approveImagesBtn.addEventListener('click', function () {
      var ids = selectedCardIds(imageCards).filter(function (id) {
        var card = imageCards.querySelector('[data-post-id="' + id + '"]');
        return card && card.querySelector('img');
      });
      if (!ids.length) {
        imageStatus.textContent = 'Select at least one rendered post to approve.';
        return;
      }
      approveImagesBtn.disabled = true;
      imageStatus.textContent = 'Approving...';
      var fd = new FormData();
      fd.append('csrf', window.CALENDAR_CSRF);
      fd.append('batch_id', batchId);
      ids.forEach(function (id) { fd.append('post_ids[]', id); });
      fetch(window.CALENDAR_APPROVE_IMAGES_URL, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          approveImagesBtn.disabled = false;
          if (!data.success) {
            imageStatus.textContent = 'Error: ' + data.error;
            return;
          }
          imageStatus.textContent = data.batch_advanced ? 'All approved — ready to schedule...' : (data.remaining + ' still pending.');
          window.location.reload();
        });
    });
  }

  // ── ready ───────────────────────────────────────────────────────────
  if (status === 'ready') {
    var scheduleForm = document.getElementById('scheduleForm');
    var scheduleStatus = document.getElementById('scheduleStatus');
    scheduleForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var accountId = document.getElementById('scheduleAccountId').value;
      if (!accountId) {
        scheduleStatus.textContent = 'Choose an account first.';
        return;
      }
      document.getElementById('scheduleBtn').disabled = true;
      scheduleStatus.textContent = 'Scheduling...';
      post(window.CALENDAR_SCHEDULE_URL, { batch_id: batchId, linkedin_account_id: accountId }).then(function (data) {
        if (!data.success) {
          document.getElementById('scheduleBtn').disabled = false;
          scheduleStatus.textContent = 'Error: ' + data.error;
          return;
        }
        scheduleStatus.textContent = data.scheduled_count + ' post(s) scheduled.';
        window.location.reload();
      });
    });
  }
})();

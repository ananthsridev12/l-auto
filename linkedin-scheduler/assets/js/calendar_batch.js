(function () {
  'use strict';

  var status = window.CALENDAR_BATCH_STATUS;
  var batchId = window.CALENDAR_BATCH_ID;

  document.addEventListener('change', function (e) {
    if (!e.target.classList.contains('cta-enabled-toggle')) return;
    var card = e.target.closest('.review-card');
    var input = card && card.querySelector('.cta-text-input');
    if (input) input.style.display = e.target.checked ? 'block' : 'none';
  });

  function post(url, fields) {
    var fd = new FormData();
    fd.append('csrf', window.CALENDAR_CSRF);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }

  // AI calls in a tight back-to-back loop can hit transient/rate-limit
  // errors that succeed on a retry a few seconds later — one automatic
  // retry here means a blip doesn't require the user to notice a failed
  // row and manually click Generate again.
  function postWithRetry(url, fields) {
    return post(url, fields).then(function (data) {
      if (data.success) return data;
      return new Promise(function (resolve) { setTimeout(resolve, 4000); }).then(function () {
        return post(url, fields);
      });
    });
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
      var i = 0, ok = 0, fail = 0, errors = [];
      function next() {
        if (i >= ids.length) { onDone(ok, fail, errors); return; }
        var id = ids[i];
        contentStatus.textContent = 'Working ' + (i + 1) + ' / ' + ids.length + '...';
        urlFn(id).then(function (data) {
          if (data.success) { ok++; } else { fail++; errors.push(data.error); }
          i++; next();
        }).catch(function (err) {
          fail++; errors.push(String(err)); i++; next();
        });
      }
      next();
    }

    contentCards.addEventListener('click', function (e) {
      if (e.target.classList.contains('generate-one-btn') || e.target.classList.contains('regenerate-btn')) {
        var card = e.target.closest('.review-card');
        var postId = card.dataset.postId;
        e.target.disabled = true;
        post(window.CALENDAR_GENERATE_ONE_URL, { post_id: postId }).then(function (data) {
          if (!data.success) {
            alert('Generation failed: ' + data.error);
          }
          // Reload either way — a failure is now saved as this post's
          // error_message server-side, so the card shows the actual
          // reason instead of reverting to a plain "No content yet."
          window.location.reload();
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
      runQueue(missing, function (id) { return postWithRetry(window.CALENDAR_GENERATE_ONE_URL, { post_id: id }); }, function (ok, fail, errors) {
        contentStatus.textContent = ok + ' generated' + (fail ? ', ' + fail + ' failed: ' + errors.join(' | ') : '') + '.';
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
            subheading: fs.querySelector('.subheading-input').value,
            body: fs.querySelector('.body-input').value,
            points: fs.querySelector('.points-input').value.split('\n').map(function (p) { return p.trim(); }).filter(function (p) { return p !== ''; }),
          });
        });
        var templateSelect = card.querySelector('.template-select');
        var tpl = templateSelect ? templateSelect.value : '';
        var template = null;
        if (tpl.indexOf('custom:') === 0) {
          template = tpl;
        } else if (tpl) {
          template = parseInt(tpl, 10);
        }
        var layoutChecked = card.querySelector('.template-picker-wrap input:checked');
        var layout = layoutChecked && layoutChecked.value !== 'classic' ? layoutChecked.value : null;
        var backgroundSelect = card.querySelector('.background-select');
        var background = backgroundSelect && backgroundSelect.value !== 'flat' ? backgroundSelect.value : null;
        var sizeSelect = card.querySelector('.size-select');
        var size = sizeSelect && sizeSelect.value !== 'square' ? sizeSelect.value : null;
        var textPositionSelect = card.querySelector('.text-position-select');
        var textPosition = textPositionSelect && textPositionSelect.value !== 'top' ? textPositionSelect.value : null;

        // "Include a CTA" is the source of truth when checked: on a
        // Carousel it forces the last (CTA) slide's line to this exact
        // text; otherwise it's appended to the caption unless already
        // present there (e.g. the AI already wrote a matching closing line).
        var ctaCheckbox = card.querySelector('.cta-enabled-toggle');
        var ctaTextInput = card.querySelector('.cta-text-input');
        var ctaValue = ctaCheckbox && ctaCheckbox.checked && ctaTextInput ? ctaTextInput.value.trim() : '';
        if (ctaValue) {
          if (slides.length > 1) {
            slides[slides.length - 1].points = [ctaValue];
          } else if (captionInput.value.indexOf(ctaValue) === -1) {
            captionInput.value = captionInput.value.replace(/\s+$/, '') + (captionInput.value.trim() ? '\n\n' : '') + ctaValue;
          }
        }

        postsData.push({ post_id: card.dataset.postId, title: titleInput.value, caption: captionInput.value, slides: slides, template: template, layout: layout, background: background, size: size, text_position: textPosition });
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

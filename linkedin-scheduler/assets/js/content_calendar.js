(function () {
  'use strict';

  var form = document.getElementById('calendarGenerateForm');
  if (!form) {
    return;
  }

  var pillarInputs = Array.prototype.slice.call(document.querySelectorAll('.pillar-weight-input'));
  var formatInputs = Array.prototype.slice.call(document.querySelectorAll('.format-weight-input'));
  var rollupDisplay = document.getElementById('rollupDisplay');
  var pillarSumDisplay = document.getElementById('pillarSumDisplay');
  var formatSumDisplay = document.getElementById('formatSumDisplay');
  var generateBtn = document.getElementById('generateBtn');
  var statusEl = document.getElementById('generateStatus');

  function updateDisplays() {
    var company = 0, personal = 0, pillarSum = 0;
    pillarInputs.forEach(function (input) {
      var val = parseFloat(input.value) || 0;
      pillarSum += val;
      if (input.dataset.category === 'personal') personal += val; else company += val;
    });
    var total = company + personal;
    var companyPct = total ? Math.round((company / total) * 100) : 0;
    var personalPct = total ? 100 - companyPct : 0;
    rollupDisplay.textContent = 'Company: ' + companyPct + '% / Personal: ' + personalPct + '%';
    pillarSumDisplay.textContent = 'Pillar total: ' + pillarSum + '% (does not need to be exactly 100 — it is normalized automatically)';

    var formatSum = 0;
    formatInputs.forEach(function (input) { formatSum += parseFloat(input.value) || 0; });
    formatSumDisplay.textContent = 'Format total: ' + formatSum + '% (also normalized automatically)';
  }
  pillarInputs.concat(formatInputs).forEach(function (input) {
    input.addEventListener('input', updateDisplays);
  });
  updateDisplays();

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    var fd = new FormData();
    fd.append('csrf', window.CALENDAR_CSRF);
    fd.append('period_days', document.getElementById('periodDays').value);
    fd.append('posts_per_week', document.getElementById('postsPerWeek').value);
    fd.append('content_length', document.getElementById('calLength').value);
    pillarInputs.forEach(function (input) {
      var val = parseFloat(input.value) || 0;
      if (val > 0) fd.append('pillar_weights[' + input.dataset.pillarId + ']', val);
    });
    formatInputs.forEach(function (input) {
      var val = parseFloat(input.value) || 0;
      if (val > 0) fd.append('format_weights[' + input.dataset.format + ']', val);
    });

    generateBtn.disabled = true;
    statusEl.textContent = 'Planning calendar...';

    fetch(window.CALENDAR_PLAN_URL, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          generateBtn.disabled = false;
          statusEl.textContent = 'Error: ' + data.error;
          return;
        }
        generateContentForBatch(data.batch_id, data.post_ids);
      })
      .catch(function (err) {
        generateBtn.disabled = false;
        statusEl.textContent = 'Request failed: ' + err;
      });
  });

  function generateContentForBatch(batchId, postIds) {
    var done = 0, failed = 0, errors = [];

    function callOnce(postId) {
      var fd = new FormData();
      fd.append('csrf', window.CALENDAR_CSRF);
      fd.append('post_id', postId);
      return fetch(window.CALENDAR_GENERATE_ONE_URL, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    }

    // One automatic retry after a short delay — AI calls fired back-to-
    // back with no gap can hit a transient/rate-limit error that clears
    // up a few seconds later, so this avoids a batch showing failed rows
    // over what's often just a momentary blip.
    function callWithRetry(postId) {
      return callOnce(postId).then(function (data) {
        if (data.success) return data;
        return new Promise(function (resolve) { setTimeout(resolve, 4000); }).then(function () { return callOnce(postId); });
      });
    }

    function next() {
      if (done + failed >= postIds.length) {
        var summary = 'Done: ' + done + ' generated';
        if (failed) {
          summary += ', ' + failed + ' failed — ' + errors.join(' | ') + ' (retry from the review screen)';
        }
        statusEl.textContent = summary + '. Opening calendar...';
        var goToBatch = function () { window.location.href = window.CALENDAR_BATCH_BASE_URL + '?id=' + batchId; };
        failed ? setTimeout(goToBatch, 2500) : goToBatch();
        return;
      }
      var postId = postIds[done + failed];
      statusEl.textContent = 'Generating content ' + (done + failed + 1) + ' / ' + postIds.length + '...';
      callWithRetry(postId)
        .then(function (data) {
          if (data.success) { done++; } else { failed++; errors.push(data.error); }
          next();
        })
        .catch(function (err) {
          failed++;
          errors.push(String(err));
          next();
        });
    }
    next();
  }
})();

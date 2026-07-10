(function () {
  'use strict';

  var toggle = document.getElementById('aiGenerateToggle');
  var fields = document.getElementById('aiGenerateFields');
  var generateBtn = document.getElementById('aiGenerateBtn');
  var statusEl = document.getElementById('aiGenerateStatus');
  var reviewEl = document.getElementById('aiSlidesReview');
  var formatSelect = document.getElementById('formatSelect');
  var captionEl = document.getElementById('caption');
  var titleEl = document.getElementById('titleField');
  var jsonField = document.getElementById('aiCreativeJsonField');
  var form = document.getElementById('newPostForm');
  if (!toggle || !form) {
    return;
  }

  var currentCreative = null;

  toggle.addEventListener('change', function () {
    fields.style.display = toggle.checked ? 'block' : 'none';
    if (window.newPostUpdateUploadFields) {
      window.newPostUpdateUploadFields();
    }
  });

  generateBtn.addEventListener('click', function () {
    var format = formatSelect.value;
    var topic = document.getElementById('aiTopic').value.trim();
    var caption = captionEl ? captionEl.value.trim() : '';
    if (!topic && !caption) {
      statusEl.textContent = 'Enter a topic/title (or write a caption) first.';
      return;
    }

    var fd = new FormData();
    fd.append('csrf', window.NEW_POST_CSRF);
    fd.append('format', format);
    fd.append('topic', topic);
    fd.append('persona', document.getElementById('aiPersona').value.trim());
    fd.append('type', document.getElementById('aiType').value.trim());
    fd.append('cta', document.getElementById('aiCta').value.trim());
    fd.append('caption', caption);

    statusEl.textContent = 'Generating...';
    generateBtn.disabled = true;
    fetch(window.AI_GENERATE_PREVIEW_URL, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        generateBtn.disabled = false;
        if (!data.success) {
          statusEl.textContent = 'Error: ' + data.error;
          return;
        }
        currentCreative = data.creative;
        statusEl.textContent = 'Generated — review and edit below, then Save/Schedule/Post as usual.';
        if (titleEl && currentCreative.title) {
          titleEl.value = currentCreative.title;
        }
        if (captionEl && currentCreative.caption) {
          captionEl.value = currentCreative.caption;
        }
        renderReview();
      })
      .catch(function (err) {
        generateBtn.disabled = false;
        statusEl.textContent = 'Request failed: ' + err;
      });
  });

  function renderReview() {
    reviewEl.innerHTML = '';
    if (!currentCreative || !currentCreative.slides || !currentCreative.slides.length) {
      return;
    }
    currentCreative.slides.forEach(function (slide, si) {
      var fieldset = document.createElement('fieldset');
      fieldset.className = 'slide-fieldset';
      fieldset.dataset.slideIndex = si;
      var legend = document.createElement('legend');
      legend.textContent = 'Slide ' + (si + 1);
      fieldset.appendChild(legend);
      fieldset.appendChild(labeledInput('Headline', 'ai-headline-input', slide.headline || ''));
      fieldset.appendChild(labeledTextarea('Body', 'ai-body-input', slide.body || ''));
      fieldset.appendChild(labeledTextarea('Points (one per line)', 'ai-points-input', (slide.points || []).join('\n')));
      reviewEl.appendChild(fieldset);
    });
  }

  function labeledInput(labelText, cls, value) {
    var label = document.createElement('label');
    label.className = 'field-row';
    label.textContent = labelText;
    var input = document.createElement('input');
    input.type = 'text';
    input.className = cls;
    input.value = value;
    label.appendChild(input);
    return label;
  }

  function labeledTextarea(labelText, cls, value) {
    var label = document.createElement('label');
    label.className = 'field-row';
    label.textContent = labelText;
    var textarea = document.createElement('textarea');
    textarea.className = cls;
    textarea.rows = 2;
    textarea.value = value;
    label.appendChild(textarea);
    return label;
  }

  form.addEventListener('submit', function () {
    if (!toggle.checked || !currentCreative) {
      jsonField.value = '';
      return;
    }
    reviewEl.querySelectorAll('.slide-fieldset').forEach(function (fs) {
      var si = parseInt(fs.dataset.slideIndex, 10);
      var slide = currentCreative.slides[si];
      slide.headline = fs.querySelector('.ai-headline-input').value;
      slide.body = fs.querySelector('.ai-body-input').value;
      slide.points = fs.querySelector('.ai-points-input').value.split('\n').map(function (p) { return p.trim(); }).filter(function (p) { return p !== ''; });
    });
    currentCreative.title = titleEl ? titleEl.value : currentCreative.title;
    currentCreative.caption = captionEl ? captionEl.value : currentCreative.caption;
    jsonField.value = JSON.stringify(currentCreative);
  });
})();

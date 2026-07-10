(function () {
  'use strict';

  var aiToggle = document.getElementById('aiGenerateToggle');
  var manualToggle = document.getElementById('manualCreativeToggle');
  var aiFields = document.getElementById('aiGenerateFields');
  var slidesPanel = document.getElementById('creativeSlidesPanel');
  var generateBtn = document.getElementById('aiGenerateBtn');
  var statusEl = document.getElementById('aiGenerateStatus');
  var reviewEl = document.getElementById('aiSlidesReview');
  var addSlideBtn = document.getElementById('addSlideBtn');
  var formatSelect = document.getElementById('formatSelect');
  var captionEl = document.getElementById('caption');
  var titleEl = document.getElementById('titleField');
  var jsonField = document.getElementById('aiCreativeJsonField');
  var form = document.getElementById('newPostForm');
  if (!form || (!aiToggle && !manualToggle)) {
    return;
  }

  var currentCreative = null;
  // 'ai' | 'manual' | null — which of the two mutually-exclusive toggles
  // currently owns currentCreative/the shared review UI.
  var mode = null;

  function emptySlide() {
    return { headline: '', body: '', points: ['', '', ''] };
  }

  // Manual mode's starting point when the toggle is switched on (or the
  // format changes while it's on): one slide for Single Image, three for
  // Carousel (typical length — Add/Remove Slide adjusts from there).
  function blankCreative() {
    var isCarousel = formatSelect.value === 'Carousel';
    var slideCount = isCarousel ? 3 : 1;
    var slides = [];
    for (var i = 0; i < slideCount; i++) {
      slides.push(emptySlide());
    }
    return {
      title: titleEl ? titleEl.value : '',
      caption: captionEl ? captionEl.value : '',
      hashtags: [],
      format: isCarousel ? 'carousel' : 'single',
      slides: slides,
    };
  }

  function updatePanels() {
    if (aiFields) aiFields.style.display = mode === 'ai' ? 'block' : 'none';
    if (slidesPanel) slidesPanel.style.display = mode ? 'block' : 'none';
    if (addSlideBtn) {
      addSlideBtn.style.display = (mode === 'manual' && formatSelect.value === 'Carousel') ? 'inline-block' : 'none';
    }
    if (window.newPostUpdateUploadFields) {
      window.newPostUpdateUploadFields();
    }
  }

  if (aiToggle) {
    aiToggle.addEventListener('change', function () {
      if (aiToggle.checked) {
        if (manualToggle) manualToggle.checked = false;
        mode = 'ai';
      } else if (mode === 'ai') {
        mode = null;
        currentCreative = null;
        reviewEl.innerHTML = '';
      }
      updatePanels();
    });
  }

  if (manualToggle) {
    manualToggle.addEventListener('change', function () {
      if (manualToggle.checked) {
        if (aiToggle) aiToggle.checked = false;
        mode = 'manual';
        currentCreative = blankCreative();
        renderReview();
      } else if (mode === 'manual') {
        mode = null;
        currentCreative = null;
        reviewEl.innerHTML = '';
      }
      updatePanels();
    });
  }

  formatSelect.addEventListener('change', function () {
    if (mode === 'manual') {
      currentCreative = blankCreative();
      renderReview();
    }
    updatePanels();
  });

  if (addSlideBtn) {
    addSlideBtn.addEventListener('click', function () {
      if (!currentCreative) return;
      var max = window.MAX_SLIDES_PER_CAMPAIGN || 20;
      if (currentCreative.slides.length >= max) {
        statusEl.textContent = 'A Carousel can have at most ' + max + ' slides.';
        return;
      }
      syncFieldsIntoCreative();
      currentCreative.slides.push(emptySlide());
      renderReview();
    });
  }

  // Each Knowledge Base dropdown reveals its "type my own" text input
  // only when "custom" is selected — otherwise the picked entry's id is
  // sent and the free-text field is ignored.
  [['aiPersonaSelect', 'aiPersona'], ['aiPillarSelect', 'aiType'], ['aiCtaSelect', 'aiCta']].forEach(function (pair) {
    var select = document.getElementById(pair[0]);
    var input = document.getElementById(pair[1]);
    if (!select || !input) return;
    select.addEventListener('change', function () {
      input.style.display = select.value === 'custom' ? 'block' : 'none';
      if (select.value !== 'custom') input.value = '';
    });
  });

  // Sends the dropdown's picked Knowledge Base id under $idField when a
  // real entry is selected, else the free-text fallback input under
  // $textField (only meaningful when "custom" was chosen).
  function appendKbField(fd, selectId, textId, idField, textField) {
    var select = document.getElementById(selectId);
    var val = select ? select.value : '';
    if (val && val !== 'custom') {
      fd.append(idField, val);
      fd.append(textField, '');
    } else {
      fd.append(idField, '');
      fd.append(textField, document.getElementById(textId).value.trim());
    }
  }

  if (generateBtn) {
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
      appendKbField(fd, 'aiPersonaSelect', 'aiPersona', 'persona_id', 'persona');
      appendKbField(fd, 'aiPillarSelect', 'aiType', 'pillar_id', 'type');
      appendKbField(fd, 'aiCtaSelect', 'aiCta', 'cta_id', 'cta');
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
  }

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
      if (mode === 'manual' && currentCreative.slides.length > 1) {
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn-tiny btn-danger';
        removeBtn.textContent = 'Remove Slide';
        removeBtn.style.marginTop = '6px';
        removeBtn.addEventListener('click', function () {
          syncFieldsIntoCreative();
          currentCreative.slides.splice(si, 1);
          renderReview();
        });
        fieldset.appendChild(removeBtn);
      }
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

  function syncFieldsIntoCreative() {
    reviewEl.querySelectorAll('.slide-fieldset').forEach(function (fs) {
      var si = parseInt(fs.dataset.slideIndex, 10);
      var slide = currentCreative.slides[si];
      if (!slide) return;
      slide.headline = fs.querySelector('.ai-headline-input').value;
      slide.body = fs.querySelector('.ai-body-input').value;
      slide.points = fs.querySelector('.ai-points-input').value.split('\n').map(function (p) { return p.trim(); }).filter(function (p) { return p !== ''; });
    });
    // render_creative_to_slides() uses slide_number to pick hook/content/
    // CTA layout for a carousel (1 = hook, last = CTA, others = content)
    // — keep it in sync with actual array position after any Add/Remove.
    currentCreative.slides.forEach(function (slide, i) {
      slide.slide_number = i + 1;
    });
  }

  form.addEventListener('submit', function () {
    if (!mode || !currentCreative) {
      jsonField.value = '';
      return;
    }
    syncFieldsIntoCreative();
    currentCreative.title = titleEl ? titleEl.value : currentCreative.title;
    currentCreative.caption = captionEl ? captionEl.value : currentCreative.caption;
    var templateSelect = document.getElementById('aiTemplateSelect');
    var tpl = templateSelect ? templateSelect.value : '';
    if (tpl.indexOf('custom:') === 0) {
      currentCreative.template = tpl;
    } else if (tpl) {
      currentCreative.template = parseInt(tpl, 10);
    } else {
      delete currentCreative.template;
    }
    jsonField.value = JSON.stringify(currentCreative);
  });
})();

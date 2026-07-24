(function () {
  'use strict';

  var state = { rows: [], pageLabels: [], accounts: [], suggested: {}, csvFilename: '' };

  var csvFileInput = document.getElementById('csvFile');
  var uploadBtn = document.getElementById('csvUploadBtn');
  var statusEl = document.getElementById('csvStatus');
  var step2 = document.getElementById('step2');
  var step3 = document.getElementById('step3');
  var mappingRows = document.getElementById('mappingRows');
  var previewSummary = document.getElementById('previewSummary');
  var reviewCards = document.getElementById('reviewCards');
  var confirmForm = document.getElementById('confirmForm');

  uploadBtn.addEventListener('click', function () {
    var file = csvFileInput.files[0];
    if (!file) {
      statusEl.textContent = 'Choose a CSV file first.';
      return;
    }
    var fd = new FormData();
    fd.append('csv', file);
    fd.append('csrf', window.CONTENT_STUDIO_CSRF);

    statusEl.textContent = 'Parsing and generating copy — this can take a moment if AI generation is needed...';
    uploadBtn.disabled = true;
    fetch(window.CONTENT_STUDIO_PREVIEW_URL, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        uploadBtn.disabled = false;
        if (!data.success) {
          statusEl.textContent = 'Error: ' + data.error;
          return;
        }
        state.rows = data.rows;
        state.pageLabels = data.page_labels;
        state.accounts = data.accounts;
        state.suggested = data.suggested_matches;
        state.csvFilename = data.csv_filename;
        statusEl.textContent = data.rows.length + ' row(s) parsed.' + (data.ai_configured ? '' : ' (No AI provider configured in Settings — only rows with pre-written Creative Content will generate.)');
        renderMapping();
        renderReview();
        step2.style.display = '';
        step3.style.display = '';
      })
      .catch(function (err) {
        uploadBtn.disabled = false;
        statusEl.textContent = 'Upload failed: ' + err;
      });
  });

  function renderMapping() {
    mappingRows.innerHTML = '';
    state.pageLabels.forEach(function (label) {
      var row = document.createElement('div');
      row.className = 'mapping-row';

      var span = document.createElement('span');
      span.textContent = label;
      row.appendChild(span);

      var select = document.createElement('select');
      select.dataset.label = label;
      select.className = 'mapping-select';

      var blank = document.createElement('option');
      blank.value = '';
      blank.textContent = '— Unmatched (assign later) —';
      select.appendChild(blank);

      state.accounts.forEach(function (acct) {
        var opt = document.createElement('option');
        opt.value = acct.id;
        opt.textContent = acct.display_name + ' (' + acct.account_type + ')';
        if (state.suggested[label] === acct.id) {
          opt.selected = true;
        }
        select.appendChild(opt);
      });

      row.appendChild(select);
      mappingRows.appendChild(row);
    });
  }

  function currentMapping() {
    var mapping = {};
    mappingRows.querySelectorAll('.mapping-select').forEach(function (sel) {
      mapping[sel.dataset.label] = sel.value ? parseInt(sel.value, 10) : null;
    });
    return mapping;
  }

  function renderReview() {
    reviewCards.innerHTML = '';
    var ready = 0, skipped = 0;

    state.rows.forEach(function (row, idx) {
      if (row.skip || !row.creative) {
        skipped++;
        var skipCard = document.createElement('div');
        skipCard.className = 'card skip-card';
        skipCard.style.opacity = '0.6';
        skipCard.innerHTML = '<strong>' + escapeHtml(row.campaign_id || '(no ID)') + '</strong>' +
          ' <span class="muted">— skipped: ' + escapeHtml(row.skip_reason || 'unknown reason') + '</span>';
        reviewCards.appendChild(skipCard);
        return;
      }

      ready++;
      var c = row.creative;
      var card = document.createElement('div');
      card.className = 'card review-card';
      card.dataset.index = idx;

      var header = document.createElement('div');
      header.className = 'review-card-header';
      header.innerHTML = '<strong>' + escapeHtml(row.campaign_id) + '</strong> ' +
        '<span class="badge">' + escapeHtml(row.format) + '</span> ' +
        '<span class="badge ' + (row.source === 'ai' ? 'badge-warning' : '') + '">' + (row.source === 'ai' ? 'AI generated' : 'Written') + '</span>';
      var skipLabel = document.createElement('label');
      skipLabel.className = 'checkbox-row skip-toggle';
      skipLabel.innerHTML = '<input type="checkbox" class="row-skip-toggle"> Skip this row';
      header.appendChild(skipLabel);
      card.appendChild(header);

      card.appendChild(labeledInput('Title', 'title-input', c.title || ''));
      card.appendChild(labeledTextarea('Caption', 'caption-input', c.caption || ''));

      var ctaLabel = document.createElement('label');
      ctaLabel.className = 'checkbox-row';
      var ctaCheckbox = document.createElement('input');
      ctaCheckbox.type = 'checkbox';
      ctaCheckbox.className = 'cta-enabled-toggle';
      ctaLabel.appendChild(ctaCheckbox);
      ctaLabel.appendChild(document.createTextNode(' Include a CTA'));
      card.appendChild(ctaLabel);
      var ctaTextInput = document.createElement('input');
      ctaTextInput.type = 'text';
      ctaTextInput.className = 'cta-text-input';
      ctaTextInput.placeholder = 'e.g. Book a call with our team';
      ctaTextInput.style.display = 'none';
      card.appendChild(ctaTextInput);
      var ctaStyleLabel = document.createElement('label');
      ctaStyleLabel.textContent = 'CTA Style ';
      ctaStyleLabel.style.display = 'none';
      ctaStyleLabel.style.marginTop = '6px';
      var ctaStyleSelect = document.createElement('select');
      ctaStyleSelect.className = 'cta-style-select';
      [['text', 'Text (default)'], ['button', 'Button'], ['outline', 'Outline Button']].forEach(function (opt) {
        var o = document.createElement('option');
        o.value = opt[0];
        o.textContent = opt[1];
        if (opt[0] === (c.cta_style || 'text')) o.selected = true;
        ctaStyleSelect.appendChild(o);
      });
      ctaStyleLabel.appendChild(ctaStyleSelect);
      card.appendChild(ctaStyleLabel);
      ctaCheckbox.addEventListener('change', function () {
        ctaTextInput.style.display = ctaCheckbox.checked ? 'block' : 'none';
        ctaStyleLabel.style.display = ctaCheckbox.checked ? 'block' : 'none';
      });

      var templateWrap = document.createElement('label');
      templateWrap.textContent = 'Color Palette ';
      var templateSelect = document.createElement('select');
      templateSelect.className = 'template-select';
      var templateOptions = [['', 'Auto'], ['1', 'Cream'], ['2', 'Dark Green'], ['3', 'Olive'], ['4', 'Medium Green']];
      (window.BRAND_PALETTES || []).forEach(function (p) {
        templateOptions.push(['custom:' + p.id, p.name]);
      });
      templateOptions.forEach(function (opt) {
        var o = document.createElement('option');
        o.value = opt[0];
        o.textContent = opt[1];
        if (String(c.template || '') === opt[0]) o.selected = true;
        templateSelect.appendChild(o);
      });
      templateWrap.appendChild(templateSelect);
      card.appendChild(templateWrap);

      var layoutLabel = document.createElement('label');
      layoutLabel.textContent = 'Design Template';
      card.appendChild(layoutLabel);
      var layoutGrid = document.createElement('div');
      layoutGrid.className = 'template-grid';
      (window.DESIGN_TEMPLATES || []).forEach(function (t) {
        var selected = (c.layout || 'classic') === t.id;
        var opt = document.createElement('label');
        opt.className = 'template-option' + (selected ? ' selected' : '');
        var input = document.createElement('input');
        input.type = 'radio';
        input.name = 'design_template_' + idx;
        input.value = t.id;
        if (selected) input.checked = true;
        var img = document.createElement('img');
        img.src = t.thumb;
        img.alt = t.name;
        img.loading = 'lazy';
        var span = document.createElement('span');
        span.textContent = t.name;
        opt.appendChild(input);
        opt.appendChild(img);
        opt.appendChild(span);
        layoutGrid.appendChild(opt);
      });
      card.appendChild(layoutGrid);

      var backgroundWrap = document.createElement('label');
      backgroundWrap.textContent = 'Background ';
      var backgroundSelect = document.createElement('select');
      backgroundSelect.className = 'background-select';
      [['flat', 'Flat'], ['gradient', 'Gradient'], ['image', 'Image']].forEach(function (opt) {
        var o = document.createElement('option');
        o.value = opt[0];
        o.textContent = opt[1];
        if ((c.background || 'flat') === opt[0]) o.selected = true;
        backgroundSelect.appendChild(o);
      });
      backgroundWrap.appendChild(backgroundSelect);
      card.appendChild(backgroundWrap);

      var sizeWrap = document.createElement('label');
      sizeWrap.textContent = 'Size ';
      var sizeSelect = document.createElement('select');
      sizeSelect.className = 'size-select';
      [['square', 'Square (1:1)'], ['portrait', 'Portrait (4:5, Document)']].forEach(function (opt) {
        var o = document.createElement('option');
        o.value = opt[0];
        o.textContent = opt[1];
        if ((c.size || 'square') === opt[0]) o.selected = true;
        sizeSelect.appendChild(o);
      });
      sizeWrap.appendChild(sizeSelect);
      card.appendChild(sizeWrap);

      var textPositionWrap = document.createElement('label');
      textPositionWrap.textContent = 'Text Position ';
      var textPositionSelect = document.createElement('select');
      textPositionSelect.className = 'text-position-select';
      [['top', 'Top (default)'], ['center', 'Center'], ['bottom', 'Bottom']].forEach(function (opt) {
        var o = document.createElement('option');
        o.value = opt[0];
        o.textContent = opt[1];
        if ((c.text_position || 'top') === opt[0]) o.selected = true;
        textPositionSelect.appendChild(o);
      });
      textPositionWrap.appendChild(textPositionSelect);
      card.appendChild(textPositionWrap);

      var fontScaleLabel = document.createElement('label');
      fontScaleLabel.textContent = 'Text Size (optional — 100% is default)';
      card.appendChild(fontScaleLabel);
      var fontScaleGroup = document.createElement('div');
      fontScaleGroup.className = 'font-scale-group';
      [['headline', 'Headline'], ['subheading', 'Subheading'], ['body', 'Body'], ['points', 'Points']].forEach(function (pair) {
        var row = document.createElement('label');
        row.className = 'field-row';
        row.textContent = pair[1] + ' ';
        var slider = document.createElement('input');
        slider.type = 'range';
        slider.className = 'font-scale-slider';
        slider.dataset.role = pair[0];
        slider.min = '50';
        slider.max = '200';
        slider.value = String((c.font_scale && c.font_scale[pair[0]]) || 100);
        var readout = document.createElement('span');
        readout.textContent = slider.value + '%';
        slider.addEventListener('input', function () {
          readout.textContent = slider.value + '%';
        });
        row.appendChild(slider);
        row.appendChild(readout);
        fontScaleGroup.appendChild(row);
      });
      card.appendChild(fontScaleGroup);

      var slidesWrap = document.createElement('div');
      slidesWrap.className = 'slides-wrap';
      (c.slides || []).forEach(function (slide, si) {
        var fieldset = document.createElement('fieldset');
        fieldset.className = 'slide-fieldset';
        fieldset.dataset.slideIndex = si;
        var legend = document.createElement('legend');
        legend.textContent = 'Slide ' + (si + 1);
        fieldset.appendChild(legend);
        fieldset.appendChild(labeledInput('Headline', 'headline-input', slide.headline || ''));
        fieldset.appendChild(labeledInput('Subheading (optional)', 'subheading-input', slide.subheading || ''));
        fieldset.appendChild(labeledTextarea('Body', 'body-input', slide.body || ''));
        fieldset.appendChild(labeledTextarea('Points (one per line)', 'points-input', (slide.points || []).join('\n')));
        slidesWrap.appendChild(fieldset);
      });
      card.appendChild(slidesWrap);

      reviewCards.appendChild(card);
    });

    previewSummary.textContent = ready + ' post(s) ready to render, ' + skipped + ' skipped.';
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
    textarea.rows = cls === 'caption-input' ? 4 : 2;
    textarea.value = value;
    label.appendChild(textarea);
    return label;
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  // Reads the (possibly edited) DOM fields for every review card back
  // into state.rows[idx].creative, and applies the per-row skip toggle,
  // right before the confirm form is submitted.
  function syncStateFromDom() {
    reviewCards.querySelectorAll('.review-card').forEach(function (card) {
      var idx = parseInt(card.dataset.index, 10);
      var row = state.rows[idx];
      if (card.querySelector('.row-skip-toggle').checked) {
        row.skip = true;
        return;
      }
      var c = row.creative;
      c.title = card.querySelector('.title-input').value;
      c.caption = card.querySelector('.caption-input').value;
      var tpl = card.querySelector('.template-select').value;
      if (tpl.indexOf('custom:') === 0) {
        c.template = tpl;
      } else if (tpl) {
        c.template = parseInt(tpl, 10);
      } else {
        delete c.template;
      }
      var layoutChecked = card.querySelector('.template-grid input:checked');
      var layout = layoutChecked ? layoutChecked.value : 'classic';
      if (layout && layout !== 'classic') {
        c.layout = layout;
      } else {
        delete c.layout;
      }
      var background = card.querySelector('.background-select').value;
      if (background && background !== 'flat') {
        c.background = background;
      } else {
        delete c.background;
      }
      var size = card.querySelector('.size-select').value;
      if (size && size !== 'square') {
        c.size = size;
      } else {
        delete c.size;
      }
      var textPosition = card.querySelector('.text-position-select').value;
      if (textPosition && textPosition !== 'top') {
        c.text_position = textPosition;
      } else {
        delete c.text_position;
      }
      var fontScale = {};
      var fontScaleChanged = false;
      card.querySelectorAll('.font-scale-slider').forEach(function (slider) {
        var val = parseInt(slider.value, 10) || 100;
        fontScale[slider.dataset.role] = val;
        if (val !== 100) fontScaleChanged = true;
      });
      if (fontScaleChanged) {
        c.font_scale = fontScale;
      } else {
        delete c.font_scale;
      }
      card.querySelectorAll('.slide-fieldset').forEach(function (fs) {
        var si = parseInt(fs.dataset.slideIndex, 10);
        var slide = c.slides[si];
        slide.headline = fs.querySelector('.headline-input').value;
        slide.subheading = fs.querySelector('.subheading-input').value;
        slide.body = fs.querySelector('.body-input').value;
        slide.points = fs.querySelector('.points-input').value.split('\n').map(function (p) { return p.trim(); }).filter(function (p) { return p !== ''; });
      });

      // "Include a CTA" is the source of truth when checked, drawn as an
      // actual banner in the image wherever the renderer supports one: on
      // a Carousel it forces the last (CTA) slide's line to this exact
      // text; on a Single Image it sets that one slide's `cta` field,
      // which render_slide_single() draws as its own banner. A row with
      // no slides at all (Text Post) has no image, so the only option is
      // appending the line to the caption.
      var ctaCheckbox = card.querySelector('.cta-enabled-toggle');
      var ctaTextInput = card.querySelector('.cta-text-input');
      var ctaValue = ctaCheckbox && ctaCheckbox.checked && ctaTextInput ? ctaTextInput.value.trim() : '';
      if (ctaValue) {
        var slides = c.slides || [];
        if (slides.length > 1) {
          slides[slides.length - 1].points = [ctaValue];
        } else if (slides.length === 1) {
          slides[0].cta = ctaValue;
        } else if (!c.caption || c.caption.indexOf(ctaValue) === -1) {
          c.caption = c.caption ? c.caption.replace(/\s+$/, '') + '\n\n' + ctaValue : ctaValue;
          card.querySelector('.caption-input').value = c.caption;
        }
      }
      var ctaStyleSelect = card.querySelector('.cta-style-select');
      if (ctaValue && ctaStyleSelect && ctaStyleSelect.value !== 'text') {
        c.cta_style = ctaStyleSelect.value;
      } else {
        delete c.cta_style;
      }
    });
  }

  confirmForm.addEventListener('submit', function () {
    syncStateFromDom();
    document.getElementById('rowsJsonField').value = JSON.stringify(state.rows);
    document.getElementById('mappingJsonField').value = JSON.stringify(currentMapping());
    document.getElementById('csvFilenameField').value = state.csvFilename;
  });
})();

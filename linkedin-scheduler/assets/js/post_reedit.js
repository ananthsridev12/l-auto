// "Edit Image Content" on pages/post.php — collects the edited slide
// fields + palette/design/background pickers and asks
// api/post_rerender.php to replace the post's rendered image, then swaps
// the on-page preview to the fresh files (URLs carry a ?v= cache-buster
// since the render reuses the same filenames).
(function () {
  'use strict';

  var btn = document.getElementById('reeditRenderBtn');
  if (!btn || !window.POST_REEDIT) {
    return;
  }
  var statusEl = document.getElementById('reeditStatus');

  btn.addEventListener('click', function () {
    var slides = [];
    document.querySelectorAll('#reeditCard .slide-fieldset').forEach(function (fs) {
      slides.push({
        headline: fs.querySelector('.reedit-headline').value,
        body: fs.querySelector('.reedit-body').value,
        points: fs.querySelector('.reedit-points').value.split('\n')
          .map(function (p) { return p.trim(); })
          .filter(function (p) { return p !== ''; }),
      });
    });

    var creative = { slides: slides };
    var tpl = document.getElementById('reeditTemplateSelect').value;
    if (tpl.indexOf('custom:') === 0) {
      creative.template = tpl;
    } else if (tpl) {
      creative.template = parseInt(tpl, 10);
    }
    var layoutChecked = document.querySelector('input[name="design_template_reedit"]:checked');
    if (layoutChecked) {
      creative.layout = layoutChecked.value;
    }
    var bg = document.getElementById('reeditBackgroundSelect').value;
    if (bg && bg !== 'flat') {
      creative.background = bg;
    }
    var size = document.getElementById('reeditSizeSelect').value;
    if (size && size !== 'square') {
      creative.size = size;
    }
    var textPosition = document.getElementById('reeditTextPositionSelect').value;
    if (textPosition && textPosition !== 'top') {
      creative.text_position = textPosition;
    }
    var fontScale = {};
    var fontScaleChanged = false;
    document.querySelectorAll('.reedit-font-scale-slider').forEach(function (slider) {
      var val = parseInt(slider.value, 10) || 100;
      fontScale[slider.dataset.role] = val;
      if (val !== 100) fontScaleChanged = true;
    });
    if (fontScaleChanged) {
      creative.font_scale = fontScale;
    }

    var fd = new FormData();
    fd.append('csrf', window.POST_REEDIT.csrf);
    fd.append('post_id', window.POST_REEDIT.postId);
    fd.append('creative_json', JSON.stringify(creative));

    statusEl.textContent = 'Re-rendering...';
    btn.disabled = true;
    fetch(window.POST_REEDIT.url, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;
        if (!data.success) {
          statusEl.textContent = 'Error: ' + data.error;
          return;
        }
        statusEl.textContent = 'Image updated.';
        var urls = data.slides.map(function (s) { return s.url; });
        window.SLIDES = urls;
        var img = document.getElementById('slideImg');
        if (img && urls.length) {
          img.src = urls[0];
        }
        var counter = document.getElementById('slideCounter');
        if (counter) {
          counter.textContent = '1 / ' + urls.length;
        }
      })
      .catch(function (err) {
        btn.disabled = false;
        statusEl.textContent = 'Request failed: ' + err;
      });
  });
})();

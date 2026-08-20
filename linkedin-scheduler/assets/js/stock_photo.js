(function () {
  'use strict';

  var toggle = document.getElementById('stockPhotoToggle');
  var panel = document.getElementById('stockPhotoPanel');
  var toggleLabel = document.getElementById('stockPhotoToggleLabel');
  var aiToggle = document.getElementById('aiGenerateToggle');
  var manualToggle = document.getElementById('manualCreativeToggle');
  var formatSelect = document.getElementById('formatSelect');
  // New Post has its own form (id="newPostForm"); Post edit's plain
  // (non-re-edit) form reuses this same panel/script under id="postForm"
  // — whichever exists on this page is the one to guard against.
  var form = document.getElementById('newPostForm') || document.getElementById('postForm');
  if (!toggle || !panel || !form) {
    return;
  }

  var searchTab = document.getElementById('stockSearchTab');
  var aiTab = document.getElementById('stockAiTab');
  var searchTabBtn = document.getElementById('stockSearchTabBtn');
  var aiTabBtn = document.getElementById('stockAiTabBtn');
  var searchQuery = document.getElementById('stockSearchQuery');
  var searchBtn = document.getElementById('stockSearchBtn');
  var searchStatus = document.getElementById('stockSearchStatus');
  var searchResults = document.getElementById('stockSearchResults');
  var aiPrompt = document.getElementById('stockAiPrompt');
  var aiGenBtn = document.getElementById('stockAiGenBtn');
  var aiStatus = document.getElementById('stockAiStatus');
  var aiResult = document.getElementById('stockAiResult');
  var selectedPreview = document.getElementById('stockSelectedPreview');
  var urlField = document.getElementById('stockImageUrlField');
  var downloadLocationField = document.getElementById('stockDownloadLocationField');
  var b64Field = document.getElementById('stockAiB64Field');

  function clearSelection() {
    if (urlField) urlField.value = '';
    if (downloadLocationField) downloadLocationField.value = '';
    if (b64Field) b64Field.value = '';
    if (selectedPreview) {
      selectedPreview.innerHTML = '';
      selectedPreview.style.display = 'none';
    }
  }

  function selectStock(fullUrl, downloadLocation, thumbUrl, credit) {
    if (b64Field) b64Field.value = '';
    if (urlField) urlField.value = fullUrl;
    if (downloadLocationField) downloadLocationField.value = downloadLocation || '';
    if (selectedPreview) {
      selectedPreview.innerHTML = '';
      var img = document.createElement('img');
      img.src = thumbUrl;
      img.style.cssText = 'max-width:160px; border-radius:8px; display:block;';
      var p = document.createElement('p');
      p.className = 'muted';
      p.style.margin = '4px 0 0';
      p.textContent = 'Selected: ' + credit;
      selectedPreview.appendChild(img);
      selectedPreview.appendChild(p);
      selectedPreview.style.display = 'block';
    }
  }

  function selectAi(dataUrl) {
    if (urlField) urlField.value = '';
    if (downloadLocationField) downloadLocationField.value = '';
    if (b64Field) b64Field.value = dataUrl;
    if (selectedPreview) {
      selectedPreview.innerHTML = '';
      var img = document.createElement('img');
      img.src = dataUrl;
      img.style.cssText = 'max-width:160px; border-radius:8px; display:block;';
      var p = document.createElement('p');
      p.className = 'muted';
      p.style.margin = '4px 0 0';
      p.textContent = 'Selected: AI-generated image';
      selectedPreview.appendChild(img);
      selectedPreview.appendChild(p);
      selectedPreview.style.display = 'block';
    }
  }

  function setPanelVisible(visible) {
    panel.style.display = visible ? 'block' : 'none';
    if (!visible) {
      clearSelection();
    }
  }

  toggle.addEventListener('change', function () {
    if (toggle.checked) {
      if (aiToggle && aiToggle.checked) {
        aiToggle.checked = false;
        aiToggle.dispatchEvent(new Event('change'));
      }
      if (manualToggle && manualToggle.checked) {
        manualToggle.checked = false;
        manualToggle.dispatchEvent(new Event('change'));
      }
      setPanelVisible(true);
    } else {
      setPanelVisible(false);
    }
    if (window.newPostUpdateUploadFields) {
      window.newPostUpdateUploadFields();
    }
  });

  // Additive listeners only — new_post_ai.js's own handlers for these
  // two toggles are untouched. This just also switches Stock/AI Photo
  // off when either of the other two modes turns on, keeping all three
  // mutually exclusive without editing that file.
  [aiToggle, manualToggle].forEach(function (other) {
    if (!other) return;
    other.addEventListener('change', function () {
      if (other.checked && toggle.checked) {
        toggle.checked = false;
        setPanelVisible(false);
      }
    });
  });

  function updateFormatVisibility() {
    var isSingleImage = formatSelect.value === 'Single Image';
    if (toggleLabel) toggleLabel.style.display = isSingleImage ? '' : 'none';
    if (!isSingleImage && toggle.checked) {
      toggle.checked = false;
      setPanelVisible(false);
      if (window.newPostUpdateUploadFields) {
        window.newPostUpdateUploadFields();
      }
    }
  }
  if (formatSelect) {
    formatSelect.addEventListener('change', updateFormatVisibility);
    updateFormatVisibility();
  }

  function switchTab(which) {
    if (searchTab) searchTab.style.display = which === 'search' ? 'block' : 'none';
    if (aiTab) aiTab.style.display = which === 'ai' ? 'block' : 'none';
    if (searchTabBtn) searchTabBtn.className = which === 'search' ? 'btn-secondary' : 'btn-tiny';
    if (aiTabBtn) aiTabBtn.className = which === 'ai' ? 'btn-secondary' : 'btn-tiny';
  }
  if (searchTabBtn) searchTabBtn.addEventListener('click', function () { switchTab('search'); });
  if (aiTabBtn) aiTabBtn.addEventListener('click', function () { switchTab('ai'); });

  if (searchBtn) {
    searchBtn.addEventListener('click', function () {
      var q = (searchQuery.value || '').trim();
      if (!q) {
        searchStatus.textContent = 'Enter something to search for.';
        return;
      }
      searchBtn.disabled = true;
      searchStatus.textContent = 'Searching…';
      searchResults.innerHTML = '';
      fetch(window.STOCK_IMAGE_SEARCH_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf=' + encodeURIComponent(window.NEW_POST_CSRF) + '&query=' + encodeURIComponent(q)
      }).then(function (r) { return r.json(); }).then(function (data) {
        searchBtn.disabled = false;
        if (!data.success) {
          searchStatus.textContent = data.error || 'Search failed.';
          return;
        }
        searchStatus.textContent = data.results.length ? '' : 'No results.';
        data.results.forEach(function (photo) {
          var wrap = document.createElement('div');
          wrap.style.cssText = 'cursor:pointer; border:2px solid transparent; border-radius:8px; overflow:hidden;';
          var img = document.createElement('img');
          img.src = photo.thumb_url;
          img.alt = photo.alt || '';
          img.title = 'Photo by ' + photo.photographer;
          img.style.cssText = 'width:100%; height:90px; object-fit:cover; display:block;';
          wrap.appendChild(img);
          wrap.addEventListener('click', function () {
            Array.prototype.forEach.call(searchResults.children, function (c) { c.style.borderColor = 'transparent'; });
            wrap.style.borderColor = 'var(--color-brand)';
            selectStock(photo.full_url, photo.download_location, photo.thumb_url, 'Photo by ' + photo.photographer + ' on Unsplash');
          });
          searchResults.appendChild(wrap);
        });
      }).catch(function () {
        searchBtn.disabled = false;
        searchStatus.textContent = 'Search failed.';
      });
    });
  }

  if (aiGenBtn) {
    aiGenBtn.addEventListener('click', function () {
      var p = (aiPrompt.value || '').trim();
      if (!p) {
        aiStatus.textContent = 'Describe the image you want.';
        return;
      }
      aiGenBtn.disabled = true;
      aiStatus.textContent = 'Generating… this can take a few seconds.';
      aiResult.innerHTML = '';
      fetch(window.AI_IMAGE_GENERATE_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf=' + encodeURIComponent(window.NEW_POST_CSRF) + '&prompt=' + encodeURIComponent(p)
      }).then(function (r) { return r.json(); }).then(function (data) {
        aiGenBtn.disabled = false;
        if (!data.success) {
          aiStatus.textContent = data.error || 'Generation failed.';
          return;
        }
        aiStatus.textContent = 'Click the image below to use it.';
        var img = document.createElement('img');
        img.src = data.data_url;
        img.style.cssText = 'max-width:200px; border-radius:8px; cursor:pointer; border:2px solid var(--color-brand);';
        img.addEventListener('click', function () { selectAi(data.data_url); });
        aiResult.appendChild(img);
        selectAi(data.data_url);
      }).catch(function () {
        aiGenBtn.disabled = false;
        aiStatus.textContent = 'Generation failed.';
      });
    });
  }
})();

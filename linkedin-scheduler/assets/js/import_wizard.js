(function () {
  'use strict';

  var state = { rows: [], pageLabels: [], accounts: [], suggested: {}, csvFilename: '' };

  var csvFileInput = document.getElementById('csvFile');
  var uploadBtn = document.getElementById('csvUploadBtn');
  var statusEl = document.getElementById('csvStatus');
  var step2 = document.getElementById('step2');
  var step3 = document.getElementById('step3');
  var mappingRows = document.getElementById('mappingRows');
  var previewRows = document.getElementById('previewRows');
  var previewSummary = document.getElementById('previewSummary');
  var confirmForm = document.getElementById('confirmForm');

  uploadBtn.addEventListener('click', function () {
    var file = csvFileInput.files[0];
    if (!file) {
      statusEl.textContent = 'Choose a CSV file first.';
      return;
    }
    var fd = new FormData();
    fd.append('csv', file);
    fd.append('csrf', window.IMPORT_CSRF);

    statusEl.textContent = 'Parsing...';
    fetch(window.IMPORT_PREVIEW_URL, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          statusEl.textContent = 'Error: ' + data.error;
          return;
        }
        state.rows = data.rows;
        state.pageLabels = data.page_labels;
        state.accounts = data.accounts;
        state.suggested = data.suggested_matches;
        state.csvFilename = data.csv_filename;
        statusEl.textContent = data.rows.length + ' rows parsed.';
        renderMapping();
        renderPreview();
        step2.style.display = '';
        step3.style.display = '';
      })
      .catch(function (err) {
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

      select.addEventListener('change', renderPreview);
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

  function renderPreview() {
    var mapping = currentMapping();
    previewRows.innerHTML = '';
    var willImport = 0, willSkip = 0, unmatched = 0;

    state.rows.forEach(function (row) {
      var tr = document.createElement('tr');
      var statusText;
      if (row.skip) {
        statusText = 'Skip — ' + row.skip_reason;
        willSkip++;
      } else {
        var accountId = mapping[row.page_label];
        if (!accountId) {
          statusText = 'Needs account';
          unmatched++;
        } else {
          statusText = 'Ready';
        }
        willImport++;
      }
      tr.innerHTML = '<td>' + escapeHtml(row.campaign_id) + '</td>' +
        '<td>' + escapeHtml(row.date || '') + '</td>' +
        '<td>' + escapeHtml(row.format) + '</td>' +
        '<td>' + escapeHtml(row.title) + '</td>' +
        '<td>' + escapeHtml(row.page_label) + '</td>' +
        '<td>' + escapeHtml(statusText) + '</td>';
      previewRows.appendChild(tr);
    });

    previewSummary.textContent = willImport + ' will be imported (' + unmatched + ' need an account), ' + willSkip + ' will be skipped.';
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  confirmForm.addEventListener('submit', function () {
    document.getElementById('rowsJsonField').value = JSON.stringify(state.rows);
    document.getElementById('mappingJsonField').value = JSON.stringify(currentMapping());
    document.getElementById('csvFilenameField').value = state.csvFilename;
  });
})();

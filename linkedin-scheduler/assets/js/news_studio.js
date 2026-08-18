// News Studio — shows the "Slides" field only when a headline row's
// format picker is set to Carousel. One delegated listener handles
// every row (each row's format <select> carries data-row / id
// "format-{itemId}" matching "slideCountField-{itemId}" — see
// pages/news_studio.php).
document.addEventListener('change', function (e) {
  if (e.target.matches('.js-format-select')) {
    var row = e.target.closest('.news-draft-form').dataset.row;
    var field = document.getElementById('slideCountField-' + row);
    if (field) field.style.display = e.target.value === 'Carousel' ? '' : 'none';
  }

  // "Dismiss Selected" — disabled with a plain label until at least
  // one headline checkbox is checked, then shows the live count.
  if (e.target.matches('.js-headline-check')) {
    var checked = document.querySelectorAll('.js-headline-check:checked').length;
    var btn = document.getElementById('dismissSelectedBtn');
    if (!btn) return;
    btn.disabled = checked === 0;
    btn.textContent = checked > 0 ? 'Dismiss Selected (' + checked + ')' : 'Dismiss Selected';
  }
});

var bulkForm = document.getElementById('bulkDismissForm');
if (bulkForm) {
  bulkForm.addEventListener('submit', function (e) {
    var count = document.querySelectorAll('.js-headline-check:checked').length;
    if (!confirm('Dismiss ' + count + ' selected headline(s)? This can\'t be undone.')) {
      e.preventDefault();
    }
  });
}

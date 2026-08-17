// News Studio — shows the "Slides" field only when a headline row's
// format picker is set to Carousel. One delegated listener handles
// every row (each row's format <select> carries data-row / id
// "format-{itemId}" matching "slideCountField-{itemId}" — see
// pages/news_studio.php).
document.addEventListener('change', function (e) {
  if (!e.target.matches('.js-format-select')) return;
  var row = e.target.closest('.news-draft-form').dataset.row;
  var field = document.getElementById('slideCountField-' + row);
  if (!field) return;
  field.style.display = e.target.value === 'Carousel' ? '' : 'none';
});

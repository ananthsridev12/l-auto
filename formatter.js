// LinkedIn Unicode Text Formatter
// Converts selected text in #caption to Unicode bold / italic equivalents.
// These characters render as styled text on LinkedIn without using markdown.

const BOLD   = {};
const ITALIC = {};
const UNDO   = {};  // Unicode → plain ASCII (for clearing)

(function buildMaps() {
  const uppers = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  const lowers = 'abcdefghijklmnopqrstuvwxyz';
  const digits = '0123456789';

  // Mathematical Sans-Serif Bold  (A = U+1D5D4, a = U+1D5EE, 0 = U+1D7EC)
  uppers.split('').forEach((c, i) => { const u = String.fromCodePoint(0x1D5D4 + i); BOLD[c] = u; UNDO[u] = c; });
  lowers.split('').forEach((c, i) => { const u = String.fromCodePoint(0x1D5EE + i); BOLD[c] = u; UNDO[u] = c; });
  digits.split('').forEach((c, i) => { const u = String.fromCodePoint(0x1D7EC + i); BOLD[c] = u; UNDO[u] = c; });

  // Mathematical Sans-Serif Italic  (A = U+1D608, a = U+1D622)
  uppers.split('').forEach((c, i) => { const u = String.fromCodePoint(0x1D608 + i); ITALIC[c] = u; UNDO[u] = c; });
  lowers.split('').forEach((c, i) => { const u = String.fromCodePoint(0x1D622 + i); ITALIC[c] = u; UNDO[u] = c; });
})();

function _transform(map) {
  const ta = document.getElementById('caption');
  if (!ta) return;
  const s = ta.selectionStart, e = ta.selectionEnd;
  if (s === e) { _toast('Select text first, then click B or I'); return; }
  const out = [...ta.value.substring(s, e)].map(c => map[c] || c).join('');
  ta.value = ta.value.slice(0, s) + out + ta.value.slice(e);
  ta.selectionStart = s;
  ta.selectionEnd   = s + out.length;
  ta.focus();
  ta.dispatchEvent(new Event('input'));
}

function applyBold()   { _transform(BOLD);   }
function applyItalic() { _transform(ITALIC); }

function clearFormatting() {
  const ta = document.getElementById('caption');
  if (!ta) return;
  const s = ta.selectionStart, e = ta.selectionEnd;

  if (s === e) {
    // No selection — clear entire textarea
    ta.value = [...ta.value].map(c => UNDO[c] || c).join('');
  } else {
    const out = [...ta.value.substring(s, e)].map(c => UNDO[c] || c).join('');
    ta.value = ta.value.slice(0, s) + out + ta.value.slice(e);
    ta.selectionStart = s;
    ta.selectionEnd   = s + out.length;
  }
  ta.focus();
  ta.dispatchEvent(new Event('input'));
}

function _toast(msg) {
  let t = document.getElementById('_toast');
  if (!t) {
    t = document.createElement('div');
    t.id = '_toast';
    Object.assign(t.style, {
      position: 'fixed', bottom: '24px', left: '50%',
      transform: 'translateX(-50%)',
      background: '#333', color: '#fff',
      padding: '8px 20px', borderRadius: '20px',
      fontSize: '13px', zIndex: '9999',
      transition: 'opacity 0.3s', pointerEvents: 'none',
    });
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.style.opacity = '1';
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.style.opacity = '0', 2200);
}

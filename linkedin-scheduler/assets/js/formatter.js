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

const BOLD_CHARS   = new Set(Object.values(BOLD));
const ITALIC_CHARS = new Set(Object.values(ITALIC));

// Underline / strikethrough use Unicode combining marks appended after
// each character (U+0332 combining low line, U+0336 combining long
// stroke overlay) — same trick as bold/italic in spirit: no real rich
// text exists in a LinkedIn post, so the visual effect has to be baked
// into the character stream itself. Renders correctly in LinkedIn's feed.
const UNDERLINE_MARK    = '̲';
const STRIKETHROUGH_MARK = '̶';
const COMBINING_MARKS_RE = /[̀-ͯ]/g;

function _transformChars(fn) {
  const ta = document.getElementById('caption');
  if (!ta) return;
  const s = ta.selectionStart, e = ta.selectionEnd;
  if (s === e) { _toast('Select text first, then click a formatting button'); return; }
  const out = [...ta.value.substring(s, e)].map(fn).join('');
  ta.value = ta.value.slice(0, s) + out + ta.value.slice(e);
  ta.selectionStart = s;
  ta.selectionEnd   = s + out.length;
  ta.focus();
  ta.dispatchEvent(new Event('input'));
}

// Same as _transformChars but hands the whole selection to fn at once,
// so a toggle (bold → plain, plain → bold) can be decided from the
// selection as a whole rather than character-by-character.
function _transformSelection(fn) {
  const ta = document.getElementById('caption');
  if (!ta) return;
  const s = ta.selectionStart, e = ta.selectionEnd;
  if (s === e) { _toast('Select text first, then click a formatting button'); return; }
  const out = fn(ta.value.substring(s, e));
  ta.value = ta.value.slice(0, s) + out + ta.value.slice(e);
  ta.selectionStart = s;
  ta.selectionEnd   = s + out.length;
  ta.focus();
  ta.dispatchEvent(new Event('input'));
}

// True when every letter/digit in str is already transformed under `map`
// (comparing each character's plain-ASCII source via UNDO) — i.e. clicking
// the same button again should remove the effect rather than re-apply it.
function _isFullyMapped(str, chars) {
  const letters = [...str].filter(c => /[a-zA-Z0-9]/.test(UNDO[c] || c));
  return letters.length > 0 && letters.every(c => chars.has(c));
}

function _transformLines(fn) {
  const ta = document.getElementById('caption');
  if (!ta) return;
  const s = ta.selectionStart, e = ta.selectionEnd;
  if (s === e) { _toast('Select one or more lines first'); return; }
  let n = 0;
  const out = ta.value.substring(s, e).split('\n')
    .map(line => line.trim() === '' ? line : fn(line, ++n))
    .join('\n');
  ta.value = ta.value.slice(0, s) + out + ta.value.slice(e);
  ta.selectionStart = s;
  ta.selectionEnd   = s + out.length;
  ta.focus();
  ta.dispatchEvent(new Event('input'));
}

// Each of these toggles: if the whole selection is already formatted,
// clicking again removes the effect; otherwise it applies it.
function applyBold() {
  _transformSelection(str => _isFullyMapped(str, BOLD_CHARS)
    ? [...str].map(c => UNDO[c] || c).join('')
    : [...str].map(c => BOLD[c] || c).join(''));
}
function applyItalic() {
  _transformSelection(str => _isFullyMapped(str, ITALIC_CHARS)
    ? [...str].map(c => UNDO[c] || c).join('')
    : [...str].map(c => ITALIC[c] || c).join(''));
}
function applyUnderline() {
  _transformSelection(str => str.includes(UNDERLINE_MARK)
    ? str.split(UNDERLINE_MARK).join('')
    : [...str].map(c => c === '\n' ? c : c + UNDERLINE_MARK).join(''));
}
function applyStrikethrough() {
  _transformSelection(str => str.includes(STRIKETHROUGH_MARK)
    ? str.split(STRIKETHROUGH_MARK).join('')
    : [...str].map(c => c === '\n' ? c : c + STRIKETHROUGH_MARK).join(''));
}

function applyBulletList()   { _transformLines(line => `• ${line}`); }
function applyNumberedList() { _transformLines((line, n) => `${n}. ${line}`); }

function clearFormatting() {
  const ta = document.getElementById('caption');
  if (!ta) return;
  const s = ta.selectionStart, e = ta.selectionEnd;
  const stripAll = str => [...str].map(c => UNDO[c] || c).join('')
    .normalize('NFC').replace(COMBINING_MARKS_RE, '')
    .replace(/^(•|\d+\.)\s+/gm, ''); // strip bullet/numbered list prefixes too

  if (s === e) {
    ta.value = stripAll(ta.value);
  } else {
    const out = stripAll(ta.value.substring(s, e));
    ta.value = ta.value.slice(0, s) + out + ta.value.slice(e);
    ta.selectionStart = s;
    ta.selectionEnd   = s + out.length;
  }
  ta.focus();
  ta.dispatchEvent(new Event('input'));
}

// ── Emoji picker ─────────────────────────────────────────────────────────

const EMOJI_SET = [
  '😀','😂','😊','😍','🤔','😅','😉','🙂','😢','😮',
  '👍','👏','🙌','💪','🤝','👀','🙏','✌️','👌','🤟',
  '🔥','✨','🎉','🚀','💡','⭐','❤️','💯','⚡','🏆',
  '📈','📊','📌','📝','📅','💬','✅','❌','⏰','🎯',
];

function closeOtherPickers(exceptId) {
  ['emojiPicker', 'mentionPicker'].forEach(id => {
    if (id === exceptId) return;
    const other = document.getElementById(id);
    if (other) other.style.display = 'none';
  });
}

function toggleEmojiPicker() {
  const picker = document.getElementById('emojiPicker');
  if (!picker) return;
  const opening = picker.style.display === 'none' || !picker.style.display;
  if (opening) closeOtherPickers('emojiPicker');
  if (opening && !picker.dataset.built) {
    EMOJI_SET.forEach(emoji => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'emoji-option';
      btn.textContent = emoji;
      btn.onclick = (ev) => { ev.stopPropagation(); insertEmoji(emoji); };
      picker.appendChild(btn);
    });
    picker.dataset.built = '1';
  }
  picker.style.display = opening ? 'grid' : 'none';
}

function insertEmoji(emoji) {
  const ta = document.getElementById('caption');
  if (!ta) return;
  const s = ta.selectionStart, e = ta.selectionEnd;
  ta.value = ta.value.slice(0, s) + emoji + ta.value.slice(e);
  ta.selectionStart = ta.selectionEnd = s + emoji.length;
  ta.focus();
  ta.dispatchEvent(new Event('input'));
  const picker = document.getElementById('emojiPicker');
  if (picker) picker.style.display = 'none';
}

// ── "Tag a Page" mention picker ─────────────────────────────────────────
// Lists the user's own connected LinkedIn accounts (window.MENTION_ACCOUNTS,
// set by the page). Inserts "@[Display Name]" at the cursor — the app
// resolves that to a real LinkedIn mention (with the account's URN) only
// at publish time, matching it by exact name against the poster's
// connected accounts. See includes/linkedin_text.php.

function toggleMentionPicker() {
  const picker = document.getElementById('mentionPicker');
  if (!picker) return;
  const opening = picker.style.display === 'none' || !picker.style.display;
  if (opening) closeOtherPickers('mentionPicker');
  if (opening && !picker.dataset.built) {
    const accounts = window.MENTION_ACCOUNTS || [];
    if (accounts.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mention-picker-empty';
      empty.textContent = 'No connected LinkedIn accounts to tag yet.';
      picker.appendChild(empty);
    } else {
      accounts.forEach(acct => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'mention-option';
        btn.innerHTML = '';
        btn.textContent = acct.name;
        const typeSpan = document.createElement('span');
        typeSpan.className = 'mention-type';
        typeSpan.textContent = '(' + acct.type + ')';
        btn.appendChild(typeSpan);
        btn.onclick = (ev) => { ev.stopPropagation(); insertMention(acct.name); };
        picker.appendChild(btn);
      });
    }
    picker.dataset.built = '1';
  }
  picker.style.display = opening ? 'block' : 'none';
}

function insertMention(name) {
  const ta = document.getElementById('caption');
  if (!ta) return;
  const tag = '@[' + name + '] ';
  const s = ta.selectionStart, e = ta.selectionEnd;
  ta.value = ta.value.slice(0, s) + tag + ta.value.slice(e);
  ta.selectionStart = ta.selectionEnd = s + tag.length;
  ta.focus();
  ta.dispatchEvent(new Event('input'));
  const picker = document.getElementById('mentionPicker');
  if (picker) picker.style.display = 'none';
}

// Keyboard shortcuts (Ctrl/Cmd+B / I / U) — plain <textarea>s don't get
// native rich-text shortcut handling, so this wires the same toggles the
// toolbar buttons use.
(function () {
  const ta = document.getElementById('caption');
  if (!ta) return;
  ta.addEventListener('keydown', (ev) => {
    if (!(ev.ctrlKey || ev.metaKey) || ev.shiftKey || ev.altKey) return;
    const key = ev.key.toLowerCase();
    if (key === 'b') { ev.preventDefault(); applyBold(); }
    else if (key === 'i') { ev.preventDefault(); applyItalic(); }
    else if (key === 'u') { ev.preventDefault(); applyUnderline(); }
  });
})();

document.addEventListener('click', (ev) => {
  ['emojiPicker', 'mentionPicker'].forEach(id => {
    const picker = document.getElementById(id);
    if (!picker || picker.style.display === 'none' || !picker.style.display) return;
    // Close unless the click landed inside this picker's own wrapper
    // (its toggle button or the picker itself) — a click on the *other*
    // picker's toggle button should still close this one.
    if (!picker.parentElement.contains(ev.target)) {
      picker.style.display = 'none';
    }
  });
});

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

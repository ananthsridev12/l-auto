<div class="toolbar">
  <button type="button" class="tool-btn" onclick="applyBold()" title="Bold selected text">B</button>
  <button type="button" class="tool-btn" onclick="applyItalic()" title="Italic selected text">I</button>
  <button type="button" class="tool-btn" onclick="applyUnderline()" title="Underline selected text">U</button>
  <button type="button" class="tool-btn" onclick="applyStrikethrough()" title="Strikethrough selected text">S</button>
  <div class="toolbar-divider"></div>
  <button type="button" class="tool-btn" onclick="applyBulletList()" title="Bullet list (select lines)">&bull; List</button>
  <button type="button" class="tool-btn" onclick="applyNumberedList()" title="Numbered list (select lines)">1. List</button>
  <div class="toolbar-divider"></div>
  <div class="toolbar-picker-wrap">
    <button type="button" class="tool-btn" onclick="toggleEmojiPicker()" title="Insert emoji">&#128512;</button>
    <div id="emojiPicker" class="emoji-picker" style="display:none;"></div>
  </div>
  <div class="toolbar-divider"></div>
  <div class="toolbar-picker-wrap">
    <button type="button" class="tool-btn" onclick="toggleMentionPicker()" title="Tag a connected LinkedIn account">@ Tag</button>
    <div id="mentionPicker" class="emoji-picker mention-picker" style="display:none;"></div>
  </div>
  <div class="toolbar-divider"></div>
  <button type="button" class="tool-btn" onclick="clearFormatting()">Clear Format</button>
  <div class="toolbar-spacer"></div>
  <span class="char-count"><span id="charCount">0</span> / 3,000</span>
</div>

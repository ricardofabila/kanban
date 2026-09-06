/**
 * Plan page: copying the prompt. The textarea holds the real text, so the
 * prompt is always selectable and copyable by hand if any of this fails.
 */
'use strict';

const copyButton = document.getElementById('copy-prompt');
const promptText = document.getElementById('prompt-text');

let resetTimer = null;

/** Swap the button label briefly. No error styling — the fallback is an
 *  instruction to the user, not a failure they need to worry about. */
function flash(label, hold = 1800) {
  clearTimeout(resetTimer);
  copyButton.textContent = label;
  resetTimer = setTimeout(() => {
    copyButton.textContent = copyButton.dataset.label;
  }, hold);
}

copyButton.addEventListener('click', async () => {
  // Select either way: it shows the user what was copied, and leaves the text
  // ready for a manual Cmd/Ctrl-C if the clipboard call is refused.
  promptText.focus();
  promptText.select();

  try {
    await navigator.clipboard.writeText(promptText.value);
    flash('Copied');
  } catch {
    // Clipboard access needs a secure context. http://localhost qualifies, but
    // reaching the app over http on a LAN address does not — so fall back to
    // the selection, which is already made.
    flash('Selected — press Ctrl/Cmd+C', 4000);
  }
});

/**
 * Backups page. The page itself is plain server-rendered HTML with form posts;
 * this only adds the confirmation step and a busy state, so it still works
 * correctly if the script never runs.
 */
'use strict';

const dialog  = document.getElementById('confirm-dialog');
const titleEl = document.getElementById('confirm-title');
const bodyEl  = document.getElementById('confirm-body');
const okBtn   = document.getElementById('confirm-ok');

let pending = null;

function closeDialog() {
  pending = null;
  if (dialog.open) dialog.close();
}

/** Freeze the page once a request is genuinely on its way. */
function markBusy(form) {
  const submitter = form.querySelector('button[type="submit"]');
  if (submitter) submitter.textContent = 'Working…';
  document.querySelectorAll('button, .btn').forEach((el) => {
    el.setAttribute('aria-disabled', 'true');
    el.classList.add('is-busy');
  });
}

/**
 * Render each timestamp in the reader's own timezone. The server writes the
 * instant into `datetime` and a UTC-labelled fallback into the text, so this is
 * a refinement rather than a requirement.
 */
document.querySelectorAll('time.stamp[datetime]').forEach((stamp) => {
  const when = new Date(stamp.dateTime);
  if (Number.isNaN(when.valueOf())) return;

  stamp.textContent = when.toLocaleString(undefined, {
    day: 'numeric', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
  stamp.title = when.toString();
});

document.querySelectorAll('form[data-confirm-title]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (form.dataset.confirmed === '1') return; // already agreed to; let it through
    event.preventDefault();

    pending = form;
    titleEl.textContent = form.dataset.confirmTitle;
    bodyEl.textContent  = form.dataset.confirmBody;
    okBtn.textContent   = form.dataset.confirmOk || 'Confirm';
    okBtn.className     = `btn ${form.dataset.confirmDanger ? 'btn-danger' : 'btn-primary'}`;

    dialog.showModal();
  });
});

// Forms without a confirmation still get the busy state.
document.querySelectorAll('form:not([data-confirm-title])').forEach((form) => {
  form.addEventListener('submit', () => markBusy(form));
});

okBtn.addEventListener('click', () => {
  const form = pending;
  closeDialog();
  if (!form) return;

  form.dataset.confirmed = '1';
  markBusy(form);
  form.requestSubmit();
});

// Settle every exit path rather than leaning on the `close` event alone.
dialog.addEventListener('click', (event) => {
  if (event.target.closest('[data-close]')) closeDialog();
});
dialog.addEventListener('cancel', closeDialog);
dialog.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') closeDialog();
});

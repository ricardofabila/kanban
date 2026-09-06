/**
 * Kanban — single-page frontend.
 *
 * Talks to api.php over fetch(): GET returns the whole board, POST carries a
 * JSON {action, ...} payload. Drag-and-drop is SortableJS; every drop is
 * applied to the DOM optimistically and then persisted, with a full reload as
 * the fallback if the write fails.
 */
'use strict';

const API = 'api.php';

/** @type {Array<{id:number,title:string,columns:Array}>} */
let board = [];

const boardEl  = document.getElementById('board');
const statusEl = document.getElementById('status');
const toastsEl = document.getElementById('toasts');
const sortables = [];

/** Ids to play the entrance animation for on the next render (restored rows). */
const entering = { projects: new Set(), columns: new Set(), tasks: new Set() };

const motionOK = () => !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const truncate = (text, max = 40) => (text.length > max ? `${text.slice(0, max - 1)}…` : text);

const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

/* ----------------------------------------------------------------- status */

let statusTimer = null;

function setStatus(text, tone = 'info') {
  clearTimeout(statusTimer);
  statusEl.textContent = text;
  statusEl.dataset.tone = tone;
  if (text && tone !== 'error') {
    statusTimer = setTimeout(() => { statusEl.textContent = ''; }, 1600);
  }
}

/* ----------------------------------------------------------------- fetches */

async function apiGet() {
  const res  = await fetch(API, { headers: { Accept: 'application/json' } });
  if (res.status === 401) { location.href = 'login.php'; throw new Error('unauthenticated'); }
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Request failed');
  return data;
}

async function apiPost(action, payload = {}) {
  setStatus('Saving…');
  const res = await fetch(API, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.CSRF_TOKEN,
    },
    body: JSON.stringify({ action, ...payload }),
  });

  if (res.status === 401) { location.href = 'login.php'; throw new Error('unauthenticated'); }

  const data = await res.json().catch(() => ({ ok: false, error: 'Bad response' }));
  if (!data.ok) {
    setStatus(data.error || 'Something went wrong', 'error');
    throw new Error(data.error || 'Request failed');
  }
  setStatus('Saved');
  return data;
}

async function loadBoard() {
  const data = await apiGet();
  board = data.projects;
  render();
}

/** Re-fetch and re-render after a failed write, so the UI can't drift. */
async function resync() {
  try { await loadBoard(); } catch { /* status already shows the error */ }
}

/* ----------------------------------------------------------------- toasts */

const MAX_TOASTS = 3;

/**
 * Transient message with an optional action. Used for undo after a delete:
 * the countdown pauses while the pointer is over the toast, so a slow reader
 * doesn't lose the chance to undo.
 */
function showToast(message, { actionLabel, onAction, duration = 8000 } = {}) {
  while (toastsEl.children.length >= MAX_TOASTS) {
    toastsEl.firstElementChild.remove();
  }

  const toast = el('div', 'toast');
  toast.append(el('span', 'toast-text', message));

  let timer = null;
  let closed = false;

  const dismiss = () => {
    if (closed) return;
    closed = true;
    clearTimeout(timer);
    toast.classList.add('toast-leaving');
    setTimeout(() => toast.remove(), motionOK() ? 220 : 0);
  };

  if (actionLabel && onAction) {
    const action = el('button', 'toast-action', actionLabel);
    action.type = 'button';
    action.addEventListener('click', () => { dismiss(); onAction(); });
    toast.append(action);
  }

  const close = iconButton('Dismiss', '✕', 'toast-close');
  close.addEventListener('click', dismiss);
  toast.append(close);

  const bar = el('span', 'toast-bar');
  bar.style.animationDuration = `${duration}ms`;
  toast.append(bar);

  toast.addEventListener('pointerenter', () => {
    clearTimeout(timer);
    bar.style.animationPlayState = 'paused';
  });
  toast.addEventListener('pointerleave', () => {
    bar.style.animationPlayState = 'running';
    timer = setTimeout(dismiss, 2500);
  });

  toastsEl.append(toast);
  timer = setTimeout(dismiss, duration);

  return dismiss;
}

/* ---------------------------------------------------------------- dialogs */

const taskDialog    = document.getElementById('task-dialog');
const taskForm      = document.getElementById('task-form');
const confirmDialog = document.getElementById('confirm-dialog');
const promptDialog  = document.getElementById('prompt-dialog');
const promptForm    = document.getElementById('prompt-form');

/**
 * Wrap a <dialog> in a promise.
 *
 * Every exit path settles explicitly — button, submit, Escape — rather than
 * hanging the promise off the `close` event alone. `close` is the obvious hook,
 * but it is one point of failure (some embedded browsers never dispatch it),
 * and a dialog that never settles leaves the caller awaiting forever.
 *
 * `setup` receives `settle(value)` and an `on()` that unregisters itself.
 */
function runDialog(dialog, setup) {
  return new Promise((resolve) => {
    let done = false;
    const cleanups = [];

    const on = (target, type, handler) => {
      target.addEventListener(type, handler);
      cleanups.push(() => target.removeEventListener(type, handler));
    };

    const settle = (value) => {
      if (done) return;
      done = true;
      cleanups.forEach((cleanup) => cleanup());
      if (dialog.open) dialog.close();
      resolve(value);
    };

    setup({ settle, on });

    on(dialog, 'click', (event) => {
      if (event.target.closest('[data-close]')) settle(null);
    });
    on(dialog, 'cancel', () => settle(null));                                  // Escape
    on(dialog, 'keydown', (e) => { if (e.key === 'Escape') settle(null); });   // …and a fallback
    on(dialog, 'close', () => settle(null));                                   // closed elsewhere

    dialog.showModal();
  });
}

/** Resolves to the trimmed string, or null if dismissed. */
function openPrompt({ title, label = 'Title', value = '', ok = 'Save' }) {
  document.getElementById('prompt-title').textContent = title;
  document.getElementById('prompt-label').textContent = label;
  document.getElementById('prompt-ok').textContent    = ok;

  const input = promptForm.elements.value;
  input.value = value;

  return runDialog(promptDialog, ({ settle, on }) => {
    on(promptForm, 'submit', (event) => {
      event.preventDefault();
      settle(input.value.trim() || null);
    });
    setTimeout(() => input.select(), 0);
  });
}

/** Resolves true only if the confirming button was pressed. */
function openConfirm({ title, body, ok = 'Delete' }) {
  document.getElementById('confirm-title').textContent = title;
  document.getElementById('confirm-body').textContent  = body;

  const okBtn = document.getElementById('confirm-ok');
  okBtn.textContent = ok;

  return runDialog(confirmDialog, ({ settle, on }) => {
    on(okBtn, 'click', () => settle(true));
  }).then((value) => value === true);
}

/** Resolves to {type:'save',title,description} | {type:'delete'} | null. */
function openTask({ heading, title = '', description = '', allowDelete }) {
  document.getElementById('task-dialog-title').textContent = heading;
  taskForm.elements.title.value       = title;
  taskForm.elements.description.value = description;

  const deleteBtn = document.getElementById('task-delete');
  deleteBtn.hidden = !allowDelete;

  return runDialog(taskDialog, ({ settle, on }) => {
    on(taskForm, 'submit', (event) => {
      event.preventDefault();
      const nextTitle = taskForm.elements.title.value.trim();
      settle(nextTitle ? {
        type: 'save',
        title: nextTitle,
        description: taskForm.elements.description.value.trim(),
      } : null);
    });
    on(deleteBtn, 'click', () => settle({ type: 'delete' }));
    setTimeout(() => taskForm.elements.title.select(), 0);
  });
}

/* ------------------------------------------------------------- DOM helpers */

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function iconButton(label, glyph, className = '') {
  const button = el('button', `icon-btn ${className}`.trim(), glyph);
  button.type = 'button';
  button.title = label;
  button.setAttribute('aria-label', label);
  return button;
}

const COLLAPSE_MS = 240;

/**
 * Collapse elements away before the board re-renders. Size, padding and a
 * negative margin animate together so the flex gap closes with them, instead
 * of the surviving siblings snapping into place.
 *
 * Cards and project rows collapse vertically; columns collapse along their
 * flex-basis, since that is what gives them their width.
 */
function collapseOut(elements, { axis = 'height', gap = 8, stagger = 0 } = {}) {
  if (elements.length === 0 || !motionOK()) return Promise.resolve();

  const horizontal = axis === 'width';
  const sizeProp = horizontal ? 'flexBasis' : 'height';

  return Promise.all(elements.map((element, index) => new Promise((resolve) => {
    setTimeout(() => {
      element.style[sizeProp] = `${horizontal ? element.offsetWidth : element.offsetHeight}px`;
      element.classList.add('collapsing');
      void element.offsetHeight; // flush the starting size so the transition has a from-value

      element.style[sizeProp] = '0px';
      if (horizontal) {
        element.style.paddingLeft = '0px';
        element.style.paddingRight = '0px';
        element.style.marginRight = `-${gap}px`;
      } else {
        element.style.paddingTop = '0px';
        element.style.paddingBottom = '0px';
        element.style.marginBottom = `-${gap}px`;
      }
      element.style.opacity = '0';
      element.style.transform = 'scale(.94)';

      setTimeout(resolve, COLLAPSE_MS);
    }, Math.min(index * stagger, 200));
  })));
}

const cardsIn = (root) => (root ? Array.from(root.querySelectorAll('.task-list > .card')) : []);

const cardElement    = (id) => boardEl.querySelector(`.card[data-task-id="${id}"]`);
const columnElement  = (id) => boardEl.querySelector(`.column[data-column-id="${id}"]`);
const projectElement = (id) => boardEl.querySelector(`.project[data-project-id="${id}"]`);

/* ------------------------------------------------------------------ render */

function render() {
  sortables.forEach((s) => s.destroy());
  sortables.length = 0;

  boardEl.replaceChildren();
  boardEl.removeAttribute('aria-busy');

  if (board.length === 0) {
    const empty = el('div', 'empty-state');
    empty.append(
      el('h2', null, 'No projects yet'),
      el('p', null, 'Create your first board to get started.')
    );
    boardEl.append(empty);
    return;
  }

  board.forEach((project) => boardEl.append(renderProject(project)));

  // Projects reorder by dragging their header grip.
  sortables.push(Sortable.create(boardEl, {
    handle: '.project-grip',
    animation: 150,
    forceFallback: true,
    ghostClass: 'project-ghost',
    onEnd: onProjectDrop,
  }));
}

function renderProject(project) {
  const row = el('section', 'project');
  row.dataset.projectId = String(project.id);
  if (entering.projects.has(project.id)) row.classList.add('restored');

  const header = el('header', 'project-header');
  const grip   = el('span', 'project-grip', '⠿');
  grip.title = 'Drag to reorder project';

  const title = el('h2', 'project-title', project.title);
  const count = project.columns.reduce((n, c) => n + c.tasks.length, 0);
  const meta  = el('span', 'project-meta', `${count} ${count === 1 ? 'task' : 'tasks'}`);

  const addColumn = el('button', 'btn btn-small', '+ Column');
  addColumn.type = 'button';
  addColumn.addEventListener('click', () => addColumnTo(project));

  const rename = iconButton('Rename project', '✎');
  rename.addEventListener('click', () => renameProject(project));

  const remove = iconButton('Delete project', '🗑', 'danger');
  remove.addEventListener('click', () => deleteProject(project));

  title.addEventListener('dblclick', () => renameProject(project));

  header.append(grip, title, meta, el('span', 'spacer'), addColumn, rename, remove);

  const lane = el('div', 'lane');
  project.columns.forEach((column) => lane.append(renderColumn(project, column)));
  lane.append(renderAddColumnTile(project));

  // Columns reorder within their own row only.
  sortables.push(Sortable.create(lane, {
    group: `columns-${project.id}`,
    handle: '.column-grip',
    draggable: '.column',
    animation: 150,
    forceFallback: true,
    ghostClass: 'column-ghost',
    scroll: true,
    onEnd: () => onColumnDrop(project, lane),
  }));

  row.append(header, lane);
  return row;
}

function renderColumn(project, column) {
  const node = el('div', 'column');
  node.dataset.columnId = String(column.id);
  if (entering.columns.has(column.id)) node.classList.add('restored');

  const header = el('header', 'column-header');
  const grip   = el('span', 'column-grip', '⠿');
  grip.title = 'Drag to reorder column';

  const title = el('h3', 'column-title', column.title);
  title.addEventListener('dblclick', () => renameColumn(project, column));

  const count = el('span', 'column-count', String(column.tasks.length));

  const rename = iconButton('Rename column', '✎');
  rename.addEventListener('click', () => renameColumn(project, column));

  const remove = iconButton('Delete column', '🗑', 'danger');
  remove.addEventListener('click', () => deleteColumn(project, column));

  header.append(grip, title, count, el('span', 'spacer'));

  // Only worth showing when there is something to clear.
  if (column.tasks.length > 0) {
    const clear = iconButton(`Clear all ${plural(column.tasks.length, 'card')}`, '🧹');
    clear.addEventListener('click', () => clearColumn(project, column));
    header.append(clear);
  }

  header.append(rename, remove);

  const list = el('div', 'task-list');
  list.dataset.columnId = String(column.id);
  column.tasks.forEach((task) => list.append(renderTask(project, column, task)));

  const add = el('button', 'add-task', '+ Add task');
  add.type = 'button';
  add.addEventListener('click', () => addTaskTo(project, column));

  // Cards move freely between columns of the same project, but not across projects.
  sortables.push(Sortable.create(list, {
    group: `tasks-${project.id}`,
    animation: 150,
    forceFallback: true,
    fallbackOnBody: true,
    ghostClass: 'card-ghost',
    dragClass: 'card-drag',
    scroll: true,
    onEnd: onTaskDrop,
  }));

  node.append(header, list, add);
  return node;
}

function renderTask(project, column, task) {
  const card = el('article', 'card');
  card.dataset.taskId = String(task.id);
  card.tabIndex = 0;

  card.append(el('div', 'card-title', task.title));
  if (task.description) {
    card.append(el('p', 'card-desc', task.description));
  }

  const open   = () => editTask(project, column, task);
  const remove = () => deleteTask(project, column, task);

  card.addEventListener('dblclick', open);
  card.addEventListener('keydown', (event) => {
    if (event.key === 'Enter')  { event.preventDefault(); open(); }
    if (event.key === 'Delete') { event.preventDefault(); remove(); }
  });

  const actions = el('div', 'card-actions');

  const edit = iconButton('Edit task', '✎');
  edit.addEventListener('click', open);

  const del = iconButton('Delete task', '🗑', 'danger');
  del.addEventListener('click', remove);

  actions.append(edit, del);
  card.append(actions);

  if (entering.tasks.has(task.id)) {
    card.classList.add('restored');
  }

  return card;
}

function renderAddColumnTile(project) {
  const tile = el('button', 'column-add', '+ Add column');
  tile.type = 'button';
  tile.addEventListener('click', () => addColumnTo(project));
  return tile;
}

/* ----------------------------------------------------------- drag handlers */

/** Read the current DOM order of a task list. */
function taskIdsIn(listEl) {
  return Array.from(listEl.querySelectorAll(':scope > .card'))
    .map((card) => Number(card.dataset.taskId));
}

async function onTaskDrop(event) {
  const lists = [{
    column_id: Number(event.to.dataset.columnId),
    task_ids:  taskIdsIn(event.to),
  }];

  if (event.from !== event.to) {
    lists.push({
      column_id: Number(event.from.dataset.columnId),
      task_ids:  taskIdsIn(event.from),
    });
  }

  refreshCounts();

  try {
    await apiPost('task.reorder', { lists });
    syncStateFromDom();
  } catch {
    await resync();
  }
}

async function onColumnDrop(project, lane) {
  const ids = Array.from(lane.querySelectorAll(':scope > .column'))
    .map((c) => Number(c.dataset.columnId));
  try {
    await apiPost('column.reorder', { project_id: project.id, ids });
    syncStateFromDom();
  } catch {
    await resync();
  }
}

async function onProjectDrop() {
  const ids = Array.from(boardEl.querySelectorAll(':scope > .project'))
    .map((p) => Number(p.dataset.projectId));
  try {
    await apiPost('project.reorder', { ids });
    syncStateFromDom();
  } catch {
    await resync();
  }
}

/** Update per-column and per-project counters after a drag, without re-rendering. */
function refreshCounts() {
  boardEl.querySelectorAll('.project').forEach((projectEl) => {
    let total = 0;
    projectEl.querySelectorAll('.column').forEach((columnEl) => {
      const n = columnEl.querySelectorAll('.task-list > .card').length;
      columnEl.querySelector('.column-count').textContent = String(n);
      total += n;
    });
    const meta = projectEl.querySelector('.project-meta');
    meta.textContent = `${total} ${total === 1 ? 'task' : 'tasks'}`;
  });
}

/**
 * Rebuild the in-memory board from the DOM after a successful drag, so later
 * edits act on the same ordering the user is looking at.
 */
function syncStateFromDom() {
  const taskById   = new Map();
  const columnById = new Map();
  const projectById = new Map();

  board.forEach((project) => {
    projectById.set(project.id, project);
    project.columns.forEach((column) => {
      columnById.set(column.id, column);
      column.tasks.forEach((task) => taskById.set(task.id, task));
    });
  });

  board = Array.from(boardEl.querySelectorAll(':scope > .project')).map((projectEl) => {
    const project = projectById.get(Number(projectEl.dataset.projectId));
    project.columns = Array.from(projectEl.querySelectorAll('.column')).map((columnEl) => {
      const column = columnById.get(Number(columnEl.dataset.columnId));
      column.tasks = taskIdsIn(columnEl.querySelector('.task-list'))
        .map((id) => taskById.get(id))
        .filter(Boolean);
      column.tasks.forEach((task) => { task.column_id = column.id; });
      return column;
    });
    return project;
  });
}

/* -------------------------------------------------------------- mutations */

async function addProject() {
  const title = await openPrompt({ title: 'New project', label: 'Project name', ok: 'Create' });
  if (!title) return;
  try {
    const data = await apiPost('project.create', { title });
    board = data.projects;
    render();
  } catch {
    await resync();
  }
}

async function renameProject(project) {
  const title = await openPrompt({ title: 'Rename project', label: 'Project name', value: project.title });
  if (!title || title === project.title) return;
  try {
    await apiPost('project.rename', { id: project.id, title });
    project.title = title;
    render();
  } catch {
    await resync();
  }
}

async function deleteProject(project) {
  const columns = project.columns.length;
  const cards = project.columns.reduce((total, column) => total + column.tasks.length, 0);

  const ok = await openConfirm({
    title: `Delete “${truncate(project.title, 40)}”?`,
    body: `The board and its ${plural(columns, 'column')} and ${plural(cards, 'card')} `
        + 'will be removed. You can undo it from the toast.',
  });
  if (!ok) return;

  const row = projectElement(project.id);

  try {
    const [data] = await Promise.all([
      apiPost('project.delete', { id: project.id }),
      collapseOut(row ? [row] : [], { gap: 18 }),
    ]);

    board = board.filter((p) => p.id !== project.id);
    render();

    showToast(`Deleted board “${truncate(project.title, 24)}”`, {
      actionLabel: 'Undo',
      onAction: () => restoreSnapshot(data.snapshot),
    });
  } catch {
    await resync();
  }
}

async function addColumnTo(project) {
  const title = await openPrompt({ title: 'New column', label: 'Column name', ok: 'Add' });
  if (!title) return;
  try {
    const data = await apiPost('column.create', { project_id: project.id, title });
    project.columns.push({ id: data.id, project_id: project.id, title, tasks: [] });
    render();
  } catch {
    await resync();
  }
}

async function renameColumn(project, column) {
  const title = await openPrompt({ title: 'Rename column', label: 'Column name', value: column.title });
  if (!title || title === column.title) return;
  try {
    await apiPost('column.rename', { id: column.id, title });
    column.title = title;
    render();
  } catch {
    await resync();
  }
}

async function deleteColumn(project, column) {
  const total = column.tasks.length;

  const ok = await openConfirm({
    title: `Delete “${truncate(column.title, 32)}”?`,
    body: total
      ? `The column and its ${plural(total, 'card')} will be removed. You can undo it from the toast.`
      : 'The empty column will be removed. You can undo it from the toast.',
  });
  if (!ok) return;

  const columnEl = columnElement(column.id);

  try {
    const [data] = await Promise.all([
      apiPost('column.delete', { id: column.id }),
      collapseOut(columnEl ? [columnEl] : [], { axis: 'width', gap: 12 }),
    ]);

    project.columns = project.columns.filter((c) => c.id !== column.id);
    render();

    showToast(`Deleted column “${truncate(column.title, 24)}”`, {
      actionLabel: 'Undo',
      onAction: () => restoreSnapshot(data.snapshot),
    });
  } catch {
    await resync();
  }
}

async function addTaskTo(project, column) {
  const result = await openTask({ heading: `Add task to ${column.title}`, allowDelete: false });
  if (!result || result.type !== 'save') return;
  try {
    const data = await apiPost('task.create', {
      column_id: column.id,
      title: result.title,
      description: result.description,
    });
    column.tasks.push({
      id: data.id,
      column_id: column.id,
      title: result.title,
      description: result.description,
    });
    render();
  } catch {
    await resync();
  }
}

async function editTask(project, column, task) {
  const result = await openTask({
    heading: 'Edit task',
    title: task.title,
    description: task.description,
    allowDelete: true,
  });
  if (!result) return;

  if (result.type === 'delete') {
    await deleteTask(project, column, task);
    return;
  }

  try {
    await apiPost('task.update', {
      id: task.id,
      title: result.title,
      description: result.description,
    });
    task.title = result.title;
    task.description = result.description;
    render();
  } catch {
    await resync();
  }
}

async function deleteTask(project, column, task) {
  const ok = await openConfirm({
    title: `Delete “${truncate(task.title, 48)}”?`,
    body: 'The card is removed from this column. You can undo it from the toast.',
  });
  if (!ok) return;

  const card = cardElement(task.id);

  try {
    // The request and the collapse animation overlap, so the card is gone by
    // the time the write lands. The server returns the row it deleted, which
    // is what Undo posts back.
    const [data] = await Promise.all([
      apiPost('task.delete', { id: task.id }),
      collapseOut(card ? [card] : []),
    ]);

    column.tasks = column.tasks.filter((t) => t.id !== task.id);
    render();

    showToast(`Deleted “${truncate(task.title)}”`, {
      actionLabel: 'Undo',
      onAction: () => restoreSnapshot(data.snapshot),
    });
  } catch {
    await resync();
  }
}

async function clearColumn(project, column) {
  const total = column.tasks.length;
  if (total === 0) return;

  const ok = await openConfirm({
    title: `Clear “${truncate(column.title, 32)}”?`,
    body: `All ${plural(total, 'card')} in this column will be removed. You can undo it from the toast.`,
    ok: `Clear ${plural(total, 'card')}`,
  });
  if (!ok) return;

  const cards = cardsIn(columnElement(column.id));

  try {
    const [data] = await Promise.all([
      apiPost('column.clear', { column_id: column.id }),
      collapseOut(cards, { stagger: 45 }),
    ]);

    column.tasks = [];
    render();

    showToast(`Cleared ${plural(data.snapshot.tasks.length, 'card')} from “${truncate(column.title, 24)}”`, {
      actionLabel: 'Undo',
      onAction: () => restoreSnapshot(data.snapshot),
    });
  } catch {
    await resync();
  }
}

/** Plain-English summary of what a snapshot holds: "1 column, 3 cards". */
function describeSnapshot(snapshot) {
  return [
    [snapshot.projects, 'project'],
    [snapshot.columns, 'column'],
    [snapshot.tasks, 'card'],
  ]
    .filter(([rows]) => rows && rows.length > 0)
    .map(([rows, noun]) => plural(rows.length, noun))
    .join(', ');
}

/** Put deleted rows back with their original ids and positions. */
async function restoreSnapshot(snapshot) {
  try {
    const data = await apiPost('restore', { snapshot });
    board = data.projects;

    (snapshot.projects || []).forEach((row) => entering.projects.add(row.id));
    (snapshot.columns  || []).forEach((row) => entering.columns.add(row.id));
    (snapshot.tasks    || []).forEach((row) => entering.tasks.add(row.id));

    render();
    setTimeout(() => {
      entering.projects.clear();
      entering.columns.clear();
      entering.tasks.clear();
    }, 700);

    setStatus(`Restored ${describeSnapshot(snapshot)}`);
  } catch {
    await resync();
  }
}

/* ------------------------------------------------------------------- boot */

document.getElementById('add-project').addEventListener('click', addProject);

loadBoard().catch((error) => {
  boardEl.removeAttribute('aria-busy');
  boardEl.replaceChildren(el('p', 'board-loading', `Could not load the board: ${error.message}`));
});

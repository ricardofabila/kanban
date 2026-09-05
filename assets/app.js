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
const sortables = [];

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

/* ---------------------------------------------------------------- dialogs */

const taskDialog    = document.getElementById('task-dialog');
const taskForm      = document.getElementById('task-form');
const confirmDialog = document.getElementById('confirm-dialog');
const promptDialog  = document.getElementById('prompt-dialog');
const promptForm    = document.getElementById('prompt-form');

document.addEventListener('click', (event) => {
  const closer = event.target.closest('[data-close]');
  if (closer) closer.closest('dialog').close();
});

function openPrompt({ title, label = 'Title', value = '', ok = 'Save' }) {
  return new Promise((resolve) => {
    document.getElementById('prompt-title').textContent = title;
    document.getElementById('prompt-label').textContent = label;
    document.getElementById('prompt-ok').textContent    = ok;

    const input = promptForm.elements.value;
    input.value = value;

    let result = null;
    const onSubmit = () => { result = input.value.trim(); };

    promptForm.addEventListener('submit', onSubmit);
    promptDialog.addEventListener('close', () => {
      promptForm.removeEventListener('submit', onSubmit);
      resolve(result || null);
    }, { once: true });

    promptDialog.showModal();
    input.select();
  });
}

function openConfirm({ title, body, ok = 'Delete' }) {
  return new Promise((resolve) => {
    document.getElementById('confirm-title').textContent = title;
    document.getElementById('confirm-body').textContent  = body;

    const okBtn = document.getElementById('confirm-ok');
    okBtn.textContent = ok;

    let confirmed = false;
    const onOk = () => { confirmed = true; confirmDialog.close(); };

    okBtn.addEventListener('click', onOk);
    confirmDialog.addEventListener('close', () => {
      okBtn.removeEventListener('click', onOk);
      resolve(confirmed);
    }, { once: true });

    confirmDialog.showModal();
  });
}

/** Resolves to {type:'save',title,description} | {type:'delete'} | null. */
function openTask({ heading, title = '', description = '', allowDelete }) {
  return new Promise((resolve) => {
    document.getElementById('task-dialog-title').textContent = heading;
    taskForm.elements.title.value       = title;
    taskForm.elements.description.value = description;

    const deleteBtn = document.getElementById('task-delete');
    deleteBtn.hidden = !allowDelete;

    let result = null;
    const onSubmit = () => {
      result = {
        type: 'save',
        title: taskForm.elements.title.value.trim(),
        description: taskForm.elements.description.value.trim(),
      };
    };
    const onDelete = () => { result = { type: 'delete' }; taskDialog.close(); };

    taskForm.addEventListener('submit', onSubmit);
    deleteBtn.addEventListener('click', onDelete);
    taskDialog.addEventListener('close', () => {
      taskForm.removeEventListener('submit', onSubmit);
      deleteBtn.removeEventListener('click', onDelete);
      resolve(result && result.type === 'save' && !result.title ? null : result);
    }, { once: true });

    taskDialog.showModal();
    taskForm.elements.title.select();
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

  header.append(grip, title, count, el('span', 'spacer'), rename, remove);

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

  const open = () => editTask(project, column, task);
  card.addEventListener('dblclick', open);
  card.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') { event.preventDefault(); open(); }
  });

  const edit = iconButton('Edit task', '✎', 'card-edit');
  edit.addEventListener('click', open);
  card.append(edit);

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
  const ok = await openConfirm({
    title: `Delete “${project.title}”?`,
    body: 'Its columns and every task on this board will be deleted. This cannot be undone.',
  });
  if (!ok) return;
  try {
    await apiPost('project.delete', { id: project.id });
    board = board.filter((p) => p.id !== project.id);
    render();
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
  const ok = await openConfirm({
    title: `Delete “${column.title}”?`,
    body: column.tasks.length
      ? `${column.tasks.length} task(s) in this column will be deleted too.`
      : 'This column is empty.',
  });
  if (!ok) return;
  try {
    await apiPost('column.delete', { id: column.id });
    project.columns = project.columns.filter((c) => c.id !== column.id);
    render();
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

  try {
    if (result.type === 'delete') {
      const ok = await openConfirm({
        title: `Delete “${task.title}”?`,
        body: 'This task will be removed from the board.',
      });
      if (!ok) return;
      await apiPost('task.delete', { id: task.id });
      column.tasks = column.tasks.filter((t) => t.id !== task.id);
    } else {
      await apiPost('task.update', {
        id: task.id,
        title: result.title,
        description: result.description,
      });
      task.title = result.title;
      task.description = result.description;
    }
    render();
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

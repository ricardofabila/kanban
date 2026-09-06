# Kanban

A single-page, multi-board Kanban app. PHP + SQLite on the back end, vanilla JS
on the front. No build step, no Composer, no npm — clone it and serve it.

## Requirements

PHP 8.1+ with `pdo_sqlite` (bundled with PHP by default).

## Run it

```bash
php -S 127.0.0.1:8000 -t .
```

Open <http://127.0.0.1:8000>. The first visit asks you to choose a password;
that hash and the SQLite file are created automatically under `data/`, along
with four starter boards you can rename or delete.

To reset everything — password included — delete `data/database.sqlite`.

## Folder structure

```
.
├── index.php         Single-page dashboard shell (auth-gated)
├── backups.php       Backups page: snapshot, restore, prune
├── backup-store.php  The backups folder and the operations against it
├── login.php         First-run password setup + sign-in
├── logout.php        Destroys the session
├── api.php           JSON API: GET returns the board, POST performs all writes
├── config.php        Session bootstrap, PDO connection, schema, seed data
├── assets/
│   ├── app.js        Board: rendering, dialogs, SortableJS, fetch calls
│   ├── backups.js    Backups page: confirmations, local timestamps
│   ├── favicon.svg   Tab icon
│   └── styles.css    All styling, light + dark
└── data/
    ├── database.sqlite   Created on first run (gitignored)
    └── backups/          Backup snapshots (gitignored)
```

## Backups

The **Backups** link in the top bar opens a page listing every snapshot, newest
first, with buttons to take one, restore from one, or delete one.

Each backup is an ordinary, self-contained SQLite file in `data/backups/`. You
can copy one out and open it with any SQLite tool, and restoring is a plain file
copy back over the live database.

Snapshots are written with `VACUUM INTO` rather than `copy()`. The database runs
in WAL mode, so recent commits live in `database.sqlite-wal` and a copy of the
main file alone can silently miss them — in testing, a naive `copy()` of a
freshly seeded database produced a file with **no tables in it at all**, because
the entire schema was still in the WAL. `VACUUM INTO` writes one consistent,
compacted file in a single statement.

Restoring:

* verifies the file first — it must pass `PRAGMA quick_check` and contain the
  `projects`, `columns` and `tasks` tables;
* takes a `pre-restore-*` snapshot of the database it is about to replace, so
  the restore is itself undoable;
* deletes the stale `-wal` / `-shm` sidecars, which belong to the file being
  replaced and would otherwise be replayed against the restored one;
* keeps your **current password**, rather than the one in the backup — restoring
  an old board should not lock you out.

Backup names are always bare filenames inside `data/backups/`; anything with a
path separator, a leading dot, or the wrong extension is rejected before it
reaches the filesystem.

## Schema

| Table      | Columns                                                  |
|------------|----------------------------------------------------------|
| `projects` | `id`, `title`, `sort_order`                              |
| `columns`  | `id`, `project_id` → projects, `title`, `sort_order`     |
| `tasks`    | `id`, `column_id` → columns, `title`, `description`, `sort_order` |
| `settings` | `key`, `value` — holds the password hash                 |

Foreign keys use `ON DELETE CASCADE` and `PRAGMA foreign_keys = ON` is set on
every connection, so deleting a project takes its columns and tasks with it.

## API

Every endpoint requires an authenticated session; unauthenticated calls get
`401` and the front end redirects to the login page.

**`GET api.php`** returns the whole board:

```json
{ "ok": true,
  "projects": [
    { "id": 1, "title": "SaaS Project",
      "columns": [
        { "id": 1, "project_id": 1, "title": "Backlog",
          "tasks": [ { "id": 1, "column_id": 1, "title": "…", "description": "…" } ] }
      ] }
  ] }
```

**`POST api.php`** takes `{"action": "…", …}` as a JSON body and requires the
session CSRF token in an `X-CSRF-Token` header.

| Action            | Payload                                                       |
|-------------------|---------------------------------------------------------------|
| `project.create`  | `title` — also creates To Do / In Progress / Done             |
| `project.rename`  | `id`, `title`                                                  |
| `project.delete`  | `id` — returns a snapshot of everything removed, for undo       |
| `project.reorder` | `ids` — full ordered list                                      |
| `column.create`   | `project_id`, `title`                                          |
| `column.rename`   | `id`, `title`                                                  |
| `column.delete`   | `id` — returns a snapshot of everything removed, for undo       |
| `column.reorder`  | `project_id`, `ids`                                            |
| `column.clear`    | `column_id` — returns a snapshot of the removed cards           |
| `task.create`     | `column_id`, `title`, `description`                            |
| `task.update`     | `id`, `title`, `description`                                   |
| `task.delete`     | `id` — returns a snapshot of the removed card                   |
| `restore`         | `snapshot` — writes a delete's snapshot back                    |
| `task.reorder`    | `lists: [{column_id, task_ids}]` — one entry per touched column |

Errors come back as `{"ok": false, "error": "…"}` with a 4xx status.

`task.reorder` is what a drag writes: it carries the final order of every
column the drag touched, so a move between columns and a reorder within one are
the same call, applied in a single transaction.

Every delete is undoable, at all three levels. Each one returns a **snapshot**
of the rows it removed:

```json
{ "ok": true,
  "snapshot": {
    "projects": [ { "id": 2, "title": "…", "sort_order": 1 } ],
    "columns":  [ { "id": 5, "project_id": 2, "title": "…", "sort_order": 0 } ],
    "tasks":    [ { "id": 9, "column_id": 5, "title": "…", "description": "", "sort_order": 0 } ]
  } }
```

The undo toast holds that snapshot and hands it straight back to `restore`,
which writes the rows in parent-first order with their original ids and
`sort_order` — so a restored card, column or whole board lands exactly where it
was, not appended to the end.

Projects and columns are inserted with `INSERT OR IGNORE`, not `OR REPLACE`:
REPLACE deletes the existing row first, and on a parent row that would cascade
away the very children the same call is restoring. Tasks are leaves, so they use
`OR REPLACE`, which keeps a double-tapped Undo idempotent. Restore fails with
`409`, writing nothing, if the parent was deleted while the toast was still
up — undoing a column delete after its board is gone, for instance.

## Notes

* Cards drag between columns of the same board; columns and whole project rows
  reorder by their `⠿` grip. Cross-project card drags are blocked on purpose —
  each board gets its own SortableJS group.
* SortableJS runs in `forceFallback` mode for consistent drag visuals across
  browsers and on touch.
* Drags apply to the DOM first and persist in the background; if the write
  fails, the board re-fetches so the UI can't drift from the database.
* Double-click a project or column title to rename it; double-click a card (or
  press Enter on it) to edit. Press Delete on a focused card to remove it.
* Cards have edit and delete buttons on hover; each column with cards gets a
  broom to clear it in one go. Every delete — card, cleared column, column, or
  whole board — confirms first, then shows an undo toast whose countdown pauses
  while the pointer is over it.
* Deleted things collapse before the board re-renders: cards and project rows
  shrink by height, columns by flex-basis, each with a negative margin so the
  gap closes with them. Restored ones fade in with a brief accent glow.
* Every dialog settles on each exit path (button, submit, Escape) rather than
  relying on the `<dialog>` `close` event, which some embedded browsers never
  dispatch.
* Animations respect `prefers-reduced-motion`.

## Deploying

Serve the folder with Apache/nginx + PHP-FPM and put `data/` outside the web
root, or block it — neither `data/database.sqlite` nor anything in
`data/backups/` must be publicly fetchable. Run it over HTTPS so the session
cookie gets the `secure` flag.

`data/backups/` grows until you prune it; the page lists sizes so you can see
what it is costing. Backups sit on the same disk as the database, so they cover
mistakes rather than hardware failure — copy the folder elsewhere for that.

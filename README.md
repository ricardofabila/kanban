# Kanban

A single-page, multi-board Kanban app. PHP + SQLite on the back end, vanilla JS
on the front. No build step, no Composer, no npm — clone it and serve it.

## Requirements

PHP 8.1+ with `pdo_sqlite` (bundled with PHP by default).

## Run it

```bash
php -S 127.0.0.1:8000 router.php
```

Open <http://127.0.0.1:8000>.

Pass `router.php`. PHP's built-in server ignores `.htaccess`, so without it the
whole of `data/` is served as static files — the database, the backups and every
session file, unauthenticated. The router returns 404 for those paths.

The first visit asks you to choose a password; that hash and the SQLite file are
created automatically under `data/`, along with four starter boards you can
rename or delete.

To reset everything — password included — delete `data/database.sqlite`.

## Folder structure

```
.
├── router.php        Dev-server guard: hides data/ from `php -S`
├── index.php         Single-page dashboard shell (auth-gated)
├── plan.php          Builds a weekly-planning prompt from the board
├── plan-prompt.php   The prompt itself: shape, wording, observations
├── backups.php       Backups page: snapshot, restore, prune
├── backup-store.php  The backups folder and the operations against it
├── board.php         Reads the board — shared by the API and the plan page
├── login.php         First-run password setup + sign-in
├── logout.php        Destroys the session
├── api.php           JSON API: GET returns the board, POST performs all writes
├── config.php        Session bootstrap, PDO connection, schema, seed data
├── assets/
│   ├── app.js        Board: rendering, dialogs, SortableJS, fetch calls
│   ├── backups.js    Backups page: confirmations, local timestamps
│   ├── plan.js       Plan page: copy to clipboard
│   ├── favicon.svg   Tab icon
│   └── styles.css    All styling, light + dark
└── data/                 Created on first run, ignored by git
    ├── .htaccess         Denies HTTP access to everything below (written by the app)
    ├── database.sqlite   Created on first run (gitignored)
    ├── backups/          Backup snapshots (gitignored)
    └── sessions/         Session files (gitignored)
```

## Staying signed in

Sessions last **30 days**, as a rolling window — every request pushes the
deadline out, so you only get signed out after leaving the app alone that long,
or by signing out. Change `SESSION_LIFETIME` in `config.php` to adjust it.

PHP's defaults are much shorter, and both had to be widened:

| | Default | Here |
|---|---|---|
| `session.gc_maxlifetime` | 1440s — 24 minutes idle | 30 days |
| `session.cookie_lifetime` | 0 — until the browser closes | 30 days |

Session files are written to `data/sessions/` rather than the default shared
temp directory. This matters more than the numbers above: on a machine running
other PHP applications, the save path is shared, and their garbage collectors
delete by *their* `gc_maxlifetime`. A long lifetime set here would be quietly
overruled by a neighbour's short one. If that directory cannot be created or
written, the app falls back to the system default rather than failing.

Because the directory is ours, no distribution's session-cleanup cron covers it,
so PHP is told to collect it itself — expired files are still removed and the
folder does not grow without bound.

`session.use_strict_mode` is on, so the server never adopts a session id it did
not issue. That matters more with a long lifetime: it stops someone fixing a
session in advance by handing you a prepared link.

## Plan my week

**Plan my week** in the top bar turns the whole board into a prompt you paste
into an AI assistant. It lists every project, what you said it is for, and
exactly where it stands column by column, then asks for a week that keeps all of
them moving rather than only the loudest one.

Two optional inputs shape it: roughly how much time you have this week, and
anything else going on. They travel in the querystring, so a plan you like can
be bookmarked.

The quality depends on the **description** on each project — what it is for and
what it will do for you. Edit it from the pencil on any project row. Without
one, the prompt says so and tells the assistant to weight that project less and
ask you about it; the page also warns you which projects are missing theirs.

The prompt reports the board's shape and lets the assistant interpret it. It
deliberately does not guess what a column *means* — "Done" and "Icebox" are both
just names someone chose — so it says things like "all 4 cards are still in the
first column" rather than claiming a project has not started.

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
| `projects` | `id`, `title`, `description`, `sort_order`               |
| `columns`  | `id`, `project_id` → projects, `title`, `sort_order`     |
| `tasks`    | `id`, `column_id` → columns, `title`, `description`, `sort_order` |
| `settings` | `key`, `value` — holds the password hash                 |

Foreign keys use `ON DELETE CASCADE` and `PRAGMA foreign_keys = ON` is set on
every connection, so deleting a project takes its columns and tasks with it.

`projects.description` was added after the first release, so `init_schema()`
patches it into older databases with an `ALTER TABLE`. That runs on every
connection, which means a database that arrives by restoring a pre-upgrade
backup is upgraded too.

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
| `project.update`  | `id`, `title`, `description`                                   |
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
root, or block it — nothing under `data/` may be publicly fetchable: not the
database, not the backups, and least of all `data/sessions/`, where a single
leaked file is a working login. `data/.htaccess` denies access under Apache;
on nginx you must block the location in the server config yourself. The app
writes that guard whenever it creates the directory, so it cannot go missing. Run it over HTTPS so the session
cookie gets the `secure` flag.

`data/backups/` grows until you prune it; the page lists sizes so you can see
what it is costing. Backups sit on the same disk as the database, so they cover
mistakes rather than hardware failure — copy the folder elsewhere for that.

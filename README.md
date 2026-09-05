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
├── index.php        Single-page dashboard shell (auth-gated)
├── login.php        First-run password setup + sign-in
├── logout.php       Destroys the session
├── api.php          JSON API: GET returns the board, POST performs all writes
├── config.php       Session bootstrap, PDO connection, schema, seed data
├── assets/
│   ├── app.js       Rendering, dialogs, SortableJS wiring, fetch calls
│   └── styles.css   All styling, light + dark
└── data/
    └── database.sqlite   Created on first run (gitignored)
```

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
| `project.delete`  | `id`                                                           |
| `project.reorder` | `ids` — full ordered list                                      |
| `column.create`   | `project_id`, `title`                                          |
| `column.rename`   | `id`, `title`                                                  |
| `column.delete`   | `id`                                                           |
| `column.reorder`  | `project_id`, `ids`                                            |
| `task.create`     | `column_id`, `title`, `description`                            |
| `task.update`     | `id`, `title`, `description`                                   |
| `task.delete`     | `id`                                                           |
| `task.reorder`    | `lists: [{column_id, task_ids}]` — one entry per touched column |

Errors come back as `{"ok": false, "error": "…"}` with a 4xx status.

`task.reorder` is what a drag writes: it carries the final order of every
column the drag touched, so a move between columns and a reorder within one are
the same call, applied in a single transaction.

## Notes

* Cards drag between columns of the same board; columns and whole project rows
  reorder by their `⠿` grip. Cross-project card drags are blocked on purpose —
  each board gets its own SortableJS group.
* SortableJS runs in `forceFallback` mode for consistent drag visuals across
  browsers and on touch.
* Drags apply to the DOM first and persist in the background; if the write
  fails, the board re-fetches so the UI can't drift from the database.
* Double-click a project or column title to rename it; double-click a card (or
  press Enter on it) to edit.

## Deploying

Serve the folder with Apache/nginx + PHP-FPM and put `data/` outside the web
root, or block it — `data/database.sqlite` must not be publicly fetchable. Run
it over HTTPS so the session cookie gets the `secure` flag.

## Architecture (how the plugin is built)

Franer uses the classic **loader architecture** (à la WordPress plugin boilerplate):

- **`franer.php`** — bootstrap only. Defines the `FRANER_*` constants, registers
  activation/deactivation (`franer_activate`/`franer_deactivate`), the upgrade and lazy
  rewrite-flush guards, then `require`s `includes/class-franer.php` and calls `franer_run()`.
- **`includes/class-franer.php`** (`Franer`) — the core orchestrator. `load_dependencies()`
  `require`s every class and instantiates `Franer_Loader`. **All hooks are registered here**, never
  scattered across classes, in four private methods: `define_i18n_hooks()`, `define_admin_hooks()`,
  `define_public_hooks()`, `define_rest_hooks()`. `run()` calls `$this->loader->run()`.
- **`includes/class-franer-loader.php`** (`Franer_Loader`) — collects `add_action`/`add_filter`
  registrations and applies them in `run()`. To wire a hook, add a `$this->loader->add_action(...)`
  / `add_filter(...)` line in the relevant `define_*_hooks()` method (component may be an object or a
  class name string for static callbacks).

### Class responsibilities

- `includes/class-franer-activator.php` / `-deactivator.php` — create the submissions table via
  `dbDelta()` on activation (option `franer_db_version`); flush rewrites on deactivation.
- `includes/class-franer-post-types.php` — registers the `franer_site` CPT and its `register_post_meta`
  keys, and the `wp_post_revision_meta_keys` filter that revisions the settings meta.
- `includes/class-franer-sanitizer.php` — pure static validation/sanitization helpers
  (`sanitize_slug`, `sanitize_roles`, `sanitize_bool`, `sanitize_payload_size`, `sanitize_datetime`,
  `validate_payload`, `sanitize_generation_prompt`, `sanitize_view_html`, `strip_activity_comments`).
- `includes/class-franer-permissions.php` — static checks: `can_manage()`, `user_can_view()`,
  `user_has_allowed_role()`, `schedule_state()`.
- `includes/class-franer-site-repository.php` — reads/writes `franer_site` posts. `get_settings()`
  returns the **typed** settings array used everywhere. `set_raw_html()` writes the activity HTML to
  `post_content` (KSES bypassed, see below).
- `includes/class-franer-submissions-repository.php` — all submissions SQL (`$wpdb->prepare()`), incl.
  `save_submission`, `get_latest_user_submission`, `get_site_submissions`, `count_site_submissions`,
  `delete_submission`, `update_submission`, `export_site_submissions`.
- `includes/class-franer-rest-controller.php` — registers the `franer/v1` routes and validates them.
- `includes/class-franer-demo-data.php` — idempotent demo seed (slug `mcode40`), gated by the
  `franer_demo_seeded` option.
- `admin/class-franer-admin.php` — menus, metaboxes (save via nonce `franer_site_nonce` /
  action `save_franer_site`; `save_meta()` dispatches to focused `save_*_meta()` helpers), assets,
  the list columns/filters/sorting + row action, and the `before_delete_post` submissions purge.
- `admin/class-franer-admin-submissions.php` (`Franer_Admin_Submissions`) — the Submissions admin
  screens: the standalone Submissions list page, the per-Franer submissions-overview page
  (`prepare_submission_view()` builds the iframe context) and the edit/delete admin-post handlers.
  Its page callbacks are wired from `Franer_Admin::add_menu()`; its admin-post handlers and the
  overview page are registered in `Franer::define_admin_hooks()`.
- `admin/class-franer-help.php` — the Help page and the two copy-paste AI prompts
  (`get_default_activity_prompt()` and `get_default_view_prompt()`).
- `admin/class-franer-export-controller.php` — the `admin-post.php` JSON export.
- `public/class-franer-public.php` — rewrite rule `/franer/{slug}/`, the `[franer]` shortcode, and the
  theme-independent sandboxed render. `public/js/franer-shell.js` is the parent shell.

### Data model

- **Activity HTML → `post_content`** (raw). It is natively revisioned and shown in the WordPress
  revision diff. `Franer_Site_Repository::set_raw_html()` bypasses KSES because the markup is
  arbitrary admin-provided HTML rendered only inside the sandboxed iframe; the CPT is non-public,
  non-REST and excluded from search, so `post_content` is never exposed directly. The admin save path
  removes/re-adds its own `save_post` hook around the write to avoid recursion.
- **Visibility = post status** — a *published* `franer_site` is visible; *draft* is hidden (and 404s
  everywhere). There is no separate visibility meta.
- **Settings → post meta** — `_franer_slug`, `_franer_accepts_submissions`, `_franer_allowed_roles`,
  `_franer_allow_multiple_submissions`, `_franer_allow_overwrite`, `_franer_max_payload_size`,
  `_franer_enabled` (default true when unset), `_franer_start_date`, `_franer_end_date`. The schema
  version is a fixed constant (`'1.0'`), not a per-site field.
- **Admin-only meta** — `_franer_generation_prompt` (free-form text; the prompt used to generate the
  activity), `_franer_view_html` (raw HTML for the submissions-overview template), and
  `_franer_view_generation_prompt`. All three are revisioned, never `show_in_rest`, never rendered
  publicly, sent to the activity iframe, or exported. The prompts are normalized + size-capped by
  `Franer_Sanitizer::sanitize_generation_prompt()` (NOT KSES'd — they may contain code); the view
  HTML is stored raw like the activity HTML.
- **Render-time comment stripping** — `Franer_Sanitizer::strip_activity_comments()` removes HTML and
  inline-JS comments (string/template/regex-aware; CSS comments kept) when building the iframe
  `srcdoc` for both the public activity render and the admin submissions-overview render. The stored
  source/revisions/editor keep all comments.
- **Submissions overview** — an optional admin page (`page=franer-submission-view&site_id=…`,
  `manage_options`) renders `_franer_view_html` in a sandboxed iframe and posts ALL of the Franer's
  decoded submissions to it via `postMessage` (`type:"franer_view_payload"`, payload
  `{ site, count, truncated, submissions:[…] }`; the template implements
  `window.FranerRenderSubmissions(context)`). The frame gets no nonce, admin URL or stored PII.
- **Availability** — `_franer_enabled` is a master switch; `_franer_start_date`/`_franer_end_date`
  (local `Y-m-d H:i:s`, compared as strings vs `current_time('mysql')`) gate submissions
  (`Franer_Permissions::schedule_state()` → `disabled|not_yet|ended|open`).
- **Submissions → custom table** `{$wpdb->prefix}franer_submissions` (hashed ip/ua, sha256
  `payload_hash`, no raw PII).

### How to make common changes (follow these patterns)

- **Add a site setting:** register the meta key in `Franer_Post_Types::register_meta()` (and in
  `add_revisioned_meta_keys()` if it should be revisioned) → read+type it in
  `Franer_Site_Repository::get_settings()` → render an input in
  `admin/partials/franer-admin-metaboxes.php` → sanitize+save it in the relevant
  `Franer_Admin::save_*_meta()` helper dispatched from `save_meta()` (via a `Franer_Sanitizer`
  helper) → enforce it where relevant (permissions/REST/public).
- **Add a developer hook:** place `do_action`/`apply_filters` at the lifecycle point with a full
  docblock (`@since`, `@param`, return type, security note). **Filters must be defensively validated**
  (check the return type, restore required keys) and must never bypass auth/nonce/role/visibility/
  schema/size/duplicate/sandbox checks. Document it in the README "Developer hooks" table and add a
  test in `tests/HooksTest.php`.
- **Add a REST route:** register it in `Franer_Rest_Controller::register_routes()` with a permission
  callback; return `WP_REST_Response`/`WP_Error` with correct status codes; cover it in
  `tests/RestControllerTest.php`.
- **Add an admin list column / row action:** extend `Franer_Admin::add_list_columns()` /
  `render_list_column()` / `add_row_actions()` and wire the corresponding
  `manage_franer_site_posts_*` / `post_row_actions` hook in `Franer::define_admin_hooks()`.
- **Add a Makefile/CI step or asset:** keep `make lint`, `make check-untranslated`, `make check-plugin`
  and the test suites green; the CI `lint_and_test` job runs them in order.

## postMessage + REST contract

postMessage:

```
iframe  -> parent: { type:"franer_submit",
                     payload:{ schema_version:"1.0", activity_id:"<slug>", data:{...} } }
parent  -> iframe (ok):    { type:"franer_submit_result", ok:true,
                             result:{ submission_id, status } }
parent  -> iframe (error): { type:"franer_submit_result", ok:false,
                             result:{ code, message } }
```

REST (`franer/v1`):

- `POST /sites/{slug}/submissions` — `permission_callback: is_user_logged_in()`; role checked in the
  handler. Status codes: 401 not logged in, 404 not found, 403 hidden/closed/role,
  400 invalid payload or bad schema_version, 413 too large, 409 duplicate. Success: `201` with
  `{ submission_id, status: "saved"|"updated" }`.
- `GET /sites/{slug}/my-submission` — same security except submissions need not be open to read;
  returns the user's latest submission or `404`.

## Activity authors' JS contract

Activity HTML must implement two globals and a result listener:

- `window.FranerCollect()` — returns the structured submission object
  (`schema_version`, `activity_id`, `activity_title`, `submitted_at`, `data`).
- `window.FranerSubmit()` — validates, then posts
  `{ type:"franer_submit", payload: FranerCollect() }` to `window.parent`.
- A `message` listener handling `{ type:"franer_submit_result", ok, result }`.

The **Help** admin page exposes a ready-to-use AI prompt (with a Copy prompt button) that produces
compliant activities.

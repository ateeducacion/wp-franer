<!-- AGENTS.md -->

# Agents Coding Conventions for the "Franer" plugin

These are natural-language guidelines for agents and developers working on **Franer**, a secure
environment to publish AI-generated forms (Framework for Running AI-geNerated Embedded foRms).

## Project conventions

- Follow the **WordPress Coding Standards** (WPCS), enforced by PHPCS via `.phpcs.xml.dist`.
  - PHP: **tabs** for indentation (WPCS default — `make fix`/PHPCBF enforce it), Yoda conditions,
    proper escaping and sanitization, use WordPress APIs.
  - Global functions declared in `franer.php` must be **prefix-first** (`franer_activate`, not
    `activate_franer`) to satisfy `WordPress.NamingConventions.PrefixAllGlobals`.
  - Use **English** for all source code: identifiers, comments and docblocks.
  - Use **Spanish** for user-facing translations (`languages/franer-es_ES.po`) and for the
    untranslated-strings assertions.
- Every class, method and function must be preceded by an **English PHPDoc block**.
- One class per file, named `class-franer-*.php`. The bootstrap file `franer.php` stays small;
  logic lives under `includes/`, admin code under `admin/`, public code under `public/`.
- First executable line of every PHP file (except tests): `if ( ! defined( 'WPINC' ) ) { die; }`.
- **Keep methods and classes simple** so they stay under the PHPMD thresholds in `phpmd.xml`
  (enforced by the PHPMD GitHub workflow and surfaced as code-scanning alerts). Per method:
  **cyclomatic complexity ≤ 15**, **NPath complexity ≤ 500**, **length ≤ 150 lines**; per class:
  **overall complexity ≤ 100**. When a method approaches a limit, extract cohesive private helpers
  (guard/early-return checks, one helper per saved field, per-mode scanners, row mappers) instead of
  growing one long body — note that splitting a method only moves complexity *within* the class, so
  when the **class** is too complex, move a whole responsibility into its own `class-franer-*.php`
  file (e.g. the Submissions admin screens live in `Franer_Admin_Submissions`, not `Franer_Admin`).
  Prefer many small, single-purpose methods over deeply nested conditionals. Verify locally with
  `php -d error_reporting=0 vendor/bin/phpmd . text phpmd.xml` (or `make lint` for WPCS) before pushing.

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

## Testing and development workflow

- Use **TDD** (Test-Driven Development): write or update tests before/with the implementation.
- Develop inside the **`@wordpress/env`** environment. Development runs on port `8888`, the tests
  environment on port `8889`.
- **Test factories and base class (reuse these — do not hand-roll fixtures).** Tests live in
  `tests/*Test.php` and extend either `WP_UnitTestCase` or `Franer_Test_Base`
  (`tests/includes/class-franer-test-base.php`). `Franer_Test_Base` extends the WordPress factory
  with Franer-specific factories (the wp-decker convention) and activates the submissions table +
  registers the CPT in `set_up()`:
  - `self::factory()->franer_site->create( array( 'slug' => 'x', 'html' => '…', 'allowed_roles' => array( 'subscriber' ), 'enabled' => true, 'accepts_submissions' => true ) )`
    — a published `franer_site` with its `_franer_*` meta (uses `Franer_Site_Repository::set_raw_html()` for the activity HTML).
  - `self::factory()->franer_submission->create( array( 'site_id' => $id, 'user_id' => $u, 'payload' => array( … ) ) )`
    — inserts a row via `Franer_Submissions_Repository::save_submission()` (auto-creates a site/user when omitted).
  - The factory classes are in `tests/includes/` and are registered in `tests/bootstrap.php`.
    Array-valued defaults (roles, payload) live in `create_object()`, not in
    `default_generation_definitions` (WordPress' `generate_args()` only accepts scalars/generators).
  - Admin/handler patterns used across the suite: `set_current_screen()` for screen-aware code,
    `ob_start()`/`ob_get_clean()` for render callbacks, `expectException( WPDieException::class )` for
    `wp_die()` paths, and a `wp_redirect` filter that throws a marker exception to capture
    `wp_safe_redirect()` targets without terminating PHPUnit (see `tests/AdminSubmissionsTest.php`).
    Assert against `__()`/`esc_html__()` output — the tests environment runs in es_ES.
- Makefile targets:
  - `make up` — start the local environment (activates the plugin).
  - `make down` / `make destroy` / `make reset` — stop / destroy / reset the environment.
  - `make lint` — PHPCS (WordPress Coding Standards). `make fix` — PHPCBF auto-fix.
  - `make test` / `make test-php` — PHPUnit tests (accepts `FILE=` and `FILTER=`).
  - `make test-php-coverage` — PHPUnit with code coverage. (Re)starts wp-env with
    `--xdebug=coverage` and writes `artifacts/coverage/clover.xml` (consumed by
    Codecov) plus a browsable `artifacts/coverage/html/` report.
  - `make test-js` — Jest JavaScript unit tests.
  - `make test-e2e` — Playwright end-to-end tests (against port 8889).
  - `make check-plugin` — WordPress Plugin Check.
  - `make check-untranslated` — fail if any Spanish `msgstr` is empty.
  - `make pot` / `make po` / `make mo` — regenerate translation catalogues.
  - `make check` — runs fix, lint, plugin-check, tests, untranslated and mo together.
  - `make package VERSION=x.y.z` — build the release ZIP.
- **Code coverage (Codecov).** The CI `lint_and_test` job runs PHPUnit with coverage
  and uploads `artifacts/coverage/clover.xml` to [Codecov](https://codecov.io/gh/ateeducacion/wp-franer).
  PHP line coverage is currently **~89%**. `codecov.yml` configures gating status
  checks: a pull request **fails** if it lowers project coverage or if its changed
  lines are less covered than the base, so add tests alongside new code. The
  coverage scope is defined in `phpunit.xml.dist` (`admin/`, `includes/`, `public/`,
  `franer.php`, `uninstall.php`). Uploads are tokenless: the organization has opted
  out of requiring a Codecov upload token for public repositories.

## Environment and tools

- Use **Alpine-based Docker** containers when setting up with Docker (wp-env defaults).
- For Linux commands assume **Ubuntu Server**.
- On macOS desktop use **Homebrew** to install tooling.
- Use **vim** as the terminal editor, not `nano`.

## Internationalization (mandatory)

- Text domain is **`franer`** for every user-facing string. Use `__()`, `_e()`, `esc_html__()`,
  `esc_html_e()`, `esc_attr__()`, `esc_attr_e()`, `_n()`, `_x()`.
- Whenever you add, change or remove a user-facing string, update the catalogues **in the same
  change set**:
  1. Run `make check-untranslated` (regenerates `languages/franer.pot`, refreshes
     `languages/franer-es_ES.po` and rebuilds the `.mo`).
  2. Translate every new `msgid` into **Spanish**. The build fails while any `msgstr ""` remains.
  3. Commit the `.pot`, the `.po` and the `.mo` together with the code that introduced the strings.
- Plural strings use `_n( 'singular', 'plural', $count, 'franer' )` and require both `msgstr[0]`
  and `msgstr[1]` in the PO file.
- **Every i18n call containing a placeholder (`%s`, `%d`, `%1$s`, ...) MUST be preceded by a
  `translators:` comment** describing each placeholder, or PHPCS
  (`WordPress.WP.I18n.MissingTranslatorsComment`) fails CI.
- Strings exposed to JavaScript must travel through `wp_localize_script()` (e.g. `FranerShell` and
  `FranerAdmin`) so they appear in the `.pot`. Never hard-code English text in JS files, and never
  hard-code REST URLs or nonces.

## Security model (must hold)

- The activity **HTML is stored RAW** (never sanitized or stripped). It is only ever rendered inside
  `<iframe srcdoc="..." sandbox="allow-scripts allow-forms">` with **no** `allow-same-origin`.
- Never trust the iframe. The **parent page** (not the iframe) performs the nonced REST call.
- Store payloads as JSON via `wp_json_encode()`; decode with `json_decode( $s, true )`.
- Store only **hashes** of the IP and user agent (`hash( 'sha256', ... )` / `wp_hash()`), never raw.
- `manage_options` is required for all admin create/edit/delete/export/submissions screens.
- REST endpoints use permission callbacks (logged-in + `X-WP-Nonce` + allowed role).
- Always use `$wpdb->prepare()` for SQL, nonces for admin forms (`wp_nonce_field` /
  `wp_verify_nonce`, `check_admin_referer` for admin-post), and escape on output
  (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`). `wp_unslash()` before sanitizing superglobals.

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

## Skills

Recurring procedures live as skills under:

- `.agents/skills/` — GitHub Copilot, Codex, Cursor and the other agents that share this path
- `.claude/skills/` — Claude Code
- `.grok/skills/` — Grok Build (the documented project path is `./.grok/skills/`, walked up to the repo root; see [Skills, Plugins & Marketplaces](https://docs.x.ai/build/features/skills-plugins-marketplaces))

Install and refresh them with the GitHub CLI (`gh skill add` is an alias of
`gh skill install`). Repeat for each host directory you care about:

```bash
gh skill add WordPress/agent-skills wp-performance --agent github-copilot
gh skill add WordPress/agent-skills wp-performance --agent claude-code
gh skill add WordPress/agent-skills wp-performance --agent grok
gh skill update --all
```

`gh skill` copies the skill into each host directory and injects source
metadata into the `SKILL.md` frontmatter so later updates work. Do not
reformat or duplicate a skill by copying `SKILL.md` yourself.

### Skill compatibility

Project compatibility requirements always take precedence over generic skill
recommendations. This plugin supports WordPress 6.1+, while some vendored
WordPress agent skills target WordPress 7.0+.

Do not introduce APIs or behavior that require a newer WordPress version unless
the project minimum version is intentionally being raised in the same change.
When following a skill, verify that every suggested WordPress API is available
in the plugin's supported version range.

This plugin stores activity HTML raw and renders it only inside a sandboxed
iframe without `allow-same-origin`. That security model takes precedence over
generic `wp-plugin-security` advice about sanitizing or KSES'ing stored HTML.
Do not "fix" raw HTML storage, the KSES bypass in `set_raw_html()`, or the
iframe sandbox flags unless the change is an intentional redesign of that model.

| Skill | Read it before | Origin |
| --- | --- | --- |
| `wp-plugin-development` | Touching hooks, activation/uninstall, the Settings API, options, cron or release packaging | [`WordPress/agent-skills`](https://github.com/WordPress/agent-skills), GPL-2.0-or-later |
| `wp-rest-api` | Adding or debugging routes: `register_rest_route`, `permission_callback`, schema/args, `register_meta`, `show_in_rest` (`Franer_Rest_Controller`) | idem |
| `wp-plugin-directory-guidelines` | Editing `readme.txt`, license headers or plugin naming — this is what `make check-plugin` enforces | idem |
| `blueprint` | Editing `blueprint.json` or the Playground preview | idem |
| `wp-performance` | Profiling or improving backend performance (WP-CLI profile/doctor, autoload, object cache, cron, HTTP API) | idem |
| `wp-project-triage` | Inspecting what kind of WordPress repo this is before changing tooling or layout | idem |
| `wp-plugin-security` | Writing or reviewing code that handles input, output, AJAX/REST, capabilities or files | [`fernandotellado/ai-skills`](https://github.com/fernandotellado/ai-skills), GPL-2.0-or-later |
| `security-audit` | Hunting vulnerabilities and validating findings | [`cloudflare/security-audit-skill`](https://github.com/cloudflare/security-audit-skill) |

All of them are **third party and vendored verbatim**. Do not reformat or edit
them: diverging from upstream makes `gh skill update` harder. Fix the problem
upstream and re-install instead.

Provenance lives in each `SKILL.md` frontmatter (`metadata.github-repo`,
`github-path`, `github-tree-sha`).

Skills, `AGENTS.md` and `CLAUDE.md` are excluded from the release ZIP via
`.distignore` and from the Playground source ZIP via `.gitattributes`.

<!-- AGENTS.md -->

# Agents Coding Conventions for the "Franer" plugin

These are natural-language guidelines for agents and developers working on **Franer**, a secure
environment to publish AI-generated forms (Framework for Running AI-geNerated Embedded foRms).

## Project conventions

- Follow the **WordPress Coding Standards** (WPCS), enforced by PHPCS via `.phpcs.xml.dist`.
  - PHP: **4 spaces** indentation (never tabs), Yoda conditions, proper escaping and sanitization,
    use WordPress APIs.
  - Use **English** for all source code: identifiers, comments and docblocks.
  - Use **Spanish** for user-facing translations (`languages/franer-es_ES.po`) and for the
    untranslated-strings assertions.
- Every class, method and function must be preceded by an **English PHPDoc block**.
- One class per file, named `class-franer-*.php`. The bootstrap file `franer.php` stays small;
  logic lives under `includes/`, admin code under `admin/`, public code under `public/`.
- First executable line of every PHP file (except tests): `if ( ! defined( 'WPINC' ) ) { die; }`.

## Testing and development workflow

- Use **TDD** (Test-Driven Development): write or update tests before/with the implementation.
- Develop inside the **`@wordpress/env`** environment. Development runs on port `8888`, the tests
  environment on port `8889`.
- Makefile targets:
  - `make up` — start the local environment (activates the plugin).
  - `make down` / `make destroy` / `make reset` — stop / destroy / reset the environment.
  - `make lint` — PHPCS (WordPress Coding Standards). `make fix` — PHPCBF auto-fix.
  - `make test` / `make test-php` — PHPUnit tests (accepts `FILE=` and `FILTER=`).
  - `make test-js` — Jest JavaScript unit tests.
  - `make test-e2e` — Playwright end-to-end tests (against port 8889).
  - `make check-plugin` — WordPress Plugin Check.
  - `make check-untranslated` — fail if any Spanish `msgstr` is empty.
  - `make pot` / `make po` / `make mo` — regenerate translation catalogues.
  - `make check` — runs fix, lint, plugin-check, tests, untranslated and mo together.
  - `make package VERSION=x.y.z` — build the release ZIP.

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

# Franer

<p align="center">
  <img src=".github/logo.png" alt="Franer logo" width="220">
</p>

![CI](https://img.shields.io/github/actions/workflow/status/ateeducacion/wp-franer/ci.yml?label=CI)
[![codecov](https://codecov.io/gh/ateeducacion/wp-franer/branch/main/graph/badge.svg)](https://codecov.io/gh/ateeducacion/wp-franer)
![WordPress Version](https://img.shields.io/badge/WordPress-6.1%2B-blue)
![Language](https://img.shields.io/badge/Language-PHP-orange)
![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)
![Last Commit](https://img.shields.io/github/last-commit/ateeducacion/wp-franer)
![Open Issues](https://img.shields.io/github/issues/ateeducacion/wp-franer)

**Franer** is a **secure environment to publish AI-generated forms**. The name is a backronym
for **F**ramework for **R**unning **AI**-ge**N**erated **E**mbedded fo**R**ms.

Franer lets an administrator paste self-contained, AI-generated HTML activities, renders them
inside a **sandboxed iframe**, and collects users' JSON submissions safely on the server. There
is no dependency on Formidable Forms or any third-party form engine.

## Demo

Try Franer instantly in your browser with WordPress Playground. The demo includes sample data so
you can explore the features. All changes are discarded when you close the tab, because everything
runs locally in your browser.

<p align="center">
  <a href="https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/ateeducacion/wp-franer/refs/heads/main/blueprint.json">
    <img src="https://raw.githubusercontent.com/ateeducacion/wp-franer/refs/heads/main/.github/playground-preview-button.svg" alt="Preview in WordPress Playground" width="320">
  </a>
</p>

## Purpose

AI assistants are great at producing rich, interactive single-file HTML activities (quizzes,
self-assessments, calculators, surveys). The hard part is publishing them safely and collecting
the data they generate. Franer solves both problems:

- It treats the AI-generated HTML as **untrusted** and never executes it in the context of your
  site. The markup only ever runs inside a locked-down iframe.
- It collects each user's structured JSON answer through a **nonced, authenticated REST call made
  by the parent page**, never by the iframe itself.
- It stores submissions server-side, keeping only hashes of the IP address and user agent.

## How it works

1. **Author**: an admin pastes an AI-generated, self-contained HTML activity into a Franer site
   (the `franer_site` custom post type).
2. **Render**: the activity is rendered inside an `<iframe srcdoc="..." sandbox="allow-scripts allow-forms">`
   with **no** `allow-same-origin`. The iframe cannot read cookies, touch the parent DOM, or make
   network requests.
3. **postMessage**: when the user submits, the activity calls `window.FranerSubmit()`, which posts
   a `franer_submit` message to the parent page.
4. **Nonced REST**: the parent shell (`public/js/franer-shell.js`) receives the message and performs
   an authenticated `POST` to the Franer REST endpoint with the `X-WP-Nonce` header and
   `credentials: 'same-origin'`.
5. **Stored JSON**: the server validates permissions, role, payload size and schema version, then
   stores the submission as a JSON string. The parent shell posts a `franer_submit_result` message
   back into the iframe so the activity can show a confirmation.

```
Activity HTML (untrusted)
   └─ sandboxed iframe ── postMessage(franer_submit) ──▶ parent shell
                                                            └─ nonced REST POST ──▶ WordPress
                                                                                      └─ stored JSON
   ◀── postMessage(franer_submit_result) ── parent shell ◀── REST response ─────────────┘
```

## Installation

1. **Download the latest release** from the [GitHub Releases page](https://github.com/ateeducacion/wp-franer/releases).
2. Upload the ZIP file to your WordPress site via **Plugins > Add New > Upload Plugin**.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Activation registers the `/franer/{slug}/` rewrite rule and creates the submissions table.
   If public URLs return 404, visit **Settings > Permalinks** and save to flush rewrite rules.

## Development setup

Bring up a local WordPress environment with the plugin pre-installed using
[`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/):

```bash
make up
```

This starts a Dockerized WordPress instance at [http://localhost:8888](http://localhost:8888)
(default admin user `admin`, password `password`). The tests environment runs on port `8889`.
Run `make help` to list every available target.

## Creating a Franer site

1. In the WordPress admin, open **Franer > Add New**.
2. Give the activity a title.
3. In **HTML source**, paste your self-contained activity. The box has two tabs:
   - **Activity HTML** — the form shown to end users. It is stored exactly as entered and is
     only ever rendered inside a sandboxed iframe. A collapsible **Prompt used to generate this
     activity** field lets you save the prompt you used, for future audits or regenerations; it is
     admin-only and never shown publicly, sent to the iframe or exported.
   - **Submission View HTML** — an optional, admin-only template that renders an overview of *all*
     of the activity's submissions (totals, charts, summary tables). See *Submissions overview*
     below.
4. In **Site settings**, configure:
   - **Slug** — lowercase letters, numbers and hyphens only; used in the public URL.
   - **Visibility** — whether allowed users can see it.
   - **Submissions** — accept new submissions, allow multiple submissions per user, allow
     overwriting the previous submission.
   - **Allowed roles** — only logged-in users with one of these roles may view and submit;
     administrators are always allowed.
   - **Max payload size (KB)** — between 1 and 5120 KB.
   - **Schema version** — currently `1.0`.
5. Publish. The **Public URL** metabox shows the shareable link and shortcode.

## Public rendering & shortcode

A published, visible activity is reachable in two ways:

- **Pretty URL**: `/franer/{slug}/`. Logged-out visitors are redirected to the login page; users
  without an allowed role get a 403; hidden activities behave as 404.
- **Shortcode**: embed it in any post or page with:

  ```
  [franer slug="your-activity-slug"]
  ```

  The shortcode renders nothing for users who are not allowed to view the activity, so it never
  leaks the existence of a hidden or restricted activity.

**Render-time comment stripping.** Both rendering paths strip HTML comments (`<!-- ... -->`) and
inline-JavaScript comments (`// ...`, `/* ... */`) from the activity HTML *before* it reaches the
iframe `srcdoc`, so maintenance comments — including any generation prompt you embed near the top —
never leak to end users. Stripping is render-time only: the stored `post_content` (and its
revisions, and the admin editor) keep every comment. The stripper is string/template/regex-aware, so
URLs (`https://…`) and comment-like text inside JS strings, template literals and regular
expressions are preserved. CSS comments inside `<style>` are intentionally kept.

## Submissions

- Each authenticated submission is stored in the `{$wpdb->prefix}franer_submissions` table as a
  JSON string, alongside SHA-256 hashes of the IP address and user agent (never the raw values).
- Review them under **Franer > Submissions**. Filter by activity, then open the **View JSON** modal
  to inspect any payload.
- Duplicate handling follows the per-site settings: with "allow multiple" off, a second submission
  either overwrites the previous one (if overwrite is enabled) or is rejected with `409`.
- The REST endpoint `GET /franer/v1/sites/{slug}/my-submission` returns the current user's latest
  submission so activities can pre-fill previously saved answers.

## JSON export

Administrators can export all submissions for an activity as a single JSON file from the
per-site metabox or the Submissions page. Internally this hits:

```
admin-post.php?action=franer_export&site_id=123
```

The action is nonce-protected and restricted to `manage_options`. The download includes the decoded
payloads plus each user's login and email.

## Submissions overview

Each Franer can carry an optional **Submission View HTML** template (the second tab of the HTML
source box) that renders an admin-only overview of *all* of its submissions — totals, charts and
summary tables. It is opened from the **View overview** button on the Submissions screen (and the
per-site submissions metabox), at:

```
edit.php?post_type=franer_site&page=franer-submission-view&site_id=123
```

This admin page requires `manage_options`. It renders the template inside a sandboxed iframe
(`sandbox="allow-scripts allow-modals"`, no `allow-same-origin`) — exactly like an activity — and
the template's HTML/JS comments are stripped at render time. The decoded submissions are **not**
interpolated into the iframe markup: after the iframe loads, the trusted parent page posts them via
`postMessage`. The iframe never receives a REST nonce, an admin URL or any stored PII (the IP/UA
hashes and the user email are not included).

postMessage contract (parent → view iframe):

```js
{
  type: "franer_view_payload",
  payload: {
    site: { id, slug, title },
    count: 123,            // total submissions for the activity
    truncated: false,      // true when only the most recent submissions were sent
    submissions: [
      { id, user_id, created_at, updated_at, payload: { schema_version, activity_id, data } }
    ]
  }
}
```

The template implements `window.FranerRenderSubmissions(context)` and a `message` listener for
`franer_view_payload`. If no template is configured, the page shows a clear notice instead. **Franer
> Help** includes a ready-to-use **Copy submission view prompt** that generates a compliant,
self-contained overview document (with inline-SVG/canvas charts, no external resources).

## Security model

- **Untrusted HTML**: the activity markup is stored raw (never sanitized or stripped) and is only
  rendered inside `<iframe srcdoc="..." sandbox="allow-scripts allow-forms">` with **no**
  `allow-same-origin`. The optional submission-view template is treated identically (admin-only, but
  still untrusted and sandboxed), and HTML/JS comments are stripped from both before rendering.
- **Parent-side trust**: the iframe is never trusted; the parent page makes the nonced REST call.
  The submission-view iframe receives only the submission JSON (via `postMessage`), never a nonce,
  an admin URL or stored PII.
- **Hashed identifiers**: only SHA-256 hashes of the IP and user agent are stored.
- **Capabilities**: every admin create/edit/delete/export/submissions screen requires
  `manage_options`. REST endpoints require a logged-in user, a valid `X-WP-Nonce`, and an allowed
  role.
- **Validation**: payloads are validated for object shape, schema version (`1.0`) and maximum byte
  size before being stored with `wp_json_encode()`.

## AI prompt workflow

Open **Franer > Help** in the admin. The page documents the JavaScript contract and provides a
ready-to-use **AI prompt** with a **Copy prompt** button. Paste the prompt into your AI assistant,
replace the activity slug placeholder, describe the topic, and the model returns a single
self-contained HTML document that implements:

- `window.FranerCollect()` — returns the structured submission object
  (`schema_version`, `activity_id`, `data`, ...).
- `window.FranerSubmit()` — validates the form and posts
  `{ type:"franer_submit", payload: FranerCollect() }` to `window.parent`.
- A `message` listener that handles `{ type:"franer_submit_result", ok, result }` from the host.

The Help page also asks the AI to add maintenance comments and to embed the generating prompt as an
HTML comment near the top of the document (Franer strips those before rendering). A second
**Copy submission view prompt** generates the admin-only *submissions overview* template described
under *Submissions overview*.

## Developer hooks

Franer exposes a small, documented set of actions and filters (all prefixed
`franer_`) so institutional integrations can observe lifecycle events and adapt
presentation, submitted payloads and exports — **without weakening the security
model**. No hook can bypass authentication, the REST nonce, capability or role
checks, site visibility, schema/size validation, duplicate handling, the
sandboxed iframe, or the export capability/nonce checks. Filters are defensively
validated (return types checked, required keys restored), and the payload filter
runs *before* size validation, so it can never exceed the configured limit.

### Rendering

| Hook | Type | Fires / Returns |
|------|------|-----------------|
| `franer_shortcode_atts` | filter | `[franer]` attributes after defaults; presentation only. Returns array. |
| `franer_before_render` | action | Before rendering, only for a viewable activity. Params: `$site, $settings, $context` (`'shortcode'`/`'pretty_url'`). |
| `franer_render_markup` | filter | The shared render markup; wrap/augment only — must keep the sandboxed iframe intact. Params: `$markup, $site, $settings, $context`. Returns string. |
| `franer_after_render` | action | After markup is built. Params: `$site, $settings, $context, $markup`. |
| `franer_public_shell_data` | filter | Data localized to the parent shell JS; required `restUrl`/`myUrl`/`nonce`/`slug` are always restored. Params: `$shell_data, $settings, $slug`. Returns array. |

### Submission lifecycle

| Hook | Type | Fires / Returns |
|------|------|-----------------|
| `franer_before_process_submission` | action | After auth/visibility/role/open checks, before validation. Params: `$body, $site, $settings, $user_id, $request`. |
| `franer_submission_payload` | filter | Submitted `data` before encode; still encoded and **size-validated** afterwards. Params: `$data, $body, $site, $settings, $user_id, $request`. Returns array (non-array ignored). |
| `franer_before_save_submission` | action | After validation, before save. Params: `$site_id, $user_id, $payload_json, $settings`. |
| `franer_after_save_submission` | action | Only after a successful save. Params: `$submission_id, $status, $site_id, $user_id, $payload_json, $settings`. |
| `franer_submission_response` | filter | Successful REST response data; add non-sensitive metadata only. Params: `$response_data, $result, $settings, $user_id`. Returns array. |
| `franer_my_submission_payload` | filter | Decoded payload returned by `my-submission`. Params: `$payload, $submission, $settings, $user_id`. Returns array. |

### Export

| Hook | Type | Fires / Returns |
|------|------|-----------------|
| `franer_before_export` | action | Only after capability + nonce checks. Params: `$site_id, $site_post, $settings`. |
| `franer_export_rows` | filter | Prepared export rows (decoded payloads + user meta). Params: `$export, $site_id`. Returns array. |
| `franer_export_payload` | filter | The full export structure before download. Params: `$export, $site_id, $site_post, $settings`. Returns array. |
| `franer_after_export_payload_generated` | action | After the payload is built/filtered, before streaming. Params: `$export, $site_id, $site_post, $settings`. |
| `franer_export_filename` | filter | Download filename (sanitized; forced to end in `.json`). Params: `$filename, $slug, $export`. Returns string. |
| `franer_export_json_flags` | filter | `wp_json_encode` flags. Params: `$json_flags, $export, $slug`. Returns int. |
| `franer_before_export_download` | action | Before headers are sent (logging/audit; do not echo). Params: `$export, $slug, $filename`. |

### Examples

```php
// Add a field to every submitted payload (still size-validated before storage).
add_filter(
    'franer_submission_payload',
    function ( $data, $body, $site, $settings, $user_id, $request ) {
        $data['_institution'] = array( 'site_slug' => $settings['slug'], 'user_id' => (int) $user_id );
        return $data;
    },
    10,
    6
);

// Log a successful submission.
add_action(
    'franer_after_save_submission',
    function ( $submission_id, $status, $site_id, $user_id ) {
        error_log( sprintf( 'Franer submission %d (%s) site %d user %d', $submission_id, $status, $site_id, $user_id ) );
    },
    10,
    4
);

// Anonymize export rows.
add_filter(
    'franer_export_rows',
    function ( $rows, $site_id ) {
        foreach ( $rows as &$row ) {
            unset( $row['user_email'], $row['user_login'] );
        }
        return $rows;
    },
    10,
    2
);
```

## Testing

```bash
make test-php    # PHPUnit tests (wp-env tests environment)
make test-js     # JavaScript unit tests (Jest)
make test-e2e    # End-to-end tests (Playwright against port 8889)
```

Other useful targets:

```bash
make lint               # PHPCS (WordPress Coding Standards)
make fix                # PHPCBF auto-fix
make check-plugin       # WordPress Plugin Check
make check-untranslated # Fail if any Spanish string is untranslated
make check              # fix + lint + plugin-check + tests + untranslated + mo
```

## License

Franer is free software, released under the **GPL-3.0+** license. See
[https://www.gnu.org/licenses/gpl-3.0.html](https://www.gnu.org/licenses/gpl-3.0.html).

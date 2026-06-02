=== Franer ===
Contributors: ateeducacion
Tags: forms, ai, iframe, sandbox, submissions
Requires at least: 6.1
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A secure environment to publish AI-generated forms inside a sandboxed iframe and collect JSON submissions safely server-side.

== Description ==

Franer is a **secure environment to publish AI-generated forms**. The name is a backronym for
**F**ramework for **R**unning **AI**-ge**N**erated **E**mbedded fo**R**ms.

Franer lets an administrator paste self-contained, AI-generated HTML activities, renders them
inside a sandboxed iframe, and collects users' JSON submissions safely on the server. There is no
dependency on Formidable Forms or any third-party form engine.

AI assistants are great at producing rich, interactive single-file HTML activities (quizzes,
self-assessments, calculators, surveys). The hard part is publishing them safely and collecting the
data they generate. Franer solves both problems: the AI-generated HTML is treated as untrusted and
never runs in the context of your site, while each user's structured JSON answer is collected
through a nonced, authenticated REST call made by the parent page.

= How it works =

1. An admin pastes an AI-generated, self-contained HTML activity into a Franer site.
2. The activity is rendered inside an `<iframe srcdoc>` with `sandbox="allow-scripts allow-forms"`
   and no same-origin access.
3. When the user submits, the activity calls `window.FranerSubmit()`, which posts a `franer_submit`
   message to the parent page.
4. The parent shell performs an authenticated REST `POST` with the `X-WP-Nonce` header.
5. The server validates permissions, role, payload size and schema version, then stores the
   submission as JSON and reports the result back to the iframe.

= Key features =

* **Sandboxed rendering** — AI HTML runs inside a locked-down iframe with no same-origin access.
* **Safe submission pipeline** — the parent page, not the iframe, performs the nonced REST call.
* **Role-based access** — only logged-in users with an allowed role may view and submit.
* **Privacy by design** — only SHA-256 hashes of IP and user agent are stored, never raw values.
* **JSON export** — administrators can export all submissions for an activity as a JSON file.
* **Public URL and shortcode** — publish at `/franer/{slug}/` or embed with `[franer slug="..."]`.
* **Built-in AI prompt** — the Help page provides a ready-to-use prompt for generating activities.
* **WordPress Coding Standards compliant** and covered by PHPUnit, Jest and Playwright tests.

== Installation ==

1. Download the plugin from the WordPress Plugin Directory or the GitHub Releases page.
2. Upload the plugin to your WordPress site via **Plugins > Add New > Upload Plugin**.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. If public URLs return 404, visit **Settings > Permalinks** and save to flush rewrite rules.
5. Create your first activity under **Franer > Add New**.

== Frequently Asked Questions ==

= Is the AI-generated HTML safe to publish? =

Yes. The HTML is stored exactly as entered and is only ever rendered inside an `<iframe srcdoc>`
with `sandbox="allow-scripts allow-forms"` and no `allow-same-origin`. It cannot read cookies,
access the parent page, or make network requests.

= Who can submit responses? =

Only logged-in users whose role is in the activity's allowed roles list. Administrators are always
allowed. Logged-out visitors are redirected to the login page.

= Where are submissions stored? =

In a dedicated table (`{prefix}franer_submissions`). The payload is stored as JSON. Only SHA-256
hashes of the IP address and user agent are kept, never the raw values.

= How do I generate a compatible activity with AI? =

Open **Franer > Help**, copy the provided prompt into your AI assistant, replace the activity slug
placeholder and describe your topic. The assistant returns a single self-contained HTML document
that implements `window.FranerCollect()` and `window.FranerSubmit()`.

= Does it require Formidable Forms or any other plugin? =

No. Franer is fully self-contained.

== Changelog ==

= 0.0.0 =
* Initial release.
* Custom post type for Franer sites with sandboxed iframe rendering.
* postMessage + nonced REST submission pipeline.
* Role-based access control and per-site submission settings.
* Submissions admin screen with JSON detail modal and JSON export.
* Built-in AI activity-generation prompt on the Help page.
* Public `/franer/{slug}/` URL and `[franer]` shortcode.

== Screenshots ==

1. The Franer site editor: HTML source, site settings, public URL and shortcode metaboxes.
2. A published activity rendered inside the sandboxed iframe.
3. The Submissions admin screen with the JSON payload detail modal.
4. The Help page with the JavaScript contract and the copyable AI prompt.

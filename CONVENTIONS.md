# Development Conventions for the Franer Plugin

Franer is a secure environment to publish AI-generated forms (Framework for Running AI-geNerated
Embedded foRms). These conventions are enforced by PHPCS and CI.

## Coding Style Guidelines

- Follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
  for PHP, HTML, CSS and JavaScript.
- Use **spaces** for indentation, with **four spaces** per level (never tabs).
- Use **Yoda conditions** in comparisons (e.g. `'1.0' !== $schema_version`).
- Use `snake_case` for functions, methods and variables; `CamelCase` with the `Franer_` prefix for
  classes (e.g. `Franer_Site_Repository`).
- Name files with lowercase letters and hyphens: one class per file as `class-franer-component.php`
  (e.g. `class-franer-admin.php`, `class-franer-rest-controller.php`).
- Include a file header in every PHP file with a brief description and `@package Franer`.
- Guard direct access at the top of every PHP source file (not test files):
  `if ( ! defined( 'WPINC' ) ) { die; }`.

## Code Comments and Documentation

- Write all comments and docblocks in clear, concise **English**.
- Use **PHPDoc** blocks for every file, class, method and function.
- Align `@param` blocks so each `$variable` starts one space after the longest type name.
- Use inline comments sparingly, only to explain non-obvious logic (and `phpcs:ignore` annotations
  that explain why a rule is intentionally bypassed, e.g. raw HTML storage).

## Directory Structure

- Main plugin file: `franer.php` (kept small; only bootstrap, constants and hooks).
- `includes/` — shared classes: post type, repositories, sanitizer, permissions, REST controller,
  activator/deactivator, loader, i18n.
- `admin/` — admin classes, assets and partials.
- `public/` — public-facing classes, the parent shell JS, assets and partials.
- `languages/` — translation files (`franer.pot`, `franer-es_ES.po`, `franer-es_ES.mo`).
- `tests/` — PHPUnit, Jest and Playwright tests.

## Constants and Configuration

- Define plugin constants in `franer.php`: `FRANER_VERSION`, `FRANER_PLUGIN_FILE`,
  `FRANER_PLUGIN_DIR`, `FRANER_PLUGIN_URL`.
- Use the WordPress options and post-meta APIs with the documented meta keys
  (`_franer_slug`, `_franer_html`, `_franer_is_visible`, ...).

## Security Best Practices

- Sanitize on input, escape on output. Use `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`,
  `esc_textarea` for output; sanitizers (and the `Franer_Sanitizer` static methods) on input.
- Call `wp_unslash()` before sanitizing `$_POST`, `$_GET` and `$_SERVER`.
- Use WordPress **nonces** for admin forms (`wp_nonce_field` / `wp_verify_nonce`) and
  `check_admin_referer()` for admin-post actions. REST endpoints use permission callbacks plus the
  `X-WP-Nonce` header.
- Use `$wpdb->prepare()` for **all** SQL.
- The activity HTML is stored **raw** by design and rendered only inside a sandboxed iframe; store
  only **SHA-256 hashes** of IP and user agent.

## Localization

- Text domain is the plugin slug, **`franer`**, declared in `franer.php`.
- Wrap every user-facing string in a translation function: `__()`, `_e()`, `esc_html__()`,
  `esc_html_e()`, `esc_attr__()`, `esc_attr_e()`, `_n()`, `_x()`.
- **Every** i18n call with a placeholder (`%s`, `%d`, `%1$s`, ...) must be immediately preceded by a
  `translators:` comment, or PHPCS fails.
- Strings used in JavaScript travel through `wp_localize_script()`; do not hard-code them in JS.
- Keep `languages/franer-es_ES.po` fully translated; `make check-untranslated` must report nothing.

## Test-Driven Development (TDD)

- Use a TDD approach: write or update tests alongside the implementation.
- Unit tests use **PHPUnit** for PHP and **Jest** for JavaScript; **Playwright** for E2E.
- Tests are **required** for new behavior. Run `make test`, `make test-js` and `make test-e2e`.
- Integrate testing into the workflow: `make check` runs fix, lint, plugin-check, tests,
  untranslated and mo before merging.

## Version Control

- Use Git with feature branches.
- Write clear, concise commit messages in English, in the imperative mood.
- Open pull requests for all changes and ensure review and a green CI before merging.
- When a change adds or modifies user-facing strings, commit the updated `.pot`, `.po` and `.mo`
  in the same change set.

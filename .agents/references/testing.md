# Franer test fixtures

- **Test factories and base class (reuse these — do not hand-roll fixtures).** Tests live in
  `tests/*Test.php` and extend either `WP_UnitTestCase` or `Franer_Test_Base`
  (`tests/includes/class-franer-test-base.php`). `Franer_Test_Base` extends the WordPress factory
  with Franer-specific factories and activates the submissions table +
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

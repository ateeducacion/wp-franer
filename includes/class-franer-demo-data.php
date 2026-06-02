<?php
/**
 * Demo data generator for the Franer plugin.
 *
 * Creates a sample franer_site activity ("Memoria-Informe MCODE40") so that a
 * fresh installation (or a WordPress Playground blueprint) immediately has a
 * working, ready-to-test activity.
 *
 * @package    Franer
 * @subpackage Franer/includes
 * @author     Área de Tecnología Educativa
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Franer_Demo_Data.
 *
 * Seeds idempotent demo content for the plugin.
 *
 * @package    Franer
 * @subpackage Franer/includes
 */
class Franer_Demo_Data {

	/**
	 * Option flag used to guarantee the seeding runs only once.
	 *
	 * @var string
	 */
	const SEEDED_OPTION = 'franer_demo_seeded';

	/**
	 * Slug used for the demo activity.
	 *
	 * @var string
	 */
	const DEMO_SLUG = 'mcode40';

	/**
	 * Conditionally seed demo data.
	 *
	 * Idempotent: it does nothing if the seeding flag is already set. This is
	 * the method hooked on 'init' (low priority).
	 *
	 * @return void
	 */
	public static function maybe_seed() {
		if ( get_option( self::SEEDED_OPTION ) ) {
			return;
		}

		self::seed();
	}

	/**
	 * Seed the demo activity.
	 *
	 * Safe to call directly from a blueprint runPHP step. It guards against
	 * duplicate creation by checking for an existing post with the demo slug
	 * and always sets the seeding flag at the end.
	 *
	 * @return int The created (or existing) post ID, or 0 on failure.
	 */
	public static function seed() {
		// Bail if the activity already exists (defensive: option may be unset).
		if ( class_exists( 'Franer_Site_Repository' ) ) {
			$repository = new Franer_Site_Repository();
			$existing   = $repository->get_by_slug( self::DEMO_SLUG );
			if ( $existing instanceof WP_Post ) {
				update_option( self::SEEDED_OPTION, '1' );
				return (int) $existing->ID;
			}
		}

		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Memoria-Informe MCODE40',
				'post_type'   => 'franer_site',
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, '_franer_slug', self::DEMO_SLUG );
		update_post_meta( $post_id, '_franer_accepts_submissions', '1' );
		update_post_meta( $post_id, '_franer_enabled', '1' );
		update_post_meta(
			$post_id,
			'_franer_allowed_roles',
			array( 'subscriber', 'author', 'editor', 'administrator' )
		);
		update_post_meta( $post_id, '_franer_allow_multiple_submissions', '' );
		update_post_meta( $post_id, '_franer_allow_overwrite', '1' );
		update_post_meta( $post_id, '_franer_max_payload_size', 256 );
		update_post_meta( $post_id, '_franer_html', self::get_demo_html() );

		update_option( self::SEEDED_OPTION, '1' );

		return (int) $post_id;
	}

	/**
	 * Build the demo activity HTML.
	 *
	 * Loads examples/mcode40.html if present; otherwise returns a minimal
	 * self-contained activity that implements the FranerCollect / FranerSubmit
	 * JavaScript contract.
	 *
	 * @return string The raw HTML document for the activity.
	 */
	private static function get_demo_html() {
		$example_file = self::plugin_dir() . 'examples/mcode40.html';

		if ( is_readable( $example_file ) ) {
			$contents = file_get_contents( $example_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin file, not a remote URL.
			if ( false !== $contents && '' !== trim( $contents ) ) {
				return $contents;
			}
		}

		return self::get_placeholder_html();
	}

	/**
	 * Resolve the plugin base directory with a trailing slash.
	 *
	 * Falls back gracefully when the FRANER_PLUGIN_DIR constant is unavailable
	 * (e.g. when invoked very early from a blueprint).
	 *
	 * @return string Absolute path with trailing slash.
	 */
	private static function plugin_dir() {
		if ( defined( 'FRANER_PLUGIN_DIR' ) ) {
			return trailingslashit( FRANER_PLUGIN_DIR );
		}

		// includes/ -> plugin root.
		return trailingslashit( dirname( __DIR__ ) );
	}

	/**
	 * Minimal placeholder activity implementing the Franer JS contract.
	 *
	 * Used only when no bundled example file is available. It runs inside a
	 * sandboxed iframe and communicates exclusively via postMessage.
	 *
	 * @return string The placeholder HTML document.
	 */
	private static function get_placeholder_html() {
		return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Memoria-Informe MCODE40</title>
<style>
  body { font-family: system-ui, sans-serif; margin: 1.5rem; color: #1d2327; }
  label { display: block; margin: 0.75rem 0 0.25rem; font-weight: 600; }
  input, textarea { width: 100%; box-sizing: border-box; padding: 0.5rem; }
  .error { color: #d63638; margin-top: 0.5rem; min-height: 1.2em; }
  button { margin-top: 1rem; padding: 0.6rem 1.2rem; cursor: pointer; }
  .status { margin-top: 0.75rem; }
</style>
</head>
<body>
<main>
  <h1>Memoria-Informe MCODE40</h1>
  <form id="franer-activity" novalidate>
    <label for="idNombre">Name</label>
    <input type="text" id="idNombre" name="idNombre" required aria-required="true">

    <label for="idCep">Centre code (CEP)</label>
    <input type="text" id="idCep" name="idCep" required aria-required="true">

    <label for="idNotes">Notes</label>
    <textarea id="idNotes" name="idNotes" rows="4"></textarea>

    <p class="error" id="franer-error" role="alert" aria-live="assertive"></p>
    <button type="button" id="franer-submit-btn">Submit</button>
    <p class="status" id="franer-status" role="status" aria-live="polite"></p>
  </form>
</main>
<script>
  window.FranerCollect = function () {
    return {
      schema_version: "1.0",
      activity_id: "mcode40",
      activity_title: "Memoria-Informe MCODE40",
      submitted_at: new Date().toISOString(),
      data: {
        answers: {
          idNombre: document.getElementById("idNombre").value,
          idCep: document.getElementById("idCep").value,
          idNotes: document.getElementById("idNotes").value
        },
        calculated_values: {},
        report: { text: "", html: "" }
      }
    };
  };

  window.FranerSubmit = function () {
    var errorEl = document.getElementById("franer-error");
    errorEl.textContent = "";
    var nombre = document.getElementById("idNombre").value.trim();
    var cep = document.getElementById("idCep").value.trim();
    if (!nombre || !cep) {
      errorEl.textContent = "Please fill in the required fields (Name and CEP).";
      return;
    }
    window.parent.postMessage(
      { type: "franer_submit", payload: window.FranerCollect() },
      "*"
    );
  };

  document.getElementById("franer-submit-btn")
    .addEventListener("click", window.FranerSubmit);

  window.addEventListener("message", function (event) {
    if (!event.data || typeof event.data !== "object") {
      return;
    }
    if (event.data.type !== "franer_submit_result") {
      return;
    }
    var statusEl = document.getElementById("franer-status");
    if (event.data.ok) {
      statusEl.textContent = "Saved (" +
        (event.data.result && event.data.result.status) + ").";
    } else {
      statusEl.textContent = "Error: " +
        (event.data.result && event.data.result.message);
    }
  });
</script>
</body>
</html>
HTML;
	}
}

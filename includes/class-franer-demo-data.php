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
	 * Slug used for the second (Canarian preferences) demo activity.
	 *
	 * @var string
	 */
	const CANARIO_SLUG = 'gustos-canarios';

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

		self::seed_all();
	}

	/**
	 * Seed every bundled demo activity and flag seeding as done.
	 *
	 * Safe to call directly from a blueprint runPHP step. Each activity is
	 * seeded idempotently (by slug), so re-running never duplicates content.
	 *
	 * @return int The MCODE40 demo post ID (or 0 on failure).
	 */
	public static function seed_all() {
		$mcode_id = self::seed();
		self::seed_canarian();

		update_option( self::SEEDED_OPTION, '1' );

		return (int) $mcode_id;
	}

	/**
	 * Seed the MCODE40 demo activity (with its submissions-overview template).
	 *
	 * Idempotent: returns the existing post if the demo slug already exists.
	 *
	 * @return int The created (or existing) post ID, or 0 on failure.
	 */
	public static function seed() {
		$post_id = self::ensure_activity(
			self::DEMO_SLUG,
			'Memoria-Informe MCODE40',
			self::get_demo_html(),
			self::load_example( 'mcode40-view.html' ),
			'', // allow_multiple stays off for MCODE40 (one submission per user).
			self::load_example( 'mcode40.prompt.txt' ),
			self::load_example( 'mcode40-view.prompt.txt' )
		);

		return (int) $post_id;
	}

	/**
	 * Seed the "Gustos canarios" demo activity (with its overview template).
	 *
	 * A light-hearted Canarian-preferences poll. Multiple submissions per user
	 * are allowed so the overview template has varied data to aggregate.
	 *
	 * @return int The created (or existing) post ID, or 0 on failure.
	 */
	public static function seed_canarian() {
		$post_id = self::ensure_activity(
			self::CANARIO_SLUG,
			'Gustos canarios',
			self::load_example( 'gustos-canarios.html' ),
			self::load_example( 'gustos-canarios-view.html' ),
			'1', // allow_multiple: accumulate many responses.
			self::load_example( 'gustos-canarios.prompt.txt' ),
			self::load_example( 'gustos-canarios-view.prompt.txt' )
		);

		return (int) $post_id;
	}

	/**
	 * Create (idempotently) a demo franer_site with activity and view HTML.
	 *
	 * @param string $slug                   The activity slug.
	 * @param string $title                  The activity title.
	 * @param string $activity_html          Raw activity HTML for post_content.
	 * @param string $view_html              Raw submissions-overview template HTML.
	 * @param string $allow_multiple         '1' to allow multiple submissions, '' otherwise.
	 * @param string $generation_prompt      Prompt used to generate the activity HTML.
	 * @param string $view_generation_prompt Prompt used to generate the view HTML.
	 * @return int The created (or existing) post ID, or 0 on failure.
	 */
	private static function ensure_activity( $slug, $title, $activity_html, $view_html, $allow_multiple, $generation_prompt = '', $view_generation_prompt = '' ) {
		// Bail if the activity already exists (defensive: option may be unset).
		if ( class_exists( 'Franer_Site_Repository' ) ) {
			$repository = new Franer_Site_Repository();
			$existing   = $repository->get_by_slug( $slug );
			if ( $existing instanceof WP_Post ) {
				return (int) $existing->ID;
			}
		}

		// Attribute the demo to an administrator (seeding may run without a
		// current user, which would otherwise leave post_author as 0).
		$admins      = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'fields'  => 'ID',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		$demo_author = ! empty( $admins ) ? (int) $admins[0] : 0;

		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => 'franer_site',
				'post_status' => 'publish',
				'post_author' => $demo_author,
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, '_franer_slug', $slug );
		update_post_meta( $post_id, '_franer_accepts_submissions', '1' );
		update_post_meta( $post_id, '_franer_enabled', '1' );
		update_post_meta(
			$post_id,
			'_franer_allowed_roles',
			array( 'subscriber', 'author', 'editor', 'administrator' )
		);
		update_post_meta( $post_id, '_franer_allow_multiple_submissions', $allow_multiple );
		update_post_meta( $post_id, '_franer_allow_overwrite', '1' );
		update_post_meta( $post_id, '_franer_max_payload_size', 256 );

		// Optional submissions-overview template (admin-only, comment-stripped at
		// render time).
		if ( '' !== trim( (string) $view_html ) ) {
			update_post_meta( $post_id, '_franer_view_html', $view_html );
		}

		// Optional, admin-only prompts that generated the activity and the view.
		if ( '' !== trim( (string) $generation_prompt ) ) {
			update_post_meta( $post_id, '_franer_generation_prompt', $generation_prompt );
		}
		if ( '' !== trim( (string) $view_generation_prompt ) ) {
			update_post_meta( $post_id, '_franer_view_generation_prompt', $view_generation_prompt );
		}

		// The activity HTML lives in post_content (revisioned, shown in the diff).
		Franer_Site_Repository::set_raw_html( $post_id, $activity_html );

		return (int) $post_id;
	}

	/**
	 * Seed a handful of sample submissions so the overview templates have data.
	 *
	 * Intended for demo/Playground environments (call it after seed_all()); it is
	 * idempotent per activity (skips an activity that already has submissions) and
	 * is intentionally NOT run automatically on every request.
	 *
	 * @return void
	 */
	public static function seed_sample_submissions() {
		if ( ! class_exists( 'Franer_Site_Repository' ) || ! class_exists( 'Franer_Submissions_Repository' ) ) {
			return;
		}

		$repository  = new Franer_Site_Repository();
		$submissions = new Franer_Submissions_Repository();

		$user_ids = get_users(
			array(
				'number'  => 8,
				'fields'  => 'ID',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		$user_ids = array_map( 'intval', (array) $user_ids );
		if ( empty( $user_ids ) ) {
			return;
		}

		self::seed_activity_submissions(
			$repository,
			$submissions,
			$user_ids,
			self::CANARIO_SLUG,
			self::sample_canarian_payloads()
		);

		self::seed_activity_submissions(
			$repository,
			$submissions,
			$user_ids,
			self::DEMO_SLUG,
			self::sample_mcode_payloads()
		);
	}

	/**
	 * Insert a list of sample payloads for one activity, cycling through users.
	 *
	 * @param Franer_Site_Repository        $repository  Site repository.
	 * @param Franer_Submissions_Repository $submissions Submissions repository.
	 * @param array                         $user_ids    Candidate user IDs.
	 * @param string                        $slug        The activity slug.
	 * @param array                         $payloads    List of payload arrays.
	 * @return void
	 */
	private static function seed_activity_submissions( $repository, $submissions, $user_ids, $slug, $payloads ) {
		$site = $repository->get_by_slug( $slug );
		if ( ! $site instanceof WP_Post ) {
			return;
		}

		$site_id = (int) $site->ID;
		if ( $submissions->count_site_submissions( $site_id ) > 0 ) {
			return; // Already has data; stay idempotent.
		}

		$count = count( $user_ids );
		foreach ( array_values( $payloads ) as $index => $payload ) {
			$user_id = $user_ids[ $index % $count ];
			$submissions->save_submission( $site_id, $user_id, (string) wp_json_encode( $payload ), true, false );
		}
	}

	/**
	 * Build a varied set of "Gustos canarios" sample payloads.
	 *
	 * @return array List of payload arrays.
	 */
	private static function sample_canarian_payloads() {
		$rows = array(
			array( 'clipper_fresa', 'ambrosias', 'rojo', 'barraquito', 'si', 5, '¡Viva la tierra!' ),
			array( 'clipper_pina', 'gofio', 'verde', 'cafe', 'si', 4, '' ),
			array( 'clipper_fresa', 'tirma', 'rojo', 'barraquito', 'no', 5, 'El mojo picón manda.' ),
			array( 'otro', 'ambrosias', 'verde', 'cafe', 'si', 3, '' ),
			array( 'clipper_fresa', 'ambrosias', 'rojo', 'barraquito', 'si', 5, 'Barraquito siempre.' ),
			array( 'clipper_pina', 'tirma', 'verde', 'barraquito', 'si', 4, '' ),
			array( 'clipper_fresa', 'gofio', 'rojo', 'cafe', 'si', 5, 'Gofio para todo.' ),
			array( 'clipper_pina', 'ambrosias', 'rojo', 'barraquito', 'no', 4, '' ),
			array( 'clipper_fresa', 'ambrosias', 'verde', 'barraquito', 'si', 5, '' ),
			array( 'otro', 'tirma', 'rojo', 'cafe', 'si', 3, 'Me quedo con el chocolate.' ),
		);

		$payloads = array();
		foreach ( $rows as $row ) {
			$payloads[] = array(
				'schema_version' => '1.0',
				'activity_id'    => self::CANARIO_SLUG,
				'activity_title' => 'Gustos canarios',
				'data'           => array(
					'answers'    => array(
						'refresco' => $row[0],
						'merienda' => $row[1],
						'mojo'     => $row[2],
						'bebida'   => $row[3],
						'papas'    => $row[4],
					),
					'rating'     => $row[5],
					'comentario' => $row[6],
				),
			);
		}

		return $payloads;
	}

	/**
	 * Build a realistic set of MCODE40 sample payloads.
	 *
	 * Mirrors the real activity's FranerCollect() schema (quantitative fields,
	 * SÍ/NO impact items with justifications, the nine formación Likert levels and
	 * the calculated coverage values) so the statistics overview has meaningful
	 * data to aggregate.
	 *
	 * Each mentor row: name, CEP, [asignados, visitados, no_visitados,
	 * mentorizados], [imp1..imp4 (SÍ/NO)], [9 formación levels 1-4].
	 *
	 * @return array List of payload arrays.
	 */
	private static function sample_mcode_payloads() {
		$mentors = array(
			array( 'Ana Martínez', 'CEP La Laguna', array( 12, 10, 2, 9 ), array( 'SÍ', 'SÍ', 'NO', 'SÍ' ), array( 4, 3, 3, 4, 3, 4, 3, 4, 2 ) ),
			array( 'Carlos López', 'CEP Las Palmas', array( 15, 11, 4, 8 ), array( 'SÍ', 'NO', 'SÍ', 'SÍ' ), array( 3, 3, 2, 3, 4, 3, 4, 3, 3 ) ),
			array( 'María García', 'CEP Telde', array( 10, 9, 1, 9 ), array( 'SÍ', 'SÍ', 'SÍ', 'SÍ' ), array( 4, 4, 3, 4, 4, 4, 4, 4, 3 ) ),
			array( 'Lucía Hernández', 'CEP Norte de Tenerife', array( 8, 5, 3, 4 ), array( 'NO', 'SÍ', 'NO', 'NO' ), array( 2, 2, 3, 2, 3, 2, 3, 3, 1 ) ),
			array( 'Javier Santana', 'CEP Gran Canaria Sur', array( 14, 13, 1, 12 ), array( 'SÍ', 'SÍ', 'SÍ', 'SÍ' ), array( 4, 3, 4, 3, 4, 3, 4, 4, 4 ) ),
			array( 'Elena Pérez', 'CEP Lanzarote', array( 9, 7, 2, 6 ), array( 'SÍ', 'NO', 'SÍ', 'SÍ' ), array( 3, 2, 3, 3, 3, 4, 3, 3, 2 ) ),
			array( 'Pedro Cabrera', 'CEP Fuerteventura', array( 11, 8, 3, 7 ), array( 'SÍ', 'SÍ', 'NO', 'SÍ' ), array( 3, 3, 3, 4, 3, 3, 4, 3, 3 ) ),
		);

		$justifications = array(
			'Se observa una integración progresiva en las programaciones de aula.',
			'Mejora en varias áreas del MRCDD, especialmente en creación de contenidos.',
			'Avances claros en programación y robótica en los centros mentorizados.',
			'El alumnado muestra mayor motivación con el pensamiento computacional.',
		);

		$payloads = array();
		foreach ( $mentors as $mentor ) {
			list( $nombre, $cep, $nums, $impacto_vals, $formacion_levels ) = $mentor;
			list( $asignados, $visitados, $no_visitados, $mentorizados ) = $nums;

			$impacto = array();
			foreach ( array( 'imp1', 'imp2', 'imp3', 'imp4' ) as $idx => $key ) {
				$impacto[ $key ] = array(
					'value'         => $impacto_vals[ $idx ],
					'justification' => ( 'SÍ' === $impacto_vals[ $idx ] )
						? $justifications[ $idx ]
						: 'No se ha constatado un impacto suficiente este curso.',
				);
			}

			$formacion = array();
			foreach ( $formacion_levels as $idx => $level ) {
				$formacion[ $idx ] = $level;
			}

			$payloads[] = array(
				'schema_version' => '1.0',
				'activity_id'    => self::DEMO_SLUG,
				'activity_title' => 'Memoria-Informe individual MCODE40',
				'data'           => array(
					'answers'           => array(
						'idNombre'         => $nombre,
						'idCep'            => $cep,
						'numAsignados'     => (string) $asignados,
						'numVisitados'     => (string) $visitados,
						'numNoVisitados'   => (string) $no_visitados,
						'numMentorizados'  => (string) $mentorizados,
						'cualLogros'       => 'Buena acogida del programa y alta implicación del profesorado.',
						'cualDificultades' => 'Disponibilidad horaria en algunos centros rezagados.',
						'cualMejoras'      => 'Reforzar la coordinación en la recta final del curso.',
						'cualBuenas'       => 'Sesión de docencia compartida con excelente resultado.',
						'impacto'          => $impacto,
						'formacion'        => $formacion,
						'otroOrg'          => 'Coordinación general adecuada.',
						'otroEquip'        => 'Equipamiento suficiente, con margen de mejora en robótica.',
						'otroColab'        => 'Participación en una jornada de buenas prácticas.',
						'otroCom'          => '',
					),
					'calculated_values' => array(
						'porc_mentorizados_sobre_visitados' => $visitados > 0 ? (int) round( ( $mentorizados / $visitados ) * 100 ) : 0,
						'porc_cobertura_mentorizacion'      => $asignados > 0 ? (int) round( ( $mentorizados / $asignados ) * 100 ) : 0,
					),
					'report'            => array(
						'text' => '',
						'html' => '',
					),
				),
			);
		}

		return $payloads;
	}

	/**
	 * Load a bundled example HTML file from the examples/ directory.
	 *
	 * @param string $filename The file name within examples/.
	 * @return string The file contents, or '' when unavailable.
	 */
	private static function load_example( $filename ) {
		$file = self::plugin_dir() . 'examples/' . $filename;

		if ( is_readable( $file ) ) {
			$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin file, not a remote URL.
			if ( false !== $contents ) {
				return $contents;
			}
		}

		return '';
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

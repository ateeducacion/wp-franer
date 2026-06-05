<?php
/**
 * Schema-less summary and label inference for Franer submissions.
 *
 * Franer stores every submission as free-form JSON (no fixed schema), so the
 * admin screens cannot rely on known answer keys. This helper inspects a set of
 * decoded submissions and infers, per top-level field, a human label and a type
 * (rating / number / category / text / other) plus an aggregate distribution.
 * That powers the readable answer chips, the auto-generated submissions summary
 * and the per-submission detail view, for ANY activity. It is a pure,
 * output-free helper so it can be unit-tested in isolation.
 *
 * @package Franer
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Infers display labels, field types and aggregate distributions for submissions.
 */
class Franer_Submission_Schema {

	/**
	 * Maximum distinct scalar values for a field to be treated as a category.
	 *
	 * @var int
	 */
	const MAX_CATEGORY_VALUES = 12;

	/**
	 * Maximum length of a value for a field to stay categorical (longer ⇒ text).
	 *
	 * @var int
	 */
	const MAX_CATEGORY_LENGTH = 40;

	/**
	 * Values no longer than this are short enough to be a category on their own,
	 * even when every value is distinct (e.g. single-word answers like "rojo").
	 *
	 * @var int
	 */
	const MAX_SHORT_TOKEN_LENGTH = 16;

	/**
	 * Humanize a raw payload key into a display label.
	 *
	 * Mirrors the prototype's franerLabel(): turns "rating", "main_dish" or
	 * "user-comment" into "Rating", "Main dish", "User comment". Already-spaced or
	 * accented labels are preserved.
	 *
	 * @param string $key The raw field key.
	 * @return string The display label.
	 */
	public static function humanize_label( $key ) {
		$key = (string) $key;
		// Dotted keys come from flattened nested payloads; label the last segment.
		$dot = strrpos( $key, '.' );
		if ( false !== $dot ) {
			$key = substr( $key, $dot + 1 );
		}
		$key = str_replace( array( '_', '-' ), ' ', $key );
		$key = trim( preg_replace( '/\s+/', ' ', $key ) );

		if ( '' === $key ) {
			return '';
		}

		// Uppercase the first letter, multibyte-safe, without touching the rest.
		$first = function_exists( 'mb_substr' ) ? mb_substr( $key, 0, 1 ) : substr( $key, 0, 1 );
		$rest  = function_exists( 'mb_substr' ) ? mb_substr( $key, 1 ) : substr( $key, 1 );
		$first = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $first ) : strtoupper( $first );

		return $first . $rest;
	}

	/**
	 * Render a single field value as a short readable string for chips/details.
	 *
	 * @param array $field The inferred field descriptor (see infer_fields()).
	 * @param mixed $value The raw value for one submission.
	 * @return string The readable value ('—' when empty, '…' for nested data).
	 */
	public static function preview_value( $field, $value ) {
		if ( is_array( $value ) ) {
			return '…';
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'franer' ) : __( 'No', 'franer' );
		}

		if ( null === $value || '' === $value ) {
			return '—';
		}

		return (string) $value;
	}

	/**
	 * Choose the fields shown as preview chips in the submissions table.
	 *
	 * Prefers categorical fields (the most scannable) and never includes the
	 * rating or the long-text field, which get their own columns.
	 *
	 * @param array $fields Inferred fields keyed by field key.
	 * @param int   $max    Maximum number of preview fields. Default 3.
	 * @return array List of field descriptors to show as chips.
	 */
	public static function select_preview_fields( array $fields, $max = 3 ) {
		$preview = array();
		foreach ( $fields as $field ) {
			if ( in_array( $field['type'], array( 'category', 'number' ), true ) ) {
				$preview[] = $field;
			}
		}

		// Fall back to any non-rating/non-text field if no category exists.
		if ( empty( $preview ) ) {
			foreach ( $fields as $field ) {
				if ( ! in_array( $field['type'], array( 'rating', 'text' ), true ) ) {
					$preview[] = $field;
				}
			}
		}

		return array_slice( $preview, 0, max( 0, (int) $max ) );
	}

	/**
	 * Find the first field of a given type, or null.
	 *
	 * @param array  $fields Inferred fields keyed by field key.
	 * @param string $type   The field type to look for.
	 * @return array|null The field descriptor or null.
	 */
	public static function first_field_of_type( array $fields, $type ) {
		foreach ( $fields as $field ) {
			if ( $field['type'] === $type ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Flatten a decoded payload to dotted keys, mirroring the CSV exporter.
	 *
	 * Associative (string-keyed) maps are recursed into so an activity that nests
	 * its answers (e.g. {"answers":{"mojo":"rojo"},"rating":5}) still exposes each
	 * answer as its own field ("answers.mojo", "rating"). Sequential lists are left
	 * as-is (classified as 'other').
	 *
	 * @param array  $payload The decoded payload (or sub-array).
	 * @param string $prefix  The current key prefix (internal recursion).
	 * @return array Flat map of dotted key => scalar/list value.
	 */
	public static function flatten_payload( array $payload, $prefix = '' ) {
		$flat = array();
		foreach ( $payload as $key => $value ) {
			$path = ( '' === $prefix ) ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) && self::is_assoc( $value ) ) {
				$flat = array_merge( $flat, self::flatten_payload( $value, $path ) );
			} else {
				$flat[ $path ] = $value;
			}
		}

		return $flat;
	}

	/**
	 * Whether an array is associative (a map) rather than a sequential list.
	 *
	 * @param array $arr The array.
	 * @return bool
	 */
	private static function is_assoc( array $arr ) {
		if ( array() === $arr ) {
			return false;
		}

		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Infer the label, type and distribution of every (flattened) field.
	 *
	 * @param array $payloads List of decoded submission payloads (associative arrays).
	 * @return array Field descriptors keyed by field key, in first-seen order. Each:
	 *               {
	 *                 key:          string,
	 *                 label:        string,
	 *                 type:         'rating'|'number'|'category'|'text'|'other',
	 *                 count:        int,            // submissions with a value
	 *                 distribution: array,          // value => count (category/number)
	 *                 avg:          float,          // rating/number
	 *                 hist:         array,          // rating: 1..5 => count
	 *               }
	 */
	public static function infer_fields( array $payloads ) {
		// Collect the values seen for each key, preserving first-seen order.
		$values = array();
		foreach ( $payloads as $payload ) {
			if ( ! is_array( $payload ) ) {
				continue;
			}
			foreach ( self::flatten_payload( $payload ) as $key => $value ) {
				if ( ! isset( $values[ $key ] ) ) {
					$values[ $key ] = array();
				}
				$values[ $key ][] = $value;
			}
		}

		$fields = array();
		foreach ( $values as $key => $list ) {
			$fields[ $key ] = self::classify_field( (string) $key, $list );
		}

		return $fields;
	}

	/**
	 * Classify one field from the list of values seen for it.
	 *
	 * @param string $key  The field key.
	 * @param array  $list The values seen across submissions.
	 * @return array The field descriptor.
	 */
	private static function classify_field( $key, array $list ) {
		$field = array(
			'key'          => $key,
			'label'        => self::humanize_label( $key ),
			'type'         => 'other',
			'count'        => 0,
			'distribution' => array(),
			'avg'          => 0.0,
			'hist'         => array(),
		);

		// Keep only present scalar values; nested data leaves the field 'other'.
		$scalars = array();
		foreach ( $list as $value ) {
			if ( is_array( $value ) || null === $value || '' === $value ) {
				continue;
			}
			$scalars[] = $value;
		}

		$field['count'] = count( $scalars );

		if ( empty( $scalars ) ) {
			return $field;
		}

		// All-numeric ⇒ rating (integers within 1..5) or number.
		if ( self::all_numeric( $scalars ) ) {
			$numbers = array_map( 'floatval', $scalars );
			$sum     = array_sum( $numbers );
			$avg     = count( $numbers ) ? $sum / count( $numbers ) : 0.0;

			if ( self::looks_like_rating( $numbers ) ) {
				$field['type'] = 'rating';
				$field['avg']  = $avg;
				$field['hist'] = self::rating_histogram( $numbers );
				return $field;
			}

			$field['type']         = 'number';
			$field['avg']          = $avg;
			$field['distribution'] = self::distribution( $scalars );
			return $field;
		}

		// String/bool values: category when few and short, else free text.
		$normalized = array();
		$max_length = 0;
		foreach ( $scalars as $value ) {
			$text         = is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value;
			$normalized[] = $text;
			$length       = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
			$max_length   = max( $max_length, $length );
		}

		// A field is a useful category only when its values actually cluster
		// (some repeat) or are very short tokens. All-distinct, sentence-length
		// values (free-text comments) have no aggregate value and stay 'text'.
		$distinct      = count( array_unique( $normalized ) );
		$repeats       = $distinct < count( $normalized );
		$short_tokens  = $max_length <= self::MAX_SHORT_TOKEN_LENGTH;
		$within_bounds = $distinct <= self::MAX_CATEGORY_VALUES && $max_length <= self::MAX_CATEGORY_LENGTH;
		if ( $within_bounds && ( $repeats || $short_tokens ) ) {
			$field['type']         = 'category';
			$field['distribution'] = self::distribution( $normalized );
			return $field;
		}

		$field['type'] = 'text';
		return $field;
	}

	/**
	 * Whether every value is numeric (int, float, or numeric string).
	 *
	 * @param array $values The values.
	 * @return bool
	 */
	private static function all_numeric( array $values ) {
		foreach ( $values as $value ) {
			if ( is_bool( $value ) || ! is_numeric( $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the numbers look like a 1..5 integer rating scale.
	 *
	 * @param array $numbers Float values.
	 * @return bool
	 */
	private static function looks_like_rating( array $numbers ) {
		foreach ( $numbers as $number ) {
			if ( floor( $number ) !== $number || $number < 1 || $number > 5 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build a 1..5 => count histogram for a rating field.
	 *
	 * @param array $numbers Float values.
	 * @return array Map of 1..5 to count (always includes every step).
	 */
	private static function rating_histogram( array $numbers ) {
		$hist = array(
			1 => 0,
			2 => 0,
			3 => 0,
			4 => 0,
			5 => 0,
		);
		foreach ( $numbers as $number ) {
			$step = (int) $number;
			if ( isset( $hist[ $step ] ) ) {
				++$hist[ $step ];
			}
		}

		return $hist;
	}

	/**
	 * Count occurrences of each value, sorted by descending frequency.
	 *
	 * @param array $values The values (cast to string).
	 * @return array value => count.
	 */
	private static function distribution( array $values ) {
		$counts = array();
		foreach ( $values as $value ) {
			$text = (string) $value;
			if ( ! isset( $counts[ $text ] ) ) {
				$counts[ $text ] = 0;
			}
			++$counts[ $text ];
		}

		arsort( $counts );

		return $counts;
	}

	/**
	 * Build the aggregate summary for a set of decoded submission rows.
	 *
	 * @param array $rows List of rows shaped as
	 *                    { user: string, created_at: string, payload: array }.
	 * @return array {
	 *     total:        int,
	 *     last_created: string,
	 *     fields:       array,   // infer_fields() result
	 *     distributions: array,  // category/number field descriptors
	 *     rating:       array|null { label, avg, count, hist },
	 *     comments:     array,   // { user, created, text }
	 *     stats:        array,   // { key, big, label } cards for the stats strip
	 * }
	 */
	public static function build_summary( array $rows ) {
		$payloads = array();
		foreach ( $rows as $row ) {
			$payloads[] = isset( $row['payload'] ) && is_array( $row['payload'] ) ? $row['payload'] : array();
		}

		$fields = self::infer_fields( $payloads );
		$total  = count( $rows );

		$last_created = '';
		foreach ( $rows as $row ) {
			$created = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
			if ( $created > $last_created ) {
				$last_created = $created;
			}
		}

		$distributions = array();
		foreach ( $fields as $field ) {
			if ( in_array( $field['type'], array( 'category', 'number' ), true ) && ! empty( $field['distribution'] ) ) {
				$distributions[] = $field;
			}
		}

		$rating_field = self::first_field_of_type( $fields, 'rating' );
		$rating       = null;
		if ( null !== $rating_field ) {
			$rating = array(
				'label' => $rating_field['label'],
				'avg'   => $rating_field['avg'],
				'count' => $rating_field['count'],
				'hist'  => $rating_field['hist'],
			);
		}

		$text_field = self::first_field_of_type( $fields, 'text' );
		$comments   = array();
		if ( null !== $text_field ) {
			$text_key = $text_field['key'];
			foreach ( $rows as $row ) {
				$payload = isset( $row['payload'] ) && is_array( $row['payload'] ) ? self::flatten_payload( $row['payload'] ) : array();
				$value   = isset( $payload[ $text_key ] ) ? $payload[ $text_key ] : '';
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$comments[] = array(
						'user'    => isset( $row['user'] ) ? (string) $row['user'] : '',
						'created' => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
						'text'    => trim( $value ),
					);
				}
			}
		}

		$stats = array(
			array(
				'key'   => 'total',
				'big'   => (string) $total,
				'label' => _n( 'response', 'responses', $total, 'franer' ),
			),
		);
		if ( null !== $rating ) {
			$stats[] = array(
				'key'   => 'rating',
				'big'   => number_format_i18n( $rating['avg'], 1 ),
				'label' => __( 'average rating', 'franer' ),
			);
		}
		if ( null !== $text_field ) {
			$stats[] = array(
				'key'   => 'comments',
				'big'   => (string) count( $comments ),
				'label' => __( 'with a comment', 'franer' ),
			);
		}
		$stats[] = array(
			'key'   => 'last',
			'big'   => '' === $last_created ? '—' : self::short_date( $last_created ),
			'label' => __( 'last response', 'franer' ),
		);

		return array(
			'total'         => $total,
			'last_created'  => $last_created,
			'fields'        => $fields,
			'distributions' => $distributions,
			'rating'        => $rating,
			'comments'      => $comments,
			'stats'         => $stats,
		);
	}

	/**
	 * Shorten a "Y-m-d H:i:s" datetime to "m-d H:i" for the stats strip.
	 *
	 * @param string $datetime The stored datetime.
	 * @return string The shortened value (input unchanged when unparseable).
	 */
	private static function short_date( $datetime ) {
		$datetime = (string) $datetime;
		if ( strlen( $datetime ) >= 16 ) {
			// "2026-06-05 13:13:00" => "06-05 13:13".
			return substr( $datetime, 5, 11 );
		}

		return $datetime;
	}
}

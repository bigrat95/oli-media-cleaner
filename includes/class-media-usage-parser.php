<?php
/**
 * Parse post/term/user/option meta values for attachment usage (ACF-agnostic).
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extract attachment IDs from stored meta values.
 */
class OLIMC_Media_Usage_Parser {

	/**
	 * WordPress core / plugin meta keys whose underscore-prefixed siblings are not ACF field refs.
	 *
	 * @var string[]
	 */
	private static $non_acf_reference_keys = [
		'_thumbnail_id',
		'_wp_page_template',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_image_alt',
		'_edit_lock',
		'_edit_last',
		'_encloseme',
		'_pingme',
		'_elementor_data',
		'_elementor_css',
		'_product_image_gallery',
	];

	/**
	 * Whether a meta row is an ACF field reference (_fieldname => field_xxx).
	 *
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return bool
	 */
	public static function is_acf_field_reference( $meta_key, $meta_value ) {
		if ( ! is_string( $meta_key ) || strlen( $meta_key ) < 2 || $meta_key[0] !== '_' ) {
			return false;
		}
		if ( in_array( $meta_key, self::$non_acf_reference_keys, true ) ) {
			return false;
		}
		if ( ! is_string( $meta_value ) ) {
			return false;
		}
		return strncmp( $meta_value, 'field_', 6 ) === 0;
	}

	/**
	 * Value meta key paired with an ACF reference key (_image => image).
	 *
	 * @param string $reference_key Meta key starting with underscore.
	 * @return string
	 */
	public static function acf_value_key_from_reference( $reference_key ) {
		return substr( $reference_key, 1 );
	}

	/**
	 * Extract attachment IDs from a single meta value.
	 *
	 * @param mixed    $value            Raw meta value.
	 * @param callable $resolve_path     function( string $upload_relative_path ): int attachment ID or 0.
	 * @param callable $is_attachment_id function( int $id ): bool.
	 * @return int[]
	 */
	public static function extract_attachment_ids_from_value( $value, callable $resolve_path, callable $is_attachment_id ) {
		$found = [];

		if ( is_numeric( $value ) ) {
			$id = (int) $value;
			if ( $id > 0 && $is_attachment_id( $id ) ) {
				$found[] = $id;
			}
			return $found;
		}

		if ( ! is_string( $value ) || $value === '' ) {
			return $found;
		}

		// Comma-separated IDs (galleries stored as plain string).
		if ( preg_match( '/^[0-9]+(,[0-9]+)*$/', $value ) ) {
			foreach ( explode( ',', $value ) as $part ) {
				$id = (int) $part;
				if ( $id > 0 && $is_attachment_id( $id ) ) {
					$found[] = $id;
				}
			}
			return $found;
		}

		// URL string return format.
		if ( strpos( $value, 'wp-content/uploads' ) !== false ) {
			$found = array_merge( $found, self::extract_ids_from_uploads_string( $value, $resolve_path ) );
		}

		// Serialized PHP.
		if ( self::looks_serialized( $value ) ) {
			$data = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $value ) : @unserialize( $value );
			if ( is_array( $data ) ) {
				$found = array_merge( $found, self::extract_attachment_ids_from_array( $data, $resolve_path, $is_attachment_id ) );
			}
			return array_values( array_unique( array_map( 'intval', $found ) ) );
		}

		// JSON (some exports / plugins).
		if ( strlen( $value ) > 0 && ( $value[0] === '[' || $value[0] === '{' ) ) {
			$data = json_decode( $value, true );
			if ( is_array( $data ) ) {
				$found = array_merge( $found, self::extract_attachment_ids_from_array( $data, $resolve_path, $is_attachment_id ) );
			}
		}

		return array_values( array_unique( array_map( 'intval', $found ) ) );
	}

	/**
	 * Recursively walk arrays (ACF image array, gallery, repeater rows).
	 *
	 * @param array    $data             Data.
	 * @param callable $resolve_path     Path resolver.
	 * @param callable $is_attachment_id Attachment validator.
	 * @return int[]
	 */
	public static function extract_attachment_ids_from_array( array $data, callable $resolve_path, callable $is_attachment_id ) {
		$found = [];

		foreach ( $data as $key => $item ) {
			if ( is_array( $item ) ) {
				if ( isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
					$id = (int) $item['id'];
					if ( $id > 0 && $is_attachment_id( $id ) ) {
						$found[] = $id;
					}
				}
				if ( isset( $item['ID'] ) && is_numeric( $item['ID'] ) ) {
					$id = (int) $item['ID'];
					if ( $id > 0 && $is_attachment_id( $id ) ) {
						$found[] = $id;
					}
				}
				if ( isset( $item['url'] ) && is_string( $item['url'] ) && strpos( $item['url'], 'wp-content/uploads' ) !== false ) {
					$found = array_merge( $found, self::extract_ids_from_uploads_string( $item['url'], $resolve_path ) );
				}
				$found = array_merge( $found, self::extract_attachment_ids_from_array( $item, $resolve_path, $is_attachment_id ) );
			} elseif ( is_numeric( $item ) ) {
				$id = (int) $item;
				if ( $id > 0 && $is_attachment_id( $id ) ) {
					$found[] = $id;
				}
			} elseif ( is_string( $item ) && strpos( $item, 'wp-content/uploads' ) !== false ) {
				$found = array_merge( $found, self::extract_ids_from_uploads_string( $item, $resolve_path ) );
			}
		}

		return $found;
	}

	/**
	 * @param string   $text         Text containing upload URLs.
	 * @param callable $resolve_path Path resolver.
	 * @return int[]
	 */
	public static function extract_ids_from_uploads_string( $text, callable $resolve_path ) {
		$found = [];
		if ( preg_match_all( '#wp-content/uploads/([^\s"\'\'<>,;}\]]+)#', $text, $matches ) ) {
			foreach ( $matches[1] as $path ) {
				$id = (int) $resolve_path( $path );
				if ( $id > 0 ) {
					$found[] = $id;
				}
			}
		}
		return $found;
	}

	/**
	 * @param string $value Meta value.
	 * @return bool
	 */
	public static function looks_serialized( $value ) {
		return is_string( $value ) && strlen( $value ) > 1 && ( $value[0] === 'a' || $value[0] === 'O' ) && strpos( $value, ':' ) !== false;
	}
}

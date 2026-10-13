<?php
/**
 * Scan ACF (and ACF-shaped) meta without requiring the ACF plugin to be active.
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

require_once OLIMC_PLUGIN_DIR . 'includes/class-media-usage-parser.php';

/**
 * Reads raw meta + _field references for attachment usage.
 */
class OLIMC_Acf_Meta_Scanner {

	/**
	 * @var OLIMC_Scanner
	 */
	private $scanner;

	/**
	 * @param OLIMC_Scanner $scanner Scanner instance.
	 */
	public function __construct( OLIMC_Scanner $scanner ) {
		$this->scanner = $scanner;
	}

	/**
	 * Collect from posts, terms, users, and options.
	 */
	public function collect_all() {
		$this->collect_postmeta_by_acf_references();
		$this->collect_postmeta_generic_attachment_values();
		$this->collect_termmeta_by_acf_references();
		$this->collect_termmeta_generic_attachment_values();
		$this->collect_usermeta_by_acf_references();
		$this->collect_usermeta_generic_attachment_values();
		$this->collect_options_by_acf_references();
		$this->collect_options_generic_attachment_values();
	}

	/**
	 * Follow ACF _fieldname => field_xxx rows on posts.
	 */
	private function collect_postmeta_by_acf_references() {
		global $wpdb;

		$value_keys = $this->discover_acf_value_keys_from_refs(
			$wpdb->get_results(
				$this->reference_rows_sql( $wpdb->postmeta, 'meta_key', 'meta_value' )
			)
		);

		if ( empty( $value_keys ) ) {
			return;
		}

		$this->load_values_for_meta_keys( $wpdb->postmeta, 'meta_key', 'meta_value', $value_keys );
		foreach ( $value_keys as $name ) {
			$this->load_meta_values_like_suffix( $wpdb->postmeta, 'meta_value', $name );
		}
	}

	/**
	 * Any postmeta numeric or complex value that resolves to an attachment ID.
	 */
	private function collect_postmeta_generic_attachment_values() {
		global $wpdb;

		$exclude_keys = [
			'_edit_lock',
			'_edit_last',
			'_wp_old_date',
			'_price',
			'_regular_price',
			'_sale_price',
			'_stock',
			'_weight',
			'_length',
			'_width',
			'_height',
			'total_sales',
			'_product_version',
			'_wp_page_template',
		];

		$placeholders = implode( ', ', array_fill( 0, count( $exclude_keys ), '%s' ) );
		$sql          = "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} att ON att.ID = CAST(pm.meta_value AS UNSIGNED) AND att.post_type = 'attachment'
			WHERE pm.meta_value REGEXP '^[0-9]+$'
			AND CAST(pm.meta_value AS UNSIGNED) > 0
			AND pm.meta_key NOT IN ($placeholders)
			AND pm.meta_key NOT LIKE %s";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $exclude_keys, [ $wpdb->esc_like( '_transient' ) . '%' ] ) ) );
		$this->scanner->merge_used_attachment_ids( $rows );

		$this->scan_meta_table_for_complex_values(
			$wpdb->postmeta,
			'meta_value',
			"meta_key NOT LIKE '_transient%'"
		);
	}

	/**
	 * Term meta ACF references.
	 */
	private function collect_termmeta_by_acf_references() {
		global $wpdb;
		if ( ! isset( $wpdb->termmeta ) ) {
			return;
		}

		$value_keys = $this->discover_acf_value_keys_from_refs(
			$wpdb->get_results(
				$this->reference_rows_sql( $wpdb->termmeta, 'meta_key', 'meta_value' )
			)
		);

		if ( empty( $value_keys ) ) {
			return;
		}

		$this->load_values_for_meta_keys( $wpdb->termmeta, 'meta_key', 'meta_value', $value_keys );
		foreach ( $value_keys as $name ) {
			$this->load_meta_values_like_suffix( $wpdb->termmeta, 'meta_value', $name );
		}
	}

	/**
	 * Generic termmeta attachment IDs.
	 */
	private function collect_termmeta_generic_attachment_values() {
		global $wpdb;
		if ( ! isset( $wpdb->termmeta ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			"SELECT tm.meta_value FROM {$wpdb->termmeta} tm
			 INNER JOIN {$wpdb->posts} att ON att.ID = CAST(tm.meta_value AS UNSIGNED) AND att.post_type = 'attachment'
			 WHERE tm.meta_value REGEXP '^[0-9]+$'
			 AND CAST(tm.meta_value AS UNSIGNED) > 0
			 AND tm.meta_key NOT IN ('order', 'product_count_product_cat')"
		);
		$this->scanner->merge_used_attachment_ids( $rows );
		$this->scan_meta_table_for_complex_values( $wpdb->termmeta, 'meta_value', '1=1' );
	}

	/**
	 * User meta ACF references.
	 */
	private function collect_usermeta_by_acf_references() {
		global $wpdb;

		$value_keys = $this->discover_acf_value_keys_from_refs(
			$wpdb->get_results(
				$this->reference_rows_sql( $wpdb->usermeta, 'meta_key', 'meta_value' )
			)
		);

		if ( empty( $value_keys ) ) {
			return;
		}

		$this->load_values_for_meta_keys( $wpdb->usermeta, 'meta_key', 'meta_value', $value_keys );
		foreach ( $value_keys as $name ) {
			$this->load_meta_values_like_suffix( $wpdb->usermeta, 'meta_value', $name );
		}
	}

	/**
	 * Generic usermeta attachment IDs.
	 */
	private function collect_usermeta_generic_attachment_values() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			"SELECT um.meta_value FROM {$wpdb->usermeta} um
			 INNER JOIN {$wpdb->posts} att ON att.ID = CAST(um.meta_value AS UNSIGNED) AND att.post_type = 'attachment'
			 WHERE um.meta_value REGEXP '^[0-9]+$'
			 AND CAST(um.meta_value AS UNSIGNED) > 0"
		);
		$this->scanner->merge_used_attachment_ids( $rows );
		$this->scan_meta_table_for_complex_values( $wpdb->usermeta, 'meta_value', '1=1' );
	}

	/**
	 * Options pages: _options_fieldname references and values.
	 */
	private function collect_options_by_acf_references() {
		global $wpdb;

		$like_field = $wpdb->esc_like( 'field_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$refs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				 AND option_value LIKE %s",
				'\_options\_%',
				$like_field
			)
		);

		$prefixes = [];
		foreach ( $refs as $row ) {
			if ( strpos( $row->option_name, '_options_' ) !== 0 ) {
				continue;
			}
			if ( ! is_string( $row->option_value ) || strncmp( $row->option_value, 'field_', 6 ) !== 0 ) {
				continue;
			}
			$field_name = substr( $row->option_name, strlen( '_options_' ) );
			if ( $field_name !== '' ) {
				$prefixes[] = 'options_' . $field_name;
			}
		}

		if ( empty( $prefixes ) ) {
			return;
		}

		$prefixes = array_values( array_unique( $prefixes ) );
		foreach ( array_chunk( $prefixes, 40 ) as $chunk ) {
			$likes = [];
			$args  = [];
			foreach ( $chunk as $prefix ) {
				$likes[] = 'option_name LIKE %s';
				$args[]  = $wpdb->esc_like( $prefix ) . '%';
			}
			$sql  = "SELECT option_value FROM {$wpdb->options} WHERE (" . implode( ' OR ', $likes ) . ') AND option_value != %s';
			$args[] = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$values = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
			$this->parse_and_merge_values( $values );
		}
	}

	/**
	 * Options table generic attachment usage.
	 */
	private function collect_options_generic_attachment_values() {
		global $wpdb;

		$like_options = $wpdb->esc_like( 'options_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT o.option_value FROM {$wpdb->options} o
				 INNER JOIN {$wpdb->posts} att ON att.ID = CAST(o.option_value AS UNSIGNED) AND att.post_type = 'attachment'
				 WHERE o.option_name LIKE %s
				 AND o.option_value REGEXP '^[0-9]+$'
				 AND CAST(o.option_value AS UNSIGNED) > 0",
				$like_options
			)
		);
		$this->scanner->merge_used_attachment_ids( $rows );

		$like_uploads = '%' . $wpdb->esc_like( 'wp-content/uploads' ) . '%';
		$batch        = 500;
		$offset       = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$values = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options}
					 WHERE option_name LIKE %s
					 AND ( option_value LIKE %s OR option_value LIKE 'a:%' OR option_value LIKE '[%' OR option_value LIKE '{%' )
					 LIMIT %d OFFSET %d",
					$like_options,
					$like_uploads,
					$batch,
					$offset
				)
			);
			if ( empty( $values ) ) {
				break;
			}
			$this->parse_and_merge_values( $values );
			if ( count( $values ) < $batch ) {
				break;
			}
			$offset += $batch;
		}
	}

	/**
	 * @param string $table      Table name.
	 * @param string $key_col    Key column.
	 * @param string $value_col  Value column.
	 * @return string Prepared SQL.
	 */
	private function reference_rows_sql( $table, $key_col, $value_col ) {
		global $wpdb;
		$like_field = $wpdb->esc_like( 'field_' ) . '%';

		return $wpdb->prepare(
			"SELECT {$key_col} AS meta_key, {$value_col} AS meta_value FROM {$table}
			 WHERE {$key_col} LIKE %s
			 AND {$value_col} LIKE %s",
			'\_%',
			$like_field
		);
	}

	/**
	 * @param array $rows Rows with meta_key and meta_value.
	 * @return string[]
	 */
	private function discover_acf_value_keys_from_refs( $rows ) {
		$value_keys = [];
		if ( empty( $rows ) ) {
			return $value_keys;
		}
		foreach ( $rows as $row ) {
			if ( ! OLIMC_Media_Usage_Parser::is_acf_field_reference( $row->meta_key, $row->meta_value ) ) {
				continue;
			}
			$key = OLIMC_Media_Usage_Parser::acf_value_key_from_reference( $row->meta_key );
			if ( $key !== '' ) {
				$value_keys[ $key ] = true;
			}
		}
		return array_keys( $value_keys );
	}

	/**
	 * @param string $table     Meta table.
	 * @param string $key_col   Key column name.
	 * @param string $value_col Value column name.
	 * @param array  $meta_keys Keys to load.
	 */
	private function load_values_for_meta_keys( $table, $key_col, $value_col, array $meta_keys ) {
		global $wpdb;

		foreach ( array_chunk( $meta_keys, 80 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$sql          = "SELECT {$value_col} FROM {$table} WHERE {$key_col} IN ($placeholders) AND {$value_col} != ''";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$values = $wpdb->get_col( $wpdb->prepare( $sql, $chunk ) );
			$this->parse_and_merge_values( $values );
		}
	}

	/**
	 * Nested repeater/flex/clone keys ending with _fieldname.
	 *
	 * @param string $table      Meta table.
	 * @param string $value_col  Value column.
	 * @param string $field_name ACF field name.
	 */
	private function load_meta_values_like_suffix( $table, $value_col, $field_name ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '_' . $field_name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$values = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT {$value_col} FROM {$table} WHERE meta_key LIKE %s AND {$value_col} != ''",
				$like
			)
		);
		$this->parse_and_merge_values( $values );
	}

	/**
	 * @param string $table      Meta table.
	 * @param string $value_col  Value column.
	 * @param string $where_sql  Safe WHERE fragment.
	 */
	private function scan_meta_table_for_complex_values( $table, $value_col, $where_sql ) {
		global $wpdb;

		$batch   = 500;
		$offset  = 0;
		$uploads = '%' . $wpdb->esc_like( 'wp-content/uploads' ) . '%';

		while ( true ) {
			$sql = "SELECT {$value_col} FROM {$table}
				WHERE ( {$value_col} LIKE %s OR {$value_col} LIKE 'a:%' OR {$value_col} LIKE '[%' OR {$value_col} LIKE '{%' )
				AND {$where_sql}
				LIMIT %d OFFSET %d";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$values = $wpdb->get_col( $wpdb->prepare( $sql, $uploads, $batch, $offset ) );
			if ( empty( $values ) ) {
				break;
			}
			$this->parse_and_merge_values( $values );
			if ( count( $values ) < $batch ) {
				break;
			}
			$offset += $batch;
		}
	}

	/**
	 * @param array $values Raw meta values.
	 */
	private function parse_and_merge_values( $values ) {
		foreach ( $values as $val ) {
			$this->scanner->merge_used_attachment_ids(
				OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value(
					$val,
					[ $this->scanner, 'resolve_upload_path_to_id' ],
					[ $this->scanner, 'is_known_attachment_id' ]
				)
			);
		}
	}
}

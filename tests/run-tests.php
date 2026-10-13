<?php
/**
 * Reproducible tests for ACF / meta attachment detection (no full WordPress required for parser tests).
 *
 * Usage: php tests/run-tests.php
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
$root = dirname( __DIR__ );
require_once $root . '/includes/class-media-usage-parser.php';

$failures = 0;

/**
 * @param bool   $condition Condition.
 * @param string $message   Message.
 */
function olimc_test_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		echo "FAIL: {$message}\n";
		$failures++;
		return;
	}
	echo "OK: {$message}\n";
}

$known_attachments = [ 61273 => true, 61309 => true, 99 => true ];
$resolve_path      = function ( $path ) {
	if ( strpos( $path, 'belle-et-la-bete' ) !== false ) {
		return 61273;
	}
	return 0;
};
$is_attachment = function ( $id ) use ( $known_attachments ) {
	return isset( $known_attachments[ (int) $id ] );
};

// Concert CPT — ACF image return format: attachment ID.
$ids = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( '61273', $resolve_path, $is_attachment );
olimc_test_assert( in_array( 61273, $ids, true ), 'concert ACF image field (ID return format)' );

// Concert — ACF image array return format (osm.ca pastille).
$array_value = serialize(
	[
		'ID'    => 61273,
		'id'    => 61273,
		'title' => 'Pastille',
		'url'   => 'https://osm.ca/wp-content/uploads/2026/02/belle-et-la-bete-1080x1080-osm-2026-2027-fr-pastille.jpg',
	]
);
$ids = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( $array_value, $resolve_path, $is_attachment );
olimc_test_assert( in_array( 61273, $ids, true ), 'concert ACF image field (array return format)' );

// Concert — URL return format.
$url_value = 'https://osm.ca/wp-content/uploads/2026/02/belle-et-la-bete-1080x1080-osm-2026-2027-fr-pastille.jpg';
$ids       = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( $url_value, $resolve_path, $is_attachment );
olimc_test_assert( in_array( 61273, $ids, true ), 'concert ACF image field (URL return format)' );

// Gallery: serialized list of IDs (regression — previously ignored).
$gallery = serialize( [ 61273, 61309 ] );
$ids     = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( $gallery, $resolve_path, $is_attachment );
olimc_test_assert( count( $ids ) === 2, 'ACF gallery serialized ID list' );

// ACF field reference detection.
olimc_test_assert(
	OLIMC_Media_Usage_Parser::is_acf_field_reference( '_image', 'field_abc123' ),
	'ACF reference meta _image => field_xxx'
);
olimc_test_assert(
	! OLIMC_Media_Usage_Parser::is_acf_field_reference( '_thumbnail_id', '61273' ),
	'WordPress _thumbnail_id is not an ACF reference'
);
olimc_test_assert(
	OLIMC_Media_Usage_Parser::acf_value_key_from_reference( '_image' ) === 'image',
	'ACF value key from reference'
 );

// Repeater row meta shape.
$repeater_value = '61309';
$ids            = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( $repeater_value, $resolve_path, $is_attachment );
olimc_test_assert( in_array( 61309, $ids, true ), 'nested repeater image sub-field (numeric ID)' );

if ( $failures > 0 ) {
	echo "\n{$failures} test(s) failed.\n";
	exit( 1 );
}

echo "\nAll parser tests passed.\n";
exit( 0 );

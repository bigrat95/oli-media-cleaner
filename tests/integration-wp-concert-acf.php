<?php
/**
 * WordPress integration test: CPT concert + ACF image meta must mark attachment as used.
 *
 * Usage (from site with plugin active):
 *   wp eval-file wp-content/plugins/oli-media-cleaner/tests/integration-wp-concert-acf.php
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

if ( ! class_exists( 'OLIMC_Scanner' ) ) {
	fwrite( STDERR, "Oli Media Cleaner is not loaded.\n" );
	exit( 1 );
}

if ( function_exists( 'acf_add_local_field_group' ) ) {
	acf_add_local_field_group(
		[
			'key'      => 'group_olimc_test_concert',
			'title'    => 'OLIMC test concert',
			'fields'   => [
				[
					'key'   => 'field_olimc_test_image',
					'label' => 'Image',
					'name'  => 'image',
					'type'  => 'image',
				],
			],
			'location' => [
				[
					[
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'concert',
					],
				],
			],
		]
	);
}

if ( ! post_type_exists( 'concert' ) ) {
	register_post_type(
		'concert',
		[
			'public' => false,
			'label'  => 'Concert (OLIMC test)',
		]
	);
}

/**
 * @param int    $attachment_id Attachment ID.
 * @param string $format        id|array|url.
 * @return int Post ID.
 */
function olimc_test_create_concert_with_image( $attachment_id, $format ) {
	$post_id = wp_insert_post(
		[
			'post_type'   => 'concert',
			'post_status' => 'publish',
			'post_title'  => 'OLIMC test concert ' . wp_generate_password( 4, false ),
		]
	);

	if ( ! $post_id ) {
		return 0;
	}

	update_post_meta( $post_id, '_image', 'field_olimc_test_image' );

	if ( 'id' === $format ) {
		update_post_meta( $post_id, 'image', (string) $attachment_id );
	} elseif ( 'array' === $format ) {
		update_post_meta(
			$post_id,
			'image',
			[
				'ID'  => $attachment_id,
				'id'  => $attachment_id,
				'url' => wp_get_attachment_url( $attachment_id ),
			]
		);
	} else {
		update_post_meta( $post_id, 'image', wp_get_attachment_url( $attachment_id ) );
	}

	return (int) $post_id;
}

$upload = wp_upload_dir();
$tmp    = wp_tempnam( 'olimc-test.jpg' );
file_put_contents( $tmp, 'fake' );
$attachment_id = wp_insert_attachment(
	[
		'post_mime_type' => 'image/jpeg',
		'post_title'     => 'OLIMC test attachment',
		'post_status'    => 'inherit',
	],
	$tmp
);
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( $attachment_id, [ 'file' => _wp_relative_upload_path( $tmp ) ] );

$formats = [ 'id', 'array', 'url' ];
$failed  = 0;

foreach ( $formats as $format ) {
	$concert_id = olimc_test_create_concert_with_image( $attachment_id, $format );
	$scanner    = new OLIMC_Scanner();
	$used       = $scanner->collect_used_ids();
	$ok         = in_array( (int) $attachment_id, $used, true );
	if ( ! $ok ) {
		echo "FAIL: attachment {$attachment_id} not detected with format {$format}\n";
		$failed++;
	} else {
		echo "OK: format {$format}\n";
	}
	wp_delete_post( $concert_id, true );
}

wp_delete_attachment( $attachment_id, true );

if ( $failed > 0 ) {
	exit( 1 );
}

echo "Integration test passed for all ACF image return formats.\n";

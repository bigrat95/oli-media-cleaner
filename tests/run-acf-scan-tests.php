<?php
/**
 * ACF API path, raw-meta fallback, clone-in-flexible regression, and a 500k-row benchmark.
 *
 * Usage: php tests/run-acf-scan-tests.php
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/includes/class-media-usage-parser.php';
require_once dirname( __DIR__ ) . '/includes/class-scanner.php';
require_once dirname( __DIR__ ) . '/includes/class-acf-meta-scanner.php';

$failures = 0;

/**
 * @param bool   $condition Condition.
 * @param string $message   Message.
 */
function olimc_scan_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		echo "FAIL: {$message}\n";
		$failures++;
		return;
	}
	echo "OK: {$message}\n";
}

/**
 * Minimal wpdb over SQLite. Counts queries so the benchmark can reject per-key LIKE scans.
 */
class OLIMC_Fake_Wpdb {
	public $posts    = 'posts';
	public $postmeta = 'postmeta';
	public $termmeta = 'termmeta';
	public $usermeta = 'usermeta';
	public $options  = 'options';
	public $prefix   = 'wp_';
	public $last_error = '';
	public $queries  = [];
	public $fail_reads = false;

	/** @var PDO */
	private $pdo;

	public function __construct( PDO $pdo ) {
		$this->pdo = $pdo;
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $sql, ...$args ) {
		$index = 0;
		return preg_replace_callback(
			'/%[dfs]/',
			function ( $match ) use ( &$args, &$index ) {
				$value = $args[ $index ] ?? '';
				$index++;
				if ( $match[0] === '%d' ) {
					return (string) (int) $value;
				}
				if ( $match[0] === '%f' ) {
					return (string) (float) $value;
				}
				return "'" . str_replace( "'", "''", (string) $value ) . "'";
			},
			$sql
		);
	}

	public function get_var( $sql ) {
		$rows = $this->run( $sql, 'var' );
		return $rows;
	}

	public function get_col( $sql ) {
		return $this->run( $sql, 'col' );
	}

	public function get_results( $sql ) {
		return $this->run( $sql, 'results' );
	}

	private function run( $sql, $mode ) {
		$this->queries[] = $sql;
		if ( $this->fail_reads ) {
			$this->last_error = 'forced failure';
			return $mode === 'var' ? null : [];
		}
		$this->last_error = '';
		try {
			$statement = $this->pdo->query( $sql );
			if ( $mode === 'var' ) {
				$value = $statement->fetchColumn();
				return $value === false ? null : $value;
			}
			if ( $mode === 'col' ) {
				return $statement->fetchAll( PDO::FETCH_COLUMN );
			}
			return $statement->fetchAll( PDO::FETCH_OBJ );
		} catch ( Exception $e ) {
			$this->last_error = $e->getMessage();
			return $mode === 'var' ? null : [];
		}
	}
}

$GLOBALS['olimc_acf_enabled'] = false;

/**
 * @return array
 */
function acf_get_field_groups() {
	if ( empty( $GLOBALS['olimc_acf_enabled'] ) ) {
		return [];
	}
	return [
		[ 'key' => 'group_concert' ],
		[ 'key' => 'group_blocks' ],
		[ 'key' => 'group_options' ],
	];
}

/**
 * @param string $key Group or field key.
 * @return array|null
 */
function acf_get_fields( $key ) {
	if ( empty( $GLOBALS['olimc_acf_enabled'] ) ) {
		return [];
	}
	if ( $key === 'group_concert' ) {
		return [
			[ 'key' => 'field_concert_image', 'name' => 'image', 'type' => 'image' ],
			[ 'key' => 'field_brochure', 'name' => 'brochure', 'type' => 'file' ],
			[ 'key' => 'field_portrait', 'name' => 'portrait', 'type' => 'image' ],
			[
				'key'        => 'field_hero',
				'name'       => 'hero',
				'type'       => 'group',
				'sub_fields' => [
					[ 'key' => 'field_shot', 'name' => 'shot', 'type' => 'image' ],
				],
			],
			[
				'key'        => 'field_shows',
				'name'       => 'shows',
				'type'       => 'repeater',
				'sub_fields' => [
					[ 'key' => 'field_photo', 'name' => 'photo', 'type' => 'image' ],
				],
			],
		];
	}
	if ( $key === 'group_blocks' ) {
		return [
			[
				'key'     => 'field_blocks',
				'name'    => 'content_blocks',
				'type'    => 'flexible_content',
				'layouts' => [
					[
						'name'       => 'carousel',
						'sub_fields' => [
							[
								'key'   => 'field_clone_carousel',
								'name'  => 'image_carousel',
								'type'  => 'clone',
								'clone' => [ 'group_carousel' ],
							],
						],
					],
				],
			],
		];
	}
	if ( $key === 'group_carousel' ) {
		return [
			[ 'key' => 'field_carousel_images', 'name' => 'images', 'type' => 'gallery' ],
		];
	}
	if ( $key === 'group_options' ) {
		return [
			[ 'key' => 'field_banner', 'name' => 'banner', 'type' => 'image' ],
		];
	}
	return null;
}

/**
 * @param string $key Field key.
 * @return array|null
 */
function acf_get_field( $key ) {
	if ( $key === 'field_carousel_images' ) {
		return [ 'key' => 'field_carousel_images', 'name' => 'images', 'type' => 'gallery' ];
	}
	return null;
}

$GLOBALS['olimc_acf_enabled'] = true;
$scanner                      = new OLIMC_Scanner();
$targets                      = $scanner->get_acf_media_field_targets();
olimc_scan_assert( is_array( $targets ), 'ACF API resolves field groups' );
olimc_scan_assert( in_array( 'image', $targets['names'], true ), 'concert image field name collected' );
olimc_scan_assert( in_array( 'images', $targets['names'], true ), 'cloned gallery field name collected' );
olimc_scan_assert( in_array( 'photo', $targets['names'], true ), 'repeater image sub-field collected' );
olimc_scan_assert( in_array( 'shot', $targets['names'], true ), 'group image sub-field collected' );
olimc_scan_assert( in_array( 'field_concert_image', $targets['keys'], true ), 'concert image field key collected' );
olimc_scan_assert( in_array( 'field_carousel_images', $targets['keys'], true ), 'clone gallery field key collected' );
olimc_scan_assert(
	$scanner->acf_meta_key_matches_field( 'content_blocks_4_image_carousel_images', 'images' ),
	'clone inside flexible content matches suffixed meta key'
);

$pdo = new PDO( 'sqlite::memory:' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->exec( 'CREATE TABLE posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT)' );
$pdo->exec( 'CREATE TABLE postmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
$pdo->exec( 'CREATE TABLE termmeta (meta_id INTEGER PRIMARY KEY, term_id INTEGER, meta_key TEXT, meta_value TEXT)' );
$pdo->exec( 'CREATE TABLE usermeta (umeta_id INTEGER PRIMARY KEY, user_id INTEGER, meta_key TEXT, meta_value TEXT)' );
$pdo->exec( 'CREATE TABLE options (option_id INTEGER PRIMARY KEY, option_name TEXT, option_value TEXT)' );

$insert_post = $pdo->prepare( 'INSERT INTO posts (ID, post_type, post_status) VALUES (?, ?, ?)' );
for ( $id = 1; $id <= 10000; $id++ ) {
	$insert_post->execute( [ $id, 'attachment', 'inherit' ] );
}

$pdo->beginTransaction();
for ( $base = 1; $base <= 500000; $base += 500 ) {
	$tuples = [];
	for ( $offset = 0; $offset < 500; $offset++ ) {
		$meta_id = $base + $offset;
		$tuples[] = "({$meta_id},1,'noise','n')";
	}
	$pdo->exec( 'INSERT INTO postmeta (meta_id, post_id, meta_key, meta_value) VALUES ' . implode( ',', $tuples ) );
}
$pdo->commit();

$array_value = serialize(
	[
		'ID'  => 101,
		'id'  => 101,
		'url' => 'https://example.com/wp-content/uploads/2026/02/not-a-file.jpg',
	]
);
$gallery_value = serialize( [ 103, 104 ] );
$clone_value   = serialize( [ 105, 106 ] );

$seed = [
	[ 500001, 'image', '100' ],
	[ 500002, 'image', $array_value ],
	[ 500003, 'image', 'https://example.com/wp-content/uploads/2026/02/url-pastille.jpg' ],
	[ 500004, '_wp_attached_file', '2026/02/url-pastille.jpg' ],
	[ 500005, 'images', $gallery_value ],
	[ 500006, '_content_blocks_4_image_carousel_images', 'field_carousel_images' ],
	[ 500007, 'content_blocks_4_image_carousel_images', $clone_value ],
	[ 500008, 'brochure', '112' ],
	[ 500009, '_shows_2_photo', 'field_photo' ],
	[ 500010, 'shows_2_photo', '109' ],
	[ 500011, '_hero_shot', 'field_shot' ],
	[ 500012, 'hero_shot', '111' ],
];
$insert_meta = $pdo->prepare( 'INSERT INTO postmeta (meta_id, post_id, meta_key, meta_value) VALUES (?, 2, ?, ?)' );
foreach ( $seed as $row ) {
	$insert_meta->execute( $row );
}
$pdo->prepare( 'INSERT INTO termmeta (meta_id, term_id, meta_key, meta_value) VALUES (1, 9, ?, ?)' )->execute( [ 'image', '108' ] );
$pdo->prepare( 'INSERT INTO usermeta (umeta_id, user_id, meta_key, meta_value) VALUES (1, 4, ?, ?)' )->execute( [ 'portrait', '110' ] );
$pdo->prepare( 'INSERT INTO options (option_id, option_name, option_value) VALUES (1, ?, ?)' )->execute( [ '_options_banner', 'field_banner' ] );
$pdo->prepare( 'INSERT INTO options (option_id, option_name, option_value) VALUES (2, ?, ?)' )->execute( [ 'options_banner', '107' ] );

// The attached file row is stored on the attachment post, but the lookup only needs the meta value.
$pdo->exec( "UPDATE postmeta SET post_id = 102 WHERE meta_id = 500004" );

/**
 * @param bool $acf_enabled Whether the ACF API path is used.
 * @return array{state: array, queries: int, likes: int, seconds: float}
 */
function olimc_run_scan( OLIMC_Fake_Wpdb $db, $acf_enabled ) {
	global $wpdb;
	$wpdb                         = $db;
	$db->queries                  = [];
	$db->fail_reads               = false;
	$GLOBALS['olimc_acf_enabled'] = $acf_enabled;
	$scanner                      = new OLIMC_Scanner();
	$engine                       = new OLIMC_Acf_Meta_Scanner( $scanner );
	$state                        = $engine->initial_state();
	$state['skip_auxiliary']      = true;
	$state['skip_classify']       = true;
	$started                      = microtime( true );
	$guard                        = 0;
	while ( ! in_array( $state['phase'], [ 'done', 'failed' ], true ) && $guard < 100000 ) {
		$state = $engine->step( $state, 60000 );
		$guard++;
	}
	$likes = 0;
	foreach ( $db->queries as $sql ) {
		if ( preg_match( "/LIKE\\s+'%_/", $sql ) ) {
			$likes++;
		}
	}
	return [
		'state'   => $state,
		'queries' => count( $db->queries ),
		'likes'   => $likes,
		'seconds' => microtime( true ) - $started,
	];
}

/**
 * @param array $used Used map.
 * @param int[] $ids  Expected IDs.
 * @param string $label Label.
 */
function olimc_assert_used( array $used, array $ids, $label ) {
	foreach ( $ids as $id ) {
		olimc_scan_assert( isset( $used[ $id ] ), "{$label} keeps attachment {$id}" );
	}
	olimc_scan_assert( ! isset( $used[50] ), "{$label} does not flag an unreferenced attachment" );
}

$expected = [ 100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112 ];

global $wpdb;
$wpdb = new OLIMC_Fake_Wpdb( $pdo );

$acf = olimc_run_scan( $wpdb, true );
olimc_scan_assert( $acf['state']['phase'] === 'done', 'ACF API scan finishes' );
olimc_scan_assert( $acf['state']['mode'] === 'acf', 'primary path uses the ACF API' );
olimc_assert_used( $acf['state']['used'], $expected, 'ACF path' );
olimc_scan_assert( $acf['likes'] === 0, 'ACF path issues no per-key LIKE scans (' . $acf['likes'] . ')' );
olimc_scan_assert( $acf['queries'] < 400, 'ACF path query count stays batched (' . $acf['queries'] . ' queries)' );
olimc_scan_assert( $acf['seconds'] < 45, 'ACF path finishes in reasonable time (' . round( $acf['seconds'], 2 ) . 's)' );
echo 'ACF path: ' . $acf['queries'] . ' queries in ' . round( $acf['seconds'], 2 ) . "s\n";

$fallback = olimc_run_scan( $wpdb, false );
olimc_scan_assert( $fallback['state']['phase'] === 'done', 'fallback scan finishes' );
olimc_scan_assert( $fallback['state']['mode'] === 'fallback', 'inactive ACF uses the raw-meta fallback' );
olimc_assert_used( $fallback['state']['used'], $expected, 'fallback' );
olimc_scan_assert( $fallback['likes'] === 0, 'fallback issues no per-key LIKE scans' );
olimc_scan_assert( $fallback['queries'] < 400, 'fallback query count stays batched (' . $fallback['queries'] . ' queries)' );
olimc_scan_assert( $fallback['seconds'] < 60, 'fallback finishes in reasonable time (' . round( $fallback['seconds'], 2 ) . 's)' );
echo 'Fallback: ' . $fallback['queries'] . ' queries in ' . round( $fallback['seconds'], 2 ) . "s\n";

$wpdb->fail_reads = true;
$engine           = new OLIMC_Acf_Meta_Scanner( new OLIMC_Scanner() );
$failed           = $engine->initial_state();
olimc_scan_assert( $failed['phase'] === 'failed', 'database error fails the scan' );
olimc_scan_assert( ! OLIMC_Acf_Meta_Scanner::may_mark_unused( $failed ), 'a failed scan marks nothing unused' );
olimc_scan_assert( $failed['pending_unused'] === [], 'a failed scan has no unused list' );

if ( $failures > 0 ) {
	echo "\n{$failures} test(s) failed.\n";
	exit( 1 );
}

echo "\nAll ACF scan tests passed.\n";
exit( 0 );

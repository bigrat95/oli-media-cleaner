<?php
/**
 * Tests for the protection system (path matching, glob patterns).
 *
 * Usage: php tests/run-protection-tests.php
 *
 * @package Oli_Media_Cleaner
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

$root = dirname(__DIR__);

// Mock WordPress functions for testing
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain) { return $text; }
}
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $mock_options;
        return isset($mock_options[$option]) ? $mock_options[$option] : $default;
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        global $mock_meta;
        $key_id = $post_id . '_' . $key;
        if (!isset($mock_meta[$key_id])) return $single ? '' : [];
        return $single ? $mock_meta[$key_id] : [$mock_meta[$key_id]];
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null, $create_dir = true) {
        return [
            'path' => '/var/www/html/wp-content/uploads',
            'url' => 'https://example.com/wp-content/uploads',
            'subdir' => '',
            'basedir' => '/var/www/html/wp-content/uploads',
            'baseurl' => 'https://example.com/wp-content/uploads',
        ];
    }
}

$failures = 0;
$mock_options = [];
$mock_meta = [];

function protection_test_assert($condition, $message) {
    global $failures;
    if (!$condition) {
        echo "FAIL: {$message}\n";
        $failures++;
        return false;
    }
    echo "OK: {$message}\n";
    return true;
}

// Load the admin class to get path matching methods
// We'll test the logic through reflection since methods are private
require_once $root . '/includes/class-admin.php';

// Test path normalization
echo "\n=== Path Normalization Tests ===\n";

$class = new ReflectionClass('OLIMC_Admin');
$normalizeMethod = $class->getMethod('normalize_protected_pattern');
$normalizeMethod->setAccessible(true);

$base_url = 'https://example.com/wp-content/uploads';

// Test URL to path conversion
$pattern = $base_url . '/2024/03/logo.png';
$result = $normalizeMethod->invokeArgs(null, [$pattern, $base_url]);
protection_test_assert(
    $result === '2024/03/logo.png',
    "URL converted to path: {$result}"
);

// Test path with leading slash
$pattern = '/2024/03/logo.png';
$result = $normalizeMethod->invokeArgs(null, [$pattern, $base_url]);
protection_test_assert(
    $result === '2024/03/logo.png',
    "Leading slash removed: {$result}"
);

// Test path without slash
$pattern = '2024/03/logo.png';
$result = $normalizeMethod->invokeArgs(null, [$pattern, $base_url]);
protection_test_assert(
    $result === '2024/03/logo.png',
    "Clean path unchanged: {$result}"
);

// Test glob pattern
$pattern = '2024/*/logo-*.png';
$result = $normalizeMethod->invokeArgs(null, [$pattern, $base_url]);
protection_test_assert(
    $result === '2024/*/logo-*.png',
    "Glob pattern preserved: {$result}"
);

// Test double-star pattern
$pattern = '2024/**/logo.png';
$result = $normalizeMethod->invokeArgs(null, [$pattern, $base_url]);
protection_test_assert(
    $result === '2024/**/logo.png',
    "Double-star pattern preserved: {$result}"
);

// Test path matching
echo "\n=== Path Matching Tests ===\n";

$matchMethod = $class->getMethod('path_matches_pattern');
$matchMethod->setAccessible(true);

// Exact match (case-sensitive)
$file = '2024/03/logo.png';
$pattern = '2024/03/logo.png';
$result = $matchMethod->invokeArgs(null, [$file, $pattern]);
protection_test_assert(
    $result === true,
    "Exact match works (case-sensitive)"
);

// Exact match - case mismatch should fail
$file = '2024/03/Logo.PNG';
$pattern = '2024/03/logo.png';
$result = $matchMethod->invokeArgs(null, [$file, $pattern]);
protection_test_assert(
    $result === false,
    "Exact match is case-sensitive: {$file} vs {$pattern}"
);

// No match
$file = '2024/03/other.png';
$pattern = '2024/03/logo.png';
$result = $matchMethod->invokeArgs(null, [$file, $pattern]);
protection_test_assert(
    $result === false,
    "Non-match correctly returns false"
);

// Single star wildcard in filename
$file = '2024/03/logo-large.png';
$pattern = '2024/03/logo-*.png';
$result = $matchMethod->invokeArgs(null, [$file, $pattern]);
protection_test_assert(
    $result === true,
    "Single * wildcard matches filename: {$file} vs {$pattern}"
);

// Single star wildcard in directory
$file = '2024/03/logo.png';
$pattern = '2024/*/logo.png';
$result = $matchMethod->invokeArgs(null, [$file, $pattern]);
protection_test_assert(
    $result === true,
    "Single * wildcard matches directory: {$file} vs {$pattern}"
);

// Single star does NOT match across segments
$file = '2024/03/subfolder/logo.png';
$pattern = '2024/*/logo.png';
$result = $matchMethod->invokeArgs(null, [$file, $pattern]);
protection_test_assert(
    $result === false,
    "Single * does NOT match nested path: {$file} vs {$pattern}"
);

// Double star matches across segments
$file = '2024/03/subfolder/logo.png';
$pattern = '2024/**/logo.png';
$result = $matchMethod->invokeArgs(null, [$file, $pattern]);
protection_test_assert(
    $result === true,
    "Double ** matches nested path: {$file} vs {$pattern}"
);

// Full path double star wildcard
$file = '2024/03/anything/logo.png';
$pattern = '**/logo.png';
$result = $matchMethod->invokeArgs(null, [$file, $pattern]);
protection_test_assert(
    $result === true,
    "Full path ** wildcard works: {$file} vs {$pattern}"
);

// Multiple single stars
$file = '2024/03/header-large.jpg';
$pattern = '*/*/header-*.jpg';
$result = $matchMethod->invokeArgs(null, [$file, $pattern]);
protection_test_assert(
    $result === true,
    "Multiple single * work: {$file} vs {$pattern}"
);

// Glob patterns are case insensitive
$file = '2024/03/Logo.PNG';
$pattern = '2024/03/logo.*';
$result = $matchMethod->invokeArgs(null, [$file, $pattern]);
protection_test_assert(
    $result === true,
    "Glob pattern is case insensitive: {$file} vs {$pattern}"
);

// Mixed single and double star
$file = '2024/03/deep/nested/path/file.jpg';
$pattern = '2024/*/**/*.jpg';
$result = $matchMethod->invokeArgs(null, [$file, $pattern]);
protection_test_assert(
    $result === true,
    "Mixed * and ** work: {$file} vs {$pattern}"
);

echo "\n=== Summary ===\n";
if ($failures === 0) {
    echo "All tests passed!\n";
    exit(0);
} else {
    echo "{$failures} test(s) failed.\n";
    exit(1);
}

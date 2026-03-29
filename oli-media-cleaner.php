<?php
/**
 * Plugin Name: Oli Media Cleaner
 * Plugin URI: https://github.com/bigrat95/oli-media-cleaner
 * Description: Scan and remove unused media files from your WordPress site to free up disk space. Deep scans post content, ACF fields, WooCommerce, Elementor, theme files, widgets, and more.
 * Version: 1.5.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Olivier Bigras
 * Author URI: https://olivierbigras.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: oli-media-cleaner
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

define('OMC_VERSION', '1.5.0');
define('OMC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OMC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OMC_BASENAME', plugin_basename(__FILE__));

require_once OMC_PLUGIN_DIR . 'includes/class-scanner.php';
require_once OMC_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook(__FILE__, ['OMC_Admin', 'activate']);
register_deactivation_hook(__FILE__, ['OMC_Admin', 'deactivate']);

// Boot
OMC_Admin::init();

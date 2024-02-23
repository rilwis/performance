<?php
/**
 * Plugin Name: Dominant Color Images
 * Plugin URI: https://github.com/WordPress/performance/tree/trunk/modules/images/dominant-color-images
 * Description: Adds support to store the dominant color of newly uploaded images and create a placeholder background of that color.
 * Requires at least: 6.3
 * Requires PHP: 7.0
 * Version: 1.0.1
 * Author: WordPress Performance Team
 * Author URI: https://make.wordpress.org/performance/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: dominant-color-images
 *
 * @package dominant-color-images
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define required constants.
define( 'DOMINANT_COLOR_IMAGES_VERSION', '1.0.1' );

require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/hooks.php';

<?php
/**
 * Plugin Name: Performance Lab
 * Plugin URI: https://github.com/WordPress/performance
 * Description: Performance plugin from the WordPress Performance Team, which is a collection of standalone performance modules.
 * Requires at least: 6.3
 * Requires PHP: 7.0
 * Version: 2.9.0
 * Author: WordPress Performance Team
 * Author URI: https://make.wordpress.org/performance/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: performance-lab
 *
 * @package performance-lab
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'PERFLAB_VERSION', '2.9.0' );
define( 'PERFLAB_MAIN_FILE', __FILE__ );
define( 'PERFLAB_PLUGIN_DIR_PATH', plugin_dir_path( PERFLAB_MAIN_FILE ) );
define( 'PERFLAB_MODULES_SETTING', 'perflab_modules_settings' );
define( 'PERFLAB_MODULES_SCREEN', 'perflab-modules' );

// If the constant isn't defined yet, it means the Performance Lab object cache file is not loaded.
if ( ! defined( 'PERFLAB_OBJECT_CACHE_DROPIN_VERSION' ) ) {
	define( 'PERFLAB_OBJECT_CACHE_DROPIN_VERSION', false );
}

require_once PERFLAB_PLUGIN_DIR_PATH . 'server-timing/class-perflab-server-timing-metric.php';
require_once PERFLAB_PLUGIN_DIR_PATH . 'server-timing/class-perflab-server-timing.php';
require_once PERFLAB_PLUGIN_DIR_PATH . 'server-timing/load.php';
require_once PERFLAB_PLUGIN_DIR_PATH . 'server-timing/defaults.php';

/**
 * Registers the performance modules setting.
 *
 * @since 1.0.0
 */
function perflab_register_modules_setting() {
	register_setting(
		PERFLAB_MODULES_SCREEN,
		PERFLAB_MODULES_SETTING,
		array(
			'type'              => 'object',
			'sanitize_callback' => 'perflab_sanitize_modules_setting',
			'default'           => perflab_get_modules_setting_default(),
		)
	);
}
add_action( 'init', 'perflab_register_modules_setting' );

/**
 * Gets the default value for the performance modules setting.
 *
 * @since 1.0.0
 *
 * @return array Associative array of module settings keyed by module slug.
 */
function perflab_get_modules_setting_default() {
	// Since the default relies on some minimal logic that includes requiring an additional file,
	// the result is "cached" in a static variable.
	static $default_option = null;

	if ( null === $default_option ) {
		// To set the default value for which modules are enabled, rely on this generated file.
		$default_enabled_modules = require PERFLAB_PLUGIN_DIR_PATH . 'default-enabled-modules.php';
		$default_option          = array_reduce(
			$default_enabled_modules,
			static function ( $module_settings, $module_dir ) {
				$module_settings[ $module_dir ] = array( 'enabled' => true );
				return $module_settings;
			},
			array()
		);
	}

	return $default_option;
}

/**
 * Sanitizes the performance modules setting.
 *
 * @since 1.0.0
 *
 * @param mixed $value Modules setting value.
 * @return array Sanitized modules setting value.
 */
function perflab_sanitize_modules_setting( $value ) {
	if ( ! is_array( $value ) ) {
		return array();
	}

	// Ensure that every element is an array with an 'enabled' key.
	return array_filter(
		array_map(
			static function ( $module_settings ) {
				if ( ! is_array( $module_settings ) ) {
					return array();
				}
				return array_merge(
					array( 'enabled' => false ),
					$module_settings
				);
			},
			$value
		)
	);
}

/**
 * Gets the performance module settings.
 *
 * @since 1.0.0
 *
 * @return array Associative array of module settings keyed by module slug.
 */
function perflab_get_module_settings() {
	// Even though a default value is registered for this setting, the default must be explicitly
	// passed here, to support scenarios where this function is called before the 'init' action,
	// for example when loading the active modules.
	$module_settings = (array) get_option( PERFLAB_MODULES_SETTING, perflab_get_modules_setting_default() );

	$legacy_module_slugs = array(
		'site-health/audit-autoloaded-options' => 'database/audit-autoloaded-options',
		'site-health/audit-enqueued-assets'    => 'js-and-css/audit-enqueued-assets',
		'site-health/webp-support'             => 'images/webp-support',
	);

	foreach ( $legacy_module_slugs as $legacy_slug => $current_slug ) {
		if ( isset( $module_settings[ $legacy_slug ] ) ) {
			$module_settings[ $current_slug ] = $module_settings[ $legacy_slug ];
			unset( $module_settings[ $legacy_slug ] );
		}
	}

	return $module_settings;
}

/**
 * Gets the active performance modules.
 *
 * @since 1.0.0
 *
 * @return array List of active module slugs.
 */
function perflab_get_active_modules() {
	$modules = array_keys(
		array_filter(
			perflab_get_module_settings(),
			static function ( $module_settings ) {
				return isset( $module_settings['enabled'] ) && $module_settings['enabled'];
			}
		)
	);

	/**
	 * Filters active modules to allow programmatically control which modules are active.
	 *
	 * @since 1.0.0
	 *
	 * @param array $modules An array of the currently active modules.
	 */
	$modules = apply_filters( 'perflab_active_modules', $modules );

	return $modules;
}

/**
 * Gets the active and valid performance modules.
 *
 * @since 1.3.0
 * @since 2.2.0 Adds an additional check for standalone plugins.
 *
 * @param string $module Slug of the module.
 * @return bool True if the module is active and valid, otherwise false.
 */
function perflab_is_valid_module( $module ) {

	if ( empty( $module ) ) {
		return false;
	}

	// Do not load the module if it can be loaded by a separate plugin.
	if ( perflab_is_standalone_plugin_loaded( $module ) ) {
		return false;
	}

	// Do not load module if no longer exists.
	$module_file = PERFLAB_PLUGIN_DIR_PATH . 'modules/' . $module . '/load.php';
	if ( ! file_exists( $module_file ) ) {
		return false;
	}

	// Do not load module if it cannot be loaded, e.g. if it was already merged and is available in WordPress core.
	$can_load_module = perflab_can_load_module( $module );
	return $can_load_module && ! is_wp_error( $can_load_module );
}

/**
 * Gets the content attribute for the generator tag for the Performance Lab plugin.
 *
 * This attribute is then used in {@see perflab_render_generator()}.
 *
 * @since 1.1.0
 * @since 2.9.0 The generator tag now includes the active standalone plugin slugs.
 */
function perflab_get_generator_content() {
	$active_and_valid_modules = array_filter( perflab_get_active_modules(), 'perflab_is_valid_module' );

	$active_plugins = array();
	foreach ( perflab_get_standalone_plugin_version_constants( 'plugins' ) as $plugin_slug => $constant_name ) {
		if ( defined( $constant_name ) && ! str_starts_with( constant( $constant_name ), 'Performance Lab ' ) ) {
			$active_plugins[] = $plugin_slug;
		}
	}

	return sprintf(
		'Performance Lab %1$s; modules: %2$s; plugins: %3$s',
		PERFLAB_VERSION,
		implode( ', ', $active_and_valid_modules ),
		implode( ', ', $active_plugins )
	);
}

/**
 * Displays the HTML generator tag for the Performance Lab plugin.
 *
 * See {@see 'wp_head'}.
 *
 * @since 1.1.0
 */
function perflab_render_generator() {
	$content = perflab_get_generator_content();

	echo '<meta name="generator" content="' . esc_attr( $content ) . '">' . "\n";
}
add_action( 'wp_head', 'perflab_render_generator' );

/**
 * Checks whether the given module can be loaded in the current environment.
 *
 * @since 1.3.0
 * @since 2.8.0 The function may now alternatively return a WP_Error.
 *
 * @param string $module Slug of the module.
 * @return bool|WP_Error True if the module can be loaded, or false or a WP_Error with more concrete information otherwise.
 */
function perflab_can_load_module( $module ) {
	$module_load_file = PERFLAB_PLUGIN_DIR_PATH . 'modules/' . $module . '/can-load.php';

	// If the `can-load.php` file does not exist, assume the module can be loaded.
	if ( ! file_exists( $module_load_file ) ) {
		return true;
	}

	// Require the file to get the closure for whether the module can load.
	$module = require $module_load_file;

	// If the `can-load.php` file is invalid and does not return a closure, assume the module can be loaded.
	if ( ! is_callable( $module ) ) {
		return true;
	}

	// Call the closure to determine whether the module can be loaded.
	$result = $module();

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return (bool) $result;
}

/**
 * Checks whether the given module has already been loaded by a separate plugin.
 *
 * @since 2.2.0
 *
 * @param string $module Slug of the module.
 * @return bool Whether the module has already been loaded by a separate plugin.
 */
function perflab_is_standalone_plugin_loaded( $module ) {
	$standalone_plugins_constants = perflab_get_standalone_plugin_version_constants( 'modules' );
	if (
		isset( $standalone_plugins_constants[ $module ] ) &&
		defined( $standalone_plugins_constants[ $module ] ) &&
		! str_starts_with( constant( $standalone_plugins_constants[ $module ] ), 'Performance Lab ' )
	) {
		return true;
	}
	return false;
}

/**
 * Gets the standalone plugin constants used for each module with a standalone plugin.
 *
 * @since 2.2.0
 * @deprecated 2.9.0
 *
 * @return array Map of module path to version constant used.
 */
function perflab_get_standalone_plugins_constants() {
	_deprecated_function( __FUNCTION__, 'Performance Lab 2.9.0', "perflab_get_standalone_plugin_version_constants( 'modules' )" );
	return perflab_get_standalone_plugin_version_constants( 'modules' );
}

/**
 * Gets the standalone plugin constants used for each available standalone plugin, or module with a standalone plugin.
 *
 * @since 2.9.0
 *
 * @param string $source Optional. Either 'plugins' or 'modules'. Default 'plugins'.
 * @return array<string, string> Map of plugin slug / module path and the version constant used.
 */
function perflab_get_standalone_plugin_version_constants( $source = 'plugins' ) {
	if ( 'modules' === $source ) {
		/*
		 * This list includes all modules which are also available as standalone plugins,
		 * as `$module_dir => $version_constant` pairs.
		 */
		return array();
	}

	/*
	 * This list includes all standalone plugins that are part of the Performance Lab project,
	 * as `$plugin_slug => $version_constant` pairs.
	 */
	return array(
		'webp-uploads'            => 'WEBP_UPLOADS_VERSION',
		'dominant-color-images'   => 'DOMINANT_COLOR_IMAGES_VERSION',
		'performant-translations' => 'PERFORMANT_TRANSLATIONS_VERSION',
		'auto-sizes'              => 'IMAGE_AUTO_SIZES_VERSION',
		'speculation-rules'       => 'SPECULATION_RULES_VERSION',
	);
}

/**
 * Loads the active and valid performance modules.
 *
 * @since 1.0.0
 * @since 1.3.0 Renamed to perflab_load_active_and_valid_modules().
 */
function perflab_load_active_and_valid_modules() {
	$active_and_valid_modules = array_filter( perflab_get_active_modules(), 'perflab_is_valid_module' );

	foreach ( $active_and_valid_modules as $module ) {

		require_once PERFLAB_PLUGIN_DIR_PATH . 'modules/' . $module . '/load.php';
	}
}
add_action( 'plugins_loaded', 'perflab_load_active_and_valid_modules' );

/**
 * Places the Performance Lab's object cache drop-in in the drop-ins folder.
 *
 * This only runs in WP Admin to not have any potential performance impact on
 * the frontend.
 *
 * This function will short-circuit if at least one of the constants
 * 'PERFLAB_DISABLE_SERVER_TIMING' or 'PERFLAB_DISABLE_OBJECT_CACHE_DROPIN' is
 * set as true.
 *
 * @since 1.8.0
 * @since 2.1.0 No longer attempts to use two of the drop-ins together.
 *
 * @global WP_Filesystem_Base $wp_filesystem WordPress filesystem subclass.
 */
function perflab_maybe_set_object_cache_dropin() {
	global $wp_filesystem;

	// Bail if Server-Timing is disabled entirely.
	if ( defined( 'PERFLAB_DISABLE_SERVER_TIMING' ) && PERFLAB_DISABLE_SERVER_TIMING ) {
		return;
	}

	// Bail if disabled via constant.
	if ( defined( 'PERFLAB_DISABLE_OBJECT_CACHE_DROPIN' ) && PERFLAB_DISABLE_OBJECT_CACHE_DROPIN ) {
		return;
	}

	// Bail if already placed.
	if ( PERFLAB_OBJECT_CACHE_DROPIN_VERSION ) {
		return;
	}

	/**
	 * Filters whether the Perflab server timing drop-in should be set.
	 *
	 * @since 2.0.0
	 *
	 * @param bool $disabled Whether to disable the server timing drop-in. Default false.
	 */
	if ( apply_filters( 'perflab_disable_object_cache_dropin', false ) ) {
		return;
	}

	// Bail if already attempted before timeout has been completed.
	// This is present in case placing the file fails for some reason, to avoid
	// excessively retrying to place it on every request.
	$timeout = get_transient( 'perflab_set_object_cache_dropin' );
	if ( false !== $timeout ) {
		return;
	}

	if ( $wp_filesystem || WP_Filesystem() ) {
		$dropin_path = WP_CONTENT_DIR . '/object-cache.php';

		/**
		 * If there is an actual object-cache.php file, do not replace it.
		 * Previous versions of the Performance Lab plugin were renaming the
		 * original object-cache.php file and then loading both. However, due
		 * to other plugins eagerly checking file headers, this caused too many
		 * problems across sites so it was decided to remove this layer.
		 * Only placing the drop-in file if no other one exists yet is the
		 * safest solution.
		 */
		if ( $wp_filesystem->exists( $dropin_path ) ) {
			// Set timeout of 1 day before retrying again (only in case the file already exists).
			set_transient( 'perflab_set_object_cache_dropin', true, DAY_IN_SECONDS );
			return;
		}

		$wp_filesystem->copy( PERFLAB_PLUGIN_DIR_PATH . 'server-timing/object-cache.copy.php', $dropin_path );
	}

	// Set timeout of 1 hour before retrying again (only relevant in case the above failed).
	set_transient( 'perflab_set_object_cache_dropin', true, HOUR_IN_SECONDS );
}
add_action( 'admin_init', 'perflab_maybe_set_object_cache_dropin' );

/**
 * Removes the Performance Lab's object cache drop-in from the drop-ins folder.
 *
 * This function should be run on plugin deactivation. For backward compatibility with
 * an earlier implementation of `perflab_maybe_set_object_cache_dropin()`, this function
 * checks whether there is an object-cache-plst-orig.php file, and if so restores it.
 *
 * This function will short-circuit if the constant
 * 'PERFLAB_DISABLE_OBJECT_CACHE_DROPIN' is set as true.
 *
 * @since 1.8.0
 *
 * @global WP_Filesystem_Base $wp_filesystem WordPress filesystem subclass.
 */
function perflab_maybe_remove_object_cache_dropin() {
	global $wp_filesystem;

	// Bail if disabled via constant.
	if ( defined( 'PERFLAB_DISABLE_OBJECT_CACHE_DROPIN' ) && PERFLAB_DISABLE_OBJECT_CACHE_DROPIN ) {
		return;
	}

	// Bail if custom drop-in not present anyway.
	if ( ! PERFLAB_OBJECT_CACHE_DROPIN_VERSION ) {
		return;
	}

	if ( $wp_filesystem || WP_Filesystem() ) {
		$dropin_path        = WP_CONTENT_DIR . '/object-cache.php';
		$dropin_backup_path = WP_CONTENT_DIR . '/object-cache-plst-orig.php';

		/**
		 * If there is an object-cache-plst-orig.php file, restore it and
		 * override the Performance Lab file. This is only relevant for
		 * backward-compatibility with previous Performance Lab versions
		 * which were backing up the file and then loading both.
		 * Otherwise just delete the Performance Lab file.
		 */
		if ( $wp_filesystem->exists( $dropin_backup_path ) ) {
			$wp_filesystem->move( $dropin_backup_path, $dropin_path, true );
		} else {
			$wp_filesystem->delete( $dropin_path );
		}
	}

	// Delete transient for drop-in check in case the plugin is reactivated shortly after.
	delete_transient( 'perflab_set_object_cache_dropin' );
}
register_deactivation_hook( __FILE__, 'perflab_maybe_remove_object_cache_dropin' );

// Only load admin integration when in admin.
if ( is_admin() ) {
	require_once PERFLAB_PLUGIN_DIR_PATH . 'admin/load.php';
	require_once PERFLAB_PLUGIN_DIR_PATH . 'admin/server-timing.php';
	require_once PERFLAB_PLUGIN_DIR_PATH . 'admin/plugins.php';
}

/**
 * Trigger actions when a module gets activated or deactivated.
 *
 * @since 1.8.0
 *
 * @param mixed $old_value Old value of the option.
 * @param mixed $value     New value of the option.
 */
function perflab_run_module_activation_deactivation( $old_value, $value ) {
	$old_value = (array) $old_value;
	$value     = (array) $value;

	// Get the list of modules that were activated, and load the activate.php files if they exist.
	if ( ! empty( $value ) ) {
		$reset_migration_pointer_dismissals = false;
		foreach ( $value as $module => $module_settings ) {
			if ( ! empty( $module_settings['enabled'] ) && ( empty( $old_value[ $module ] ) || empty( $old_value[ $module ]['enabled'] ) ) ) {
				perflab_activate_module( PERFLAB_PLUGIN_DIR_PATH . 'modules/' . $module );
				$reset_migration_pointer_dismissals = true;
			}
		}
		if ( $reset_migration_pointer_dismissals ) {
			// Retrieve a list of active modules with associated standalone plugins.
			$active_modules_with_plugins = perflab_get_active_modules_with_standalone_plugins();

			/*
			 * Check if there are any active modules with compatible standalone plugins.
			 * If no such modules are found bail early.
			 */
			if ( empty( $active_modules_with_plugins ) ) {
				return;
			}

			$current_user = wp_get_current_user();

			/*
			 * Disable WordPress pointers for specific users based on conditions.
			 *
			 * Checks if there is a large user count on the site. If true,
			 * disables pointers for the current user only. Otherwise, disables
			 * pointers for users with the same role as the current user.
			 */
			if ( wp_is_large_user_count() ) {
				perflab_undismiss_module_migration_pointer( $current_user );
			} else {
				$current_user_roles = $current_user->roles;
				$current_user_role  = array_shift( $current_user_roles );

				$args = array(
					'role'       => $current_user_role,
					'meta_query' => array(
						array(
							'key'     => 'dismissed_wp_pointers',
							'value'   => 'perflab-module-migration-pointer',
							'compare' => 'LIKE',
						),
					),
				);

				$users = get_users( $args );

				foreach ( $users as $user ) {
					perflab_undismiss_module_migration_pointer( $user );
				}
			}
		}
	}

	// Get the list of modules that were deactivated, and load the deactivate.php files if they exist.
	if ( ! empty( $old_value ) ) {
		foreach ( $old_value as $module => $module_settings ) {
			if ( ! empty( $module_settings['enabled'] ) && ( empty( $value[ $module ] ) || empty( $value[ $module ]['enabled'] ) ) ) {
				perflab_deactivate_module( PERFLAB_PLUGIN_DIR_PATH . 'modules/' . $module );
			}
		}
	}

	return $value;
}

/**
 * Reverts the module migration pointer dismissal for the given user.
 *
 * @since 2.8.0
 *
 * @param WP_User $user The WP_User object.
 */
function perflab_undismiss_module_migration_pointer( $user ) {
	$dismissed = array_filter( explode( ',', (string) get_user_meta( $user->ID, 'dismissed_wp_pointers', true ) ) );

	$pointer_index = array_search( 'perflab-module-migration-pointer', $dismissed, true );
	if ( false === $pointer_index ) {
		return;
	}

	unset( $dismissed[ $pointer_index ] );
	$dismissed = implode( ',', $dismissed );

	update_user_meta( $user->ID, 'dismissed_wp_pointers', $dismissed );
}

/**
 * Activate a module.
 *
 * Runs the activate.php file if it exists.
 *
 * @since 1.8.0
 *
 * @param string $module_dir_path The module's directory path.
 */
function perflab_activate_module( $module_dir_path ) {
	$module_activation_file = $module_dir_path . '/activate.php';
	if ( ! file_exists( $module_activation_file ) ) {
		return;
	}
	$module = require $module_activation_file;
	if ( ! is_callable( $module ) ) {
		return;
	}
	$module();
}

/**
 * Deactivate a module.
 *
 * Runs the deactivate.php file if it exists.
 *
 * @since 1.8.0
 *
 * @param string $module_dir_path The module's directory path.
 */
function perflab_deactivate_module( $module_dir_path ) {
	$module_deactivation_file = $module_dir_path . '/deactivate.php';
	if ( ! file_exists( $module_deactivation_file ) ) {
		return;
	}
	$module = require $module_deactivation_file;
	if ( ! is_callable( $module ) ) {
		return;
	}
	$module();
}

// Run the module activation & deactivation actions when the option is updated.
add_action( 'update_option_' . PERFLAB_MODULES_SETTING, 'perflab_run_module_activation_deactivation', 10, 2 );

// Run the module activation & deactivation actions when the option is added.
add_action(
	'add_option_' . PERFLAB_MODULES_SETTING,
	/**
	 * Fires after the option has been added.
	 *
	 * @param string $option Name of the option to add.
	 * @param mixed  $value  Value of the option.
	 */
	static function ( $option, $value ) {
		perflab_run_module_activation_deactivation( perflab_get_modules_setting_default(), $value );
	},
	10,
	2
);

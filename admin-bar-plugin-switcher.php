<?php
/**
 * The Admin Bar Plugin Switcher Plugin
 *
 * Activate/deactivate plugins from admin bar.
 *
 * @package    Admin_Bar_Plugin_Switcher
 * @subpackage Main
 */

/**
 * Plugin Name: Admin Bar Plugin Switcher
 * Plugin URI:  http://blog.milandinic.com/wordpress/plugins/
 * Description: Activate/deactivate plugins from admin bar.
 * Author:      Milan Dinić
 * Author URI:  http://blog.milandinic.com/
 * Version:     1.0
 * Text Domain: admin-bar-plugin-switcher
 * Domain Path: /languages/
 * License:     GPL
 */

/* Exit if accessed directly */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Initialize a plugin.
 *
 * Load class when all plugins are loaded
 * so that other plugins can overwrite it.
 *
 * @since 1.0
 */
function abps_instantiate() {
	global $admin_bar_plugin_switcher;
	$admin_bar_plugin_switcher = new Admin_Bar_Plugin_Switcher();
}
add_action( 'plugins_loaded', 'abps_instantiate', 15 );

if ( ! class_exists( 'Admin_Bar_Plugin_Switcher' ) ) :
/**
 * Post to Queue main class.
 *
 * Queue and publish posts automatically.
 *
 * @since 1.0
 */
class Admin_Bar_Plugin_Switcher {
	/**
	 * Initialize Admin_Bar_Plugin_Switcher object.
	 *
	 * Add main methods to appropriate hooks.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function __construct() {
		// Load translations
		load_plugin_textdomain( 'admin-bar-plugin-switcher', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Register main actions
		add_action( 'init',                                 array( $this, 'init'           )     );
		add_action( 'admin_bar_menu',                       array( $this, 'admin_bar_menu' ), 95 );

		// Add cache cleaner on apropiate hooks
		add_action( 'activated_plugin',                     array( $this, 'purge_cache'    )     );
		add_action( 'deactivated_plugin',                   array( $this, 'purge_cache'    )     );
		add_action( 'delete_site_transient_update_plugins', array( $this, 'purge_cache'    )     );
		add_action( 'set_site_transient_update_plugins',    array( $this, 'purge_cache'    )     );
	}

	/**
	 * Register handler on all pages.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function init() {
		if ( isset( $_REQUEST['abps-action'] ) && ( $action = $_REQUEST['abps-action'] ) && in_array( $action, array( 'activate', 'deactivate' ) ) ) {
			$this->handle_action();
		}
	}

	/**
	 * Add a list of plugins to the admin bar.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function admin_bar_menu() {
		// If the current user can't activate plugins, don't display
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		global $wp_admin_bar;

		$current_page_url = $this->get_current_page_url();

		// Add main admin bar item
		$wp_admin_bar->add_menu(
			array(
				'id'    => 'abps-menu',
				'title' => __( 'Plugins', 'admin-bar-plugin-switcher' ),
				'href'  => admin_url( 'plugins.php' ),
			)
		);

		// Add subsubitem with plugin activation toggle
		foreach ( $this->get_plugins() as $plugin_file => $plugin_data ) {
			$action = $plugin_data['status'] == 'active' ? 'deactivate' : 'activate';
			$title  = $plugin_data['status'] == 'active' ? __( 'Deactivate %s', 'admin-bar-plugin-switcher' ) : __( 'Activate %s', 'admin-bar-plugin-switcher' );

			$wp_admin_bar->add_menu(
				array(
					'parent' => 'abps-menu',
					'id'     => 'abps-all-' . md5( $plugin_file ),
					'title'  => sprintf( $title, $plugin_data['name'] ),
					'href'   => wp_nonce_url(
						add_query_arg(
							array(
								'abps-action' => $action,
								'plugin'      => $plugin_file
							),
							$current_page_url
						),
						'abps-'. $action . '-plugin_' . $plugin_file
					),
				)
			);
		}
	}

	/**
	 * Delete cache from transient.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function purge_cache() {
		delete_transient( 'abps_plugins' );
	}

	/**
	 * Get an array with all installed plugins.
	 *
	 * @since 1.0
	 * @access protected
	 */
	protected function get_plugins( $status = '' ) {
		if ( false === ( $plugins = get_transient( 'abps_plugins' ) ) ) {
			// Get raw array of installed plugins
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
			$raw_plugins = get_plugins();

			$plugins = array();

			// Format array of installed plugins
			foreach ( $raw_plugins as $plugin_file => $plugin_data ) {
				$plugins[ $plugin_file ] = array(
					'status' => is_plugin_active( $plugin_file ) ? 'active' : 'inactive',
					'name'   => $plugin_data['Name']
				);
			}

			// Sort array of installed plugins by name
			$plugins = $this->sort_multidimensional_array( $plugins, 'name', 'asc' );

			set_transient( 'abps_plugins', $plugins, HOUR_IN_SECONDS );
		}

		// If there is a activation status set, remove other plugins
		if ( $status ) {
			foreach ( $plugins as $plugin_file => $plugin_data ) {
				if ( $plugin_data['status'] != $status ) {
					unset( $plugins[ $plugin_file ] );
				}
			}
		}

		return $plugins;
	}

	/**
	 * Sort multidimensional array.
	 *
	 * @link http://www.php.net/manual/en/function.sort.php#104464
	 *
	 * @since 1.0
	 * @access protected
	 *
	 * @param array  $array          A multidimensional array that needs to be sorted.
	 * @param string $index          A second level that is used as a base for sorting.
	 * @param string $order          Direction of sorting output.
	 * @param bool   $natsort        Should an array be sorted using a "natural order". Default false.
	 * @param bool   $case_sensitive Should an array be sorted using a case insensitive "natural order". Default false.
	 * @return array $array A sorted multidimensional array.
	 */
	protected function sort_multidimensional_array( $array, $index, $order, $natsort = false, $case_sensitive = false ) {
		if ( is_array( $array ) && count( $array ) > 0 ) {
			foreach ( array_keys( $array ) as $key ) {
				$temp[ $key ] = $array[ $key ][ $index ];
			}

			if ( ! $natsort ) {
				if ( $order == 'asc' ) {
					asort( $temp );
				} else {
					arsort( $temp );
				}
			} else {
				if ( $case_sensitive === true ) {
					natsort( $temp );
				} else {
					natcasesort( $temp );
				}

				if  ( $order != 'asc' ) {
					$temp = array_reverse( $temp, true );
				}
			}

			foreach ( array_keys( $temp ) as $key ) {
				if ( is_numeric( $key ) ) {
					$sorted[] = $array[ $key ];
				} else {
					$sorted[ $key ] = $array[ $key ];
				}
			}

			return $sorted;
		}

		return $array;
	}

	/**
	 * Get URL of current page.
	 *
	 * @link http://www.webcheatsheet.com/PHP/get_current_page_url.php
	 *
	 * @since 1.0
	 * @access protected
	 */
	protected function get_current_page_url() {
		if ( is_ssl() ) {
			$protocol = 'https';
		} else {
			$protocol = 'http';
		}

		if ( $_SERVER["SERVER_PORT"] != '80' ) {
			$host = $_SERVER["SERVER_NAME"] . ':' . $_SERVER["SERVER_PORT"];
		} else {
			$host = $_SERVER["SERVER_NAME"];
		}

		return $protocol . '://' . $host . $_SERVER['REQUEST_URI'];
	}

	/**
	 * Toggle activation for admin bar link.
	 *
	 * @since 1.0
	 * @access protected
	 */
	protected function handle_action() {
		// Only allowed are users with appropiate permisson
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( __( 'You do not have sufficient permissions to deactivate plugins for this site.', 'admin-bar-plugin-switcher' ) );
		}

		$action = $_REQUEST['abps-action'];
		$plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : '';

		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		switch ( $action ) {
			case 'activate':
				// Check if appropiate nonce was set
				check_admin_referer( 'abps-activate-plugin_' . $plugin );

				$result = activate_plugin( $plugin );

				// If there was an error activating plugin, redirect to plugins page or display error
				if ( is_wp_error( $result ) ) {
					if ( 'unexpected_output' == $result->get_error_code() ) {
						$redirect = self_admin_url( 'plugins.php?error=true&charsout=' . strlen( $result->get_error_data() ) . '&plugin=' . $plugin );
						wp_redirect( add_query_arg( '_error_nonce', wp_create_nonce( 'plugin-activation-error_' . $plugin ), $redirect ) );
						exit;
					} else {
						wp_die( $result );
					}
				}
				break;
			case 'deactivate':
				// Check if appropiate nonce was set
				check_admin_referer( 'abps-deactivate-plugin_' . $plugin );

				deactivate_plugins( $plugin );
				break;
		}

		$current_page_url = $this->get_current_page_url();

		// Remove query keys
		$current_page_url = remove_query_arg( array( 'abps-action', 'plugin', '_wpnonce' ), $current_page_url );

		// Redirect to the same page without query keys
		wp_safe_redirect( $current_page_url );
		exit;
	}
}
endif;

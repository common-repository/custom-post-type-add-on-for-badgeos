<?php
/**
 * Plugin Name: Custom Post Type Add-On for BadgeOS
 * Plugin URI: https://wordpress.org/plugins/custom-post-type-add-on-for-badgeos
 * Description: This BadgeOS add-on adds triggers for publishing and commenting oncustom post types.
 * Tags: custom post types, badgeos
 * Author: konnektiv
 * Version: 1.0.2
 * Author URI: https://konnektiv.de/
 * License: GNU AGPLv3
 * Text Domain: badgeos-cpt
 */

/*
 * Copyright © 2016 Konnektiv Kollektiv GmbH
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU Affero General
 * Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/agpl-3.0.html>;.
*/

class BadgeOS_CPT {

	function __construct() {

		// Define plugin constants
		$this->basename       = plugin_basename( __FILE__ );
		$this->directory_path = plugin_dir_path( __FILE__ );

		// Load translations
		load_plugin_textdomain( 'badgeos-cpt', false, dirname( $this->basename ) . '/languages/' );

		// If BadgeOS is unavailable, deactivate our plugin
		add_action( 'admin_notices', array( $this, 'maybe_disable_plugin' ) );
		add_action( 'plugins_loaded', array( $this, 'includes' ), 11 );
	}

	/**
	 * Files to include for BadgeOS integration.
	 *
	 * @since  1.1.1
	 */
	public function includes() {
		if ( $this->meets_requirements() ) {
			require_once( $this->directory_path . '/includes/rules-engine.php' );
			require_once( $this->directory_path . '/includes/steps-ui.php' );
		}
	}

	/**
	 * Check if BadgeOS is available
	 *
	 * @since  0.0.1
	 * @return bool True if BadgeOS is available, false otherwise
	 */
	public static function meets_requirements() {

		if ( class_exists('BadgeOS') && version_compare( BadgeOS::$version, '1.4.0', '>=' ) ) {
			return true;
		} else {
			return false;
		}

	}

	/**
	 * Generate a custom error message and deactivates the plugin if we don't meet requirements
	 *
	 * @since 1.0.0
	 */
	public function maybe_disable_plugin() {
		if ( ! $this->meets_requirements() ) {
			// Display our error
			echo '<div id="message" class="error">';
			echo '<p>' . sprintf( __( 'BadgeOS Custom Post Type Addon requires BadgeOS 1.4.0 or greater and has been <a href="%s">deactivated</a>. Please install and activate BadgeOS and then reactivate this plugin.', 'badgeos-cpt' ), admin_url( 'plugins.php' ) ) . '</p>';
			echo '</div>';

			// Deactivate our plugin
			deactivate_plugins( $this->basename );
		}
	}

}
new BadgeOS_CPT();

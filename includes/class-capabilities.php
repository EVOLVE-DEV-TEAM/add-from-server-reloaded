<?php
/**
 * Capability helpers for browse / import / manage screens.
 *
 * @package AFSRReloaded
 * @since   5.4.0
 */

namespace AFSRReloaded;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central capability checks. Free uses core caps; Pro RBAC adds custom caps.
 *
 * @since 5.4.0
 */
class Capabilities {

	const CAP_BROWSE    = 'afsrreloaded_browse';
	const CAP_IMPORT    = 'afsrreloaded_import';
	const CAP_HISTORY   = 'afsrreloaded_manage_history';
	const CAP_SCHEDULES = 'afsrreloaded_manage_schedules';
	const CAP_REMOTE    = 'afsrreloaded_manage_remote';
	const CAP_SETTINGS  = 'afsrreloaded_manage_settings';

	/**
	 * All custom capability slugs.
	 *
	 * @since 5.4.0
	 *
	 * @return string[]
	 */
	public static function all_caps() {
		return array(
			self::CAP_BROWSE,
			self::CAP_IMPORT,
			self::CAP_HISTORY,
			self::CAP_SCHEDULES,
			self::CAP_REMOTE,
			self::CAP_SETTINGS,
		);
	}

	/**
	 * Whether RBAC mode is active.
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	public static function rbac_enabled() {
		return class_exists( __NAMESPACE__ . '\\Features' ) && Features::enabled( 'rbac' );
	}

	/**
	 * Can browse the media browser.
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	public static function can_browse() {
		if ( self::rbac_enabled() ) {
			return current_user_can( self::CAP_BROWSE ) || current_user_can( 'manage_options' );
		}

		return current_user_can( 'upload_files' );
	}

	/**
	 * Can create / run imports.
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	public static function can_import() {
		if ( self::rbac_enabled() ) {
			return current_user_can( self::CAP_IMPORT ) || current_user_can( 'manage_options' );
		}

		return current_user_can( 'upload_files' );
	}

	/**
	 * Can view import history.
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	public static function can_manage_history() {
		if ( self::rbac_enabled() ) {
			return current_user_can( self::CAP_HISTORY ) || current_user_can( 'manage_options' );
		}

		return current_user_can( 'upload_files' );
	}

	/**
	 * Can manage scheduled imports.
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	public static function can_manage_schedules() {
		if ( self::rbac_enabled() ) {
			return current_user_can( self::CAP_SCHEDULES ) || current_user_can( 'manage_options' );
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Can manage FTP/SFTP/cloud sources.
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	public static function can_manage_remote() {
		if ( self::rbac_enabled() ) {
			return current_user_can( self::CAP_REMOTE ) || current_user_can( 'manage_options' );
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Can change plugin settings (root path, RBAC roles, network).
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	public static function can_manage_settings() {
		if ( self::rbac_enabled() ) {
			return current_user_can( self::CAP_SETTINGS ) || current_user_can( 'manage_options' );
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Ensure Administrator always has custom caps when RBAC is on.
	 *
	 * Role → cap map is stored in option `afsrreloaded_role_caps`.
	 *
	 * @since 5.4.0
	 */
	public static function sync_role_caps() {
		if ( ! self::rbac_enabled() ) {
			return;
		}

		$role_map = get_option( 'afsrreloaded_role_caps', array() );
		if ( ! is_array( $role_map ) ) {
			$role_map = array();
		}

		// Administrator always gets every custom cap.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		$editable = function_exists( 'get_editable_roles' ) ? get_editable_roles() : wp_roles()->roles;
		foreach ( array_keys( $editable ) as $role_key ) {
			if ( 'administrator' === $role_key ) {
				continue;
			}

			$role = get_role( $role_key );
			if ( ! $role ) {
				continue;
			}

			$granted = isset( $role_map[ $role_key ] ) && is_array( $role_map[ $role_key ] )
				? array_map( 'sanitize_key', $role_map[ $role_key ] )
				: array();

			foreach ( self::all_caps() as $cap ) {
				if ( in_array( $cap, $granted, true ) ) {
					$role->add_cap( $cap );
				} else {
					$role->remove_cap( $cap );
				}
			}
		}
	}
}

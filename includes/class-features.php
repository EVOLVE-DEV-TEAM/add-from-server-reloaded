<?php
/**
 * Feature flags for Free vs Pro.
 *
 * @package AFSRReloaded
 * @since   5.3.0
 */

namespace AFSRReloaded;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central feature checks so Pro can unlock capabilities via filters.
 *
 * @since 5.3.0
 */
class Features {

	/**
	 * Whether a named Pro feature is available.
	 *
	 * Free defaults to false for Pro-only features. Pro add-on hooks this filter.
	 *
	 * @since 5.3.0
	 *
	 * @param string $feature Feature slug.
	 * @return bool
	 */
	public static function enabled( $feature ) {
		$feature = sanitize_key( $feature );

		/**
		 * Filters whether an Add From Server Pro feature is enabled.
		 *
		 * @since 5.3.0
		 *
		 * @param bool   $enabled Whether enabled.
		 * @param string $feature Feature slug.
		 */
		return (bool) apply_filters( 'afsrreloaded_pro_feature', false, $feature );
	}

	/**
	 * Whether any Pro feature pack is active.
	 *
	 * @since 5.3.0
	 *
	 * @return bool
	 */
	public static function is_pro() {
		/**
		 * Filters whether the Pro add-on is considered active.
		 *
		 * @since 5.3.0
		 *
		 * @param bool $is_pro Whether Pro is active.
		 */
		return (bool) apply_filters( 'afsrreloaded_is_pro', false );
	}

	/**
	 * Feature list exposed to JavaScript.
	 *
	 * @since 5.3.0
	 *
	 * @return array
	 */
	public static function js_flags() {
		return array(
			'isPro'              => self::is_pro(),
			'background'         => self::enabled( 'background' ),
			'history'            => self::enabled( 'history' ),
			'queueControls'      => self::enabled( 'queue_controls' ),
			'deferThumbnails'    => self::enabled( 'defer_thumbnails' ),
			'scheduledImports'   => self::enabled( 'scheduled_imports' ),
			'folderPreserve'     => self::enabled( 'folder_preserve' ),
			'advancedDuplicates' => self::enabled( 'advanced_duplicates' ),
			'ftpSftp'            => self::enabled( 'ftp_sftp' ),
			'cloudStorage'       => self::enabled( 'cloud_storage' ),
			'rbac'               => self::enabled( 'rbac' ),
			'restApi'            => self::enabled( 'rest_api' ),
			'wpCli'              => self::enabled( 'wp_cli' ),
			'emailNotifications' => self::enabled( 'email_notifications' ),
		);
	}
}

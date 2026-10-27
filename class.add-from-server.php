<?php
/**
 * Add From Server Lite - Main Plugin Class
 *
 * @package   AFSRReloaded
 * @copyright Copyright (c) 2025, Very Good Plugins, https://verygoodplugins.com
 * @license   GPL-3.0+
 * @since     4.0.0
 */

namespace AFSRReloaded;

use WP_Error;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const COOKIE = 'afsrreloaded_path';

/**
 * Main Plugin Class.
 *
 * @since 4.0.0
 */
class Plugin {
	/**
	 * Get uploads directory info for imports with optional subdir prefix.
	 *
	 * @since 5.0.1
	 *
	 * @param int   $time       Unix timestamp.
	 * @param bool  $create_dir Whether to create the directory.
	 * @param array $context    Optional import context passed to the upload subdir filter.
	 * @return array Uploads array (path/url/baseurl/basedir/subdir/error).
	 */
	protected function get_import_uploads_dir( $time, $create_dir = true, $context = array() ) {
		$time_str = is_numeric( $time ) ? gmdate( 'Y-m-d H:i:s', $time ) : $time;
		$uploads  = wp_upload_dir( $time_str, false );
		if ( ! empty( $uploads['error'] ) ) {
			return $uploads;
		}

		$context = is_array( $context ) ? $context : array();

		/**
		 * Filters the uploads subdirectory used for imported files.
		 *
		 * Empty string keeps WordPress year/month folders. Pro Folder_Preserve hooks this.
		 *
		 * @since 5.0.1
		 * @since 5.4.0 Added $context (source_relative, preserve_structure).
		 *
		 * @param string $subdir  Relative subdirectory under uploads basedir.
		 * @param array  $context Import context.
		 */
		$subdir = apply_filters( 'afsrreloaded_upload_subdir', '', $context );
		$subdir = is_string( $subdir ) ? trim( $subdir ) : '';

		if ( '' === $subdir ) {
			if ( $create_dir && ! empty( $uploads['path'] ) && ! wp_mkdir_p( $uploads['path'] ) ) {
				$uploads['error'] = __( 'Unable to create the uploads subdirectory.', 'add-from-server-reloaded' );
			}
			return $uploads;
		}

		$subdir            = '/' . ltrim( $subdir, '/' );
		$uploads['subdir'] = $subdir;
		$uploads['path']   = $uploads['basedir'] . $subdir;
		$uploads['url']    = $uploads['baseurl'] . $subdir;

		if ( $create_dir && ! wp_mkdir_p( $uploads['path'] ) ) {
			$uploads['error'] = __( 'Unable to create the uploads subdirectory.', 'add-from-server-reloaded' );
		}

		return $uploads;
	}

	/**
	 * Singleton instance.
	 *
	 * @since 4.0.0
	 *
	 * @return Plugin
	 */
	public static function instance() {
		static $instance = false;
		$class           = static::class;

		if ( ! $instance ) {
			$instance = new $class();
		}

		return $instance;
	}

	/**
	 * Import processor.
	 *
	 * @since 5.3.0
	 * @var Import_Processor|null
	 */
	protected $import_processor = null;

	/**
	 * Constructor.
	 *
	 * @since 4.0.0
	 */
	protected function __construct() {
		\add_action( 'admin_init', array( $this, 'admin_init' ) );
		\add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		\add_action( 'wp_ajax_afsrreloaded_batch_import', array( $this, 'ajax_batch_import' ) );
		\add_action( 'wp_ajax_afsrreloaded_check_duplicate', array( $this, 'ajax_check_duplicate' ) );

		// Allow additional file types.
		\add_filter( 'upload_mimes', array( $this, 'allow_additional_mimes' ) );

		$this->boot_import_queue();
	}

	/**
	 * Bootstrap chunked / background import subsystem.
	 *
	 * @since 5.3.0
	 */
	protected function boot_import_queue() {
		if ( ! class_exists( __NAMESPACE__ . '\\Installer' ) ) {
			return;
		}

		$this->import_processor = new Import_Processor( $this );
		new Import_Ajax( $this->import_processor );

		\add_action(
			'init',
			static function () {
				Installer::maybe_upgrade();
			},
			20
		);

		// Free-only teasers / locked Pro menu stubs. Pro boots real feature modules
		// from add-from-server-reloaded-pro when licensed (plugins_loaded:20+).
		Pro_Teaser::boot();
	}

	/**
	 * Get the import processor instance.
	 *
	 * @since 5.3.0
	 *
	 * @return Import_Processor|null
	 */
	public function get_import_processor() {
		return $this->import_processor;
	}

	/**
	 * Allow additional MIME types for upload.
	 *
	 * @since 4.0.0
	 *
	 * @param  array $mimes Allowed MIME types.
	 * @return array
	 */
	public function allow_additional_mimes( $mimes ) {
		$mimes['md']   = 'text/markdown';
		$mimes['json'] = 'application/json';

		// Opt-in only: these types are blocked by default for security.
		if ( $this->allows_dangerous_file_types() ) {
			$mimes['svg']   = 'image/svg+xml';
			$mimes['php']   = 'application/x-httpd-php';
			$mimes['phtml'] = 'application/x-httpd-php';
			$mimes['phps']  = 'application/x-httpd-php';
			$mimes['pht']   = 'application/x-httpd-php';
			$mimes['phar']  = 'application/octet-stream';
			$mimes['exe']   = 'application/x-msdownload';
			$mimes['sh']    = 'application/x-sh';
			$mimes['bat']   = 'application/x-msdos-program';
			$mimes['cmd']   = 'application/x-msdos-program';
		}

		return $mimes;
	}

	/**
	 * Whether the site allows importing PHP/SVG and other normally blocked types.
	 *
	 * @since 5.4.4
	 *
	 * @return bool
	 */
	public function allows_dangerous_file_types() {
		return (bool) get_option( 'afsrreloaded_allow_dangerous_types', false );
	}

	/**
	 * Initialize admin hooks...
	 *
	 * @since 4.0.0
	 */
	public function admin_init() {
		// Register JS & CSS with cache busting.
		$core_js  = AFSRRELOADED_PLUGIN_DIR_PATH . 'add-from-server.js';
		$core_css = AFSRRELOADED_PLUGIN_DIR_PATH . 'add-from-server.css';

		\wp_register_script(
			'add-from-server-reloaded',
			\plugins_url( '/add-from-server.js', __FILE__ ),
			array( 'jquery' ),
			file_exists( $core_js ) ? (string) filemtime( $core_js ) : AFSRRELOADED_VERSION,
			true
		);

		\wp_register_style(
			'add-from-server-reloaded',
			\plugins_url( '/add-from-server.css', __FILE__ ),
			array(),
			file_exists( $core_css ) ? (string) filemtime( $core_css ) : AFSRRELOADED_VERSION
		);

		$wizard_css = AFSRRELOADED_PLUGIN_DIR_PATH . 'assets/css/admin-styles.css';
		$wizard_js  = AFSRRELOADED_PLUGIN_DIR_PATH . 'assets/js/admin-scripts.js';

		\wp_register_style(
			'afsr-admin-ui',
			\plugins_url( 'assets/css/admin-styles.css', __FILE__ ),
			array( 'common', 'dashicons', 'add-from-server-reloaded' ),
			file_exists( $wizard_css ) ? (string) filemtime( $wizard_css ) : AFSRRELOADED_VERSION
		);

		\wp_register_script(
			'afsr-admin-ui',
			\plugins_url( 'assets/js/admin-scripts.js', __FILE__ ),
			array( 'jquery', 'add-from-server-reloaded' ),
			file_exists( $wizard_js ) ? (string) filemtime( $wizard_js ) : AFSRRELOADED_VERSION,
			true
		);

		// Localize script for AJAX.
		\wp_localize_script(
			'add-from-server-reloaded',
			'afsrreloadedData',
			array(
				'ajaxurl'         => \admin_url( 'admin-ajax.php' ),
				'nonce'           => \wp_create_nonce( 'afsrreloaded_import' ),
				'historyUrl'      => \admin_url( 'admin.php?page=add-from-server-reloaded-history' ),
				'proUrl'          => 'https://elearningevolve.com/products/add-from-server-pro/',
				'chunkSize'       => (int) apply_filters( 'afsrreloaded_import_chunk_size', Import_Processor::DEFAULT_CHUNK_SIZE ),
				'features'        => Features::js_flags(),
				'processing'      => __( 'Processing...', 'add-from-server-reloaded' ),
				'scanning'        => __( 'Scanning folders...', 'add-from-server-reloaded' ),
				'importing'       => __( 'Importing files...', 'add-from-server-reloaded' ),
				'complete'        => __( 'Import Complete!', 'add-from-server-reloaded' ),
				'cancelled'       => __( 'Import cancelled.', 'add-from-server-reloaded' ),
				'paused'          => __( 'Import paused.', 'add-from-server-reloaded' ),
				'error'           => __( 'An error occurred. Please try again.', 'add-from-server-reloaded' ),
				'confirmLarge'    => __( 'This may take a while. Continue?', 'add-from-server-reloaded' ),
				'selectSomething' => __( 'Please select at least one file or folder to import.', 'add-from-server-reloaded' ),
				'backgroundHint'  => __( 'You can leave this page; the import will continue in the background.', 'add-from-server-reloaded' ),
				'selectToImport'  => __( 'Select files to import', 'add-from-server-reloaded' ),
				'selectedSuffix'  => __( 'selected', 'add-from-server-reloaded' ),
				'i18n'            => array(
					'imported'    => __( 'Imported', 'add-from-server-reloaded' ),
					'duplicates'  => __( 'Duplicates', 'add-from-server-reloaded' ),
					'errors'      => __( 'Errors', 'add-from-server-reloaded' ),
					'skipped'     => __( 'Skipped', 'add-from-server-reloaded' ),
					'progress'    => __( 'Progress', 'add-from-server-reloaded' ),
					'pause'       => __( 'Pause', 'add-from-server-reloaded' ),
					'resume'      => __( 'Resume', 'add-from-server-reloaded' ),
					'cancel'      => __( 'Cancel', 'add-from-server-reloaded' ),
					'retry'       => __( 'Retry failed', 'add-from-server-reloaded' ),
					'viewHistory' => __( 'View import history', 'add-from-server-reloaded' ),
				),
			)
		);

		\add_filter( 'plugin_action_links_' . \plugin_basename( AFSRRELOADED_PLUGIN_FILE ), array( $this, 'add_upload_link' ) );

		// Handle the path selection early.
		$this->path_selection_cookie();
	}

	/**
	 * Register admin menu.
	 *
	 * @since 4.0.0
	 */
	public function admin_menu() {
		$cap = Capabilities::rbac_enabled() ? Capabilities::CAP_BROWSE : 'upload_files';

		$menu_label = Features::is_pro()
			? __( 'AFS Pro', 'add-from-server-reloaded' )
			: __( 'AFS Lite', 'add-from-server-reloaded' );

		$page_slug = \add_menu_page(
			__( 'Add From Server Lite', 'add-from-server-reloaded' ),
			$menu_label,
			$cap,
			'add-from-server-reloaded',
			array( $this, 'menu_page' ),
			'dashicons-upload',
			30
		);

		$settings_hook = \add_submenu_page(
			'add-from-server-reloaded',
			__( 'Settings', 'add-from-server-reloaded' ),
			__( 'Settings', 'add-from-server-reloaded' ),
			Capabilities::rbac_enabled() ? Capabilities::CAP_SETTINGS : 'manage_options',
			'add-from-server-reloaded-settings',
			array( $this, 'render_settings_page' )
		);

		\add_action(
			'load-' . $page_slug,
			function () {
				Pro_Teaser::enqueue_browse_layout_critical_css();
				\wp_enqueue_style( 'dashicons' );
				\wp_enqueue_style( 'add-from-server-reloaded' );
				\wp_enqueue_script( 'add-from-server-reloaded' );
				\wp_enqueue_style( 'afsr-admin-ui' );
				\wp_enqueue_script( 'afsr-admin-ui' );

				// Handle settings save.
				$this->handle_settings_save();
			}
		);

		if ( $settings_hook ) {
			\add_action(
				'load-' . $settings_hook,
				function () {
					Pro_Teaser::enqueue_locked_ui_assets();
					$this->handle_settings_save();
				}
			);
		}

		// Set page title to avoid deprecation warnings.
		\add_filter( 'admin_title', array( $this, 'set_admin_page_title' ), 10, 2 );
	}

	/**
	 * Set admin page title.
	 *
	 * @since 5.0.0
	 *
	 * @param string $admin_title The page title.
	 * @param string $title       The original title.
	 * @return string
	 */
	public function set_admin_page_title( $admin_title, $title ) {
		$screen = \get_current_screen();
		if ( $screen && 'add-from-server-reloaded' === $screen->id ) {
			return \__( 'Add From Server Lite', 'add-from-server-reloaded' ) . $admin_title;
		}
		return $admin_title;
	}

	/**
	 * Add plugin action links.
	 *
	 * @since 4.0.0
	 *
	 * @param  array $links Plugin action links.
	 * @return array
	 */
	public function add_upload_link( $links ) {
		$extra = array();

		if ( Capabilities::can_browse() ) {
			$extra[] = '<a href="' . esc_url( admin_url( 'admin.php?page=add-from-server-reloaded' ) ) . '">' . esc_html__( 'Import Files', 'add-from-server-reloaded' ) . '</a>';
		}

		$can_settings = Capabilities::rbac_enabled()
			? current_user_can( Capabilities::CAP_SETTINGS )
			: current_user_can( 'manage_options' );

		if ( $can_settings ) {
			$extra[] = '<a href="' . esc_url( admin_url( 'admin.php?page=add-from-server-reloaded-settings' ) ) . '">' . esc_html__( 'Settings', 'add-from-server-reloaded' ) . '</a>';
		}

		if ( ! empty( $extra ) ) {
			$links = array_merge( $extra, $links );
		}

		if ( ! Features::is_pro() ) {
			$links['afsr_get_pro'] = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer" style="color:#dfa91e;font-weight:700;">%2$s</a>',
				esc_url( Pro_Teaser::UPGRADE_URL ),
				esc_html__( 'Get AFS Pro', 'add-from-server-reloaded' )
			);
		}

		return $links;
	}

	/**
	 * Render the menu page.
	 *
	 * @since 4.0.0
	 */
	public function menu_page() {
		// Set page title.
		global $title;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress admin menu pages expect $title.
		$title = \__( 'Add From Server Lite', 'add-from-server-reloaded' );

		// Legacy non-JS fallback import (chunked AJAX is preferred when JS is available).
		if ( isset( $_POST['import'] ) && ( ! empty( $_POST['files'] ) || ! empty( $_POST['folders'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside handle_imports().
			$this->handle_imports();
		}

		echo '<div class="wrap afsr-admin-wrap">';
		echo '<h1>' . esc_html__( 'Add From Server Lite', 'add-from-server-reloaded' ) . '</h1>';

		$this->outdated_options_notice();
		$this->main_content();

		echo '</div>';
	}

	/**
	 * Dedicated Settings screen (root directory, risk options, license).
	 *
	 * @since 5.4.3
	 */
	public function render_settings_page() {
		if ( ! Capabilities::can_manage_settings() ) {
			\wp_die( esc_html__( 'You do not have permission to access this page.', 'add-from-server-reloaded' ) );
		}

		$root            = $this->get_root();
		$allow_dangerous = $this->allows_dangerous_file_types();
		Pro_Teaser::enqueue_locked_ui_assets();
		?>
		<div class="wrap afsr-admin-wrap">
			<div id="afsr-admin-app" class="afsr-wrap afsr-settings-wrap" style="<?php echo esc_attr( Pro_Teaser::support_app_inline_style() ); ?>">
				<div class="afsr-page-header">
					<h1 class="afsr-page-title"><?php esc_html_e( 'Settings', 'add-from-server-reloaded' ); ?></h1>
					<p class="afsr-page-subtitle"><?php esc_html_e( 'Configure import root, file safety options, and Pro license.', 'add-from-server-reloaded' ); ?></p>
				</div>

				<?php Pro_Teaser::support_layout_open(); ?>
						<div id="afsr-pro-license" class="afsr-settings-license">
							<?php if ( Pro_Teaser::is_pro_plugin_present() ) : ?>
								<div class="afsr-card">
									<?php
									/**
									 * Renders the Pro license panel at the top of Settings.
									 *
									 * @since 5.4.4
									 */
									do_action( 'afsrreloaded_settings_license_panel' );
									?>
								</div>
							<?php else : ?>
								<?php Pro_Teaser::render_settings_section(); ?>
							<?php endif; ?>
						</div>

						<div class="afsr-card">
							<?php \settings_errors( 'afsrreloaded_settings' ); ?>
							<form method="post" action="">
								<?php \wp_nonce_field( 'afsrreloaded_settings' ); ?>
								<table class="form-table" style="margin-top:0;">
									<tr>
										<th scope="row">
											<label for="afsrreloaded_root_directory"><?php esc_html_e( 'Root Directory Path', 'add-from-server-reloaded' ); ?></label>
										</th>
										<td>
											<input
												type="text"
												name="afsrreloaded_root_directory"
												id="afsrreloaded_root_directory"
												class="regular-text"
												placeholder="/var/www/your-files/"
												value="<?php echo esc_attr( $root ? rtrim( $root, '/' ) : '' ); ?>"
											/>
											<p class="description">
												<?php esc_html_e( 'The path above is your current root directory. Change it to browse files from a different location.', 'add-from-server-reloaded' ); ?>
											</p>
										</td>
									</tr>
									<tr>
										<th scope="row">
											<?php esc_html_e( 'Blocked file types', 'add-from-server-reloaded' ); ?>
										</th>
										<td>
											<label for="afsrreloaded_allow_dangerous_types">
												<input
													type="checkbox"
													name="afsrreloaded_allow_dangerous_types"
													id="afsrreloaded_allow_dangerous_types"
													value="1"
													<?php checked( $allow_dangerous ); ?>
												/>
												<?php esc_html_e( 'Allow PHP, SVG, and other normally blocked file types', 'add-from-server-reloaded' ); ?>
											</label>
											<p class="description" style="color:#b32d2e;max-width:42em;">
												<?php esc_html_e( 'Enable at your own risk. PHP and similar scripts can execute on the server; SVG can contain malicious code. Only turn this on if you trust every file you import.', 'add-from-server-reloaded' ); ?>
											</p>
										</td>
									</tr>
								</table>
								<p>
									<input type="submit" name="afsrreloaded_save_settings" class="afsr-btn afsr-btn-primary" value="<?php esc_attr_e( 'Save Changes', 'add-from-server-reloaded' ); ?>" />
									<?php if ( \get_option( 'afsrreloaded_root_directory', '' ) ) : ?>
										<input type="submit" name="afsrreloaded_save_settings" class="afsr-btn afsr-btn-secondary" value="<?php esc_attr_e( 'Reset to Default', 'add-from-server-reloaded' ); ?>"
											onclick="document.getElementById('afsrreloaded_root_directory').value=''; return true;" />
									<?php endif; ?>
								</p>
							</form>
						</div>
				<?php Pro_Teaser::support_layout_close(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Get the root directory for file browsing.
	 *
	 * @since 4.0.0
	 *
	 * @return string|false Root path or false on error.
	 */
	public function get_root() {
		// Priority order for root directory:
		// 1. User-saved setting in WordPress options.
		// 2. The 'ADD_FROM_SERVER_RELOADED' constant.
		// 3. Parent of ABSPATH (or WP_CONTENT_DIR when content is outside ABSPATH).
		// 4. Fallbacks below if that path is not readable (ABSPATH, then uploads).

		$saved_root = \get_option( 'afsrreloaded_root_directory', '' );

		if ( ! empty( $saved_root ) ) {
			$root = $saved_root;
		} elseif ( defined( 'ADD_FROM_SERVER_RELOADED' ) ) {
			$root = ADD_FROM_SERVER_RELOADED;
		} elseif ( defined( 'ADD_FROM_SERVER' ) ) {
			// Backwards compatibility.
			$root = ADD_FROM_SERVER;
		} elseif ( str_starts_with( WP_CONTENT_DIR, ABSPATH ) ) {
			$root = dirname( ABSPATH );
		} else {
			$root = dirname( WP_CONTENT_DIR );
		}

		// Normalize: Remove trailing slash for consistent path handling.
		$root = rtrim( $root, '/' );

		// Precautions: Validate root path exists and is readable.
		// Check open_basedir first so is_dir()/is_readable() never run on a forbidden path
		// (avoids PHP warnings on Studio / shared hosts; fallback chain unchanged).
		if ( ! Path_Guard::is_path_allowed( $root ) || ! is_dir( $root ) || ! is_readable( $root ) ) {
			// Guessed path wasn't accessible (common on locked-down shared hosting).
			// Fall back to ABSPATH — this is always readable since WP itself runs from here.
			$root = rtrim( ABSPATH, '/' );

			if ( ! Path_Guard::is_path_allowed( $root ) || ! is_dir( $root ) || ! is_readable( $root ) ) {
				// Last resort: uploads folder is always readable/writable by WP.
				$uploads = wp_upload_dir( null, false );
				$root    = ! empty( $uploads['basedir'] ) ? rtrim( $uploads['basedir'], '/' ) : false;
			}
		}

		// Legacy placeholder guard from old frmsvr_root %tokens%.
		// Do not blank (or re-root) users who are allowed to browse — that broke
		// Access Control Editors (no unfiltered_html) and produced doubled paths
		// when a prior cookie was combined with an uploads-only fallback root.
		if (
			$root &&
			empty( $saved_root ) &&
			! defined( 'ADD_FROM_SERVER_RELOADED' ) &&
			! defined( 'ADD_FROM_SERVER' ) &&
			str_contains( (string) get_option( 'frmsvr_root', '%' ), '%' ) &&
			is_admin() &&
			is_user_logged_in() &&
			! wp_doing_cron() &&
			! current_user_can( 'unfiltered_html' ) &&
			! Capabilities::can_browse()
		) {
			$root = false;
		}

		/**
		 * Filters the root directory path.
		 *
		 * @since 4.0.0
		 *
		 * @param string|false $root Root directory path or false.
		 */
		return apply_filters( 'afsrreloaded_root_directory', $root );
	}

	/**
	 * Handle settings save.
	 *
	 * @since 4.0.6
	 */
	protected function handle_settings_save() {
		if ( ! isset( $_POST['afsrreloaded_save_settings'] ) ) {
			return;
		}

		if ( ! Capabilities::can_manage_settings() ) {
			return;
		}

		\check_admin_referer( 'afsrreloaded_settings' );

		$allow_dangerous = ! empty( $_POST['afsrreloaded_allow_dangerous_types'] );
		\update_option( 'afsrreloaded_allow_dangerous_types', $allow_dangerous ? 1 : 0 );

		$new_root = isset( $_POST['afsrreloaded_root_directory'] ) ? \sanitize_text_field( \wp_unslash( $_POST['afsrreloaded_root_directory'] ) ) : '';

		if ( empty( $new_root ) ) {
			\delete_option( 'afsrreloaded_root_directory' );
			\add_settings_error(
				'afsrreloaded_settings',
				'afsrreloaded_root_cleared',
				__( 'Settings saved. Root directory reset to default.', 'add-from-server-reloaded' ),
				'success'
			);
			return;
		}

		// Validate the path (normalize by removing trailing slash).
		$new_root = rtrim( $new_root, '/' );

		if ( ! \is_dir( $new_root ) ) {
			\add_settings_error(
				'afsrreloaded_settings',
				'afsrreloaded_invalid_path',
				\sprintf(
					/* translators: %s: directory path */
					__( 'Error: The directory "%s" does not exist on your server.', 'add-from-server-reloaded' ),
					\esc_html( $new_root )
				),
				'error'
			);
			return;
		}

		if ( ! \is_readable( $new_root ) ) {
			\add_settings_error(
				'afsrreloaded_settings',
				'afsrreloaded_not_readable',
				\sprintf(
					/* translators: %s: directory path */
					__( 'Error: The directory "%s" is not readable. Please check file permissions.', 'add-from-server-reloaded' ),
					\esc_html( $new_root )
				),
				'error'
			);
			return;
		}

		\update_option( 'afsrreloaded_root_directory', $new_root );
		\add_settings_error(
			'afsrreloaded_settings',
			'afsrreloaded_root_saved',
			\sprintf(
				/* translators: %s: directory path */
				__( 'Settings saved. Now browsing: %s', 'add-from-server-reloaded' ),
				'<code>' . \esc_html( $new_root ) . '</code>'
			),
			'success'
		);
	}

	/**
	 * Handle path selection cookie.
	 *
	 * @since 4.0.0
	 */
	public function path_selection_cookie() {
		if ( isset( $_REQUEST['path'] ) && Capabilities::can_browse() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// Sanitize the path.
			$path = sanitize_text_field( wp_unslash( $_REQUEST['path'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			// Verify path is within allowed root.
			$root = $this->get_root();
			if ( ! $root ) {
				return;
			}

			// Root / reset.
			if ( '/' === $path || '' === $path ) {
				$_COOKIE[ COOKIE ] = '/';
				$admin_url_parts   = wp_parse_url( admin_url() );
				setcookie(
					COOKIE,
					'/',
					time() + 30 * DAY_IN_SECONDS,
					isset( $admin_url_parts['path'] ) ? $admin_url_parts['path'] : '/',
					isset( $admin_url_parts['host'] ) ? $admin_url_parts['host'] : '',
					'https' === ( isset( $admin_url_parts['scheme'] ) ? $admin_url_parts['scheme'] : 'http' ),
					true
				);
				return;
			}

			$full_path = realpath( trailingslashit( $root ) . ltrim( $path, '/' ) );

			// Security: Ensure the path is within root (boundary, not prefix-only).
			if ( ! $full_path || ! Path_Guard::path_has_root_boundary( $full_path, $root ) ) {
				return;
			}

			$_COOKIE[ COOKIE ] = $path;

			$admin_url_parts = wp_parse_url( admin_url() );
			setcookie(
				COOKIE,
				$path,
				time() + 30 * DAY_IN_SECONDS,
				isset( $admin_url_parts['path'] ) ? $admin_url_parts['path'] : '/',
				isset( $admin_url_parts['host'] ) ? $admin_url_parts['host'] : '',
				'https' === ( isset( $admin_url_parts['scheme'] ) ? $admin_url_parts['scheme'] : 'http' ),
				true
			);
		}
	}

	/**
	 * Handle file imports.
	 *
	 * @since 4.0.0
	 */
	public function handle_imports() {

		if ( empty( $_POST['files'] ) && empty( $_POST['folders'] ) ) {
			return;
		}

		check_admin_referer( 'afsrreloaded_import' );

		$files          = isset( $_POST['files'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['files'] ) ) : array();
		$folders        = isset( $_POST['folders'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['folders'] ) ) : array();
		$selected_files = $files;

			$root = $this->get_root();
		if ( ! $root ) {
			wp_die( esc_html__( 'Unable to determine root directory. Please check your configuration.', 'add-from-server-reloaded' ) );
		}

		// Get all files from selected folders.
		$folder_files  = array();
		$blocked_files = array();
		foreach ( $folders as $folder ) {
			$folder_path  = trailingslashit( $root ) . ltrim( $folder, '/' );
			$folder_files = array_merge( $folder_files, $this->get_files_from_folder( $folder_path, $root, $blocked_files ) );
		}

		// Merge folder files with individually selected files.
		$files = array_merge( $files, $folder_files );

		// Enable output buffering for progress updates.
		if ( ! defined( 'DOING_AJAX' ) ) {
			flush();
			if ( function_exists( 'wp_ob_end_flush_all' ) ) {
				wp_ob_end_flush_all();
			}
		}

		$imported         = 0;
		$errors           = 0;
		$duplicates       = 0;
		$skipped_selected = array();
		$error_files      = array();
		$imported_files   = array();
		$duplicate_files  = array();

		foreach ( (array) $files as $file ) {
				$filename = trailingslashit( $root ) . ltrim( $file, '/' );

			// Security: Verify the real path to prevent directory traversal (root + trailing slash).
			$realpath = realpath( $filename );

			if ( ! $realpath || ! Path_Guard::path_has_root_boundary( $realpath, $root ) ) {
				++$errors;
				$error_files[] = array(
					'filename' => basename( $file ),
					'message'  => __( 'Security error: file is outside the allowed directory', 'add-from-server-reloaded' ),
				);
					continue;
			}

			// If user selected specific files, skip restricted ones with a clear message.
			if ( ! empty( $selected_files ) && in_array( $file, $selected_files, true ) && $this->is_restricted_file( $realpath ) ) {
				$skipped_selected[] = array(
					'filename' => basename( $file ),
					'message'  => __( 'This file was not imported due to security restrictions.', 'add-from-server-reloaded' ),
				);
				continue;
			}

			$id = $this->handle_import_file( $realpath );

			if ( \is_wp_error( $id ) ) {
				if ( 'file_exists' === $id->get_error_code() ) {
					++$duplicates;
					$duplicate_files[] = array(
						'filename' => basename( $file ),
						'message'  => $id->get_error_message(),
					);
				} else {
					++$errors;
					$error_files[] = array(
						'filename' => basename( $file ),
						'message'  => $id->get_error_message(),
					);
				}
			} else {
				++$imported;
				$imported_files[] = array(
					'filename' => basename( $file ),
					'id'       => $id,
				);
			}

			if ( ! defined( 'DOING_AJAX' ) ) {
				flush();
			}
		}

		// Single summary message.
		if ( $imported > 0 || $errors > 0 || $duplicates > 0 || ! empty( $blocked_files ) || ! empty( $skipped_selected ) ) {
			$message_class = ( $errors > 0 ) ? 'notice-warning' : 'notice-success';
			echo '<div class="notice ' . \esc_attr( $message_class ) . '"><p>';

			if ( $imported > 0 ) {
				echo '<strong>';
				printf(
					/* translators: %d: number of files */
					\esc_html( \_n( '%d file imported successfully.', '%d files imported successfully.', $imported, 'add-from-server-reloaded' ) ),
					absint( $imported )
				);
				echo '</strong>';

				if ( ! empty( $folders ) ) {
					// Show uploaded folder names when folder import is used.
					echo '<br><small>';
					foreach ( $folders as $folder ) {
						$folder_name = basename( $folder );
						if ( ! $folder_name ) {
							$folder_name = $folder;
						}
						echo '<strong>' . esc_html( $folder_name ) . '</strong> ' . esc_html__( 'folder uploaded.', 'add-from-server-reloaded' ) . '<br>';
					}
					echo '</small>';
				}

				if ( ! empty( $imported_files ) ) {
					// Show imported file names (works for folder and direct selection).
					echo '<br><small>';
					foreach ( $imported_files as $file ) {
						$edit_link = admin_url( 'post.php?post=' . $file['id'] . '&action=edit' );
						echo '<a href="' . esc_url( $edit_link ) . '" target="_blank">' . esc_html( $file['filename'] ) . '</a><br>';
					}
					echo '</small>';
				}
			}

			if ( $duplicates > 0 ) {
				if ( $imported > 0 ) {
					echo '<br><br>';
				}
				echo '<strong>';
				printf(
					/* translators: %d: number of duplicates */
					esc_html( _n( '%d file already exists in Media Library.', '%d files already exist in Media Library.', $duplicates, 'add-from-server-reloaded' ) ),
					absint( $duplicates )
				);
				echo '</strong>';

				if ( ! empty( $duplicate_files ) ) {
					echo '<br><small>';
					foreach ( $duplicate_files as $dup ) {
						echo '<strong>' . esc_html( $dup['filename'] ) . '</strong>: ' . wp_kses_post( $dup['message'] ) . '<br>';
					}
					echo '</small>';
				}
			}

			if ( $errors > 0 ) {
				if ( $imported > 0 ) {
					echo '<br><br>';
				}
				echo '<strong>';
				printf(
					/* translators: %d: number of errors */
					\esc_html( \_n( '%d file failed.', '%d files failed.', $errors, 'add-from-server-reloaded' ) ),
					absint( $errors )
				);
				echo '</strong>';

				// Show error details.
				if ( ! empty( $error_files ) && empty( $folders ) && ! empty( $selected_files ) ) {
					echo '<br><small>';
					foreach ( $error_files as $error ) {
						echo '<strong>' . \esc_html( $error['filename'] ) . '</strong>: ' . \wp_kses_post( $error['message'] ) . '<br>';
					}
					echo '</small>';
				}
			}

			if ( ! empty( $blocked_files ) ) {
				if ( $imported > 0 || $errors > 0 || $duplicates > 0 ) {
					echo '<br><br>';
				}
				echo '<strong>';
				printf(
					/* translators: %d: number of blocked files */
					\esc_html( \_n( '%d file was skipped for security reasons.', '%d files were skipped for security reasons.', count( $blocked_files ), 'add-from-server-reloaded' ) ),
					absint( count( $blocked_files ) )
				);
				echo '</strong>';
				$restricted_list = implode( ', ', array_map( 'strtoupper', $this->get_restricted_extensions() ) );
				echo '<br><small>';
				printf(
					/* translators: %s: comma-separated list of restricted extensions */
					esc_html__( 'Some files in the selected folders were not imported because their file types are not allowed: %s.', 'add-from-server-reloaded' ),
					esc_html( $restricted_list )
				);
				echo '</small>';
			}

			if ( ! empty( $skipped_selected ) ) {
				if ( $imported > 0 || $errors > 0 || $duplicates > 0 ) {
					echo '<br><br>';
				}
				echo '<strong>' . esc_html__( 'Some files were skipped for security reasons.', 'add-from-server-reloaded' ) . '</strong>';
				echo '<br><small>';
				foreach ( $skipped_selected as $skip ) {
					echo '<strong>' . esc_html( $skip['filename'] ) . '</strong>: ' . esc_html( $skip['message'] ) . '<br>';
				}
				echo '</small>';
			}

				echo '</p></div>';
		}
	}

	/**
	 * Get restricted file extensions.
	 *
	 * @since 5.0.1
	 *
	 * @return array
	 */
	protected function get_restricted_extensions() {
		if ( $this->allows_dangerous_file_types() ) {
			return array();
		}

		return array( 'php', 'phtml', 'phps', 'pht', 'phar', 'exe', 'sh', 'bat', 'cmd' );
	}

	/**
	 * Check if a file is restricted from import.
	 *
	 * @since 5.0.1
	 *
	 * @param string $path File path.
	 * @return bool True if restricted.
	 */
	public function is_restricted_file( $path ) {
		$dangerous_extensions = $this->get_restricted_extensions();
		$ext                  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( in_array( $ext, $dangerous_extensions, true ) ) {
			return true;
		}

		// Admin opted in: skip WordPress MIME denylist for unknown/extra types (SVG, etc.).
		if ( $this->allows_dangerous_file_types() ) {
			return false;
		}

		$wp_filetype = \wp_check_filetype( $path, null );
		$type        = $wp_filetype['type'];
		$ext_check   = $wp_filetype['ext'];

		if ( ( ! $type || ! $ext_check ) && ! current_user_can( 'unfiltered_upload' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Recursively get all files from a folder.
	 *
	 * @since 4.0.5
	 *
	 * @param  string $folder_path Absolute folder path.
	 * @param  string $root Root directory path.
	 * @param  array  $blocked_files Restricted relative paths collected during recursion.
	 * @return array Array of relative file paths.
	 */
	protected function get_files_from_folder( $folder_path, $root, &$blocked_files = array() ) {
		$files = array();

		// Security: Verify the real path (directory boundary, not prefix-only).
		$realpath = \realpath( $folder_path );
		if ( ! $realpath || ! Path_Guard::path_has_root_boundary( $realpath, $root ) ) {
			return $files;
		}

		$items = \glob( $realpath . '/*' );
		if ( ! $items ) {
			return $files;
		}

		foreach ( $items as $item ) {
			// Skip hidden files and directories.
			if ( '.' === \basename( $item )[0] ) {
				continue;
			}

			$relative_item = Path_Guard::absolute_to_relative( $item, $root );
			if ( false === $relative_item ) {
				continue;
			}

			if ( \is_dir( $item ) ) {
				// Recursively get files from subdirectory.
				$files = \array_merge( $files, $this->get_files_from_folder( $item, $root, $blocked_files ) );
			} elseif ( \is_file( $item ) ) {
				if ( $this->is_restricted_file( $item ) ) {
					$blocked_files[] = $relative_item;
					continue;
				}
				$files[] = $relative_item;
			}
		}

		return $files;
	}

	/**
	 * AJAX handler for batch imports.
	 *
	 * @since 4.0.0
	 */
	public function ajax_batch_import() {
		check_ajax_referer( 'afsrreloaded_import', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to upload files.', 'add-from-server-reloaded' ) ) );
		}

		$file = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';

		if ( empty( $file ) ) {
			wp_send_json_error( array( 'message' => __( 'No file specified.', 'add-from-server-reloaded' ) ) );
		}

		$root = $this->get_root();
		if ( ! $root ) {
			wp_send_json_error( array( 'message' => __( 'Unable to determine root directory.', 'add-from-server-reloaded' ) ) );
		}

		$filename = trailingslashit( $root ) . ltrim( $file, '/' );
		$realpath = realpath( $filename );

		// Security: Verify the real path (directory boundary, not prefix-only).
		if ( ! $realpath || ! Path_Guard::path_has_root_boundary( $realpath, $root ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
					/* translators: %s: file name */
						__( 'Security error: %s is outside the allowed directory.', 'add-from-server-reloaded' ),
						basename( $file )
					),
				)
			);
		}

		$id = $this->handle_import_file( $realpath );

		if ( is_wp_error( $id ) ) {
			wp_send_json_error(
				array(
					'message' => $id->get_error_message(),
					'file'    => basename( $file ),
				)
			);
		} else {
			wp_send_json_success(
				array(
					'message'       => sprintf(
					/* translators: %s: file name */
						__( '%s imported successfully.', 'add-from-server-reloaded' ),
						basename( $file )
					),
					'file'          => basename( $file ),
					'attachment_id' => $id,
				)
			);
		}
	}

	/**
	 * AJAX handler for checking duplicates.
	 *
	 * @since 4.0.0
	 */
	public function ajax_check_duplicate() {
		check_ajax_referer( 'afsrreloaded_import', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'add-from-server-reloaded' ) ) );
		}

		$file = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';

		if ( empty( $file ) ) {
			wp_send_json_error( array( 'message' => __( 'No file specified.', 'add-from-server-reloaded' ) ) );
		}

		$resolved = \realpath( $file );
		$root     = $this->get_root();
		if (
			false === $resolved
			|| ! \is_file( $resolved )
			|| ! $root
			|| ! Path_Guard::is_under_root( $root, $resolved )
		) {
			wp_send_json_error( array( 'message' => __( 'Invalid file path.', 'add-from-server-reloaded' ) ) );
		}

		$resolved  = \wp_normalize_path( $resolved );
		$duplicate = $this->check_if_duplicate( $resolved );

		if ( $duplicate ) {
			wp_send_json_success(
				array(
					'is_duplicate' => true,
					'message'      => sprintf(
					/* translators: 1: file name, 2: attachment ID */
						__( '%1$s already exists in the media library (ID: %2$d).', 'add-from-server-reloaded' ),
						basename( $resolved ),
						$duplicate
					),
				)
			);
		} else {
			wp_send_json_success(
				array(
					'is_duplicate' => false,
				)
			);
		}
	}

	/**
	 * Get file hash to detect duplicates reliably.
	 *
	 * Skips hashing when the file is larger than the configured byte cap so a
	 * single request cannot burn the full PHP time budget on md5_file() (DoS
	 * hardening). Oversized files still import; duplicate checks then fall back
	 * to path / filename matching only. This does not make large imports faster.
	 *
	 * @since 5.0.2
	 * @since 6.0.0 Skip hash above afsrreloaded_file_hash_max_bytes (default 64 MiB).
	 *
	 * @param  string $file File path.
	 * @return string|false File MD5 hash or false on error / over size cap.
	 */
	protected function get_file_hash( $file ) {
		if ( ! \is_string( $file ) || '' === $file ) {
			return false;
		}

		// Require a normal readable file (rejects dirs / special devices like /dev/urandom).
		if ( ! \is_file( $file ) || ! \is_readable( $file ) ) {
			return false;
		}

		$size = \filesize( $file );
		if ( false === $size || $size < 0 ) {
			return false;
		}

		/**
		 * Max bytes hashed for duplicate detection (0 = no cap).
		 *
		 * @since 6.0.0
		 *
		 * @param int    $max_bytes Default 64 MiB.
		 * @param string $file      Absolute file path.
		 */
		$max_bytes = (int) \apply_filters( 'afsrreloaded_file_hash_max_bytes', 64 * 1024 * 1024, $file );
		if ( $max_bytes > 0 && (int) $size > $max_bytes ) {
			return false;
		}

		$hash = md5_file( $file );
		return $hash ? $hash : false;
	}

	/**
	 * Check if file is already in media library.
	 *
	 * @since 4.0.0
	 *
	 * @param  string $file File path.
	 * @return int|false Attachment ID if duplicate found, false otherwise.
	 */
	protected function check_if_duplicate( $file ) {
		global $wpdb;

		$file     = \wp_normalize_path( $file );
		$filename = \basename( $file );

		// First, check by file hash (most reliable).
		$file_hash = $this->get_file_hash( $file );
		if ( $file_hash ) {
			$attachment_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_afsrreloaded_file_hash' AND meta_value = %s LIMIT 1",
					$file_hash
				)
			);
			if ( $attachment_id ) {
				return (int) $attachment_id;
			}
		}

		// Also check by filename in uploads directory.
		$uploads = \wp_upload_dir( null, false );
		if ( preg_match( '|^' . preg_quote( \wp_normalize_path( $uploads['basedir'] ), '|' ) . '(.*)$|i', $file, $mat ) ) {
			$attached_file = ltrim( $mat[1], '/' );

			// Query for existing attachment by exact path.
			$attachment_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
					$attached_file
				)
			);

			if ( $attachment_id ) {
				return (int) $attachment_id;
			}
		}

		// Check by filename in media library.
		$attachment_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p 
				WHERE p.post_type = 'attachment' 
				AND p.guid LIKE %s 
				LIMIT 1",
				'%/' . $wpdb->esc_like( $filename )
			)
		);

		return $attachment_id ? (int) $attachment_id : false;
	}

	/**
	 * Handle individual file import.
	 *
	 * @since 4.0.0
	 *
	 * @param  string $file Full file path.
	 * @param  array  $args {
	 *     Optional. Import behavior flags.
	 *
	 *     @type bool   $generate_metadata  Whether to generate image sizes immediately. Default true.
	 *     @type string $source_relative    Relative path under plugin root (for folder preserve).
	 *     @type bool   $preserve_structure Whether to keep source folder tree under uploads.
	 *     @type string $duplicate_action   skip|replace|rename when Pro advanced_duplicates is on.
	 * }
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	public function handle_import_file( $file, $args = array() ) {
		set_time_limit( 60 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Set reasonable time limit per file.

		$args = wp_parse_args(
			$args,
			array(
				'generate_metadata'  => true,
				'source_relative'    => '',
				'preserve_structure' => false,
				'duplicate_action'   => 'skip',
			)
		);

		$file = wp_normalize_path( $file );

		// Security: Verify file exists and is readable.
		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			return new WP_Error( 'file_not_readable', __( 'The file does not exist or is not readable.', 'add-from-server-reloaded' ) );
		}

		// Security: Prevent importing of PHP files or other dangerous types.
		$dangerous_extensions = $this->get_restricted_extensions();
		$ext                  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		if ( in_array( $ext, $dangerous_extensions, true ) ) {
			return new WP_Error( 'dangerous_file_type', __( 'This file type cannot be imported for security reasons.', 'add-from-server-reloaded' ) );
		}

		// Base the time on the file's modified time when possible.
		$time = filemtime( $file );
		if ( ! $time || ! is_int( $time ) ) {
			$time = time();
		}

		// Guard against invalid or extreme file dates.
		$current_time = (int) current_time( 'timestamp' );
		$current_year = (int) gmdate( 'Y', $current_time );
		$time_year    = (int) gmdate( 'Y', $time );
		if ( $time_year < 1970 || $time_year > ( $current_year + 1 ) ) {
			$time = $current_time;
		}

		// If the file path contains a valid YYYY/MM segment, use that date.
		if ( preg_match( '~/(?P<year>\d{4})/(?P<month>0[1-9]|1[0-2])(?:/|$)~', wp_normalize_path( $file ), $datemat ) ) {
			$year  = (int) $datemat['year'];
			$month = (int) $datemat['month'];
			if ( $year >= 1970 && $year <= ( $current_year + 1 ) ) {
				$time = mktime( 0, 0, 0, $month, 1, $year );
			}
		}

		// Get uploads info without creating new year/month folders.
		$uploads = wp_upload_dir( $time, false );
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'upload_error', $uploads['error'] );
		}

		$wp_filetype = \wp_check_filetype( $file, null );
		$type        = $wp_filetype['type'];
		$ext_check   = $wp_filetype['ext'];

		if ( ( ! $type || ! $ext_check ) && ! current_user_can( 'unfiltered_upload' ) && ! $this->allows_dangerous_file_types() ) {
			return new WP_Error( 'wrong_file_type', __( 'Sorry, this file type is not permitted for security reasons.', 'add-from-server-reloaded' ) );
		}

		// When opted in, ensure attachment creation still has a usable MIME/type.
		if ( ( ! $type || ! $ext_check ) && $this->allows_dangerous_file_types() ) {
			$ext_check = $ext ? $ext : strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			$type      = $type ? $type : 'application/octet-stream';
		}

		$dup_action = 'skip';
		if ( Features::enabled( 'advanced_duplicates' ) ) {
			$dup_action = sanitize_key( $args['duplicate_action'] );
			if ( ! in_array( $dup_action, array( 'skip', 'replace', 'rename' ), true ) ) {
				/**
				 * Default duplicate action when the request omits a valid one (Pro).
				 *
				 * @since 5.5.0
				 *
				 * @param string $action Default: skip.
				 */
				$fallback   = sanitize_key( (string) apply_filters( 'afsrreloaded_default_duplicate_action', 'skip' ) );
				$dup_action = in_array( $fallback, array( 'skip', 'replace', 'rename' ), true ) ? $fallback : 'skip';
			}
		}

		// Check if file is already in media library (duplicate detection).
		$duplicate = ( 'rename' === $dup_action ) ? false : $this->check_if_duplicate( $file );
		if ( $duplicate ) {
			if ( 'replace' === $dup_action ) {
				\wp_delete_attachment( (int) $duplicate, true );
			} else {
				$edit_link = \admin_url( 'post.php?post=' . $duplicate . '&action=edit' );
				return new WP_Error(
					'file_exists',
					sprintf(
						/* translators: %s: link to edit attachment */
						__( 'File already exists. <a href="%s" target="_blank">View in Media Library</a>', 'add-from-server-reloaded' ),
						\esc_url( $edit_link )
					)
				);
			}
		}

		$upload_context = array(
			'source_relative'    => (string) $args['source_relative'],
			'preserve_structure' => ! empty( $args['preserve_structure'] ) && Features::enabled( 'folder_preserve' ),
		);

		// Is the file already in the uploads folder?
		if ( preg_match( '|^' . preg_quote( wp_normalize_path( $uploads['basedir'] ), '|' ) . '(.*)$|i', $file, $mat ) ) {

			$filename   = basename( $file );
			$file_mtime = filemtime( $file );
			if ( $file_mtime ) {
				$time = $file_mtime;
			}

			// Ensure the destination uploads folder exists for copying (use plugin prefix).
			$uploads = $this->get_import_uploads_dir( $time, true, $upload_context );
			if ( ! empty( $uploads['error'] ) ) {
				return new WP_Error( 'upload_error', $uploads['error'] );
			}

			$target_dir  = wp_normalize_path( $uploads['path'] );
			$current_dir = wp_normalize_path( dirname( $file ) );

			// If the file is already in the target directory, keep it.
			if ( 0 === strcmp( $current_dir, $target_dir ) ) {
				$new_file = $file;
				$url      = $uploads['url'] . '/' . $filename;
			} else {
				$filename = \wp_unique_filename( $uploads['path'], $filename );
				$new_file = $uploads['path'] . '/' . $filename;

				// Copy into the destination folder; keep the original source file.
				if ( false === @copy( $file, $new_file ) ) {
					return new WP_Error(
						'upload_error',
						sprintf(
							/* translators: %s: upload directory path */
							__( 'The selected file could not be copied to %s.', 'add-from-server-reloaded' ),
							$uploads['path']
						)
					);
				}

				// Set correct file permissions.
				$stat  = stat( dirname( $new_file ) );
				$perms = $stat['mode'] & 0000666;
				chmod( $new_file, $perms ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

				$url = $uploads['url'] . '/' . $filename;
			}
		} else {
			// Ensure the destination uploads folder exists for copying (use plugin prefix).
			$uploads = $this->get_import_uploads_dir( $time, true, $upload_context );
			if ( ! empty( $uploads['error'] ) ) {
				return new WP_Error( 'upload_error', $uploads['error'] );
			}

			// File is outside uploads directory - copy it.
			$filename = \wp_unique_filename( $uploads['path'], basename( $file ) );
			$new_file = $uploads['path'] . '/' . $filename;

			if ( false === @copy( $file, $new_file ) ) {
				return new WP_Error(
					'upload_error',
					sprintf(
						/* translators: %s: upload directory path */
						__( 'The selected file could not be copied to %s.', 'add-from-server-reloaded' ),
						$uploads['path']
					)
				);
			}

			// Set correct file permissions.
			$stat  = stat( dirname( $new_file ) );
			$perms = $stat['mode'] & 0000666;
			chmod( $new_file, $perms ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

			// Compute the URL.
			$url = $uploads['url'] . '/' . $filename;
		}

		// Apply the core upload filter so other plugins can adjust sideloads (same as wp_handle_sideload).
		$return = apply_filters(
			'wp_handle_upload', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WP filter, invoked intentionally.
			array(
				'file' => $new_file,
				'url'  => $url,
				'type' => $type,
			),
			'sideload'
		);
		$new_file = $return['file'];
		$url      = $return['url'];
		$type     = $return['type'];

		// Generate title from filename.
		$title   = preg_replace( '!\.[^.]+$!', '', basename( $file ) );
		$content = '';
		$excerpt = '';

		// Extract metadata for audio files.
		if ( preg_match( '#^audio#', $type ) ) {
			$meta = \wp_read_audio_metadata( $new_file );

			if ( ! empty( $meta['title'] ) ) {
				$title = $meta['title'];
			}

			if ( ! empty( $title ) ) {

				if ( ! empty( $meta['album'] ) && ! empty( $meta['artist'] ) ) {
					/* translators: 1: audio track title, 2: album title, 3: artist name */
					$content .= sprintf( __( '"%1$s" from %2$s by %3$s.', 'add-from-server-reloaded' ), $title, $meta['album'], $meta['artist'] );
				} elseif ( ! empty( $meta['album'] ) ) {
					/* translators: 1: audio track title, 2: album title */
					$content .= sprintf( __( '"%1$s" from %2$s.', 'add-from-server-reloaded' ), $title, $meta['album'] );
				} elseif ( ! empty( $meta['artist'] ) ) {
					/* translators: 1: audio track title, 2: artist name */
					$content .= sprintf( __( '"%1$s" by %2$s.', 'add-from-server-reloaded' ), $title, $meta['artist'] );
				} else {
					/* translators: %s: audio track title */
					$content .= sprintf( __( '"%s".', 'add-from-server-reloaded' ), $title );
				}
			} elseif ( ! empty( $meta['album'] ) ) {

				if ( ! empty( $meta['artist'] ) ) {
					/* translators: 1: audio album title, 2: artist name */
					$content .= sprintf( __( '%1$s by %2$s.', 'add-from-server-reloaded' ), $meta['album'], $meta['artist'] );
				} else {
					$content .= $meta['album'] . '.';
				}
			} elseif ( ! empty( $meta['artist'] ) ) {

				$content .= $meta['artist'] . '.';

			}

			if ( ! empty( $meta['year'] ) ) {
				/* translators: %d: release year */
				$content .= ' ' . sprintf( __( 'Released: %d.', 'add-from-server-reloaded' ), $meta['year'] );
			}

			if ( ! empty( $meta['track_number'] ) ) {
				$track_number = explode( '/', $meta['track_number'] );
				if ( isset( $track_number[1] ) ) {
					/* translators: 1: track number, 2: total tracks */
					$content .= ' ' . sprintf( __( 'Track %1$s of %2$s.', 'add-from-server-reloaded' ), number_format_i18n( $track_number[0] ), number_format_i18n( $track_number[1] ) );
				} else {
					/* translators: %s: track number */
					$content .= ' ' . sprintf( __( 'Track %s.', 'add-from-server-reloaded' ), number_format_i18n( $track_number[0] ) );
				}
			}

			if ( ! empty( $meta['genre'] ) ) {
				/* translators: %s: genre */
				$content .= ' ' . sprintf( __( 'Genre: %s.', 'add-from-server-reloaded' ), $meta['genre'] );
			}

			// Use image exif/iptc data for title and caption defaults if possible.
		} elseif ( 0 === strpos( $type, 'image/' ) ) {
			$image_meta = @\wp_read_image_metadata( $new_file );

			if ( $image_meta && ! empty( $image_meta['title'] ) && ! is_numeric( sanitize_title( $image_meta['title'] ) ) ) {
				$title = $image_meta['title'];
			}

			if ( $image_meta && ! empty( $image_meta['caption'] ) ) {
				$excerpt = $image_meta['caption'];
			}
		}

		// Construct the attachment array.
		$attachment_time_local = current_time( 'mysql' );
		$attachment_time_gmt   = get_gmt_from_date( $attachment_time_local );

		$attachment = array(
			'post_mime_type' => $type,
			'guid'           => $url,
			'post_parent'    => 0,
			'post_title'     => $title,
			'post_name'      => $title,
			'post_content'   => $content,
			'post_excerpt'   => $excerpt,
			'post_date'      => $attachment_time_local,
			'post_date_gmt'  => $attachment_time_gmt,
		);

		/**
		 * Filters the attachment data before import.
		 *
		 * @since 4.0.0
		 *
		 * @param array  $attachment Attachment data.
		 * @param string $file       File path.
		 */
		$attachment = apply_filters( 'afsrreloaded_import_attachment_data', $attachment, $file );

		// Backwards compatibility filter.
		$attachment = apply_filters( 'afsrreloaded_import_details', $attachment, $file, 0, 'current' );

		// Save the data.
		$id = \wp_insert_attachment( $attachment, $new_file, 0 );

		if ( ! \is_wp_error( $id ) ) {
			if ( ! empty( $args['generate_metadata'] ) ) {
				// Generate attachment metadata (thumbnails / image sizes).
				$data = \wp_generate_attachment_metadata( $id, $new_file );
				\wp_update_attachment_metadata( $id, $data );
			} else {
				// Defer expensive metadata generation; Pro background cron consumes this.
				\update_post_meta( $id, '_afsrreloaded_needs_metadata', 1 );
				do_action( 'afsrreloaded_schedule_background_tick', null );
			}

			// Store file hash for reliable duplicate detection.
			$file_hash = $this->get_file_hash( $new_file );
			if ( $file_hash ) {
				\update_post_meta( $id, '_afsrreloaded_file_hash', $file_hash );
			}

			/**
			 * Fires after a file has been imported.
			 *
			 * @since 4.0.0
			 *
			 * @param int    $id   Attachment ID.
			 * @param string $file File path.
			 */
			do_action( 'afsrreloaded_file_imported', $id, $file );
		}

		return $id;
	}

	/**
	 * Get the default directory.
	 *
	 * @since 4.0.0
	 *
	 * @return string Default directory path.
	 */
	protected function get_default_dir() {
		$root = $this->get_root();

		if ( ! $root ) {
			return '';
		}

		// Always start at the configured root directory.
		return $root;
	}

	/**
	 * Create the main content for the page.
	 *
	 * @since 4.0.0
	 */
	public function main_content() {

		$url = admin_url( 'admin.php?page=add-from-server-reloaded' );

		$root = $this->get_root();
		if ( ! $root ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Unable to determine root directory. Please check your configuration.', 'add-from-server-reloaded' );
			echo '</p><p>';
			printf(
				/* translators: %s: constant name */
				esc_html__( 'You can define the %s constant in your wp-config.php file to set a custom root directory.', 'add-from-server-reloaded' ),
				'<code>ADD_FROM_SERVER_RELOADED</code>'
			);
			echo '</p></div>';
			return;
		}

		$cwd = $this->get_default_dir();

		// Prefer the current request path (folder click) over a stale cookie.
		// Stale cookies from a different root caused doubled paths for RBAC users.
		$relative_path = null;
		if ( isset( $_REQUEST['path'] ) && Capabilities::can_browse() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$relative_path = sanitize_text_field( wp_unslash( $_REQUEST['path'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( ! empty( $_COOKIE[ COOKIE ] ) ) {
			$relative_path = sanitize_text_field( wp_unslash( $_COOKIE[ COOKIE ] ) );
		}

		if ( null !== $relative_path && '/' !== $relative_path && '' !== $relative_path ) {
			$temp_cwd = realpath( trailingslashit( $root ) . ltrim( $relative_path, '/' ) );

			// Validate the path stays under root.
			if ( $temp_cwd && Path_Guard::path_has_root_boundary( $temp_cwd, $root ) ) {
				$cwd = $temp_cwd;
			}
		}

		// Validate current directory.
		if ( ! str_starts_with( $cwd, $root ) ) {
			$cwd = $root;
		}

		$cwd_relative = substr( $cwd, strlen( $root ) );

		// Build breadcrumb navigation.
		$dirparts   = array();
		$dirparts[] = '<a href="' . esc_url( add_query_arg( 'path', rawurlencode( '/' ), $url ) ) . '">' . esc_html( trailingslashit( $root ) ) . '</a> ';

		$dir_path = '';
		foreach ( array_filter( explode( '/', $cwd_relative ) ) as $dir ) {
			$dir_path   = $dir_path . '/' . $dir;
			$dir_label  = $dir ? $dir : basename( $root );
			$dirparts[] = '<a href="' . esc_url( add_query_arg( 'path', rawurlencode( $dir_path ), $url ) ) . '">' . esc_html( $dir_label ) . '/</a> ';
		}

		$dirparts = implode( '', $dirparts );

		// Sort function for case-insensitive alphabetical sorting.
		$sort_by_text = function ( $a, $b ) {
			return strtolower( $a['text'] ) <=> strtolower( $b['text'] );
		};

		// Get a list of files to show.
		$globbed = glob( rtrim( $cwd, '/' ) . '/*' );
		$nodes   = is_array( $globbed ) ? $globbed : array();

		$directories = array_flip(
			array_filter(
				$nodes,
				function ( $node ) {
					return is_dir( $node );
				}
			)
		);

		$get_root_relative_path = function ( $path ) use ( $root ) {
			$root_offset = strlen( $root );
			if ( '/' !== $root ) {
				++$root_offset;
			}

			return substr( $path, $root_offset );
		};

		// One level at a time — do not collapse empty intermediate folders into
		// paths like "wordpress/wp-content/" or "2026/09/".
		array_walk(
			$directories,
			function ( &$data, $path ) use ( $get_root_relative_path ) {
				if ( ! is_readable( $path ) ) {
					$data = false;
					return;
				}

				$data = array(
					'text' => basename( $path ) . '/',
					'path' => $get_root_relative_path( $path ),
				);
			}
		);

		$directories = array_filter( $directories );

		// Sort the directories case insensitively.
		uasort( $directories, $sort_by_text );

		// Prefix the parent directory.
		if ( Path_Guard::path_has_root_boundary( dirname( $cwd ), $root ) && 0 !== strcmp( $cwd, dirname( $cwd ) ) ) {
			$parent_relative_path = $get_root_relative_path( dirname( $cwd ) );
			$directories          = array_merge(
				array(
					dirname( $cwd ) => array(
						'text' => __( 'Parent Folder', 'add-from-server-reloaded' ),
						'path' => $parent_relative_path ? $parent_relative_path : '/',
					),
				),
				$directories
			);
		}

		$files = array_flip(
			array_filter(
				$nodes,
				function ( $node ) {
					return is_file( $node );
				}
			)
		);

		array_walk(
			$files,
			function ( &$data, $path ) use ( $root, $get_root_relative_path ) {
				// Honor Settings → allow dangerous types (not just WP MIME / unfiltered_upload).
				$importable = ! $this->is_restricted_file( $path );
				$readable   = is_readable( $path );

				// Get file modification time.
				$file_date = '';
				if ( $readable && file_exists( $path ) ) {
					$file_date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), filemtime( $path ) );
				}

				$filetype = \wp_check_filetype( $path );

				$data = array(
					'text'       => basename( $path ),
					'file'       => $get_root_relative_path( $path ),
					'importable' => $importable,
					'readable'   => $readable,
					'size'       => $readable ? size_format( filesize( $path ) ) : 'N/A',
					'size_bytes' => $readable ? (int) filesize( $path ) : 0,
					'mtime'      => $readable ? (int) filemtime( $path ) : 0,
					'ext'        => strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ),
					'mime'       => (string) ( ! empty( $filetype['type'] ) ? $filetype['type'] : '' ),
					'date'       => $file_date,
					'error'      => (
						! $importable ? 'doesnt-meet-guidelines' : (
							! $readable ? 'unreadable' : false
						)
					),
				);
			}
		);

		// Sort case insensitively.
		uasort( $files, $sort_by_text );

		?>
		<div id="afsr-admin-app" class="afsr-wrap afsrreloaded-wrap" style="<?php echo esc_attr( Pro_Teaser::support_app_inline_style() ); ?>">
			<div class="afsr-page-header">
				<h1 class="afsr-page-title"><?php esc_html_e( 'Import', 'add-from-server-reloaded' ); ?></h1>
				<p class="afsr-page-subtitle"><?php esc_html_e( 'Bring files already on your server into the Media Library.', 'add-from-server-reloaded' ); ?></p>
			</div>

			<?php
			Pro_Teaser::render_upgrade_banner(
				__( 'Unlock more import options', 'add-from-server-reloaded' ),
				__( 'Background imports, deferred thumbnails, folder structure, and duplicate handling.', 'add-from-server-reloaded' )
			);
			?>

			<ol class="afsr-stepper" aria-label="<?php esc_attr_e( 'Import steps', 'add-from-server-reloaded' ); ?>">
				<li class="afsr-stepper__item is-active" data-step="1">
					<span class="afsr-stepper__dot">1</span>
					<span><?php esc_html_e( 'Browse & select', 'add-from-server-reloaded' ); ?></span>
				</li>
				<li class="afsr-stepper__line" data-after="1" aria-hidden="true"></li>
				<li class="afsr-stepper__item" data-step="2">
					<span class="afsr-stepper__dot">2</span>
					<span><?php esc_html_e( 'Options', 'add-from-server-reloaded' ); ?></span>
				</li>
				<li class="afsr-stepper__line" data-after="2" aria-hidden="true"></li>
				<li class="afsr-stepper__item" data-step="3">
					<span class="afsr-stepper__dot">3</span>
					<span><?php esc_html_e( 'Import', 'add-from-server-reloaded' ); ?></span>
				</li>
			</ol>

			<?php Pro_Teaser::support_layout_open(); ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>" id="afsrreloaded-import-form">
				<?php wp_nonce_field( 'afsrreloaded_import' ); ?>

				<!-- Keep legacy toggle target for existing JS -->
				<button type="button" id="afsrreloaded-toggle-hidden" class="afsr-legacy-toolbar" hidden aria-hidden="true"><?php esc_html_e( 'Show Hidden Files', 'add-from-server-reloaded' ); ?></button>

				<div class="afsr-panel" data-afsr-panel="browse">
					<div class="afsr-card">
						<div class="afsr-browser-top">
							<div class="afsr-path-row">
								<span class="afsr-root-pill"><?php esc_html_e( 'ROOT', 'add-from-server-reloaded' ); ?></span>
								<span class="afsr-path-text"><?php echo esc_html( $cwd ); ?></span>
								<?php if ( Capabilities::can_manage_settings() ) : ?>
									<a class="afsr-link afsr-change-root-link" href="<?php echo esc_url( admin_url( 'admin.php?page=add-from-server-reloaded-settings' ) ); ?>"><?php esc_html_e( 'Change root directory', 'add-from-server-reloaded' ); ?></a>
								<?php endif; ?>
								<?php if ( 0 !== strcmp( $cwd, $root ) ) : ?>
									<a class="afsr-link" href="<?php echo esc_url( add_query_arg( 'path', rawurlencode( '/' ), $url ) ); ?>"><?php esc_html_e( 'Back to root', 'add-from-server-reloaded' ); ?></a>
								<?php endif; ?>
							</div>
							<div class="afsr-browser-top-actions">
								<button type="button" class="afsr-btn afsr-btn-primary is-disabled" data-afsr-action="continue-step1" disabled><?php esc_html_e( 'Import files', 'add-from-server-reloaded' ); ?></button>
							</div>
						</div>

						<div class="afsr-toolbar">
							<input type="search" id="afsrreloaded-file-search" class="afsr-search" placeholder="<?php esc_attr_e( 'Search this folder...', 'add-from-server-reloaded' ); ?>" autocomplete="off" />
							<button type="button" class="afsr-btn afsr-btn-secondary" data-afsr-action="toggle-filters"><?php esc_html_e( 'More filters', 'add-from-server-reloaded' ); ?></button>
							<button type="button" class="afsr-link" data-afsr-action="toggle-hidden"><?php esc_html_e( 'Show hidden files', 'add-from-server-reloaded' ); ?></button>
						</div>

						<div class="afsr-filters" hidden>
							<div class="afsr-field">
								<label for="afsrreloaded-filter-type"><?php esc_html_e( 'Type', 'add-from-server-reloaded' ); ?></label>
								<select id="afsrreloaded-filter-type">
									<option value="all"><?php esc_html_e( 'All', 'add-from-server-reloaded' ); ?></option>
									<option value="images"><?php esc_html_e( 'Images', 'add-from-server-reloaded' ); ?></option>
									<option value="audio"><?php esc_html_e( 'Audio', 'add-from-server-reloaded' ); ?></option>
									<option value="video"><?php esc_html_e( 'Video', 'add-from-server-reloaded' ); ?></option>
									<option value="documents"><?php esc_html_e( 'Documents', 'add-from-server-reloaded' ); ?></option>
								</select>
							</div>
							<div class="afsr-field">
								<label for="afsrreloaded-filter-min-size"><?php esc_html_e( 'Min size (MB)', 'add-from-server-reloaded' ); ?></label>
								<input type="number" id="afsrreloaded-filter-min-size" min="0" step="0.1" value="0" />
							</div>
							<div class="afsr-field">
								<label for="afsrreloaded-filter-max-size"><?php esc_html_e( 'Max size (MB)', 'add-from-server-reloaded' ); ?></label>
								<input type="number" id="afsrreloaded-filter-max-size" min="0" step="0.1" placeholder="-" />
							</div>
							<div class="afsr-field">
								<label for="afsrreloaded-filter-date"><?php esc_html_e( 'Newer than', 'add-from-server-reloaded' ); ?></label>
								<input type="date" id="afsrreloaded-filter-date" />
							</div>
						</div>

						<table class="widefat afsrreloaded-file-table afsr-file-table">
							<thead>
							<tr>
								<td class="check-column"><input type="checkbox" id="afsrreloaded-select-all" /></td>
								<td><?php esc_html_e( 'Name', 'add-from-server-reloaded' ); ?></td>
								<td class="afsr-col-size"><?php esc_html_e( 'Size', 'add-from-server-reloaded' ); ?></td>
								<td class="afsr-col-modified"><?php esc_html_e( 'Modified', 'add-from-server-reloaded' ); ?></td>
							</tr>
							</thead>
							<tbody>
							<?php
							$folder_id = 0;
							foreach ( $directories as $dir ) {
								if ( empty( $dir['path'] ) ) {
									continue;
								}

								$folder_path = trailingslashit( $root ) . ltrim( $dir['path'], '/' );
								$folder_date = '';
								if ( file_exists( $folder_path ) ) {
									$folder_date = date_i18n( get_option( 'date_format' ), filemtime( $folder_path ) );
								}

								$is_parent    = ( __( 'Parent Folder', 'add-from-server-reloaded' ) === $dir['text'] );
								$folder_label = $is_parent ? __( 'Parent folder', 'add-from-server-reloaded' ) : $dir['text'];
								if ( ! $is_parent && '/' !== substr( $folder_label, -1 ) ) {
									$folder_label .= '/';
								}

								printf(
									'<tr class="afsrreloaded-folder-row">
										<th class="check-column">%1$s</th>
										<td>
											<a class="afsr-folder-link" href="%2$s">%3$s</a>
										</td>
										<td class="afsr-col-size">%4$s</td>
										<td class="afsr-col-modified">%5$s</td>
									</tr>',
									$is_parent ? '&nbsp;' : '<input type="checkbox" id="folder-' . absint( $folder_id ) . '" name="folders[]" value="' . esc_attr( $dir['path'] ) . '" />',
									esc_url( add_query_arg( 'path', rawurlencode( $dir['path'] ), $url ) ),
									esc_html( $folder_label ),
									$is_parent ? '&nbsp;' : esc_html( '-' ),
									esc_html( $folder_date )
								);

								if ( ! $is_parent ) {
									++$folder_id;
								}
							}

							$file_id = 0;
							foreach ( $files as $file ) {
								$error_str = '';
								if ( 'doesnt-meet-guidelines' === $file['error'] ) {
									$error_str = __( 'Sorry, this file type is not permitted for security reasons.', 'add-from-server-reloaded' );
								} elseif ( 'unreadable' === $file['error'] ) {
									$error_str = __( 'Sorry, but this file is unreadable by your Webserver. Perhaps check your File Permissions?', 'add-from-server-reloaded' );
								}

								$file_error = ! empty( $file['error'] ) ? $file['error'] : '';
								$ext_badge  = ! empty( $file['ext'] )
									? '<span class="afsr-file-tag">' . esc_html( strtoupper( $file['ext'] ) ) . '</span>'
									: '';

								printf(
									'<tr class="%1$s afsrreloaded-file-row" title="%2$s" data-name="%9$s" data-ext="%10$s" data-mime="%11$s" data-size="%12$d" data-mtime="%13$d">
										<th class="check-column">
											<input type="checkbox" id="file-%3$d" name="files[]" value="%4$s" %5$s />
										</th>
										<td><label for="file-%3$d" class="afsr-file-name">%6$s %14$s</label></td>
										<td class="afsr-col-size">%7$s</td>
										<td class="afsr-col-modified">%8$s</td>
									</tr>',
									esc_attr( $file_error ),
									esc_attr( $error_str ),
									absint( $file_id++ ),
									esc_attr( $file['file'] ),
									disabled( false, $file['readable'] && $file['importable'], false ),
									esc_html( $file['text'] ),
									esc_html( $file['size'] ),
									esc_html( ! empty( $file['mtime'] ) ? date_i18n( get_option( 'date_format' ), (int) $file['mtime'] ) : '' ),
									esc_attr( strtolower( $file['text'] ) ),
									esc_attr( $file['ext'] ?? '' ),
									esc_attr( $file['mime'] ?? '' ),
									absint( $file['size_bytes'] ?? 0 ),
									absint( $file['mtime'] ?? 0 ),
									$ext_badge // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
								);
							}

							if ( array_filter( array_column( $files, 'error' ) ) ) {
								printf(
									'<tr class="hidden-toggle"><td>&nbsp;</td><td colspan="3"><a href="#">%s</a></td></tr>',
									esc_html__( 'Show hidden files', 'add-from-server-reloaded' )
								);
							}
							?>
							</tbody>
							<tfoot>
							<tr>
								<td class="check-column"><input type="checkbox" id="afsrreloaded-select-all-footer" /></td>
								<td><?php esc_html_e( 'Name', 'add-from-server-reloaded' ); ?></td>
								<td class="afsr-col-size"><?php esc_html_e( 'Size', 'add-from-server-reloaded' ); ?></td>
								<td class="afsr-col-modified"><?php esc_html_e( 'Modified', 'add-from-server-reloaded' ); ?></td>
							</tr>
							</tfoot>
						</table>

						<div class="afsr-pagination" data-afsr-pagination id="afsrreloaded-pagination"></div>
						<button type="button" id="afsrreloaded-clear-search" class="afsr-legacy-toolbar" hidden aria-hidden="true">Clear</button>
					</div>

					<div class="afsr-step-footer">
						<span class="afsr-hint afsr-step1-hint"><?php esc_html_e( 'Select files to import', 'add-from-server-reloaded' ); ?></span>
						<button type="button" class="afsr-btn afsr-btn-primary is-disabled" data-afsr-action="continue-step1" disabled><?php esc_html_e( 'Import files', 'add-from-server-reloaded' ); ?></button>
					</div>
				</div>

				<div class="afsr-panel" data-afsr-panel="options" hidden>
					<div class="afsr-card">
						<div class="afsr-options-list">
							<?php if ( Features::enabled( 'background' ) ) : ?>
							<div class="afsr-option-row">
								<div class="afsr-option-copy">
									<p class="afsr-option-title"><?php esc_html_e( 'Background imports', 'add-from-server-reloaded' ); ?></p>
									<p class="afsr-option-desc"><?php esc_html_e( 'Keep importing after you leave this page.', 'add-from-server-reloaded' ); ?></p>
								</div>
								<label class="afsr-switch">
									<input type="checkbox" name="afsrreloaded_background" id="afsrreloaded-background" value="1" checked="checked" />
									<span></span>
								</label>
							</div>
							<?php else : ?>
							<div class="afsr-option-row afsr-option-locked" data-afsr-pro-feature="background">
								<div class="afsr-option-copy">
									<p class="afsr-option-title">
										<?php esc_html_e( 'Background imports', 'add-from-server-reloaded' ); ?>
										<span class="afsr-pro-badge-dark"><?php esc_html_e( 'PRO', 'add-from-server-reloaded' ); ?></span>
									</p>
									<p class="afsr-option-desc"><?php esc_html_e( 'Keep importing after you leave this page.', 'add-from-server-reloaded' ); ?></p>
								</div>
								<label class="afsr-switch">
									<input type="checkbox" disabled />
									<span></span>
								</label>
							</div>
							<?php endif; ?>

							<?php if ( Features::enabled( 'defer_thumbnails' ) ) : ?>
							<div class="afsr-option-row">
								<div class="afsr-option-copy">
									<p class="afsr-option-title"><?php esc_html_e( 'Defer thumbnails', 'add-from-server-reloaded' ); ?></p>
									<p class="afsr-option-desc"><?php esc_html_e( 'Import files first, generate sizes later.', 'add-from-server-reloaded' ); ?></p>
								</div>
								<label class="afsr-switch">
									<input type="checkbox" name="afsrreloaded_defer_thumbs" id="afsrreloaded-defer-thumbs" value="1" />
									<span></span>
								</label>
							</div>
							<?php else : ?>
							<div class="afsr-option-row afsr-option-locked" data-afsr-pro-feature="defer_thumbnails">
								<div class="afsr-option-copy">
									<p class="afsr-option-title">
										<?php esc_html_e( 'Defer thumbnails', 'add-from-server-reloaded' ); ?>
										<span class="afsr-pro-badge-dark"><?php esc_html_e( 'PRO', 'add-from-server-reloaded' ); ?></span>
									</p>
									<p class="afsr-option-desc"><?php esc_html_e( 'Import files first, generate sizes later.', 'add-from-server-reloaded' ); ?></p>
								</div>
								<label class="afsr-switch">
									<input type="checkbox" disabled />
									<span></span>
								</label>
							</div>
							<?php endif; ?>

							<?php if ( Features::enabled( 'folder_preserve' ) ) : ?>
							<div class="afsr-option-row">
								<div class="afsr-option-copy">
									<p class="afsr-option-title"><?php esc_html_e( 'Preserve folder structure', 'add-from-server-reloaded' ); ?></p>
									<p class="afsr-option-desc"><?php esc_html_e( 'Keep the same folders in Media Library.', 'add-from-server-reloaded' ); ?></p>
								</div>
								<label class="afsr-switch">
									<input type="checkbox" name="afsrreloaded_preserve_structure" id="afsrreloaded-preserve-structure" value="1" />
									<span></span>
								</label>
							</div>
							<?php else : ?>
							<div class="afsr-option-row afsr-option-locked" data-afsr-pro-feature="folder_preserve">
								<div class="afsr-option-copy">
									<p class="afsr-option-title">
										<?php esc_html_e( 'Preserve folder structure', 'add-from-server-reloaded' ); ?>
										<span class="afsr-pro-badge-dark"><?php esc_html_e( 'PRO', 'add-from-server-reloaded' ); ?></span>
									</p>
									<p class="afsr-option-desc"><?php esc_html_e( 'Keep the same folders in Media Library.', 'add-from-server-reloaded' ); ?></p>
								</div>
								<label class="afsr-switch">
									<input type="checkbox" disabled />
									<span></span>
								</label>
							</div>
							<?php endif; ?>

							<?php if ( Features::enabled( 'advanced_duplicates' ) ) : ?>
							<div class="afsr-option-row">
								<div class="afsr-option-copy">
									<p class="afsr-option-title"><?php esc_html_e( 'On duplicate files', 'add-from-server-reloaded' ); ?></p>
								</div>
								<select name="afsrreloaded_duplicate_action" id="afsrreloaded-duplicate-action" class="afsr-select">
									<option value="skip"><?php esc_html_e( 'Skip the file', 'add-from-server-reloaded' ); ?></option>
									<option value="replace"><?php esc_html_e( 'Replace', 'add-from-server-reloaded' ); ?></option>
									<option value="rename"><?php esc_html_e( 'Import as new', 'add-from-server-reloaded' ); ?></option>
								</select>
							</div>
							<?php else : ?>
							<div class="afsr-option-row afsr-option-locked" data-afsr-pro-feature="advanced_duplicates">
								<div class="afsr-option-copy">
									<p class="afsr-option-title">
										<?php esc_html_e( 'On duplicate files', 'add-from-server-reloaded' ); ?>
										<span class="afsr-pro-badge-dark"><?php esc_html_e( 'PRO', 'add-from-server-reloaded' ); ?></span>
									</p>
								</div>
								<select class="afsr-select" disabled>
									<option><?php esc_html_e( 'Skip the file', 'add-from-server-reloaded' ); ?></option>
								</select>
							</div>
							<?php endif; ?>
						</div>
					</div>

					<div class="afsr-step-footer is-split">
						<button type="button" class="afsr-btn afsr-btn-ghost" data-afsr-action="back-step2"><?php esc_html_e( 'Back', 'add-from-server-reloaded' ); ?></button>
						<button type="button" class="afsr-btn afsr-btn-primary" data-afsr-action="continue-step2"><?php esc_html_e( 'Review import', 'add-from-server-reloaded' ); ?></button>
					</div>
				</div>

				<div class="afsr-panel" data-afsr-panel="ready" hidden>
					<div class="afsr-card">
						<h2 class="afsr-summary-title"><?php esc_html_e( 'Ready to import', 'add-from-server-reloaded' ); ?></h2>
						<ul class="afsr-summary-list">
							<li>
								<span class="afsr-summary-label"><?php esc_html_e( 'Files selected', 'add-from-server-reloaded' ); ?></span>
								<span class="afsr-summary-value" data-afsr-summary="files">0 files</span>
							</li>
							<li>
								<span class="afsr-summary-label"><?php esc_html_e( 'On duplicate', 'add-from-server-reloaded' ); ?></span>
								<span class="afsr-summary-value" data-afsr-summary="duplicate"><?php esc_html_e( 'Skip the file', 'add-from-server-reloaded' ); ?></span>
							</li>
							<li>
								<span class="afsr-summary-label"><?php esc_html_e( 'Options', 'add-from-server-reloaded' ); ?></span>
								<span class="afsr-summary-value" data-afsr-summary="options"><?php esc_html_e( 'None', 'add-from-server-reloaded' ); ?></span>
							</li>
						</ul>
						<div class="afsr-summary-actions">
							<button type="button" class="afsr-btn afsr-btn-ghost" data-afsr-action="back-step3"><?php esc_html_e( 'Back', 'add-from-server-reloaded' ); ?></button>
							<button type="submit" name="import" class="afsr-btn afsr-btn-primary" id="afsr-start-import"><?php esc_html_e( 'Start import', 'add-from-server-reloaded' ); ?></button>
						</div>
					</div>
				</div>

				<div class="afsr-panel" data-afsr-panel="progress" hidden>
					<div class="afsr-card" id="afsrreloaded-progress-panel">
						<h2 class="afsr-progress-title"><?php esc_html_e( 'Importing files', 'add-from-server-reloaded' ); ?></h2>
						<div class="afsr-progress-bar afsrreloaded-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
							<span class="afsr-progress-bar-fill afsrreloaded-progress-bar-fill"></span>
						</div>
						<p class="afsr-progress-meta afsrreloaded-progress-message">0 of 0 files · 0%</p>
						<span class="afsrreloaded-progress-percent afsr-wizard-hidden-counts" aria-hidden="true">0%</span>
						<ul class="afsrreloaded-progress-counts afsr-wizard-hidden-counts" aria-hidden="true">
							<li data-count="imported"><span>0</span></li>
							<li data-count="duplicates"><span>0</span></li>
							<li data-count="errors"><span>0</span></li>
							<li data-count="skipped"><span>0</span></li>
						</ul>
						<div class="afsr-progress-actions">
							<button type="button" class="afsr-btn afsr-btn-secondary" id="afsrreloaded-cancel-job"><?php esc_html_e( 'Cancel', 'add-from-server-reloaded' ); ?></button>
							<?php if ( Features::enabled( 'queue_controls' ) ) : ?>
							<button type="button" class="afsr-btn afsr-btn-ghost" id="afsrreloaded-pause-job"><?php esc_html_e( 'Pause', 'add-from-server-reloaded' ); ?></button>
							<button type="button" class="afsr-btn afsr-btn-ghost" id="afsrreloaded-resume-job" hidden><?php esc_html_e( 'Resume', 'add-from-server-reloaded' ); ?></button>
							<?php endif; ?>
						</div>
						<?php if ( Features::enabled( 'queue_controls' ) ) : ?>
						<div class="afsr-wizard-hidden-extra" hidden>
							<button type="button" class="button" id="afsrreloaded-retry-failed" hidden><?php esc_html_e( 'Retry failed', 'add-from-server-reloaded' ); ?></button>
						</div>
						<?php endif; ?>
						<div class="afsrreloaded-progress-log afsr-wizard-hidden-log" aria-hidden="true"></div>
						<span class="afsrreloaded-import-status afsr-wizard-hidden-counts" aria-hidden="true"></span>
					</div>
				</div>

				<div class="afsr-panel" data-afsr-panel="complete" hidden>
					<div class="afsr-card">
						<div class="afsr-complete-head">
							<span class="afsr-complete-icon" aria-hidden="true">&#10003;</span>
							<h2 class="afsr-complete-title"><?php esc_html_e( 'Import complete', 'add-from-server-reloaded' ); ?></h2>
						</div>
						<p class="afsr-complete-stats" data-afsr-complete="stats">0 imported · 0 duplicates skipped · 0 errors</p>
						<div class="afsr-complete-actions">
							<button type="button" class="afsr-btn afsr-btn-primary" data-afsr-action="import-more" data-afsr-root-url="<?php echo esc_url( add_query_arg( 'path', rawurlencode( '/' ), $url ) ); ?>"><?php esc_html_e( 'Import more files', 'add-from-server-reloaded' ); ?></button>
							<?php if ( Features::enabled( 'history' ) ) : ?>
							<a class="afsr-link" href="<?php echo esc_url( admin_url( 'admin.php?page=add-from-server-reloaded-history' ) ); ?>"><?php esc_html_e( 'View in Import History', 'add-from-server-reloaded' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</form>
			<?php Pro_Teaser::support_layout_close(); ?>
		</div>
		<?php
	}

	/**
	 * Display outdated options notice.
	 *
	 * @since 4.0.0
	 */
	public function outdated_options_notice() {
		$old_root = get_option( 'frmsvr_root', '' );

		if (
			$old_root &&
			str_contains( $old_root, '%' ) &&
			! defined( 'ADD_FROM_SERVER_RELOADED' ) &&
			! defined( 'ADD_FROM_SERVER' )
		) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				wp_kses_post(
					/* translators: %username% and %role% are literal example text that users may have entered in settings, not string placeholders. The <a> tag links to options.php page. */
					__( 'You previously used the "Root Directory" option with a placeholder, such as %username% or %role%. Unfortunately this feature is no longer supported. As a result, Add From Server has been disabled for users who have restricted upload privileges. To make this warning go away, empty the "frmsvr_root" option on <a href="options.php#frmsvr_root">options.php</a>.', 'add-from-server-reloaded' )
				)
			);
		}

		if ( $old_root && ! str_starts_with( $old_root, $this->get_root() ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				wp_kses_post(
					sprintf(
						/* translators: 1: old root path, 2: new root path */
						__( 'Warning: Root Directory changed. You previously used <code>%1$s</code> as your "Root Directory", this has been changed to <code>%2$s</code>. To restore your previous settings, add the following line to your <code>wp-config.php</code> file: <code>define( "ADD_FROM_SERVER_RELOADED", "%1$s" );</code> To make this warning go away, empty the "frmsvr_root" option on <a href="options.php#frmsvr_root">options.php</a>.', 'add-from-server-reloaded' ),
						esc_html( $old_root ),
						esc_html( $this->get_root() )
					)
				)
			);
		}
	}
}

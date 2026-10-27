<?php
/**
 * Locked (Free) previews of Pro admin screens.
 *
 * @package AFSRReloaded
 * @since   5.4.3
 */

namespace AFSRReloaded;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders empty, disabled Pro feature UIs with an upgrade banner.
 *
 * @since 5.4.3
 */
class Pro_Locked_Screens {

	/**
	 * Open shared page chrome.
	 *
	 * @since 5.4.3
	 *
	 * @param string $title    Page title.
	 * @param string $subtitle Page subtitle.
	 * @param string $banner_title Banner bold title.
	 * @param string $banner_desc  Banner description.
	 */
	public static function open( $title, $subtitle, $banner_title, $banner_desc ) {
		Pro_Teaser::enqueue_locked_ui_assets();
		?>
		<div class="wrap afsr-admin-wrap">
			<div id="afsr-admin-app" class="afsr-wrap afsr-locked-page">
				<div class="afsr-page-header">
					<h1 class="afsr-page-title"><?php echo esc_html( $title ); ?></h1>
					<p class="afsr-page-subtitle"><?php echo esc_html( $subtitle ); ?></p>
				</div>
				<?php Pro_Teaser::render_upgrade_banner( $banner_title, $banner_desc ); ?>
				<div class="afsr-locked-content" aria-disabled="true">
		<?php
	}

	/**
	 * Close shared page chrome.
	 *
	 * @since 5.4.3
	 */
	public static function close() {
		?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Import History locked preview.
	 *
	 * @since 5.4.3
	 */
	public static function history() {
		self::open(
			__( 'Import History', 'add-from-server-reloaded' ),
			__( 'Every import job, with per-file results.', 'add-from-server-reloaded' ),
			__( 'Import History is a Pro feature', 'add-from-server-reloaded' ),
			__( 'Track what succeeded or failed, and open per-file details for each job.', 'add-from-server-reloaded' )
		);
		?>
		<div class="afsr-card">
			<table class="afsr-locked-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Job', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Status', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Progress', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Imported', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Errors', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Created', 'add-from-server-reloaded' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td colspan="6" class="afsr-locked-empty"><?php esc_html_e( 'No import jobs yet.', 'add-from-server-reloaded' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
		self::close();
	}

	/**
	 * Scheduled Imports locked preview.
	 *
	 * @since 5.4.3
	 */
	public static function schedules() {
		self::open(
			__( 'Scheduled Imports', 'add-from-server-reloaded' ),
			__( 'Automatic imports on a recurring schedule.', 'add-from-server-reloaded' ),
			__( 'Scheduled Imports is a Pro feature', 'add-from-server-reloaded' ),
			__( 'Pull files from a folder automatically, on a schedule, no manual runs needed.', 'add-from-server-reloaded' )
		);
		?>
		<div class="afsr-card">
			<table class="afsr-locked-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Folder', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Frequency', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Active', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Last run', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Next run', 'add-from-server-reloaded' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td colspan="6" class="afsr-locked-empty"><?php esc_html_e( 'No schedules yet.', 'add-from-server-reloaded' ); ?></td>
					</tr>
				</tbody>
			</table>
			<p class="afsr-locked-actions">
				<button type="button" class="afsr-btn afsr-btn-secondary" disabled><?php esc_html_e( 'New schedule', 'add-from-server-reloaded' ); ?></button>
			</p>
		</div>

		<div class="afsr-card" style="margin-top:18px;">
			<h2 class="afsr-summary-title"><?php esc_html_e( 'Recent runs', 'add-from-server-reloaded' ); ?></h2>
			<table class="afsr-locked-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Schedule', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Status', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Job', 'add-from-server-reloaded' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td colspan="4" class="afsr-locked-empty"><?php esc_html_e( 'No recent runs.', 'add-from-server-reloaded' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
		self::close();
	}

	/**
	 * Remote Sources locked preview.
	 *
	 * @since 5.4.3
	 */
	public static function remote() {
		self::open(
			__( 'Remote Sources', 'add-from-server-reloaded' ),
			__( 'Connect FTP, SFTP, or S3-compatible storage.', 'add-from-server-reloaded' ),
			__( 'Remote Sources is a Pro feature', 'add-from-server-reloaded' ),
			__( 'Connect FTP, SFTP, or S3-compatible storage, browse it, and import straight in.', 'add-from-server-reloaded' )
		);
		?>
		<div class="afsr-card">
			<table class="afsr-locked-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Type', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Host / bucket', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Path', 'add-from-server-reloaded' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td colspan="4" class="afsr-locked-empty"><?php esc_html_e( 'No remote sources yet.', 'add-from-server-reloaded' ); ?></td>
					</tr>
				</tbody>
			</table>
			<p class="afsr-locked-actions">
				<button type="button" class="afsr-btn afsr-btn-secondary" disabled><?php esc_html_e( 'Add remote source', 'add-from-server-reloaded' ); ?></button>
			</p>
		</div>

		<div class="afsr-card" style="margin-top:18px;">
			<h2 class="afsr-summary-title"><?php esc_html_e( 'Stage & import', 'add-from-server-reloaded' ); ?></h2>
			<p class="afsr-page-subtitle" style="margin-bottom:14px;"><?php esc_html_e( 'Download a remote folder into local staging, then import it like any server file.', 'add-from-server-reloaded' ); ?></p>
			<div class="afsr-locked-form-row">
				<label>
					<span><?php esc_html_e( 'Source', 'add-from-server-reloaded' ); ?></span>
					<select disabled><option><?php esc_html_e( 'No sources', 'add-from-server-reloaded' ); ?></option></select>
				</label>
				<label>
					<span><?php esc_html_e( 'Remote path', 'add-from-server-reloaded' ); ?></span>
					<input type="text" disabled value="" placeholder="/incoming" />
				</label>
				<button type="button" class="afsr-btn afsr-btn-primary" disabled><?php esc_html_e( 'Stage & import', 'add-from-server-reloaded' ); ?></button>
			</div>
		</div>
		<?php
		self::close();
	}

	/**
	 * Duplicates locked preview.
	 *
	 * @since 5.4.3
	 */
	public static function duplicates() {
		self::open(
			__( 'Duplicates', 'add-from-server-reloaded' ),
			__( 'Find files with identical content and keep one copy.', 'add-from-server-reloaded' ),
			__( 'Duplicate cleanup is a Pro feature', 'add-from-server-reloaded' ),
			__( 'Group files by content, keep one copy, and delete the rest in a click.', 'add-from-server-reloaded' )
		);
		?>
		<div class="afsr-card">
			<p class="afsr-locked-empty" style="margin:0;"><?php esc_html_e( 'No duplicate groups found.', 'add-from-server-reloaded' ); ?></p>
		</div>
		<?php
		self::close();
	}

	/**
	 * Email Alerts locked preview.
	 *
	 * @since 5.4.3
	 */
	public static function email() {
		self::open(
			__( 'Email Alerts', 'add-from-server-reloaded' ),
			__( 'Get notified when an import finishes or fails.', 'add-from-server-reloaded' ),
			__( 'Email Alerts is a Pro feature', 'add-from-server-reloaded' ),
			__( 'Get an email when an import finishes or fails, so you don\'t have to watch it.', 'add-from-server-reloaded' )
		);
		?>
		<div class="afsr-card">
			<label class="afsr-locked-check">
				<input type="checkbox" disabled />
				<span><?php esc_html_e( 'Send email when import jobs finish', 'add-from-server-reloaded' ); ?></span>
			</label>

			<h3 class="afsr-locked-section-title"><?php esc_html_e( 'Events', 'add-from-server-reloaded' ); ?></h3>
			<label class="afsr-locked-check"><input type="checkbox" disabled /> <span><?php esc_html_e( 'Completed jobs', 'add-from-server-reloaded' ); ?></span></label>
			<label class="afsr-locked-check"><input type="checkbox" disabled /> <span><?php esc_html_e( 'Failed jobs', 'add-from-server-reloaded' ); ?></span></label>
			<label class="afsr-locked-check"><input type="checkbox" disabled /> <span><?php esc_html_e( 'Cancelled jobs', 'add-from-server-reloaded' ); ?></span></label>

			<h3 class="afsr-locked-section-title"><?php esc_html_e( 'Recipients', 'add-from-server-reloaded' ); ?></h3>
			<textarea class="afsr-locked-textarea" disabled placeholder="<?php esc_attr_e( 'Leave empty to use the site admin email', 'add-from-server-reloaded' ); ?>"></textarea>

			<p class="afsr-locked-actions">
				<button type="button" class="afsr-btn afsr-btn-primary" disabled><?php esc_html_e( 'Save changes', 'add-from-server-reloaded' ); ?></button>
			</p>
		</div>
		<?php
		self::close();
	}

	/**
	 * Access Control locked preview.
	 *
	 * @since 5.4.3
	 */
	public static function access() {
		self::open(
			__( 'Access Control', 'add-from-server-reloaded' ),
			__( 'Grant custom capabilities to roles.', 'add-from-server-reloaded' ),
			__( 'Access Control is a Pro feature', 'add-from-server-reloaded' ),
			__( 'Choose which roles can browse files and which can run imports.', 'add-from-server-reloaded' )
		);

		$roles  = function_exists( 'get_editable_roles' ) ? get_editable_roles() : wp_roles()->roles;
		$labels = array(
			'browse'   => __( 'Browse', 'add-from-server-reloaded' ),
			'import'   => __( 'Import', 'add-from-server-reloaded' ),
			'history'  => __( 'History / duplicates', 'add-from-server-reloaded' ),
			'schedule' => __( 'Schedules', 'add-from-server-reloaded' ),
			'remote'   => __( 'Remote sources', 'add-from-server-reloaded' ),
		);
		?>
		<div class="afsr-card">
			<table class="afsr-locked-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Role', 'add-from-server-reloaded' ); ?></th>
						<?php foreach ( $labels as $label ) : ?>
							<th><?php echo esc_html( $label ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $roles as $role_key => $role_data ) : ?>
					<tr>
						<td><strong><?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?></strong></td>
						<?php foreach ( array_keys( $labels ) as $cap_key ) : ?>
							<td><input type="checkbox" disabled <?php echo ( 'administrator' === $role_key ) ? 'checked' : ''; ?> /></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="afsr-locked-actions">
				<button type="button" class="afsr-btn afsr-btn-primary" disabled><?php esc_html_e( 'Save access rules', 'add-from-server-reloaded' ); ?></button>
			</p>
		</div>
		<?php
		self::close();
	}
}

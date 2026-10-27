<?php
/**
 * Import job processor (scan + chunked import).
 *
 * @package AFSRReloaded
 * @since   5.3.0
 */

namespace AFSRReloaded;

use WP_Error;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes import jobs in time-boxed chunks.
 *
 * @since 5.3.0
 */
class Import_Processor {

	/**
	 * Default files processed per chunk.
	 *
	 * @since 5.3.0
	 * @var int
	 */
	const DEFAULT_CHUNK_SIZE = 5;

	/**
	 * Soft time budget per request (seconds).
	 *
	 * @since 5.3.0
	 * @var int
	 */
	const DEFAULT_TIME_BUDGET = 15;

	/**
	 * Max folder entries inspected per scan chunk.
	 *
	 * @since 5.3.0
	 * @var int
	 */
	const DEFAULT_SCAN_BUDGET = 200;

	/**
	 * Plugin instance.
	 *
	 * @since 5.3.0
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Constructor.
	 *
	 * @since 5.3.0
	 *
	 * @param Plugin $plugin Main plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Create a job from selected files and folders.
	 *
	 * @since 5.3.0
	 *
	 * @param string[] $files   Relative file paths.
	 * @param string[] $folders Relative folder paths.
	 * @param array    $options Job options.
	 * @return object|WP_Error Job object or error.
	 */
	public function create_job( $files, $folders, $options = array() ) {
		$root = $this->plugin->get_root();
		if ( ! $root ) {
			return new WP_Error( 'no_root', __( 'Unable to determine root directory.', 'add-from-server-reloaded' ) );
		}

		$files   = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $files ) ) ) );
		$folders = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $folders ) ) ) );

		if ( empty( $files ) && empty( $folders ) ) {
			return new WP_Error( 'no_selection', __( 'Please select at least one file or folder to import.', 'add-from-server-reloaded' ) );
		}

		$defaults              = array(
			'mode'               => 'ajax',
			'generate_metadata'  => true,
			'chunk_size'         => self::DEFAULT_CHUNK_SIZE,
			'background'         => false,
			'preserve_structure' => false,
			'duplicate_action'   => 'skip',
			'file_types'         => 'all',
			'allowed_exts'       => '',
			'max_file_size_mb'   => 0,
			'min_mtime'          => 0,
		);
		$options               = wp_parse_args( $options, $defaults );
		$options['chunk_size'] = max( 1, min( 25, absint( $options['chunk_size'] ) ) );
		// Freeze the browse root for this job so background/cron use the same jail.
		$options['root'] = wp_normalize_path( untrailingslashit( $root ) );

		/**
		 * Filters import job options before creation.
		 *
		 * @since 5.3.0
		 *
		 * @param array $options Job options.
		 * @param array $files   Selected files.
		 * @param array $folders Selected folders.
		 */
		$options = apply_filters( 'afsrreloaded_import_job_options', $options, $files, $folders );

		$validated_files  = array();
		$skipped_selected = array();
		$scan_queue       = array();

		foreach ( $files as $relative ) {
			$absolute = $this->resolve_path( $root, $relative );
			if ( is_wp_error( $absolute ) ) {
				$skipped_selected[] = array(
					'path'    => $relative,
					'message' => $absolute->get_error_message(),
				);
				continue;
			}

			if ( $this->plugin->is_restricted_file( $absolute ) ) {
				$skipped_selected[] = array(
					'path'    => $relative,
					'message' => __( 'This file was not imported due to security restrictions.', 'add-from-server-reloaded' ),
				);
				continue;
			}

			$validated_files[] = ltrim( $relative, '/' );
		}

		foreach ( $folders as $folder ) {
			$absolute = $this->resolve_path( $root, $folder );
			if ( is_wp_error( $absolute ) || ! is_dir( $absolute ) ) {
				continue;
			}
			$scan_queue[] = array(
				'path'   => ltrim( $folder, '/' ),
				'offset' => 0,
			);
		}

		if ( empty( $validated_files ) && empty( $scan_queue ) ) {
			return new WP_Error(
				'nothing_importable',
				__( 'No importable files were found in your selection.', 'add-from-server-reloaded' )
			);
		}

		$mode   = ! empty( $options['background'] ) ? 'background' : 'ajax';
		$status = ! empty( $scan_queue ) ? 'scanning' : 'pending';

		$options['initial_skipped'] = $skipped_selected;

		$job_id = Import_Job_Repository::create_job(
			array(
				'status'     => $status,
				'mode'       => $mode,
				'options'    => $options,
				'scan_queue' => $scan_queue,
			)
		);

		if ( ! $job_id ) {
			return new WP_Error( 'create_failed', __( 'Could not create the import job.', 'add-from-server-reloaded' ) );
		}

		if ( ! empty( $validated_files ) ) {
			$inserted = Import_Job_Repository::insert_items( $job_id, $validated_files );
			Import_Job_Repository::increment_counters(
				$job_id,
				array(
					'total_files' => $inserted,
					'skipped'     => count( $skipped_selected ),
				)
			);
		} elseif ( ! empty( $skipped_selected ) ) {
			Import_Job_Repository::increment_counters(
				$job_id,
				array( 'skipped' => count( $skipped_selected ) )
			);
		}

		$job = Import_Job_Repository::get_job( $job_id );

		if ( $job && 'background' === $mode ) {
			/**
			 * Ensure Pro background cron is registered (no-op without Pro).
			 *
			 * @since 5.5.0
			 */
			do_action( 'afsrreloaded_ensure_background_cron' );

			/**
			 * Request a near-term background processing tick (Pro).
			 *
			 * @since 5.5.0
			 *
			 * @param object|null $job Job object when available.
			 */
			do_action( 'afsrreloaded_schedule_background_tick', $job );
		}

		/**
		 * Fires after an import job is created.
		 *
		 * @since 5.3.0
		 *
		 * @param object $job Job object.
		 */
		do_action( 'afsrreloaded_import_job_created', $job );

		return $job;
	}

	/**
	 * Scan folders into job items (chunked).
	 *
	 * @since 5.3.0
	 *
	 * @param int $job_id Job ID.
	 * @return array|WP_Error Status payload.
	 */
	public function scan_chunk( $job_id ) {
		$job = Import_Job_Repository::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'invalid_job', __( 'Import job not found.', 'add-from-server-reloaded' ) );
		}

		if ( in_array( $job->status, array( 'cancelled', 'completed', 'failed', 'paused' ), true ) ) {
			return $this->status_payload( $job );
		}

		$root = $this->job_root( $job );
		if ( ! $root ) {
			return new WP_Error( 'no_root', __( 'Unable to determine root directory.', 'add-from-server-reloaded' ) );
		}

		Import_Job_Repository::update_job( $job_id, array( 'status' => 'scanning' ) );

		$started    = microtime( true );
		$budget     = (int) apply_filters( 'afsrreloaded_scan_time_budget', self::DEFAULT_TIME_BUDGET );
		$scan_limit = (int) apply_filters( 'afsrreloaded_scan_entry_budget', self::DEFAULT_SCAN_BUDGET );
		$queue      = $this->normalize_scan_queue( $job->scan_queue );
		$inspected  = 0;
		$found      = array();
		$blocked    = 0;

		while ( ! empty( $queue ) && $inspected < $scan_limit && ( microtime( true ) - $started ) < $budget ) {
			// Stop promptly if the user paused/cancelled mid-scan.
			$live = Import_Job_Repository::get_job( $job_id );
			if ( $live && in_array( $live->status, array( 'paused', 'cancelled' ), true ) ) {
				$payload                  = $this->status_payload( $live );
				$payload['scan_complete'] = false;
				return $payload;
			}

			$current    = array_shift( $queue );
			$folder_rel = isset( $current['path'] ) ? (string) $current['path'] : '';
			$offset     = isset( $current['offset'] ) ? absint( $current['offset'] ) : 0;
			$folder_abs = $this->resolve_path( $root, $folder_rel );

			if ( is_wp_error( $folder_abs ) || ! is_dir( $folder_abs ) ) {
				continue;
			}

			$entries = glob( trailingslashit( $folder_abs ) . '*' );
			if ( ! $entries ) {
				continue;
			}

			// Stable ordering so offset resume is deterministic.
			sort( $entries, SORT_STRING );
			$total_entries = count( $entries );

			for ( $i = $offset; $i < $total_entries; $i++ ) {
				++$inspected;
				if ( $inspected > $scan_limit || ( microtime( true ) - $started ) >= $budget ) {
					// Resume this folder later from the current index.
					array_unshift(
						$queue,
						array(
							'path'   => $folder_rel,
							'offset' => $i,
						)
					);
					break 2;
				}

				$entry = $entries[ $i ];
				$name  = basename( $entry );
				if ( '' === $name || '.' === $name[0] ) {
					continue;
				}

				$relative = Path_Guard::absolute_to_relative( $entry, $root );
				if ( false === $relative ) {
					continue;
				}

				if ( is_dir( $entry ) ) {
					$queue[] = array(
						'path'   => $relative,
						'offset' => 0,
					);
					continue;
				}

				if ( ! is_file( $entry ) ) {
					continue;
				}

				if ( $this->plugin->is_restricted_file( $entry ) ) {
					++$blocked;
					continue;
				}

				if ( ! File_Filters::passes( $entry, is_array( $job->options ) ? $job->options : array() ) ) {
					++$blocked;
					continue;
				}

				$found[] = $relative;
			}
		}

		// Deduplicate within this chunk before insert.
		$found    = array_values( array_unique( $found ) );
		$inserted = 0;
		if ( ! empty( $found ) ) {
			$inserted = Import_Job_Repository::insert_items( $job_id, $found );
		}

		$deltas = array(
			'total_files' => $inserted,
			'skipped'     => $blocked,
		);
		Import_Job_Repository::increment_counters( $job_id, $deltas );

		// Do not overwrite a concurrent pause/cancel.
		$fresh = Import_Job_Repository::get_job( $job_id );
		if ( $fresh && in_array( $fresh->status, array( 'paused', 'cancelled' ), true ) ) {
			$payload                       = $this->status_payload( $fresh );
			$payload['scan_complete']      = empty( $fresh->scan_queue );
			$payload['scanned_this_chunk'] = $inserted;
			$payload['blocked_this_chunk'] = $blocked;
			return $payload;
		}

		$update = array(
			'scan_queue' => $queue,
		);

		if ( empty( $queue ) ) {
			$update['status'] = 'pending';
		}

		Import_Job_Repository::update_job( $job_id, $update );

		$job                           = Import_Job_Repository::get_job( $job_id );
		$payload                       = $this->status_payload( $job );
		$payload['scan_complete']      = empty( $job->scan_queue );
		$payload['scanned_this_chunk'] = $inserted;
		$payload['blocked_this_chunk'] = $blocked;

		if ( $job && 'background' === $job->mode && empty( $job->scan_queue ) && in_array( $job->status, array( 'pending', 'running' ), true ) ) {
			do_action( 'afsrreloaded_schedule_background_tick', $job );
		}

		return $payload;
	}

	/**
	 * Process a chunk of pending import items.
	 *
	 * @since 5.3.0
	 *
	 * @param int $job_id Job ID.
	 * @return array|WP_Error Status payload.
	 */
	public function process_chunk( $job_id ) {
		$job = Import_Job_Repository::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'invalid_job', __( 'Import job not found.', 'add-from-server-reloaded' ) );
		}

		if ( 'paused' === $job->status || 'cancelled' === $job->status ) {
			return $this->status_payload( $job );
		}

		if ( ! empty( $job->scan_queue ) || 'scanning' === $job->status ) {
			return new WP_Error( 'still_scanning', __( 'Folder scan is not finished yet.', 'add-from-server-reloaded' ) );
		}

		$root = $this->job_root( $job );
		if ( ! $root ) {
			return new WP_Error( 'no_root', __( 'Unable to determine root directory.', 'add-from-server-reloaded' ) );
		}

		Import_Job_Repository::update_job( $job_id, array( 'status' => 'running' ) );

		$chunk_size = isset( $job->options['chunk_size'] ) ? absint( $job->options['chunk_size'] ) : self::DEFAULT_CHUNK_SIZE;
		$chunk_size = max( 1, min( 25, (int) apply_filters( 'afsrreloaded_import_chunk_size', $chunk_size, $job ) ) );
		$budget     = (int) apply_filters( 'afsrreloaded_import_time_budget', self::DEFAULT_TIME_BUDGET, $job );
		$generate = ! isset( $job->options['generate_metadata'] ) || ! empty( $job->options['generate_metadata'] );

		/**
		 * Whether this job should preserve source folder structure (Pro).
		 *
		 * @since 5.5.0
		 *
		 * @param bool   $preserve Default from job options + feature flag.
		 * @param object $job      Job object.
		 */
		$preserve = (bool) apply_filters(
			'afsrreloaded_job_preserve_structure',
			! empty( $job->options['preserve_structure'] ) && Features::enabled( 'folder_preserve' ),
			$job
		);

		/**
		 * Default duplicate action when the job omits one (Pro may override).
		 *
		 * @since 5.5.0
		 *
		 * @param string $action Default action: skip|replace|rename.
		 */
		$fallback   = sanitize_key( (string) apply_filters( 'afsrreloaded_default_duplicate_action', 'skip' ) );
		$dup_action = 'skip';
		if ( Features::enabled( 'advanced_duplicates' ) ) {
			$dup_action = sanitize_key( $job->options['duplicate_action'] ?? $fallback );
			if ( ! in_array( $dup_action, array( 'skip', 'replace', 'rename' ), true ) ) {
				$dup_action = in_array( $fallback, array( 'skip', 'replace', 'rename' ), true ) ? $fallback : 'skip';
			}
		}

		$items   = Import_Job_Repository::get_pending_items( $job_id, $chunk_size );
		$started = microtime( true );
		$results = array();

		$counters = array(
			'processed_files' => 0,
			'imported'        => 0,
			'duplicates'      => 0,
			'errors'          => 0,
			'skipped'         => 0,
		);

		foreach ( $items as $item ) {
			if ( ( microtime( true ) - $started ) >= $budget ) {
				break;
			}

			$path_rel = $item->file_path;
			$absolute = $this->resolve_path( $root, $path_rel );

			if ( is_wp_error( $absolute ) ) {
				Import_Job_Repository::update_item(
					$item->id,
					array(
						'status'       => 'error',
						'message'      => $absolute->get_error_message(),
						'processed_at' => current_time( 'mysql', true ),
					)
				);
				++$counters['processed_files'];
				++$counters['errors'];
				$results[] = array(
					'file'    => basename( $path_rel ),
					'status'  => 'error',
					'message' => $absolute->get_error_message(),
				);
				continue;
			}

			$id = $this->plugin->handle_import_file(
				$absolute,
				array(
					'generate_metadata'  => $generate,
					'source_relative'    => $path_rel,
					'preserve_structure' => $preserve,
					'duplicate_action'   => $dup_action,
				)
			);

			if ( is_wp_error( $id ) ) {
				$code = $id->get_error_code();
				if ( 'file_exists' === $code ) {
					$status_key = 'duplicate';
					++$counters['duplicates'];
				} elseif ( 'dangerous_file_type' === $code || 'wrong_file_type' === $code ) {
					$status_key = 'skipped';
					++$counters['skipped'];
				} else {
					$status_key = 'error';
					++$counters['errors'];
				}

				Import_Job_Repository::update_item(
					$item->id,
					array(
						'status'       => $status_key,
						'message'      => wp_strip_all_tags( $id->get_error_message() ),
						'processed_at' => current_time( 'mysql', true ),
					)
				);

				$results[] = array(
					'file'    => basename( $path_rel ),
					'status'  => $status_key,
					'message' => wp_strip_all_tags( $id->get_error_message() ),
				);
			} else {
				Import_Job_Repository::update_item(
					$item->id,
					array(
						'status'        => 'imported',
						'attachment_id' => absint( $id ),
						'message'       => __( 'Imported successfully.', 'add-from-server-reloaded' ),
						'processed_at'  => current_time( 'mysql', true ),
					)
				);
				++$counters['imported'];
				$results[] = array(
					'file'          => basename( $path_rel ),
					'status'        => 'imported',
					'attachment_id' => absint( $id ),
					'message'       => __( 'Imported successfully.', 'add-from-server-reloaded' ),
				);
			}

			++$counters['processed_files'];

			// Free memory between files on large imports.
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		Import_Job_Repository::increment_counters( $job_id, $counters );

		// A concurrent pause/cancel must win over this chunk's status writes.
		$fresh = Import_Job_Repository::get_job( $job_id );
		if ( $fresh && in_array( $fresh->status, array( 'paused', 'cancelled' ), true ) ) {
			$payload                  = $this->status_payload( $fresh );
			$payload['chunk_results'] = $results;
			return $payload;
		}

		$job = $fresh ? $fresh : Import_Job_Repository::get_job( $job_id );
		$this->maybe_complete_job( $job );

		$job                      = Import_Job_Repository::get_job( $job_id );
		$payload                  = $this->status_payload( $job );
		$payload['chunk_results'] = $results;

		if ( $job && 'background' === $job->mode && in_array( $job->status, array( 'running', 'pending' ), true ) ) {
			do_action( 'afsrreloaded_schedule_background_tick', $job );
		}

		return $payload;
	}

	/**
	 * Pause a running job.
	 *
	 * @since 5.3.0
	 *
	 * @param int $job_id Job ID.
	 * @return array|WP_Error
	 */
	public function pause_job( $job_id ) {
		$job = Import_Job_Repository::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'invalid_job', __( 'Import job not found.', 'add-from-server-reloaded' ) );
		}

		if ( ! in_array( $job->status, array( 'pending', 'scanning', 'running' ), true ) ) {
			return $this->status_payload( $job );
		}

		Import_Job_Repository::update_job( $job_id, array( 'status' => 'paused' ) );
		return $this->status_payload( Import_Job_Repository::get_job( $job_id ) );
	}

	/**
	 * Resume a paused job.
	 *
	 * @since 5.3.0
	 *
	 * @param int $job_id Job ID.
	 * @return array|WP_Error
	 */
	public function resume_job( $job_id ) {
		$job = Import_Job_Repository::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'invalid_job', __( 'Import job not found.', 'add-from-server-reloaded' ) );
		}

		if ( 'paused' !== $job->status ) {
			return $this->status_payload( $job );
		}

		$next = ! empty( $job->scan_queue ) ? 'scanning' : 'running';
		Import_Job_Repository::update_job(
			$job_id,
			array(
				'status' => $next,
				'mode'   => 'background' === $job->mode ? 'background' : $job->mode,
			)
		);

		if ( 'background' === $job->mode ) {
			do_action( 'afsrreloaded_schedule_background_tick', Import_Job_Repository::get_job( $job_id ) );
		}

		return $this->status_payload( Import_Job_Repository::get_job( $job_id ) );
	}

	/**
	 * Cancel a job.
	 *
	 * @since 5.3.0
	 *
	 * @param int $job_id Job ID.
	 * @return array|WP_Error
	 */
	public function cancel_job( $job_id ) {
		$job = Import_Job_Repository::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'invalid_job', __( 'Import job not found.', 'add-from-server-reloaded' ) );
		}

		Import_Job_Repository::cancel_pending_items( $job_id );
		Import_Job_Repository::update_job(
			$job_id,
			array(
				'status'       => 'cancelled',
				'scan_queue'   => array(),
				'completed_at' => current_time( 'mysql', true ),
			)
		);

		return $this->status_payload( Import_Job_Repository::get_job( $job_id ) );
	}

	/**
	 * Retry failed items on a job.
	 *
	 * @since 5.3.0
	 *
	 * @param int $job_id Job ID.
	 * @return array|WP_Error
	 */
	public function retry_failed( $job_id ) {
		$job = Import_Job_Repository::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'invalid_job', __( 'Import job not found.', 'add-from-server-reloaded' ) );
		}

		$reset = Import_Job_Repository::reset_failed_items( $job_id );
		if ( $reset < 1 ) {
			return new WP_Error( 'nothing_to_retry', __( 'No failed files to retry.', 'add-from-server-reloaded' ) );
		}

		// Adjust counters: failed items move back to pending.
		Import_Job_Repository::update_job(
			$job_id,
			array(
				'status'          => 'pending',
				'errors'          => max( 0, (int) $job->errors - $reset ),
				'processed_files' => max( 0, (int) $job->processed_files - $reset ),
				'completed_at'    => null,
			)
		);

		if ( 'background' === $job->mode ) {
			do_action( 'afsrreloaded_schedule_background_tick', Import_Job_Repository::get_job( $job_id ) );
		}

		return $this->status_payload( Import_Job_Repository::get_job( $job_id ) );
	}

	/**
	 * Build a consistent status payload for AJAX/UI.
	 *
	 * @since 5.3.0
	 *
	 * @param object $job Job object.
	 * @return array
	 */
	public function status_payload( $job ) {
		$total     = max( 0, (int) $job->total_files );
		$processed = max( 0, (int) $job->processed_files );
		$percent   = ( $total > 0 ) ? min( 100, round( ( $processed / $total ) * 100, 1 ) ) : 0;

		if ( 'scanning' === $job->status || ! empty( $job->scan_queue ) ) {
			$percent = 0;
		}

		$recent = array();
		foreach ( Import_Job_Repository::get_recent_items( $job->id, 15 ) as $item ) {
			$recent[] = array(
				'file'          => basename( $item->file_path ),
				'status'        => $item->status,
				'message'       => $item->message,
				'attachment_id' => $item->attachment_id ? (int) $item->attachment_id : null,
			);
		}

		return array(
			'job_id'         => (int) $job->id,
			'status'         => $job->status,
			'mode'           => $job->mode,
			'total'          => $total,
			'processed'      => $processed,
			'imported'       => (int) $job->imported,
			'duplicates'     => (int) $job->duplicates,
			'errors'         => (int) $job->errors,
			'skipped'        => (int) $job->skipped,
			'percent'        => $percent,
			'scan_remaining' => is_array( $job->scan_queue ) ? count( $job->scan_queue ) : 0,
			'scan_complete'  => empty( $job->scan_queue ) && 'scanning' !== $job->status,
			'is_complete'    => in_array( $job->status, array( 'completed', 'cancelled', 'failed' ), true ),
			'recent'         => $recent,
			'created_at'     => $job->created_at,
			'updated_at'     => $job->updated_at,
			'completed_at'   => $job->completed_at,
		);
	}

	/**
	 * Mark job completed when no pending work remains.
	 *
	 * @since 5.3.0
	 *
	 * @param object $job Job object.
	 */
	protected function maybe_complete_job( $job ) {
		if ( ! $job || in_array( $job->status, array( 'cancelled', 'paused', 'failed', 'completed' ), true ) ) {
			return;
		}

		if ( ! empty( $job->scan_queue ) ) {
			return;
		}

		$pending = Import_Job_Repository::get_pending_items( $job->id, 1 );
		if ( ! empty( $pending ) ) {
			// Keep "running" while work remains so the UI / cron do not treat the
			// job as idle "pending" between chunks (looks stuck; pause races).
			if ( 'running' !== $job->status ) {
				Import_Job_Repository::update_job( $job->id, array( 'status' => 'running' ) );
			}
			return;
		}

		Import_Job_Repository::update_job(
			$job->id,
			array(
				'status'       => 'completed',
				'completed_at' => current_time( 'mysql', true ),
			)
		);

		/**
		 * Fires when an import job completes.
		 *
		 * @since 5.3.0
		 *
		 * @param object $job Job object.
		 */
		do_action( 'afsrreloaded_import_job_completed', Import_Job_Repository::get_job( $job->id ) );
	}

	/**
	 * Root directory frozen on the job (falls back to current plugin root).
	 *
	 * @since 5.3.0
	 *
	 * @param object $job Job row.
	 * @return string|false
	 */
	protected function job_root( $job ) {
		if ( $job && ! empty( $job->options['root'] ) && is_string( $job->options['root'] ) ) {
			$stored = wp_normalize_path( untrailingslashit( $job->options['root'] ) );
			if ( Path_Guard::is_path_allowed( $stored ) && is_dir( $stored ) && is_readable( $stored ) ) {
				return $stored;
			}
		}

		return $this->plugin->get_root();
	}

	/**
	 * Resolve and validate a relative path against the root.
	 *
	 * @since 5.3.0
	 *
	 * @param string $root     Absolute root.
	 * @param string $relative Relative path.
	 * @return string|WP_Error Absolute real path or error.
	 */
	protected function resolve_path( $root, $relative ) {
		$relative  = ltrim( (string) $relative, '/' );
		$root_real = realpath( $root );
		if ( $root_real ) {
			$root = $root_real;
		}
		$candidate = trailingslashit( $root ) . $relative;

		if ( ! file_exists( $candidate ) ) {
			return new WP_Error(
				'missing_file',
				__( 'File not found under the import root. It may have been moved or the path is stale.', 'add-from-server-reloaded' )
			);
		}

		$realpath = realpath( $candidate );

		if ( ! $realpath || ! Path_Guard::path_has_root_boundary( $realpath, $root ) ) {
			return new WP_Error(
				'security_path',
				__( 'Security error: file is outside the allowed directory.', 'add-from-server-reloaded' )
			);
		}

		return $realpath;
	}

	/**
	 * Whether a scanned file passes job filter options.
	 *
	 * @since 5.4.0
	 * @deprecated 5.4.0 Use File_Filters::passes().
	 *
	 * @param string $absolute Absolute path.
	 * @param array  $options  Job options.
	 * @return bool
	 */
	protected function file_passes_filters( $absolute, array $options ) {
		return File_Filters::passes( $absolute, $options );
	}

	/**
	 * Normalize scan queue entries to path/offset arrays.
	 *
	 * @since 5.3.0
	 *
	 * @param array $queue Raw queue.
	 * @return array
	 */
	protected function normalize_scan_queue( $queue ) {
		$normalized = array();

		foreach ( (array) $queue as $entry ) {
			if ( is_string( $entry ) ) {
				$normalized[] = array(
					'path'   => ltrim( $entry, '/' ),
					'offset' => 0,
				);
				continue;
			}

			if ( is_array( $entry ) && ! empty( $entry['path'] ) ) {
				$normalized[] = array(
					'path'   => ltrim( (string) $entry['path'], '/' ),
					'offset' => isset( $entry['offset'] ) ? absint( $entry['offset'] ) : 0,
				);
			}
		}

		return $normalized;
	}
}

<?php
/**
 * Import job persistence layer.
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
 * CRUD helpers for import jobs and job items.
 *
 * @since 5.3.0
 */
class Import_Job_Repository {

	/**
	 * Jobs table name (with prefix).
	 *
	 * @since 5.3.0
	 *
	 * @return string
	 */
	public static function jobs_table() {
		global $wpdb;
		return $wpdb->prefix . 'afsrreloaded_jobs';
	}

	/**
	 * Job items table name (with prefix).
	 *
	 * @since 5.3.0
	 *
	 * @return string
	 */
	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'afsrreloaded_job_items';
	}

	/**
	 * Create a new import job.
	 *
	 * @since 5.3.0
	 *
	 * @param array $args {
	 *     Job creation arguments.
	 *
	 *     @type int    $user_id    WordPress user ID owning the job.
	 *     @type string $mode       Import mode: ajax or background.
	 *     @type array  $options    Job options (chunk size, metadata flags, etc.).
	 *     @type array  $scan_queue Relative folder paths still to scan.
	 * }
	 * @return int|false Job ID or false on failure.
	 */
	public static function create_job( $args ) {
		global $wpdb;

		$defaults = array(
			'user_id'    => get_current_user_id(),
			'mode'       => 'ajax',
			'status'     => 'pending',
			'options'    => array(),
			'scan_queue' => array(),
		);

		$args = wp_parse_args( $args, $defaults );
		$now  = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			self::jobs_table(),
			array(
				'user_id'         => absint( $args['user_id'] ),
				'status'          => sanitize_key( $args['status'] ),
				'mode'            => sanitize_key( $args['mode'] ),
				'total_files'     => 0,
				'processed_files' => 0,
				'imported'        => 0,
				'duplicates'      => 0,
				'errors'          => 0,
				'skipped'         => 0,
				'options'         => wp_json_encode( $args['options'] ),
				'scan_queue'      => wp_json_encode( array_values( (array) $args['scan_queue'] ) ),
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get a job by ID.
	 *
	 * @since 5.3.0
	 *
	 * @param int $job_id Job ID.
	 * @return object|null
	 */
	public static function get_job( $job_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live job row; must not be stale-cached.
		$job = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				self::jobs_table(),
				absint( $job_id )
			)
		);

		if ( ! $job ) {
			return null;
		}

		return self::hydrate_job( $job );
	}

	/**
	 * Normalize decoded JSON fields on a job row.
	 *
	 * @since 5.3.0
	 *
	 * @param object $job Raw DB row.
	 * @return object
	 */
	protected static function hydrate_job( $job ) {
		$job->id              = (int) $job->id;
		$job->user_id         = (int) $job->user_id;
		$job->total_files     = (int) $job->total_files;
		$job->processed_files = (int) $job->processed_files;
		$job->imported        = (int) $job->imported;
		$job->duplicates      = (int) $job->duplicates;
		$job->errors          = (int) $job->errors;
		$job->skipped         = (int) $job->skipped;
		$job->options         = json_decode( (string) $job->options, true );
		$job->scan_queue      = json_decode( (string) $job->scan_queue, true );

		if ( ! is_array( $job->options ) ) {
			$job->options = array();
		}
		if ( ! is_array( $job->scan_queue ) ) {
			$job->scan_queue = array();
		}

		return $job;
	}

	/**
	 * Update job fields.
	 *
	 * @since 5.3.0
	 *
	 * @param int   $job_id Job ID.
	 * @param array $data   Column => value.
	 * @return bool
	 */
	public static function update_job( $job_id, $data ) {
		global $wpdb;

		if ( isset( $data['options'] ) && is_array( $data['options'] ) ) {
			$data['options'] = wp_json_encode( $data['options'] );
		}
		if ( isset( $data['scan_queue'] ) && is_array( $data['scan_queue'] ) ) {
			$data['scan_queue'] = wp_json_encode( array_values( $data['scan_queue'] ) );
		}

		$data['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			self::jobs_table(),
			$data,
			array( 'id' => absint( $job_id ) )
		);

		return false !== $result;
	}

	/**
	 * Increment job counters atomically-ish.
	 *
	 * @since 5.3.0
	 *
	 * @param int   $job_id  Job ID.
	 * @param array $deltas  Keys: processed_files, imported, duplicates, errors, skipped, total_files.
	 * @return bool
	 */
	public static function increment_counters( $job_id, $deltas ) {
		global $wpdb;

		$processed = isset( $deltas['processed_files'] ) ? (int) $deltas['processed_files'] : 0;
		$imported  = isset( $deltas['imported'] ) ? (int) $deltas['imported'] : 0;
		$dups      = isset( $deltas['duplicates'] ) ? (int) $deltas['duplicates'] : 0;
		$errors    = isset( $deltas['errors'] ) ? (int) $deltas['errors'] : 0;
		$skipped   = isset( $deltas['skipped'] ) ? (int) $deltas['skipped'] : 0;
		$total     = isset( $deltas['total_files'] ) ? (int) $deltas['total_files'] : 0;

		if ( ! $processed && ! $imported && ! $dups && ! $errors && ! $skipped && ! $total ) {
			return true;
		}

		// Fixed SQL shape (no concatenated $sql) so Plugin Check can verify prepare/%i.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live counter updates during import.
		return false !== $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET processed_files = processed_files + %d, imported = imported + %d, duplicates = duplicates + %d, errors = errors + %d, skipped = skipped + %d, total_files = total_files + %d, updated_at = %s WHERE id = %d',
				self::jobs_table(),
				$processed,
				$imported,
				$dups,
				$errors,
				$skipped,
				$total,
				current_time( 'mysql', true ),
				absint( $job_id )
			)
		);
	}

	/**
	 * Bulk insert pending file items.
	 *
	 * @since 5.3.0
	 *
	 * @param int      $job_id Job ID.
	 * @param string[] $paths  Relative file paths.
	 * @return int Number of rows inserted.
	 */
	public static function insert_items( $job_id, $paths ) {
		global $wpdb;

		$paths = array_values( array_unique( array_filter( array_map( 'strval', (array) $paths ) ) ) );
		if ( empty( $paths ) ) {
			return 0;
		}

		$table    = self::items_table();
		$job_id   = absint( $job_id );
		$inserted = 0;
		$chunk    = array_chunk( $paths, 100 );

		foreach ( $chunk as $batch ) {
			// Row-by-row insert keeps SQL fully prepared (no dynamic VALUES SQL string).
			// IGNORE semantics: skip duplicates on (job_id, path_hash).
			foreach ( $batch as $path ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk scan path inserts; caching not applicable.
				$result = $wpdb->query(
					$wpdb->prepare(
						'INSERT IGNORE INTO %i (job_id, file_path, path_hash, status) VALUES (%d, %s, %s, %s)',
						$table,
						$job_id,
						$path,
						md5( $path ),
						'pending'
					)
				);
				if ( false !== $result ) {
					$inserted += (int) $result;
				}
			}
		}

		return $inserted;
	}

	/**
	 * Fetch next pending items for a job.
	 *
	 * @since 5.3.0
	 *
	 * @param int $job_id Job ID.
	 * @param int $limit  Max items.
	 * @return array
	 */
	public static function get_pending_items( $job_id, $limit = 5 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue read; caching would stall imports.
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE job_id = %d AND status = %s ORDER BY id ASC LIMIT %d',
				self::items_table(),
				absint( $job_id ),
				'pending',
				absint( $limit )
			)
		);
	}

	/**
	 * Update a single job item.
	 *
	 * @since 5.3.0
	 *
	 * @param int   $item_id Item ID.
	 * @param array $data    Column => value.
	 * @return bool
	 */
	public static function update_item( $item_id, $data ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			self::items_table(),
			$data,
			array( 'id' => absint( $item_id ) )
		);

		return false !== $result;
	}

	/**
	 * Count items by status for a job.
	 *
	 * @since 5.3.0
	 *
	 * @param int $job_id Job ID.
	 * @return array status => count
	 */
	public static function count_items_by_status( $job_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live progress counts.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS cnt FROM %i WHERE job_id = %d GROUP BY status',
				self::items_table(),
				absint( $job_id )
			)
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row->status ] = (int) $row->cnt;
		}

		return $counts;
	}

	/**
	 * Get recent processed items for logging UI.
	 *
	 * @since 5.3.0
	 *
	 * @param int $job_id Job ID.
	 * @param int $limit  Max rows.
	 * @return array
	 */
	public static function get_recent_items( $job_id, $limit = 20 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- History UI needs fresh rows.
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE job_id = %d AND status != %s ORDER BY processed_at DESC, id DESC LIMIT %d',
				self::items_table(),
				absint( $job_id ),
				'pending',
				absint( $limit )
			)
		);
	}

	/**
	 * Get items for a job filtered by status.
	 *
	 * @since 5.4.1
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status Status.
	 * @param int    $limit  Max rows.
	 * @return array
	 */
	public static function get_items_by_status( $job_id, $status, $limit = 100 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Status-filtered items for UI/retry.
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE job_id = %d AND status = %s ORDER BY processed_at DESC, id DESC LIMIT %d',
				self::items_table(),
				absint( $job_id ),
				sanitize_key( $status ),
				absint( $limit )
			)
		);
	}

	/**
	 * Reset failed items to pending for retry.
	 *
	 * @since 5.3.0
	 *
	 * @param int $job_id Job ID.
	 * @return int Rows affected.
	 */
	public static function reset_failed_items( $job_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Retry must write through immediately.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, message = NULL, attachment_id = NULL, processed_at = NULL WHERE job_id = %d AND status = %s',
				self::items_table(),
				'pending',
				absint( $job_id ),
				'error'
			)
		);

		return (int) $updated;
	}

	/**
	 * Mark remaining pending items as cancelled.
	 *
	 * @since 5.3.0
	 *
	 * @param int $job_id Job ID.
	 * @return int
	 */
	public static function cancel_pending_items( $job_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cancel must write through immediately.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, processed_at = %s WHERE job_id = %d AND status = %s',
				self::items_table(),
				'cancelled',
				current_time( 'mysql', true ),
				absint( $job_id ),
				'pending'
			)
		);

		return (int) $updated;
	}

	/**
	 * List jobs for history UI.
	 *
	 * @since 5.3.0
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function list_jobs( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'user_id'  => 0,
			'per_page' => 20,
			'page'     => 1,
			'status'   => '',
		);
		$args     = wp_parse_args( $args, $defaults );
		$per_page = max( 1, absint( $args['per_page'] ) );
		$page     = max( 1, absint( $args['page'] ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = self::jobs_table();
		$user_id  = absint( $args['user_id'] );
		$status   = ! empty( $args['status'] ) ? sanitize_key( $args['status'] ) : '';

		// Distinct prepare shapes so the WHERE clause is never user-built SQL.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- History list must reflect latest jobs.
		if ( $user_id > 0 && '' !== $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE user_id = %d AND status = %s ORDER BY id DESC LIMIT %d OFFSET %d',
					$table,
					$user_id,
					$status,
					$per_page,
					$offset
				)
			);
		} elseif ( $user_id > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
					$table,
					$user_id,
					$per_page,
					$offset
				)
			);
		} elseif ( '' !== $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d',
					$table,
					$status,
					$per_page,
					$offset
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
					$table,
					$per_page,
					$offset
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( __CLASS__, 'hydrate_job' ), (array) $rows );
	}

	/**
	 * Count jobs matching filters.
	 *
	 * @since 5.3.0
	 *
	 * @param array $args Query args.
	 * @return int
	 */
	public static function count_jobs( $args = array() ) {
		global $wpdb;

		$table   = self::jobs_table();
		$user_id = ! empty( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;
		$status  = ! empty( $args['status'] ) ? sanitize_key( $args['status'] ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count must match list_jobs.
		if ( $user_id > 0 && '' !== $status ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE user_id = %d AND status = %s',
					$table,
					$user_id,
					$status
				)
			);
		} elseif ( $user_id > 0 ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE user_id = %d',
					$table,
					$user_id
				)
			);
		} elseif ( '' !== $status ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE status = %s',
					$table,
					$status
				)
			);
		} else {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i',
					$table
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $count;
	}

	/**
	 * Find active jobs that background cron should continue.
	 *
	 * @since 5.3.0
	 *
	 * @param int $limit Max jobs.
	 * @return array
	 */
	public static function get_runnable_jobs( $limit = 3 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron must see current runnable jobs.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status IN (%s, %s, %s) ORDER BY updated_at ASC LIMIT %d',
				self::jobs_table(),
				'scanning',
				'running',
				'pending',
				absint( $limit )
			)
		);

		return array_map( array( __CLASS__, 'hydrate_job' ), (array) $rows );
	}

	/**
	 * Delete a job and its items.
	 *
	 * @since 5.3.0
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public static function delete_job( $job_id ) {
		global $wpdb;

		$job_id = absint( $job_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::items_table(), array( 'job_id' => $job_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::jobs_table(), array( 'id' => $job_id ), array( '%d' ) );

		return true;
	}

	/**
	 * Delete import history jobs (optionally scoped to one user).
	 *
	 * @since 5.4.4
	 *
	 * @param int|null $user_id User ID, or null for all jobs.
	 * @return int Number of jobs deleted.
	 */
	public static function delete_jobs( $user_id = null ) {
		global $wpdb;

		$jobs_table  = self::jobs_table();
		$items_table = self::items_table();

		if ( null === $user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $items_table ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $jobs_table ) );
		}

		$user_id = absint( $user_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE user_id = %d',
				$jobs_table,
				$user_id
			)
		);
		if ( empty( $ids ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $ids as $job_id ) {
			self::delete_job( (int) $job_id );
			++$count;
		}

		return $count;
	}
}

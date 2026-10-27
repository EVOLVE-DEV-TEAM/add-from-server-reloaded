/**
 * Add From Server Lite - JavaScript
 *
 * Handles UI helpers and chunked / resumable bulk imports.
 *
 * @package Add From Server Lite
 * @since   4.0.0
 */

jQuery( document ).ready( function( $ ) {
	var data = window.afsrreloadedData || {};
	var state = {
		jobId: 0,
		running: false,
		paused: false,
		cancelled: false,
		pollTimer: null,
		processRetries: 0
	};

	function i18n( key, fallback ) {
		if ( data.i18n && data.i18n[ key ] ) {
			return data.i18n[ key ];
		}
		return fallback || key;
	}

	function ajax( action, payload ) {
		payload = payload || {};
		payload.action = action;
		payload.nonce = data.nonce;

		return $.ajax( {
			url: data.ajaxurl,
			method: 'POST',
			dataType: 'json',
			data: payload
		} );
	}

	function selectedValues( name ) {
		var values = [];
		$( 'input[name="' + name + '"]:checked' ).each( function() {
			values.push( $( this ).val() );
		} );
		return values;
	}

	function showProgressPanel() {
		if ( window.afsrWizard && typeof window.afsrWizard.showProgress === 'function' ) {
			window.afsrWizard.showProgress();
			return;
		}
		var $panel = $( '#afsrreloaded-progress-panel' );
		$panel.prop( 'hidden', false ).show();
		$( 'html, body' ).animate( { scrollTop: $panel.offset().top - 40 }, 250 );
	}

	function setBusy( isBusy ) {
		$( '#afsrreloaded-import-form input[type="submit"], #afsr-start-import' ).prop( 'disabled', isBusy );
		$( '#afsrreloaded-pause-job' ).prop( 'disabled', ! isBusy || state.paused || ! state.jobId );
		// Cancel while job is running or paused (not before job_id, not after complete/cancel).
		var canCancel = !! state.jobId && ! state.cancelled && ( !! state.running || !! state.paused );
		$( '#afsrreloaded-cancel-job' ).prop( 'disabled', ! canCancel );
	}

	function updateProgress( payload ) {
		if ( ! payload ) {
			return;
		}

		// Ignore stale in-flight chunk responses that would undo pause/cancel UI.
		if ( state.paused && payload.status !== 'paused' && ! payload.is_complete ) {
			return;
		}
		if ( state.cancelled && payload.status !== 'cancelled' && ! payload.is_complete ) {
			return;
		}

		var percent = typeof payload.percent === 'number' ? payload.percent : 0;
		$( '.afsrreloaded-progress-percent' ).text( percent + '%' );
		$( '.afsrreloaded-progress-bar-fill, .afsr-progress-bar-fill' ).css( 'width', percent + '%' );
		$( '.afsrreloaded-progress-bar, .afsr-progress-bar' ).attr( 'aria-valuenow', percent );

		$( '.afsrreloaded-progress-counts [data-count="imported"] span' ).text( payload.imported || 0 );
		$( '.afsrreloaded-progress-counts [data-count="duplicates"] span' ).text( payload.duplicates || 0 );
		$( '.afsrreloaded-progress-counts [data-count="errors"] span' ).text( payload.errors || 0 );
		$( '.afsrreloaded-progress-counts [data-count="skipped"] span' ).text( payload.skipped || 0 );

		var message = '';
		if ( payload.status === 'scanning' || ( payload.scan_complete === false && ! payload.is_complete ) ) {
			message = data.scanning || 'Scanning folders...';
			if ( payload.total ) {
				message += ' (' + payload.total + ' files found)';
			}
		} else if ( payload.status === 'paused' ) {
			message = data.paused || 'Import paused.';
		} else if ( payload.status === 'cancelled' ) {
			message = data.cancelled || 'Import cancelled.';
		} else if ( payload.is_complete || payload.status === 'completed' ) {
			message = data.complete || 'Import Complete!';
			message += ' ' + ( payload.imported || 0 ) + ' / ' + ( payload.total || 0 );
		} else {
			message = data.importing || 'Importing files...';
			message += ' ' + ( payload.processed || 0 ) + ' / ' + ( payload.total || 0 );
		}

		$( '.afsrreloaded-progress-message' ).text( message );
		$( '.afsrreloaded-import-status' ).text( message );

		if ( window.afsrWizard && typeof window.afsrWizard.updateProgressMeta === 'function' ) {
			window.afsrWizard.updateProgressMeta( payload );
		}

		if ( payload.errors > 0 && payload.is_complete ) {
			$( '#afsrreloaded-retry-failed' ).prop( 'hidden', false );
		}

		if ( payload.recent && payload.recent.length ) {
			var lines = payload.recent.slice( 0, 12 ).map( function( item ) {
				return '<div class="afsrreloaded-log-line afsrreloaded-log-' + item.status + '"><strong>' +
					$( '<div>' ).text( item.file ).html() + '</strong>: ' +
					$( '<div>' ).text( item.message || item.status ).html() + '</div>';
			} );
			$( '.afsrreloaded-progress-log' ).html( lines.join( '' ) );
		}

		state.paused = ( payload.status === 'paused' );
		$( '#afsrreloaded-pause-job' ).prop( 'hidden', state.paused || payload.is_complete || payload.status === 'cancelled' );
		$( '#afsrreloaded-resume-job' ).prop( 'hidden', ! state.paused || payload.is_complete || payload.status === 'cancelled' );

		if ( payload.is_complete ) {
			state.running = false;
			setBusy( false );
			$( '#afsrreloaded-pause-job, #afsrreloaded-resume-job' ).prop( 'hidden', true );
			if (
				window.afsrWizard &&
				typeof window.afsrWizard.showComplete === 'function' &&
				payload.status !== 'cancelled' &&
				payload.status !== 'paused'
			) {
				window.afsrWizard.showComplete( payload );
			}
		}
	}

	function fail( err ) {
		state.running = false;
		setBusy( false );
		var message = data.error || 'An error occurred. Please try again.';
		if ( err && err.responseJSON && err.responseJSON.data && err.responseJSON.data.message ) {
			message = err.responseJSON.data.message;
		} else if ( err && err.message ) {
			message = err.message;
		}
		$( '.afsrreloaded-progress-message' ).text( message );
		$( '.afsrreloaded-import-status' ).text( message );
		window.alert( message );
	}

	function processLoop() {
		if ( ! state.jobId || state.paused || state.cancelled || ! state.running ) {
			return;
		}

		ajax( 'afsrreloaded_process_job', { job_id: state.jobId } )
			.done( function( response ) {
				state.processRetries = 0;
				if ( ! response || ! response.success ) {
					var code = response && response.data && response.data.code;
					// Scan may still be finishing — keep going instead of aborting the job.
					if ( code === 'still_scanning' ) {
						window.setTimeout( scanLoop, 100 );
						return;
					}
					fail( { message: ( response && response.data && response.data.message ) || data.error } );
					return;
				}

				updateProgress( response.data );

				if ( response.data.is_complete || response.data.status === 'paused' || response.data.status === 'cancelled' ) {
					state.running = false;
					setBusy( false );
					return;
				}

				// Keep pumping chunks while the page is open.
				window.setTimeout( processLoop, 50 );
			} )
			.fail( function( err ) {
				state.processRetries = ( state.processRetries || 0 ) + 1;
				if ( state.processRetries <= 3 && ! state.paused && ! state.cancelled && state.running ) {
					window.setTimeout( processLoop, 500 * state.processRetries );
					return;
				}
				fail( err );
			} );
	}

	function scanLoop() {
		if ( ! state.jobId || state.paused || state.cancelled || ! state.running ) {
			return;
		}

		ajax( 'afsrreloaded_scan_job', { job_id: state.jobId } )
			.done( function( response ) {
				if ( ! response || ! response.success ) {
					fail( { message: ( response && response.data && response.data.message ) || data.error } );
					return;
				}

				updateProgress( response.data );

				if ( response.data.scan_complete ) {
					processLoop();
					return;
				}

				window.setTimeout( scanLoop, 50 );
			} )
			.fail( fail );
	}

	function startImport() {
		var files = selectedValues( 'files[]' );
		var folders = selectedValues( 'folders[]' );

		if ( ! files.length && ! folders.length ) {
			window.alert( data.selectSomething || 'Please select at least one file or folder to import.' );
			return;
		}

		var totalSelected = files.length + folders.length;
		if ( totalSelected > 50 || folders.length > 5 ) {
			var confirmMsg = 'You are about to import ';
			if ( files.length && folders.length ) {
				confirmMsg += files.length + ' file(s) and ' + folders.length + ' folder(s).';
			} else if ( files.length ) {
				confirmMsg += files.length + ' file(s).';
			} else {
				confirmMsg += folders.length + ' folder(s).';
			}
			confirmMsg += ' ' + ( data.confirmLarge || 'This may take a while. Continue?' );
			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}
		}

		state.running = true;
		state.paused = false;
		state.cancelled = false;
		state.jobId = 0;
		setBusy( true );
		showProgressPanel();
		$( '#afsrreloaded-retry-failed' ).prop( 'hidden', true ).hide();
		$( '.afsrreloaded-progress-log' ).empty();
		$( '.afsrreloaded-progress-message' ).text( data.processing || 'Processing...' );

		var payload = {
			files: files,
			folders: folders,
			background: ( data.features && data.features.background && $( '#afsrreloaded-background' ).is( ':checked' ) ) ? 1 : 0,
			generate_metadata: ( data.features && data.features.deferThumbnails && $( '#afsrreloaded-defer-thumbs' ).is( ':checked' ) ) ? 0 : 1,
			chunk_size: data.chunkSize || 5,
			preserve_structure: ( data.features && data.features.folderPreserve && $( '#afsrreloaded-preserve-structure' ).is( ':checked' ) ) ? 1 : 0,
			duplicate_action: ( data.features && data.features.advancedDuplicates && $( '#afsrreloaded-duplicate-action' ).length )
				? $( '#afsrreloaded-duplicate-action' ).val()
				: 'skip'
		};

		ajax( 'afsrreloaded_create_job', payload )
			.done( function( response ) {
				if ( ! response || ! response.success ) {
					fail( { message: ( response && response.data && response.data.message ) || data.error } );
					return;
				}

				state.jobId = response.data.job_id;
				setBusy( true );
				updateProgress( response.data );

				if ( ! response.data.scan_complete ) {
					scanLoop();
				} else {
					processLoop();
				}
			} )
			.fail( fail );
	}

	// Handle "select all" checkboxes.
	$( '#afsrreloaded-select-all, #afsrreloaded-select-all-footer' ).on( 'change', function() {
		var isChecked = $( this ).prop( 'checked' );
		$( '.afsrreloaded-file-table tbody input[type="checkbox"]:not(:disabled):visible' ).prop( 'checked', isChecked );
		$( '#afsrreloaded-select-all, #afsrreloaded-select-all-footer' ).prop( 'checked', isChecked );
	} );

	// Sync both select-all checkboxes when individual files are selected.
	$( '.afsrreloaded-file-table tbody input[type="checkbox"]' ).on( 'change', function() {
		var totalCheckboxes = $( '.afsrreloaded-file-table tbody input[type="checkbox"]:not(:disabled)' ).length;
		var checkedCheckboxes = $( '.afsrreloaded-file-table tbody input[type="checkbox"]:not(:disabled):checked' ).length;

		$( '#afsrreloaded-select-all, #afsrreloaded-select-all-footer' ).prop(
			'checked',
			totalCheckboxes === checkedCheckboxes && totalCheckboxes > 0
		);
	} );

	// Chunked AJAX import (prevent full-page POST timeouts).
	$( '#afsrreloaded-import-form' ).on( 'submit', function( e ) {
		e.preventDefault();
		startImport();
		return false;
	} );

	$( '#afsrreloaded-pause-job' ).on( 'click', function() {
		if ( ! state.jobId ) {
			return;
		}
		// Optimistic UI so an in-flight chunk cannot flip Pause back on.
		state.paused = true;
		state.running = false;
		$( '#afsrreloaded-resume-job' ).prop( 'hidden', false );
		$( '#afsrreloaded-pause-job' ).prop( 'hidden', true );
		setBusy( false );
		ajax( 'afsrreloaded_pause_job', { job_id: state.jobId } )
			.done( function( response ) {
				if ( response && response.success ) {
					updateProgress( response.data );
				}
			} )
			.fail( fail );
	} );

	$( '#afsrreloaded-resume-job' ).on( 'click', function() {
		if ( ! state.jobId ) {
			return;
		}
		state.paused = false;
		state.cancelled = false;
		state.running = true;
		$( '#afsrreloaded-pause-job' ).prop( 'hidden', false );
		$( '#afsrreloaded-resume-job' ).prop( 'hidden', true );
		setBusy( true );
		ajax( 'afsrreloaded_resume_job', { job_id: state.jobId } )
			.done( function( response ) {
				if ( response && response.success ) {
					updateProgress( response.data );
					if ( ! response.data.scan_complete ) {
						scanLoop();
					} else {
						processLoop();
					}
				} else {
					fail( { message: ( response && response.data && response.data.message ) || data.error } );
				}
			} )
			.fail( fail );
	} );

	$( '#afsrreloaded-cancel-job' ).on( 'click', function() {
		if ( ! state.jobId || state.cancelled ) {
			return;
		}
		if ( ! state.running && ! state.paused ) {
			return;
		}
		if ( ! window.confirm( 'Cancel this import? Files already imported will remain in the Media Library.' ) ) {
			return;
		}
		state.cancelled = true;
		state.running = false;
		ajax( 'afsrreloaded_cancel_job', { job_id: state.jobId } )
			.done( function( response ) {
				if ( response && response.success ) {
					updateProgress( response.data );
				}
				setBusy( false );
			} )
			.fail( fail );
	} );

	$( '#afsrreloaded-retry-failed' ).on( 'click', function() {
		if ( ! state.jobId ) {
			return;
		}
		state.running = true;
		state.cancelled = false;
		state.paused = false;
		setBusy( true );
		ajax( 'afsrreloaded_retry_failed', { job_id: state.jobId } )
			.done( function( response ) {
				if ( response && response.success ) {
					updateProgress( response.data );
					processLoop();
				} else {
					fail( { message: ( response && response.data && response.data.message ) || data.error } );
				}
			} )
			.fail( fail );
	} );

	// When leaving the page during a background import, kick cron so the job continues.
	$( window ).on( 'pagehide beforeunload', function() {
		if ( ! state.jobId || state.cancelled || state.paused ) {
			return;
		}
		if ( ! ( data.features && data.features.background && $( '#afsrreloaded-background' ).is( ':checked' ) ) ) {
			return;
		}
		try {
			var payload = 'action=' + encodeURIComponent( 'afsrreloaded_kick_cron' ) +
				'&nonce=' + encodeURIComponent( data.nonce || '' );
			if ( navigator.sendBeacon ) {
				// Must use a form-urlencoded Blob so PHP populates $_POST (URLSearchParams alone often fails).
				var blob = new Blob( [ payload ], { type: 'application/x-www-form-urlencoded' } );
				navigator.sendBeacon( data.ajaxurl, blob );
			} else {
				ajax( 'afsrreloaded_kick_cron', {} );
			}
		} catch ( e ) {
			// Ignore beacon failures; admin_init / WP-Cron remain as fallback.
		}
	} );

	// File search, smart filters, and pagination.
	if ( $( '.afsrreloaded-file-table' ).length ) {
		var browserState = {
			page: 1,
			perPage: 50,
			search: '',
			type: 'all',
			minSize: 0,
			maxSize: 0,
			minMtime: 0
		};

		var hasWizardUi = $( '#afsr-admin-app' ).length > 0;

		if ( ! hasWizardUi ) {
			$( '.afsrreloaded-file-table' ).before(
				'<div class="afsrreloaded-browser-toolbar">' +
				'<div class="afsrreloaded-search-box">' +
				'<input type="text" id="afsrreloaded-file-search" class="regular-text" placeholder="Search files..." />' +
				'<button type="button" class="button" id="afsrreloaded-clear-search">Clear</button>' +
				'</div>' +
				'<div class="afsrreloaded-filters-box">' +
				'<label>Type <select id="afsrreloaded-filter-type">' +
				'<option value="all">All</option>' +
				'<option value="images">Images</option>' +
				'<option value="audio">Audio</option>' +
				'<option value="video">Video</option>' +
				'<option value="documents">Documents</option>' +
				'</select></label>' +
				'<label>Min size (MB) <input type="number" id="afsrreloaded-filter-min-size" min="0" step="0.1" value="" class="small-text" /></label>' +
				'<label>Max size (MB) <input type="number" id="afsrreloaded-filter-max-size" min="0" step="0.1" value="" class="small-text" /></label>' +
				'<label>Newer than <input type="date" id="afsrreloaded-filter-date" /></label>' +
				'<label>Per page <select id="afsrreloaded-per-page">' +
				'<option value="25">25</option><option value="50" selected>50</option><option value="100">100</option><option value="0">All</option>' +
				'</select></label>' +
				'</div>' +
				'<div class="afsrreloaded-pagination" id="afsrreloaded-pagination"></div>' +
				'</div>'
			);
		}

		function isGuidelineHiddenRow( $row ) {
			return $row.hasClass( 'doesnt-meet-guidelines' ) || $row.hasClass( 'unreadable' );
		}

		function showingHiddenFiles() {
			return $( '.afsrreloaded-file-table' ).hasClass( 'showhidden' );
		}

		function clearInlineDisplay( $row ) {
			// Drop jQuery .show()/.hide() inline styles so CSS (showhidden) can win.
			$row.css( 'display', '' );
		}

		function syncHiddenToggleLabels() {
			var showing = showingHiddenFiles();
			$( '#afsrreloaded-toggle-hidden' ).text( showing ? 'Hide Hidden Files' : 'Show Hidden Files' );
			$( '#afsr-admin-app' ).find( '[data-afsr-action="toggle-hidden"]' ).text(
				showing ? 'Hide hidden files' : 'Show hidden files'
			);
			$( 'tr.hidden-toggle a' ).text( showing ? 'Hide hidden files' : 'Show hidden files' );
		}

		function toggleHiddenFiles( forceShow ) {
			var $table = $( '.afsrreloaded-file-table' );
			if ( typeof forceShow === 'boolean' ) {
				$table.toggleClass( 'showhidden', forceShow );
			} else {
				$table.toggleClass( 'showhidden' );
			}
			syncHiddenToggleLabels();
			applyBrowserView();
		}

		function rowMatchesFilters( $row ) {
			if ( $row.hasClass( 'afsrreloaded-folder-row' ) || $row.hasClass( 'hidden-toggle' ) ) {
				return true;
			}
			if ( ! $row.hasClass( 'afsrreloaded-file-row' ) && ! $row.find( 'input[name="files[]"]' ).length ) {
				return true;
			}

			var name = ( $row.data( 'name' ) || $row.find( 'label' ).text() || '' ).toString().toLowerCase();
			var mime = ( $row.data( 'mime' ) || '' ).toString().toLowerCase();
			var size = parseInt( $row.data( 'size' ), 10 ) || 0;
			var mtime = parseInt( $row.data( 'mtime' ), 10 ) || 0;

			if ( browserState.search && name.indexOf( browserState.search ) === -1 ) {
				return false;
			}

			if ( browserState.type === 'images' && mime.indexOf( 'image/' ) !== 0 ) {
				return false;
			}
			if ( browserState.type === 'audio' && mime.indexOf( 'audio/' ) !== 0 ) {
				return false;
			}
			if ( browserState.type === 'video' && mime.indexOf( 'video/' ) !== 0 ) {
				return false;
			}
			if ( browserState.type === 'documents' ) {
				var isDoc = mime.indexOf( 'pdf' ) !== -1 || mime.indexOf( 'msword' ) !== -1 || mime.indexOf( 'officedocument' ) !== -1 || mime.indexOf( 'text/' ) === 0;
				if ( ! isDoc ) {
					return false;
				}
			}

			if ( browserState.minSize > 0 && size < browserState.minSize ) {
				return false;
			}
			if ( browserState.maxSize > 0 && size > browserState.maxSize ) {
				return false;
			}
			if ( browserState.minMtime > 0 && mtime < browserState.minMtime ) {
				return false;
			}

			return true;
		}

		function applyBrowserView() {
			var $rows = $( '.afsrreloaded-file-table tbody tr' );
			var matchedFiles = [];
			var showHidden = showingHiddenFiles();

			$rows.each( function() {
				var $row = $( this );
				if ( $row.hasClass( 'hidden-toggle' ) ) {
					// In-table link: only when there are blocked files and they are currently hidden.
					clearInlineDisplay( $row );
					$row.toggleClass( 'afsrreloaded-page-hidden', showHidden );
					$row.removeClass( 'afsrreloaded-filter-hidden' );
					return;
				}
				if ( $row.hasClass( 'afsrreloaded-folder-row' ) ) {
					clearInlineDisplay( $row );
					$row.css( 'opacity', '1' ).removeClass( 'afsrreloaded-page-hidden afsrreloaded-filter-hidden' );
					return;
				}
				// Blocked/unreadable rows stay out of the list until "Show hidden files".
				if ( isGuidelineHiddenRow( $row ) && ! showHidden ) {
					clearInlineDisplay( $row );
					$row.removeClass( 'afsrreloaded-filter-hidden afsrreloaded-page-hidden' );
					return;
				}
				if ( rowMatchesFilters( $row ) ) {
					matchedFiles.push( $row );
					clearInlineDisplay( $row );
					$row.removeClass( 'afsrreloaded-filter-hidden' );
				} else {
					clearInlineDisplay( $row );
					$row.addClass( 'afsrreloaded-filter-hidden' ).removeClass( 'afsrreloaded-page-hidden' );
				}
			} );

			var perPage = browserState.perPage;
			var total = matchedFiles.length;
			var pages = perPage > 0 ? Math.max( 1, Math.ceil( total / perPage ) ) : 1;
			if ( browserState.page > pages ) {
				browserState.page = pages;
			}

			matchedFiles.forEach( function( $row, index ) {
				clearInlineDisplay( $row );
				if ( perPage > 0 ) {
					var start = ( browserState.page - 1 ) * perPage;
					var end = start + perPage;
					if ( index >= start && index < end ) {
						$row.css( 'opacity', '1' ).removeClass( 'afsrreloaded-page-hidden' );
					} else {
						$row.addClass( 'afsrreloaded-page-hidden' );
					}
				} else {
					$row.css( 'opacity', '1' ).removeClass( 'afsrreloaded-page-hidden' );
				}
			} );

			var $pagers = $( '#afsrreloaded-pagination' );
			var perPageOptions = [ 25, 50, 100, 0 ];
			var perPageHtml = '<label class="afsr-pager__perpage"><span class="afsr-pager__perpage-label">Per page</span>' +
				'<select class="afsr-pager__perpage-select" aria-label="Files per page">';
			perPageOptions.forEach( function( opt ) {
				var selected = ( parseInt( opt, 10 ) === parseInt( perPage, 10 ) ) ? ' selected' : '';
				var label = opt === 0 ? 'All' : String( opt );
				perPageHtml += '<option value="' + opt + '"' + selected + '>' + label + '</option>';
			} );
			perPageHtml += '</select></label>';

			var rangeStart = total === 0 ? 0 : ( ( browserState.page - 1 ) * ( perPage > 0 ? perPage : total ) ) + 1;
			var rangeEnd = perPage > 0 ? Math.min( total, browserState.page * perPage ) : total;
			var metaText = total === 0
				? '0 files'
				: ( 'Showing ' + rangeStart + '–' + rangeEnd + ' of ' + total );

			var navHtml = '';
			if ( perPage > 0 && pages > 1 ) {
				var pageButtons = '';
				var windowSize = 2;
				var startPage = Math.max( 1, browserState.page - windowSize );
				var endPage = Math.min( pages, browserState.page + windowSize );
				if ( startPage > 1 ) {
					pageButtons += '<button type="button" class="afsr-pager__page" data-afsr-page="1">1</button>';
					if ( startPage > 2 ) {
						pageButtons += '<span class="afsr-pager__ellipsis" aria-hidden="true">…</span>';
					}
				}
				for ( var p = startPage; p <= endPage; p++ ) {
					pageButtons += '<button type="button" class="afsr-pager__page' + ( p === browserState.page ? ' is-active' : '' ) + '" data-afsr-page="' + p + '"' + ( p === browserState.page ? ' aria-current="page"' : '' ) + '>' + p + '</button>';
				}
				if ( endPage < pages ) {
					if ( endPage < pages - 1 ) {
						pageButtons += '<span class="afsr-pager__ellipsis" aria-hidden="true">…</span>';
					}
					pageButtons += '<button type="button" class="afsr-pager__page" data-afsr-page="' + pages + '">' + pages + '</button>';
				}

				navHtml =
					'<div class="afsr-pager__nav" role="navigation" aria-label="Pagination">' +
					'<button type="button" class="afsr-pager__btn afsr-pager__prev"' + ( browserState.page <= 1 ? ' disabled' : '' ) + ' aria-label="Previous page">&lsaquo;</button>' +
					'<div class="afsr-pager__pages">' + pageButtons + '</div>' +
					'<button type="button" class="afsr-pager__btn afsr-pager__next"' + ( browserState.page >= pages ? ' disabled' : '' ) + ' aria-label="Next page">&rsaquo;</button>' +
					'</div>';
			}

			var pagerHtml =
				'<div class="afsr-pager">' +
				'<div class="afsr-pager__left">' +
				'<span class="afsr-pager__meta">' + metaText + '</span>' +
				perPageHtml +
				'</div>' +
				navHtml +
				'</div>';

			$pagers.html( pagerHtml );
		}

		$( '#afsrreloaded-toggle-hidden' ).on( 'click', function( e ) {
			e.preventDefault();
			toggleHiddenFiles();
		} );

		$( document ).on( 'click', 'tr.hidden-toggle a', function( e ) {
			e.preventDefault();
			toggleHiddenFiles( true );
		} );

		// Expose for wizard UI (admin-scripts.js).
		window.afsrToggleHiddenFiles = toggleHiddenFiles;

		$( document ).on( 'keydown', '#afsrreloaded-file-search', function( e ) {
			if ( e.keyCode === 13 ) {
				e.preventDefault();
				e.stopPropagation();
				return false;
			}
		} );

		$( '#afsrreloaded-file-search' ).on( 'keyup', function() {
			browserState.search = $( this ).val().toLowerCase().trim();
			browserState.page = 1;
			applyBrowserView();
		} );

		$( '#afsrreloaded-clear-search' ).on( 'click', function() {
			$( '#afsrreloaded-file-search' ).val( '' );
			browserState.search = '';
			browserState.page = 1;
			applyBrowserView();
		} );

		$( '#afsrreloaded-filter-type' ).on( 'change', function() {
			browserState.type = $( this ).val();
			browserState.page = 1;
			applyBrowserView();
		} );

		$( '#afsrreloaded-filter-min-size, #afsrreloaded-filter-max-size' ).on( 'change keyup', function() {
			var minMb = parseFloat( $( '#afsrreloaded-filter-min-size' ).val() ) || 0;
			var maxMb = parseFloat( $( '#afsrreloaded-filter-max-size' ).val() ) || 0;
			browserState.minSize = minMb > 0 ? minMb * 1024 * 1024 : 0;
			browserState.maxSize = maxMb > 0 ? maxMb * 1024 * 1024 : 0;
			browserState.page = 1;
			applyBrowserView();
		} );

		$( '#afsrreloaded-filter-date' ).on( 'change', function() {
			var val = $( this ).val();
			browserState.minMtime = val ? Math.floor( new Date( val ).getTime() / 1000 ) : 0;
			browserState.page = 1;
			applyBrowserView();
		} );

		$( document ).on( 'change', '.afsr-pager__perpage-select, #afsrreloaded-per-page', function() {
			browserState.perPage = parseInt( $( this ).val(), 10 ) || 0;
			browserState.page = 1;
			applyBrowserView();
		} );

		$( document ).on( 'click', '.afsr-pager__prev', function() {
			if ( browserState.page > 1 ) {
				browserState.page -= 1;
				applyBrowserView();
			}
		} );

		$( document ).on( 'click', '.afsr-pager__next', function() {
			browserState.page += 1;
			applyBrowserView();
		} );

		$( document ).on( 'click', '.afsr-pager__page', function() {
			var page = parseInt( $( this ).data( 'afsr-page' ), 10 ) || 1;
			if ( page !== browserState.page ) {
				browserState.page = page;
				applyBrowserView();
			}
		} );

		applyBrowserView();
	}

	// File size display enhancement.
	$( '.afsrreloaded-file-table tbody tr' ).each( function() {
		var checkbox = $( this ).find( 'input[type="checkbox"]' );
		if ( checkbox.length && checkbox.prop( 'disabled' ) ) {
			$( this ).addClass( 'afsrreloaded-disabled-row' );
		}
	} );

	// Keyboard shortcuts.
	$( document ).on( 'keydown', function( e ) {
		if ( ( e.ctrlKey || e.metaKey ) && e.keyCode === 65 && $( '.afsrreloaded-file-table' ).length ) {
			e.preventDefault();
			$( '#afsrreloaded-select-all' ).prop( 'checked', true ).trigger( 'change' );
		}

		if ( e.keyCode === 27 && $( '#afsrreloaded-file-search' ).length ) {
			$( '#afsrreloaded-file-search' ).val( '' ).trigger( 'keyup' );
		}
	} );

	function updateFileCount() {
		var totalFiles = $( 'input[name="files[]"]:not(:disabled)' ).length;
		var selectedFiles = $( 'input[name="files[]"]:not(:disabled):checked' ).length;
		var totalFolders = $( 'input[name="folders[]"]:not(:disabled)' ).length;
		var selectedFolders = $( 'input[name="folders[]"]:not(:disabled):checked' ).length;
		var countText = '';

		if ( selectedFiles > 0 || selectedFolders > 0 ) {
			if ( selectedFiles > 0 && selectedFolders > 0 ) {
				countText = selectedFiles + ' file(s) and ' + selectedFolders + ' folder(s) selected';
			} else if ( selectedFiles > 0 ) {
				countText = selectedFiles + ' of ' + totalFiles + ' file(s) selected';
			} else {
				countText = selectedFolders + ' of ' + totalFolders + ' folder(s) selected';
			}
		}

		if ( $( '.afsrreloaded-file-count' ).length === 0 ) {
			$( '.afsrreloaded-import-status' ).before( '<span class="afsrreloaded-file-count" style="margin-left: 15px; color: #000;"></span>' );
		}

		$( '.afsrreloaded-file-count' ).text( countText );
	}

	$( '.afsrreloaded-file-table tbody input[type="checkbox"], #afsrreloaded-select-all, #afsrreloaded-select-all-footer' ).on( 'change', function() {
		updateFileCount();
	} );

	if ( $( '.afsrreloaded-file-table' ).length ) {
		updateFileCount();
	}

	$( '.afsrreloaded-file-table tbody tr[title]' ).each( function() {
		var title = $( this ).attr( 'title' );
		if ( title ) {
			$( this ).find( 'label' ).attr( 'title', title );
		}
	} );
} );

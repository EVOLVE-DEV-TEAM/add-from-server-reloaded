/**
 * Add From Server Lite - Main Import wizard UI interactions.
 * IIFE + event delegation. Depends on jQuery and existing import AJAX script.
 */
( function( $ ) {
	'use strict';

	var root = document.getElementById( 'afsr-admin-app' );
	if ( ! root || typeof $ === 'undefined' ) {
		return;
	}

	var $app = $( root );
	var currentStep = 1;
	var filtersOpen = false;

	function selectedCount() {
		return $( '#afsrreloaded-import-form input[name="files[]"]:checked, #afsrreloaded-import-form input[name="folders[]"]:checked' ).length;
	}

	function selectionLabel() {
		var files = $( '#afsrreloaded-import-form input[name="files[]"]:checked' ).length;
		var folders = $( '#afsrreloaded-import-form input[name="folders[]"]:checked' ).length;
		var parts = [];
		if ( files > 0 ) {
			parts.push( files === 1 ? '1 file' : files + ' files' );
		}
		if ( folders > 0 ) {
			parts.push( folders === 1 ? '1 folder' : folders + ' folders' );
		}
		return parts.length ? parts.join( ' + ' ) : '0 files';
	}

	function isProFeature( flag ) {
		var data = window.afsrreloadedData || {};
		return !!( data.features && data.features[ flag ] );
	}

	function optionsSummary() {
		var items = [];
		if ( isProFeature( 'background' ) && $( '#afsrreloaded-background' ).is( ':checked' ) ) {
			items.push( 'Background' );
		}
		if ( isProFeature( 'deferThumbnails' ) && $( '#afsrreloaded-defer-thumbs' ).is( ':checked' ) ) {
			items.push( 'Defer thumbnails' );
		}
		if ( isProFeature( 'folderPreserve' ) && $( '#afsrreloaded-preserve-structure' ).is( ':checked' ) ) {
			items.push( 'Preserve folders' );
		}
		return items.length ? items.join( ', ' ) : 'None';
	}

	function duplicateSummary() {
		if ( isProFeature( 'advancedDuplicates' ) && $( '#afsrreloaded-duplicate-action' ).length ) {
			var map = {
				skip: 'Skip the file',
				replace: 'Replace',
				rename: 'Import as new'
			};
			var val = $( '#afsrreloaded-duplicate-action' ).val();
			return map[ val ] || 'Skip the file';
		}
		return 'Skip the file';
	}

	function updateContinueState() {
		var hasSelection = selectedCount() > 0;
		var $btn = $app.find( '[data-afsr-action="continue-step1"]' );
		var data = window.afsrreloadedData || {};
		var emptyHint = data.selectToImport || 'Select files to import';
		var selectedWord = data.selectedSuffix || 'selected';
		$btn.prop( 'disabled', ! hasSelection );
		$btn.toggleClass( 'is-disabled', ! hasSelection );
		$app.find( '.afsr-step1-hint' ).text(
			hasSelection ? selectionLabel() + ' ' + selectedWord : emptyHint
		);
	}

	function setStepper( step ) {
		currentStep = step;
		$app.find( '.afsr-stepper__item' ).each( function() {
			var n = parseInt( $( this ).attr( 'data-step' ), 10 );
			$( this ).toggleClass( 'is-active', n === step );
			$( this ).toggleClass( 'is-done', n < step );
			var $dot = $( this ).find( '.afsr-stepper__dot' );
			if ( n < step ) {
				$dot.html( '&#10003;' );
			} else {
				$dot.text( String( n ) );
			}
		} );
		$app.find( '.afsr-stepper__line' ).each( function() {
			var after = parseInt( $( this ).attr( 'data-after' ), 10 );
			$( this ).toggleClass( 'is-done', after < step );
		} );
	}

	function showPanel( name ) {
		$app.find( '.afsr-panel' ).prop( 'hidden', true );
		$app.find( '.afsr-panel[data-afsr-panel="' + name + '"]' ).prop( 'hidden', false );

		if ( name === 'browse' ) {
			setStepper( 1 );
		} else if ( name === 'options' ) {
			setStepper( 2 );
		} else {
			setStepper( 3 );
		}

		if ( name === 'ready' ) {
			$app.find( '[data-afsr-summary="files"]' ).text( selectionLabel() );
			$app.find( '[data-afsr-summary="duplicate"]' ).text( duplicateSummary() );
			$app.find( '[data-afsr-summary="options"]' ).text( optionsSummary() );
		}
	}

	function setFiltersOpen( open ) {
		filtersOpen = !! open;
		$app.find( '.afsr-filters' ).prop( 'hidden', ! filtersOpen );
		$app.find( '[data-afsr-action="toggle-filters"]' ).text( filtersOpen ? 'Hide filters' : 'More filters' );
	}

	function hideLockedTooltips() {
		$app.find( '.afsr-option-locked' ).removeClass( 'is-tooltip-visible' );
	}

	function ensureLockedTooltip( $row ) {
		if ( ! $row.find( '.afsr-pro-tooltip' ).length ) {
			$row.append( '<span class="afsr-pro-tooltip" role="tooltip">Unlock with PRO</span>' );
		}
	}

	$app.on( 'click', '.afsr-option-locked', function( e ) {
		e.preventDefault();
		e.stopPropagation();
		var $row = $( this );
		ensureLockedTooltip( $row );
		$app.find( '.afsr-option-locked' ).not( $row ).removeClass( 'is-tooltip-visible' );
		$row.addClass( 'is-tooltip-visible' );
		window.clearTimeout( $row.data( 'afsrTooltipTimer' ) );
		$row.data(
			'afsrTooltipTimer',
			window.setTimeout( function() {
				$row.removeClass( 'is-tooltip-visible' );
			}, 1800 )
		);
		return false;
	} );

	$app.on( 'mouseenter', '.afsr-option-locked', function() {
		ensureLockedTooltip( $( this ) );
	} );

	$( document ).on( 'click.afsrTooltip', function( e ) {
		if ( ! $( e.target ).closest( '.afsr-option-locked' ).length ) {
			hideLockedTooltips();
		}
	} );

	$app.on( 'change', 'input[name="files[]"], input[name="folders[]"], #afsrreloaded-select-all, #afsrreloaded-select-all-footer', function() {
		updateContinueState();
	} );

	$app.on( 'click', '[data-afsr-action]', function( e ) {
		var action = $( this ).attr( 'data-afsr-action' );

		if ( action === 'continue-step1' ) {
			e.preventDefault();
			if ( selectedCount() < 1 ) {
				return;
			}
			showPanel( 'options' );
			return;
		}

		if ( action === 'back-step2' ) {
			e.preventDefault();
			showPanel( 'browse' );
			return;
		}

		if ( action === 'continue-step2' ) {
			e.preventDefault();
			showPanel( 'ready' );
			return;
		}

		if ( action === 'back-step3' ) {
			e.preventDefault();
			showPanel( 'options' );
			return;
		}

		if ( action === 'toggle-filters' ) {
			e.preventDefault();
			setFiltersOpen( ! filtersOpen );
			return;
		}

		if ( action === 'toggle-hidden' ) {
			e.preventDefault();
			if ( typeof window.afsrToggleHiddenFiles === 'function' ) {
				window.afsrToggleHiddenFiles();
			} else {
				$( '#afsrreloaded-toggle-hidden' ).trigger( 'click' );
			}
			return;
		}

		if ( action === 'import-more' ) {
			e.preventDefault();
			var url = $( this ).attr( 'data-afsr-root-url' );
			if ( url ) {
				window.location.href = url;
			}
			return;
		}
	} );

	// Expose helpers for the core import script.
	window.afsrWizard = {
		showProgress: function() {
			showPanel( 'progress' );
			$( '#afsrreloaded-progress-panel' ).prop( 'hidden', false );
			$app.find( '.afsr-progress-title' ).text( 'Importing files' );
			$( '#afsrreloaded-pause-job' ).prop( 'hidden', false );
			$( '#afsrreloaded-resume-job' ).prop( 'hidden', true );
		},
		showComplete: function( payload ) {
			var imported = ( payload && payload.imported ) || 0;
			var duplicates = ( payload && payload.duplicates ) || 0;
			var errors = ( payload && payload.errors ) || 0;
			$app.find( '[data-afsr-complete="stats"]' ).text(
				imported + ' imported · ' + duplicates + ' duplicates skipped · ' + errors + ' errors'
			);
			showPanel( 'complete' );
		},
		updateProgressMeta: function( payload ) {
			if ( ! payload ) {
				return;
			}
			var percent = typeof payload.percent === 'number' ? payload.percent : 0;
			var processed = payload.processed || 0;
			var total = payload.total || 0;
			var text;
			var title = 'Importing files';

			if ( payload.status === 'scanning' || ( payload.scan_complete === false && ! payload.is_complete ) ) {
				text = 'Scanning…' + ( total ? ' (' + total + ' files found)' : '' );
			} else if ( payload.status === 'paused' ) {
				title = 'Import paused';
				text = processed + ' of ' + total + ' files · ' + percent + '%';
			} else if ( payload.status === 'cancelled' ) {
				title = 'Import cancelled';
				text = 'Import cancelled';
			} else {
				text = processed + ' of ' + total + ' files · ' + percent + '%';
			}

			$app.find( '.afsr-progress-title' ).text( title );
			$app.find( '.afsr-progress-meta' ).text( text );
			$app.find( '.afsrreloaded-progress-bar-fill, .afsr-progress-bar-fill' ).css( 'width', percent + '%' );
			$app.find( '.afsrreloaded-progress-bar, .afsr-progress-bar' ).attr( 'aria-valuenow', percent );

			if ( payload.status === 'paused' ) {
				$( '#afsrreloaded-pause-job' ).prop( 'hidden', true );
				$( '#afsrreloaded-resume-job' ).prop( 'hidden', false );
			} else if ( ! payload.is_complete && payload.status !== 'cancelled' ) {
				$( '#afsrreloaded-pause-job' ).prop( 'hidden', false );
				$( '#afsrreloaded-resume-job' ).prop( 'hidden', true );
			}
		},
		getStep: function() {
			return currentStep;
		}
	};

	// Collapse / reveal panels (Scheduled + Remote add forms).
	$app.on( 'click', '[data-afsr-reveal]', function( e ) {
		e.preventDefault();
		var target = $( this ).attr( 'data-afsr-reveal' );
		if ( ! target ) {
			return;
		}
		$app.find( target ).prop( 'hidden', false );
		$( this ).prop( 'hidden', true );
	} );

	$app.on( 'click', '[data-afsr-hide]', function( e ) {
		e.preventDefault();
		var target = $( this ).attr( 'data-afsr-hide' );
		var revealBtn = $( this ).attr( 'data-afsr-reveal-btn' );
		if ( target ) {
			$app.find( target ).prop( 'hidden', true );
		}
		if ( revealBtn ) {
			$app.find( revealBtn ).prop( 'hidden', false );
		}
	} );

	$app.on( 'change', '.afsr-dup-item input[type="radio"]', function() {
		var $form = $( this ).closest( 'form' );
		$form.find( '.afsr-dup-item' ).removeClass( 'is-selected' );
		$( this ).closest( '.afsr-dup-item' ).addClass( 'is-selected' );
	} );

	var isWizard = $app.find( '.afsr-stepper' ).length > 0;
	if ( isWizard ) {
		setFiltersOpen( false );
		showPanel( 'browse' );
		updateContinueState();
	}
}( jQuery ) );

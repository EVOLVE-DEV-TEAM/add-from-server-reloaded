/**
 * Pro teaser: Get Pro new-tab links only.
 * Upgrade popup modal is intentionally disabled in Free.
 *
 * @package Add_From_Server_Reloaded
 * @since   5.4.2
 */

( function( $ ) {
	'use strict';

	var cfg = window.afsrreloadedProTeaser || {};

	// Always open the pricing URL from our sidebar in a new tab.
	$( function() {
		$( '#adminmenu a[href="' + ( cfg.upgradeUrl || 'https://elearningevolve.com/products/add-from-server-pro/' ) + '"]' )
			.attr( { target: '_blank', rel: 'noopener noreferrer' } );
	} );
}( jQuery ) );

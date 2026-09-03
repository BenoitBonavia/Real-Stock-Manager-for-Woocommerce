/**
 * Scripts d'administration – Real Stock Manager for WooCommerce.
 *
 * `rsmwAdmin` est fourni par wp_localize_script() : { ajaxUrl, nonce }.
 */
( function ( $ ) {
	'use strict';

	var RSMW = {
		init: function () {
			// Les interactions des modules viendront se brancher ici.
		}
	};

	$( function () {
		RSMW.init();
	} );

	window.RSMW = RSMW;
}( jQuery ) );

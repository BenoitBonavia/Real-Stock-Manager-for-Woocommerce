/**
 * Journal des mouvements — recherche et filtre par sens.
 *
 * Chargé sur les deux onglets de la page Gestion du stock, qui partagent le même
 * tableau de journal.
 */
( function () {
	'use strict';

	var table = document.getElementById( 'rsmw-journal-table' );

	if ( ! table ) {
		return;
	}

	var search = document.getElementById( 'rsmw-journal-search' );
	var empty = document.getElementById( 'rsmw-journal-empty' );
	var buttons = document.querySelectorAll( '[data-rsmw-filter]' );
	var rows = Array.prototype.slice.call( table.tBodies[ 0 ].rows );
	var current = 'all';

	function normalize( value ) {
		return ( value || '' ).toLowerCase().normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
	}

	function inFamily( type ) {
		if ( 'all' === current ) {
			return true;
		}

		// « Fournisseur » regroupe les commandes passées et leurs annulations.
		if ( 'supply' === current ) {
			return 'order' === type || 'unorder' === type;
		}

		// Une réception de colis est une entrée de stock comme une autre.
		if ( 'in' === current ) {
			return 'in' === type || 'reception' === type;
		}

		return type === current;
	}

	function refresh() {
		var query = normalize( search ? search.value.trim() : '' );
		var visible = 0;

		rows.forEach( function ( row ) {
			var matches = inFamily( row.dataset.rsmwType ) &&
				( ! query || row.dataset.rsmwSearch.indexOf( query ) !== -1 );

			row.hidden = ! matches;

			if ( matches ) {
				visible++;
			}
		} );

		if ( empty ) {
			empty.hidden = visible > 0;
		}

		table.hidden = 0 === visible;
	}

	if ( search ) {
		search.addEventListener( 'input', refresh );
	}

	Array.prototype.forEach.call( buttons, function ( button ) {
		button.addEventListener( 'click', function () {
			current = button.dataset.rsmwFilter;

			Array.prototype.forEach.call( buttons, function ( other ) {
				other.setAttribute( 'aria-pressed', other === button ? 'true' : 'false' );
			} );

			refresh();
		} );
	} );
}() );

/**
 * Onglet « Inventaire » — recherche, filtre par catégorie et tri.
 *
 * Même principe que `needs-table.js` : le tableau est complet dès le
 * chargement, tout se joue côté client sur les lignes déjà rendues.
 */
( function () {
	'use strict';

	var table = document.getElementById( 'rsmw-inventory-table' );

	if ( ! table ) {
		return;
	}

	var search = document.getElementById( 'rsmw-inventory-search' );
	var category = document.getElementById( 'rsmw-inventory-category' );
	var noResult = document.getElementById( 'rsmw-inventory-noresult' );
	var rows = Array.prototype.slice.call( table.tBodies[ 0 ].rows );

	function normalize( value ) {
		return ( value || '' ).toLowerCase().normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
	}

	function refresh() {
		var query = normalize( search.value.trim() );
		var wantedCategory = category.value;
		var visible = 0;

		rows.forEach( function ( row ) {
			var d = row.dataset;
			var categories = ( d.categories || '' ).split( ' ' );

			var matches = ( ! query || d.search.indexOf( query ) !== -1 ) &&
				( ! wantedCategory || categories.indexOf( wantedCategory ) !== -1 );

			row.style.display = matches ? '' : 'none';

			if ( matches ) {
				visible++;
			}
		} );

		noResult.style.display = visible ? 'none' : '';
		table.style.display = visible ? '' : 'none';
	}

	search.addEventListener( 'input', refresh );
	category.addEventListener( 'change', refresh );

	table.querySelectorAll( 'th[data-key]' ).forEach( function ( th ) {
		th.addEventListener( 'click', function () {
			var key = th.dataset.key;
			var direction = th.getAttribute( 'data-dir' ) === 'asc' ? 'desc' : 'asc';

			table.querySelectorAll( 'th[data-key]' ).forEach( function ( other ) {
				other.removeAttribute( 'data-dir' );
			} );

			th.setAttribute( 'data-dir', direction );

			var sorted = rows.slice().sort( function ( a, b ) {
				var va = a.dataset[ key ];
				var vb = b.dataset[ key ];
				var na = parseFloat( va );
				var nb = parseFloat( vb );
				var comparison = ( ! isNaN( na ) && ! isNaN( nb ) )
					? na - nb
					: String( va ).localeCompare( String( vb ), 'fr' );

				return direction === 'asc' ? comparison : -comparison;
			} );

			var body = table.tBodies[ 0 ];

			sorted.forEach( function ( row ) {
				body.appendChild( row );
			} );
		} );
	} );
}() );

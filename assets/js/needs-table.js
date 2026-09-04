/**
 * Page « Besoins & stock » — recherche, filtres, tri et export CSV.
 *
 * Tout se joue côté client sur les lignes déjà rendues : le tableau est complet
 * dès le chargement, il n'y a pas de pagination à gérer.
 */
( function () {
	'use strict';

	var table = document.getElementById( 'mh-table' );

	if ( ! table ) {
		return;
	}

	var search = document.getElementById( 'mh-search' );
	var onlyLack = document.getElementById( 'mh-only-lack' );
	var onlyStock = document.getElementById( 'mh-only-stock' );
	var noResult = document.getElementById( 'mh-noresult' );
	var exportLink = document.getElementById( 'mh-export' );
	var rows = Array.prototype.slice.call( table.tBodies[ 0 ].rows );

	function normalize( value ) {
		return ( value || '' ).toLowerCase().normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
	}

	var valueCell = document.getElementById( 'k-valeur' );

	function formatCurrency( value ) {
		var locale = valueCell.dataset.locale || 'fr-FR';
		var currency = valueCell.dataset.currency || 'EUR';
		var decimals = parseInt( valueCell.dataset.decimals, 10 );

		if ( isNaN( decimals ) ) {
			decimals = 2;
		}

		try {
			return value.toLocaleString( locale, {
				style: 'currency',
				currency: currency,
				minimumFractionDigits: decimals,
				maximumFractionDigits: decimals
			} );
		} catch ( error ) {
			// Locale ou code devise refusé par le navigateur : on dégrade sans casser.
			return value.toFixed( decimals );
		}
	}

	function refresh() {
		var query = normalize( search.value.trim() );
		var lackOnly = onlyLack.checked;
		var stockOnly = onlyStock.checked;
		var visible = 0;
		var remaining = 0;
		var ordered = 0;
		var missing = 0;
		var value = 0;
		var missingRefs = 0;

		rows.forEach( function ( row ) {
			var d = row.dataset;
			var matches = ( ! query || d.search.indexOf( query ) !== -1 ) &&
				( ! lackOnly || parseInt( d.manque, 10 ) > 0 ) &&
				( ! stockOnly || parseInt( d.libre, 10 ) > 0 );

			row.style.display = matches ? '' : 'none';

			if ( ! matches ) {
				return;
			}

			visible++;
			remaining += parseInt( d.restant, 10 );
			ordered += parseInt( d.commande, 10 ) || 0;
			missing += parseInt( d.manque, 10 );
			value += parseFloat( d.valeur );

			if ( parseInt( d.manque, 10 ) > 0 ) {
				missingRefs++;
			}
		} );

		document.getElementById( 'k-refs' ).textContent = visible;
		document.getElementById( 'k-restant' ).textContent = remaining;

		var orderedCell = document.getElementById( 'k-commande' );

		if ( orderedCell ) {
			orderedCell.textContent = ordered;
		}

		document.getElementById( 'k-manque' ).textContent = missing;
		document.getElementById( 'k-refsmanque' ).textContent = missingRefs;
		document.getElementById( 'k-valeur' ).textContent = formatCurrency( value );

		noResult.style.display = visible ? 'none' : '';
		table.style.display = visible ? '' : 'none';
	}

	search.addEventListener( 'input', refresh );
	onlyLack.addEventListener( 'change', refresh );
	onlyStock.addEventListener( 'change', refresh );

	/*
	 * Application immédiate des filtres cochés d'entrée — « Manquants uniquement »
	 * l'est. Sans cet appel, la case serait cochée devant un tableau complet, et
	 * les indicateurs compteraient des lignes que le filtre est censé masquer :
	 * le rendu serveur les calcule sur la totalité des références.
	 */
	refresh();

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

	exportLink.addEventListener( 'click', function ( event ) {
		event.preventDefault();

		var lines = [
			[ 'Reference', 'Demande', 'Deja pointe', 'Reste a preparer', 'Stock libre', 'En commande', 'Reste a commander', 'Commandes' ]
		];

		rows.forEach( function ( row ) {
			if ( row.style.display === 'none' ) {
				return;
			}

			var d = row.dataset;
			lines.push( [ d.name, d.demande, d.pointe, d.restant, d.libre, d.commande || 0, d.manque, d.commandes ] );
		} );

		var csv = lines.map( function ( line ) {
			return line.map( function ( cell ) {
				return '"' + String( cell ).replace( /"/g, '""' ) + '"';
			} ).join( ';' );
		} ).join( '\n' );

		// Le BOM force Excel à lire le fichier en UTF-8.
		var blob = new Blob( [ '\ufeff' + csv ], { type: 'text/csv;charset=utf-8;' } );
		var link = document.createElement( 'a' );

		link.href = URL.createObjectURL( blob );
		link.download = ( exportLink.dataset.filename || 'export' ) + '-' + new Date().toISOString().slice( 0, 10 ) + '.csv';
		link.click();
		URL.revokeObjectURL( link.href );
	} );
}() );

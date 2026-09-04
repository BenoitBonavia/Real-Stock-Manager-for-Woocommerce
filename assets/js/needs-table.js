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
			[ 'Reference', 'SKU', 'Fournisseur', 'Demande', 'Deja pointe', 'Reste a preparer', 'Stock libre', 'En commande', 'Reste a commander', 'Commandes' ]
		];

		rows.forEach( function ( row ) {
			if ( row.style.display === 'none' ) {
				return;
			}

			var d = row.dataset;
			lines.push( [ d.name, d.sku || '', d.fournisseur || '', d.demande, d.pointe, d.restant, d.libre, d.commande || 0, d.manque, d.commandes ] );
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

	/*
	 * Copie de la liste à commander.
	 *
	 * Le canal dominant d'une petite boutique est l'e-mail ou le portail du
	 * fournisseur, pas l'import CSV. Le fichier impose de télécharger, ouvrir un
	 * tableur, sélectionner, copier, coller, puis réparer l'encodage ; le
	 * presse-papier, c'est un clic. D'où les deux formats.
	 */
	function orderedQuantity( row ) {
		var input = row.querySelector( 'input[name^="rsmw_purchase"]' );

		if ( input ) {
			return parseInt( input.value, 10 ) || 0;
		}

		return parseInt( row.dataset.manque, 10 ) || 0;
	}

	function visibleLines( separator ) {
		var lines = [];

		rows.forEach( function ( row ) {
			if ( row.style.display === 'none' ) {
				return;
			}

			var quantity = orderedQuantity( row );

			// Une ligne à zéro ne fait pas partie de la commande : l'inclure
			// obligerait le fournisseur à la lire pour l'écarter lui-même.
			if ( quantity <= 0 ) {
				return;
			}

			var sku = row.dataset.sku || '';
			var name = row.dataset.name || '';

			if ( separator === '\t' ) {
				lines.push( [ sku, name, quantity ].join( '\t' ) );

				return;
			}

			lines.push( ( sku ? sku + ' — ' : '' ) + name + ' × ' + quantity );
		} );

		return lines.join( '\n' );
	}

	function copy( text, button ) {
		var label = button.textContent;

		function done() {
			button.textContent = button.dataset.done || label;
			window.setTimeout( function () {
				button.textContent = label;
			}, 2000 );
		}

		// navigator.clipboard exige un contexte sécurisé : sur un site de recette
		// en HTTP simple, il est absent. Le repli par textarea + execCommand reste
		// le seul mécanisme disponible dans ce cas.
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );

			return;
		}

		var area = document.createElement( 'textarea' );

		area.value = text;
		area.setAttribute( 'readonly', 'readonly' );
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();

		try {
			document.execCommand( 'copy' );
			done();
		} catch ( error ) {
			// Rien à faire de plus : le texte reste sélectionné dans la zone.
		}

		document.body.removeChild( area );
	}

	var copyText = document.getElementById( 'rsmw-copy-text' );
	var copyCells = document.getElementById( 'rsmw-copy-cells' );

	if ( copyText ) {
		copyText.addEventListener( 'click', function () {
			copy( visibleLines( ' ' ), copyText );
		} );
	}

	if ( copyCells ) {
		copyCells.addEventListener( 'click', function () {
			copy( visibleLines( '\t' ), copyCells );
		} );
	}
}() );

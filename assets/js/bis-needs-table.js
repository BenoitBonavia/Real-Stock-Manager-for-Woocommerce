/**
 * Page « Besoins Back in Stock » — recherche, filtres, tri et export CSV.
 *
 * Tout se joue côté client sur les lignes déjà rendues, comme sur la page
 * « Besoins pour commande » : le tableau est complet dès le chargement, il
 * n'y a pas de pagination à gérer.
 */
( function () {
	'use strict';

	var table = document.getElementById( 'rsmw-bis-table' );

	if ( ! table ) {
		return;
	}

	var search = document.getElementById( 'rsmw-bis-search' );
	var onlyMissing = document.getElementById( 'rsmw-bis-only-missing' );
	var onlyPartial = document.getElementById( 'rsmw-bis-only-partial' );
	var noResult = document.getElementById( 'rsmw-bis-noresult' );
	var exportLink = document.getElementById( 'rsmw-bis-export' );
	var rows = Array.prototype.slice.call( table.tBodies[ 0 ].rows );

	function normalize( value ) {
		return ( value || '' ).toLowerCase().normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
	}

	function refresh() {
		var query = normalize( search.value.trim() );
		var missingOnly = onlyMissing.checked;
		var partialOnly = onlyPartial.checked;
		var visible = 0;
		var inscrits = 0;
		var demande = 0;
		var satisfait = 0;
		var manque = 0;
		var refsManque = 0;

		rows.forEach( function ( row ) {
			var d = row.dataset;
			var rowManque = parseInt( d.manque, 10 ) || 0;
			var rowSatisfait = parseInt( d.satisfait, 10 ) || 0;
			var isPartial = rowSatisfait > 0 && rowManque > 0;

			var matches = ( ! query || d.search.indexOf( query ) !== -1 ) &&
				( ! missingOnly || rowManque > 0 ) &&
				( ! partialOnly || isPartial );

			row.style.display = matches ? '' : 'none';

			if ( ! matches ) {
				return;
			}

			visible++;
			inscrits += parseInt( d.inscrits, 10 ) || 0;
			demande += parseInt( d.demande, 10 ) || 0;
			satisfait += rowSatisfait;
			manque += rowManque;

			if ( rowManque > 0 ) {
				refsManque++;
			}
		} );

		document.getElementById( 'k-bis-refs' ).textContent = visible;
		document.getElementById( 'k-bis-inscrits' ).textContent = inscrits;
		document.getElementById( 'k-bis-demande' ).textContent = demande;
		document.getElementById( 'k-bis-satisfait' ).textContent = satisfait;
		document.getElementById( 'k-bis-manque' ).textContent = manque;
		document.getElementById( 'k-bis-refsmanque' ).textContent = refsManque;

		var rate = demande > 0 ? Math.min( 100, ( satisfait / demande ) * 100 ) : 100;

		setGauge( document.getElementById( 'k-bis-taux' ), rate );

		noResult.style.display = visible ? 'none' : '';
		table.style.display = visible ? '' : 'none';
	}

	function gaugeModifier( rate ) {
		if ( rate < 40 ) {
			return 'rsmw-gauge__fill--low';
		}

		if ( rate < 80 ) {
			return 'rsmw-gauge__fill--mid';
		}

		return '';
	}

	function setGauge( container, rate ) {
		if ( ! container ) {
			return;
		}

		var fill = container.querySelector( '.rsmw-gauge__fill' );
		var label = container.querySelector( '.rsmw-gauge__label' );
		var rounded = Math.round( rate * 10 ) / 10;

		if ( fill ) {
			fill.style.width = Math.max( 0, Math.min( 100, rate ) ) + '%';
			fill.className = 'rsmw-gauge__fill ' + gaugeModifier( rate );
		}

		if ( label ) {
			label.textContent = rounded + ' %';
		}
	}

	search.addEventListener( 'input', refresh );
	onlyMissing.addEventListener( 'change', refresh );
	onlyPartial.addEventListener( 'change', refresh );

	/*
	 * Application immédiate des filtres cochés d'entrée — « Non couvertes
	 * uniquement » l'est. Sans cet appel, la case serait cochée devant un
	 * tableau complet : le rendu serveur calcule les KPI sur la totalité des
	 * références.
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

	if ( exportLink ) {
		exportLink.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			var lines = [
				[ 'Reference', 'SKU', 'Fournisseur', 'Inscrits', 'Demande', 'Stock libre', 'A venir', 'Disponible', 'Satisfait', 'Manque', 'Taux (%)' ]
			];

			rows.forEach( function ( row ) {
				if ( row.style.display === 'none' ) {
					return;
				}

				var d = row.dataset;
				lines.push( [ d.name, d.sku || '', d.fournisseur || '', d.inscrits, d.demande, d.libre, d.avenir, d.disponible, d.satisfait, d.manque, d.taux ] );
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
	}

	/*
	 * Copie de la liste des manques, au même geste que la page « Besoins pour
	 * commande » : le canal dominant d'une petite boutique est l'e-mail ou le
	 * portail du fournisseur, pas l'import CSV.
	 */
	function visibleLines( separator ) {
		var lines = [];

		rows.forEach( function ( row ) {
			if ( row.style.display === 'none' ) {
				return;
			}

			var quantity = parseInt( row.dataset.manque, 10 ) || 0;

			// Une ligne sans manque ne fait pas partie de la liste : l'inclure
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

	var copyText = document.getElementById( 'rsmw-bis-copy-text' );
	var copyCells = document.getElementById( 'rsmw-bis-copy-cells' );

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

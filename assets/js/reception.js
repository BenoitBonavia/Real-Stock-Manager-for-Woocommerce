/**
 * Onglet « Réception d'un colis » — saisie en lot.
 *
 * Tout se joue côté client sur les lignes rendues par le serveur : totaux en
 * direct, reste attendu par ligne, recherche. Rien n'est écrit avant la
 * vérification côté serveur.
 */
( function () {
	'use strict';

	/*
	 * Filtre fournisseur : soumission au changement, pour épargner un clic.
	 *
	 * Branché AVANT la sortie anticipée ci-dessous : le sélecteur est présent même
	 * quand le tableau ne l'est pas — c'est justement le cas où le marchand vient
	 * de choisir un fournisseur qui n'attend rien et doit pouvoir en choisir un
	 * autre. Le bouton « Filtrer » reste le chemin sans JavaScript.
	 */
	var supplierFilter = document.getElementById( 'rsmw-supplier-filter' );
	var supplierSelect = document.getElementById( 'rsmw-supplier' );

	if ( supplierFilter && supplierSelect ) {
		supplierSelect.addEventListener( 'change', function () {
			supplierFilter.submit();
		} );
	}

	var table = document.getElementById( 'rsmw-reception-table' );

	if ( ! table ) {
		return;
	}

	var rows = Array.prototype.slice.call( table.tBodies[ 0 ].rows );
	var search = document.getElementById( 'rsmw-reception-search' );
	var empty = document.getElementById( 'rsmw-reception-empty' );
	var fillVisible = document.getElementById( 'rsmw-fill-visible' );
	var clearAll = document.getElementById( 'rsmw-clear-all' );

	function normalize( value ) {
		return ( value || '' ).toLowerCase().normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
	}

	function quantity( input ) {
		var parsed = parseInt( input.value, 10 );

		return ( isNaN( parsed ) || parsed < 0 ) ? 0 : parsed;
	}

	function setText( id, value ) {
		var element = document.getElementById( id );

		if ( element ) {
			element.textContent = value;
		}
	}

	/**
	 * Met à jour la colonne « restera attendu » d'une ligne et renvoie ses totaux.
	 */
	function refreshRow( row ) {
		var expected = parseInt( row.dataset.rsmwExpected, 10 ) || 0;
		var ok = quantity( row.querySelector( '.rsmw-reception__ok' ) );
		var defective = quantity( row.querySelector( '.rsmw-reception__defective' ) );
		var entered = ok + defective;
		var remaining = Math.max( 0, expected - entered );
		var cell = row.querySelector( '.rsmw-reception__remaining' );

		cell.textContent = remaining;
		cell.classList.toggle( 'rsmw-ordered', remaining > 0 );
		cell.classList.toggle( 'rsmw-full', 0 === remaining && entered > 0 );

		// Plus saisi qu'attendu : le surplus partira en stock libre, on le signale
		// tout de suite plutôt qu'à la vérification.
		row.classList.toggle( 'rsmw-reception--over', entered > expected );

		return { ok: ok, defective: defective, remaining: remaining, touched: entered > 0 };
	}

	function refresh() {
		var references = 0;
		var ok = 0;
		var defective = 0;
		var remaining = 0;

		rows.forEach( function ( row ) {
			var totals = refreshRow( row );

			if ( totals.touched ) {
				references++;
			}

			ok += totals.ok;
			defective += totals.defective;
			remaining += totals.remaining;
		} );

		setText( 'rsmw-k-refs', references );
		setText( 'rsmw-k-ok', ok );
		setText( 'rsmw-k-defective', defective );
		setText( 'rsmw-k-remaining', remaining );
	}

	function filter() {
		var query = normalize( search ? search.value.trim() : '' );
		var visible = 0;

		rows.forEach( function ( row ) {
			var matches = ! query || row.dataset.rsmwSearch.indexOf( query ) !== -1;

			row.hidden = ! matches;

			if ( matches ) {
				visible++;
			}
		} );

		if ( empty ) {
			empty.hidden = visible > 0;
		}
	}

	table.addEventListener( 'input', function ( event ) {
		if ( event.target.matches( '.rsmw-reception__ok, .rsmw-reception__defective' ) ) {
			refresh();
		}
	} );

	if ( search ) {
		search.addEventListener( 'input', filter );
	}

	if ( fillVisible ) {
		fillVisible.addEventListener( 'click', function () {
			// Uniquement les lignes visibles : le marchand filtre sur son bon de
			// livraison, puis remplit d'un geste ce qui reste à l'écran.
			rows.forEach( function ( row ) {
				if ( row.hidden ) {
					return;
				}

				row.querySelector( '.rsmw-reception__ok' ).value = row.dataset.rsmwExpected;
			} );

			refresh();
		} );
	}

	if ( clearAll ) {
		clearAll.addEventListener( 'click', function () {
			rows.forEach( function ( row ) {
				row.querySelector( '.rsmw-reception__ok' ).value = '';
				row.querySelector( '.rsmw-reception__defective' ).value = '';
			} );

			refresh();
		} );
	}

	refresh();
}() );

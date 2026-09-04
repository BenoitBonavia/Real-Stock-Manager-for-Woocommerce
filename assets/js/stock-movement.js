/**
 * Page « Gestion stock » — console de mouvement et filtres du journal.
 *
 * jQuery est nécessaire pour le champ de recherche produit : select2 émet ses
 * évènements via jQuery.trigger(), que les écouteurs natifs addEventListener
 * ne reçoivent pas.
 */
( function ( $ ) {
	'use strict';

	var root = document.querySelector( '.rsmw-console' );
	var panel = document.getElementById( 'rsmw-context' );

	if ( root && panel ) {
		initDirection();
		initContext();
	}

	initJournal();

	/**
	 * Affiche les champs propres au retrait selon le sens choisi.
	 */
	function initDirection() {
		var inputs = root.querySelectorAll( '[name="rsmw_movement_direction"]' );

		if ( ! inputs.length ) {
			return;
		}

		function sync() {
			var checked = root.querySelector( '[name="rsmw_movement_direction"]:checked' );
			var current = checked ? checked.value : 'in';

			// Champs propres à un seul sens — le motif ne concerne que le retrait.
			root.querySelectorAll( '[data-rsmw-only]' ).forEach( function ( element ) {
				var visible = element.dataset.rsmwOnly === current;

				element.classList.toggle( 'rsmw-field--hidden', ! visible );
				element.hidden = ! visible;
			} );

			// Explication du sens sélectionné.
			root.querySelectorAll( '[data-rsmw-hint]' ).forEach( function ( element ) {
				element.hidden = element.dataset.rsmwHint !== current;
			} );
		}

		Array.prototype.forEach.call( inputs, function ( input ) {
			input.addEventListener( 'change', sync );
		} );

		sync();
	}

	/**
	 * Renseigne le panneau de contexte quand une référence est choisie.
	 */
	function initContext() {
		var body = panel.querySelector( '.rsmw-card__body' );
		var select = $( '#rsmw_movement_product' );
		var sku = document.getElementById( 'rsmw_movement_sku' );

		function label( key ) {
			return panel.dataset[ key ] || '';
		}

		function request( payload ) {
			panel.classList.add( 'is-loading' );

			var body_ = new URLSearchParams(
				Object.assign( { action: root.dataset.action, nonce: root.dataset.nonce }, payload )
			);

			fetch( window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body_
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					panel.classList.remove( 'is-loading' );

					if ( ! result || ! result.success ) {
						renderMessage( label( 'notFound' ) );
						return;
					}

					render( result.data );
				} )
				.catch( function () {
					panel.classList.remove( 'is-loading' );
					renderMessage( label( 'networkError' ) );
				} );
		}

		function renderMessage( message ) {
			body.textContent = '';

			var paragraph = document.createElement( 'p' );
			paragraph.className = 'rsmw-context__empty';
			paragraph.textContent = message;
			body.appendChild( paragraph );
		}

		function statRow( name, value, modifier ) {
			var row = document.createElement( 'li' );
			row.className = 'rsmw-stats__row';

			var key = document.createElement( 'span' );
			key.className = 'rsmw-stats__label';
			key.textContent = name;

			var figure = document.createElement( 'span' );
			figure.className = 'rsmw-stats__value' + ( modifier ? ' rsmw-stats__value--' + modifier : '' );
			figure.textContent = value;

			row.appendChild( key );
			row.appendChild( figure );

			return row;
		}

		function render( data ) {
			body.textContent = '';

			var name = document.createElement( 'div' );
			name.className = 'rsmw-context__name';
			name.textContent = data.label;
			body.appendChild( name );

			if ( data.sku ) {
				var sku_ = document.createElement( 'div' );
				sku_.className = 'rsmw-context__sku';
				sku_.textContent = data.sku;
				body.appendChild( sku_ );
			}

			var stats = document.createElement( 'ul' );
			stats.className = 'rsmw-stats';
			stats.appendChild( statRow( label( 'free' ), data.free, data.free > 0 ? 'ok' : '' ) );
			stats.appendChild( statRow( label( 'remaining' ), data.remaining, '' ) );
			stats.appendChild( statRow( label( 'ordered' ), data.ordered, data.ordered > 0 ? 'ordered' : '' ) );
			stats.appendChild( statRow( label( 'orders' ), data.orders, '' ) );

			if ( data.missing > 0 ) {
				stats.appendChild( statRow( label( 'missing' ), data.missing, 'lack' ) );
			}

			body.appendChild( stats );

			if ( data.oldest ) {
				var oldest = document.createElement( 'div' );
				oldest.className = 'rsmw-context__oldest';
				oldest.appendChild( document.createTextNode( label( 'oldest' ) + ' ' ) );

				var link = document.createElement( 'a' );
				link.href = data.oldest.url;
				link.textContent = '#' + data.oldest.num;
				oldest.appendChild( link );

				if ( data.oldest.date ) {
					oldest.appendChild( document.createTextNode( ' ' + label( 'on' ) + ' ' + data.oldest.date ) );
				}

				body.appendChild( oldest );
			}
		}

		select.on( 'change', function () {
			var id = parseInt( select.val(), 10 );

			if ( id > 0 ) {
				request( { product: id } );
			}
		} );

		if ( sku ) {
			sku.addEventListener( 'change', function () {
				var value = sku.value.trim();

				if ( value ) {
					request( { sku: value } );
				}
			} );
		}
	}

	/**
	 * Recherche et filtre par sens sur le journal des mouvements.
	 */
	function initJournal() {
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

		function refresh() {
			var query = normalize( search ? search.value.trim() : '' );
			var visible = 0;

			rows.forEach( function ( row ) {
				var type = row.dataset.rsmwType;

				// « Fournisseur » regroupe les commandes passées et leurs annulations.
				var inFamily = ( 'all' === current ) ||
					( 'supply' === current ? ( 'order' === type || 'unorder' === type ) : type === current );

				var matches = inFamily &&
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
	}
}( jQuery ) );

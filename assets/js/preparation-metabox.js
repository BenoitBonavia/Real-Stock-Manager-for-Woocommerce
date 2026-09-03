/**
 * Métabox « Préparation » — pointage des lignes de commande.
 *
 * Le nonce et l'identifiant de commande sont portés par des attributs de données
 * sur le conteneur ; les libellés le sont par le paragraphe de message. Aucune
 * variable globale n'est donc nécessaire, hormis `ajaxurl` fourni par WordPress.
 */
( function () {
	'use strict';

	var box = document.getElementById( 'mh-prep-box-inner' );

	if ( ! box || box.dataset.bound ) {
		return;
	}

	box.dataset.bound = '1';

	var msg = box.querySelector( '.mh-msg' );

	function text( key ) {
		return msg.dataset[ key ] || '';
	}

	function apply( data ) {
		data.lines.forEach( function ( line ) {
			var row = box.querySelector( 'tr[data-item="' + line.item + '"]' );

			if ( ! row ) {
				return;
			}

			var max = parseInt( row.dataset.max, 10 );

			row.querySelector( '.mh-qty' ).textContent = line.qty + ' / ' + max;
			row.querySelector( '[data-mh-delta="-1"]' ).disabled = ( line.qty <= 0 );
			row.querySelector( '[data-mh-delta="1"]' ).disabled = ( line.qty >= max );
			row.classList.toggle( 'is-done', line.qty >= max );

			var free = row.querySelector( '.mh-free' );
			free.textContent = line.free;
			free.classList.toggle( 'neg', line.free < 0 );
		} );

		document.getElementById( 'mh-prep-done' ).textContent = data.done;
		document.getElementById( 'mh-prep-fill' ).style.width = data.pct + '%';
		msg.textContent = data.message;

		if ( data.reload ) {
			msg.textContent = data.message + ' ' + text( 'reloading' );
			setTimeout( function () {
				window.location.reload();
			}, 900 );
		}
	}

	function send( payload ) {
		box.classList.add( 'busy' );
		msg.textContent = text( 'saving' );

		var body = new URLSearchParams(
			Object.assign(
				{
					action: 'mh_prep_set',
					nonce: box.dataset.nonce,
					order: box.dataset.order
				},
				payload
			)
		);

		fetch( window.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				box.classList.remove( 'busy' );

				if ( ! result || ! result.success ) {
					msg.textContent = ( result && result.data ) ? result.data : text( 'failed' );
					return;
				}

				apply( result.data );
			} )
			.catch( function () {
				box.classList.remove( 'busy' );
				msg.textContent = text( 'network' );
			} );
	}

	box.addEventListener( 'click', function ( event ) {
		var step = event.target.closest( '[data-mh-delta]' );

		if ( step ) {
			send( { item: step.closest( 'tr' ).dataset.item, delta: step.dataset.mhDelta } );
			return;
		}

		var all = event.target.closest( '[data-mh-all]' );

		if ( all ) {
			send( { all: all.dataset.mhAll } );
		}
	} );
}() );

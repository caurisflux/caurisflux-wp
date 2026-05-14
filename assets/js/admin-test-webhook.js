/* global CaurisFluxTestWebhook */
(function () {
	'use strict';

	var data = window.CaurisFluxTestWebhook || {};

	function init() {
		var btn = document.getElementById( 'caurisflux-test-webhook' );
		if ( ! btn || btn.dataset.cfluxBound === '1' ) {
			return;
		}
		btn.dataset.cfluxBound = '1';

		btn.addEventListener( 'click', function () {
			var oid = window.prompt( data.i18n.orderIdPrompt );
			if ( ! oid ) {
				return;
			}
			var event = window.prompt( data.i18n.eventPrompt, 'payment.completed' );
			if ( ! event ) {
				return;
			}
			btn.disabled = true;
			var status = document.getElementById( 'caurisflux-test-webhook-result' );
			if ( status ) {
				status.textContent = data.i18n.sending;
			}

			fetch( data.restUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': data.nonce
				},
				body: JSON.stringify( {
					event: event,
					order_id: parseInt( oid, 10 )
				} )
			} )
				.then( function ( r ) {
					return r.json().then( function ( j ) {
						return [ r.status, j ];
					} );
				} )
				.then( function ( arr ) {
					btn.disabled = false;
					if ( status ) {
						status.textContent = arr[ 0 ] + ': ' + JSON.stringify( arr[ 1 ] );
					}
				} )
				.catch( function ( e ) {
					btn.disabled = false;
					if ( status ) {
						status.textContent = 'Error: ' + e.message;
					}
				} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();

/* global CaurisFluxOrderMetabox */
(function () {
	'use strict';

	var data = window.CaurisFluxOrderMetabox || {};

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target && e.target.closest && e.target.closest( '.cflux-check-status-btn' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();

		var orderId = btn.getAttribute( 'data-order-id' );
		var nonce = btn.getAttribute( 'data-nonce' );
		var result = btn.parentElement.querySelector( '.cflux-check-status-result' );

		btn.disabled = true;
		if ( result ) {
			result.textContent = data.i18n.checking;
		}

		var body = new FormData();
		body.append( 'action', 'caurisflux_check_status' );
		body.append( 'order_id', orderId );
		body.append( '_wpnonce', nonce );

		fetch( data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( r ) {
				return r.json().then( function ( j ) {
					return [ r.ok, j ];
				} );
			} )
			.then( function ( arr ) {
				btn.disabled = false;
				if ( result ) {
					result.textContent = arr[ 1 ] && arr[ 1 ].data && arr[ 1 ].data.message
						? arr[ 1 ].data.message
						: ( arr[ 0 ] ? data.i18n.ok : data.i18n.error );
				}
				if ( arr[ 0 ] && arr[ 1 ] && arr[ 1 ].data && arr[ 1 ].data.status_changed ) {
					setTimeout( function () {
						window.location.reload();
					}, 800 );
				}
			} )
			.catch( function ( err ) {
				btn.disabled = false;
				if ( result ) {
					result.textContent = String( err );
				}
			} );
	} );
})();

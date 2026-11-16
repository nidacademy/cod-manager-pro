/* COD Manager Pro – Finance: live totals in the payout form */
( function () {
	'use strict';
	var __ = wp.i18n.__, sprintf = wp.i18n.sprintf;
	var U = window.CODMUI;
	if ( ! U ) { return; }

	function update( form ) {
		var total = 0;
		form.querySelectorAll( 'input[name="order_ids[]"]:checked' ).forEach( function ( cb ) {
			total += parseFloat( cb.getAttribute( 'data-net' ) ) || 0;
		} );
		total = Math.round( total * 100 ) / 100;
		var out = form.querySelector( '.codm-expected' );
		if ( out ) { out.textContent = U.money( total ); }
		var received = form.querySelector( '.codm-received' );
		if ( received && ! received.dataset.touched ) { received.value = total.toFixed( 2 ); }
		showDiff( form, total );
	}

	function showDiff( form, expected ) {
		var received = form.querySelector( '.codm-received' );
		var diffBox = form.querySelector( '.codm-diff' );
		if ( ! received || ! diffBox ) { return; }
		var got = parseFloat( String( received.value ).replace( /\s/g, '' ).replace( ',', '.' ) ) || 0;
		var diff = Math.round( ( got - expected ) * 100 ) / 100;
		diffBox.textContent = Math.abs( diff ) < 0.01 ? '' : ( diff < 0
			/* translators: %s: missing amount with currency. */
			? sprintf( __( 'Missing %s compared to the expected payout.', 'cod-manager-pro' ), U.money( -diff ) )
			/* translators: %s: extra amount with currency. */
			: sprintf( __( '%s more than expected.', 'cod-manager-pro' ), U.money( diff ) ) );
		diffBox.className = 'codm-diff' + ( diff < 0 ? ' codm-neg' : '' );
	}

	function expectedOf( form ) {
		var total = 0;
		form.querySelectorAll( 'input[name="order_ids[]"]:checked' ).forEach( function ( cb ) { total += parseFloat( cb.getAttribute( 'data-net' ) ) || 0; } );
		return Math.round( total * 100 ) / 100;
	}

	document.querySelectorAll( '.codm-payout-form' ).forEach( function ( form ) {
		form.addEventListener( 'change', function ( e ) {
			if ( e.target.classList.contains( 'codm-check-all' ) ) {
				form.querySelectorAll( 'input[name="order_ids[]"]' ).forEach( function ( cb ) { cb.checked = e.target.checked; } );
			}
			if ( e.target.name === 'order_ids[]' || e.target.classList.contains( 'codm-check-all' ) ) { update( form ); }
		} );
		var received = form.querySelector( '.codm-received' );
		if ( received ) {
			received.addEventListener( 'input', function () {
				received.dataset.touched = '1';
				showDiff( form, expectedOf( form ) );
			} );
		}
	} );

	if ( location.hash === '#payouts' || location.hash === '#expenses' ) {
		var target = document.querySelector( location.hash );
		if ( target ) { target.scrollIntoView( { behavior: 'smooth' } ); }
	}
}() );

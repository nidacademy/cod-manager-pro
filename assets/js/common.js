/* COD Manager Pro – shared helpers */
( function () {
	'use strict';
	var __ = wp.i18n.__, sprintf = wp.i18n.sprintf;

	var C = window.CODM || {};

	var statusLabels = {};
	( C.statuses || [] ).forEach( function ( s ) { statusLabels[ s.key ] = s.label; } );
	Object.keys( C.status_labels || {} ).forEach( function ( k ) { if ( ! statusLabels[ k ] ) { statusLabels[ k ] = C.status_labels[ k ]; } } );

	function esc( v ) {
		return String( v === null || v === undefined ? '' : v )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	function num( v, decimals ) {
		var d = decimals === undefined ? 2 : decimals;
		var n = Number( v ) || 0;
		var parts = Math.abs( n ).toFixed( d ).split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, '\u202F' );
		return ( n < 0 ? '−' : '' ) + parts.join( ',' );
	}

	function money( v, decimals ) {
		return num( v, decimals ) + '\u00A0' + ( C.currency || 'DH' );
	}

	function pct( v ) {
		return v === null || v === undefined ? '—' : num( v, 1 ) + '\u00A0%';
	}

	function badge( status ) {
		return '<span class="codm-badge codm-badge--' + esc( status ) + '">' + esc( statusLabels[ status ] || status ) + '</span>';
	}

	var riskText = {
		blacklisted: __( 'Blacklisted', 'cod-manager-pro' ),
		high: __( 'High risk', 'cod-manager-pro' ),
		medium: __( 'Watch', 'cod-manager-pro' ),
		trusted: __( 'Trusted', 'cod-manager-pro' ),
		'new': __( 'New customer', 'cod-manager-pro' )
	};
	function risk( customer ) {
		var r = customer.risk;
		/* translators: 1: number of orders, 2: delivered orders, 3: returned orders, 4: cancelled orders. */
		var title = sprintf( __( '%1$s orders · %2$s delivered · %3$s returned · %4$s cancelled', 'cod-manager-pro' ), customer.orders, customer.delivered, customer.returned, customer.cancelled );
		var extra = r === 'trusted' ? ' · ' + customer.delivered + '✓' : ( r === 'high' || r === 'medium' ? ' · ' + sprintf(
			/* translators: %s: number of refused (returned) deliveries. */
			__( '%s refused', 'cod-manager-pro' ), customer.returned ) : '' );
		return '<span class="codm-risk codm-risk--' + esc( r ) + '" title="' + esc( title ) + '">' + esc( riskText[ r ] || r ) + esc( extra ) + '</span>';
	}

	function api( path, opts ) {
		opts = opts || {};
		var init = {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.nonce, 'Accept': 'application/json' }
		};
		var url = C.rest + path.replace( /^\//, '' );
		if ( opts.query ) {
			var q = Object.keys( opts.query ).filter( function ( k ) {
				return opts.query[ k ] !== '' && opts.query[ k ] !== null && opts.query[ k ] !== undefined;
			} ).map( function ( k ) {
				return encodeURIComponent( k ) + '=' + encodeURIComponent( opts.query[ k ] );
			} ).join( '&' );
			if ( q ) { url += ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + q; }
		}
		if ( opts.body ) {
			init.headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( opts.body );
		}
		return fetch( url, init ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( ( data && data.message ) || sprintf(
						/* translators: %s: HTTP status code. */
						__( 'Request failed (%s)', 'cod-manager-pro' ), res.status ) );
				}
				return data;
			} );
		} );
	}

	var toastBox;
	function toast( message, type ) {
		if ( ! toastBox ) {
			toastBox = document.createElement( 'div' );
			toastBox.className = 'codm-toasts';
			toastBox.setAttribute( 'role', 'status' );
			toastBox.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( toastBox );
		}
		var t = document.createElement( 'div' );
		t.className = 'codm-toast' + ( type === 'error' ? ' codm-toast--error' : '' );
		t.textContent = message;
		toastBox.appendChild( t );
		setTimeout( function () { t.classList.add( 'is-out' ); }, 3600 );
		setTimeout( function () { t.remove(); }, 4000 );
	}

	function debounce( fn, ms ) {
		var timer;
		return function () {
			var args = arguments, ctx = this;
			clearTimeout( timer );
			timer = setTimeout( function () { fn.apply( ctx, args ); }, ms );
		};
	}

	function ymd( d ) {
		var m = d.getMonth() + 1, day = d.getDate();
		return d.getFullYear() + '-' + ( m < 10 ? '0' : '' ) + m + '-' + ( day < 10 ? '0' : '' ) + day;
	}

	window.CODMUI = {
		esc: esc, num: num, money: money, pct: pct, badge: badge, risk: risk,
		api: api, toast: toast, debounce: debounce, ymd: ymd, statusLabels: statusLabels
	};
}() );

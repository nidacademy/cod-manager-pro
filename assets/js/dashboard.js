/* COD Manager Pro – Dashboard */
( function () {
	'use strict';
	var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;
	var U = window.CODMUI, Charts = window.CODMCharts;
	if ( ! U || ! Charts ) { return; }

	var COLORS = {
		'new': '#3D63DD', pending: '#C27C0E', confirmed: '#4636C9', processing: '#7E5BD6', ready: '#0E7C86',
		shipped: '#2B87C8', out_for_delivery: '#1F9BB5', delivered: '#13865B', cancelled: '#8A8F98', returned: '#C93A32'
	};

	var state = { from: '', to: '', group: 'day' };
	var $ = function ( s, ctx ) { return ( ctx || document ).querySelector( s ); };
	var $$ = function ( s, ctx ) { return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( s ) ); };
	var lastData = null;

	function preset( key ) {
		var today = new Date(), from = new Date( today ), to = new Date( today );
		if ( key === 'today' ) { /* same day */ }
		else if ( key === 'month' ) { from = new Date( today.getFullYear(), today.getMonth(), 1 ); }
		else if ( key === 'last_month' ) {
			from = new Date( today.getFullYear(), today.getMonth() - 1, 1 );
			to = new Date( today.getFullYear(), today.getMonth(), 0 );
		} else { from.setDate( today.getDate() - ( parseInt( key, 10 ) - 1 ) ); }
		return [ U.ymd( from ), U.ymd( to ) ];
	}

	function setKpis( s ) {
		var values = {
			net_profit: U.money( s.net_profit, 0 ),
			margin_text: s.margin === null ? __( 'No delivered orders in this period yet', 'cod-manager-pro' ) : sprintf(
				/* translators: 1: net margin percentage number, 2: gross profit amount with currency. */
				__( '%1$s %% net margin · gross profit %2$s', 'cod-manager-pro' ), U.num( s.margin, 1 ), U.money( s.gross_profit, 0 ) ),
			revenue: U.money( s.revenue, 0 ),
			delivery_rate: U.pct( s.delivery_rate ),
			return_rate: U.pct( s.return_rate ),
			confirmation_rate: U.pct( s.confirmation_rate ),
			orders: s.orders, pending: s.pending, confirmed: s.confirmed, shipped: s.shipped,
			delivered: s.delivered, cancelled: s.cancelled, returned: s.returned,
			pending_payouts: U.money( s.pending_payouts, 0 )
		};
		$$( '[data-k]' ).forEach( function ( node ) {
			var k = node.getAttribute( 'data-k' );
			if ( k in values ) { node.textContent = values[ k ]; }
		} );
		var hero = $( '[data-k="net_profit"]' );
		if ( hero ) { hero.classList.toggle( 'codm-neg', s.net_profit < 0 ); }
	}

	function renderChart( series ) {
		Charts.combo( $( '#codm-sales-chart' ), {
			title: __( 'Orders, revenue and profit', 'cod-manager-pro' ),
			labels: series.map( function ( p ) { return p.label; } ),
			bars: { name: __( 'Orders', 'cod-manager-pro' ), values: series.map( function ( p ) { return p.orders; } ), color: '#D9D5F6' },
			lines: [
				{ name: __( 'Revenue', 'cod-manager-pro' ), values: series.map( function ( p ) { return p.revenue; } ), color: '#4636C9' },
				{ name: __( 'Profit', 'cod-manager-pro' ), values: series.map( function ( p ) { return p.profit; } ), color: '#13865B' }
			],
			formatLine: function ( v ) { return U.money( v, 0 ); }
		} );
	}

	function renderStatus( counts ) {
		var items = ( window.CODM.statuses || [] ).map( function ( s ) {
			return { label: s.label, value: counts[ s.key ] || 0, color: COLORS[ s.key ] || '#999' };
		} );
		Charts.donut( $( '#codm-status-chart' ), items, __( 'orders', 'cod-manager-pro' ) );
	}

	function renderRecent( rows ) {
		var box = $( '#codm-recent' );
		if ( ! rows.length ) { box.innerHTML = '<p class="codm-empty">' + U.esc( __( 'No orders yet. New WooCommerce orders appear here automatically.', 'cod-manager-pro' ) ) + '</p>'; return; }
		box.innerHTML = '<table class="codm-table codm-table--compact"><thead><tr><th>' + U.esc( __( 'Order', 'cod-manager-pro' ) ) + '</th><th>' + U.esc( __( 'Customer', 'cod-manager-pro' ) ) + '</th><th>' + U.esc( __( 'City', 'cod-manager-pro' ) ) + '</th><th>' + U.esc( __( 'Status', 'cod-manager-pro' ) ) + '</th><th class="num">' + U.esc( __( 'COD', 'cod-manager-pro' ) ) + '</th></tr></thead><tbody>' +
			rows.map( function ( o ) {
				return '<tr><td><a href="' + U.esc( window.CODM.urls.orders + '&open=' + o.id ) + '">#' + U.esc( o.number ) + '</a><span class="codm-muted">' + U.esc( o.date_short ) + '</span></td>' +
					'<td>' + U.esc( o.customer.name ) + ' ' + U.risk( o.customer ) + '</td><td>' + U.esc( o.city ) + '</td><td>' + U.badge( o.status ) + '</td><td class="num">' + U.money( o.total ) + '</td></tr>';
			} ).join( '' ) + '</tbody></table>';
	}

	function renderSources( list ) {
		var box = $( '#codm-sources' );
		if ( ! box ) { return; }
		if ( ! list || ! list.length ) { box.innerHTML = '<p class="codm-empty">' + U.esc( __( 'No orders in this period.', 'cod-manager-pro' ) ) + '</p>'; return; }
		var max = Math.max.apply( null, list.map( function ( s ) { return s.orders; } ) ) || 1;
		box.innerHTML = '<table class="codm-table codm-table--compact codm-sources"><thead><tr><th>' + U.esc( __( 'Source', 'cod-manager-pro' ) ) + '</th><th class="num">' + U.esc( __( 'Orders', 'cod-manager-pro' ) ) + '</th><th class="num">' + U.esc( __( 'Confirmed', 'cod-manager-pro' ) ) + '</th><th class="num">' + U.esc( __( 'Delivered', 'cod-manager-pro' ) ) + '</th><th class="num">' + U.esc( __( 'Returned', 'cod-manager-pro' ) ) + '</th><th class="num">' + U.esc( __( 'Cancelled', 'cod-manager-pro' ) ) + '</th><th class="num">' + U.esc( __( 'Revenue', 'cod-manager-pro' ) ) + '</th></tr></thead><tbody>' +
			list.map( function ( s ) {
				return '<tr><td><span class="codm-srcbar"><i style="width:' + Math.round( 100 * s.orders / max ) + '%"></i></span><strong>' + U.esc( s.source ) + '</strong></td>' +
					'<td class="num"><strong>' + s.orders + '</strong></td>' +
					'<td class="num">' + s.confirmed + ' <span class="codm-muted">' + U.pct( s.confirmation_rate ) + '</span></td>' +
					'<td class="num">' + s.delivered + ' <span class="codm-muted">' + U.pct( s.delivery_rate ) + '</span></td>' +
					'<td class="num">' + s.returned + '</td><td class="num">' + s.cancelled + '</td>' +
					'<td class="num">' + U.money( s.revenue, 0 ) + '</td></tr>';
			} ).join( '' ) + '</tbody></table><p class="codm-help">' + U.esc( __( 'Confirmed % = share of orders confirmed by phone/WhatsApp. Delivered % = delivered out of delivered + returned.', 'cod-manager-pro' ) ) + '</p>';
	}

	function renderLowStock( list ) {
		var ul = $( '#codm-lowstock' );
		ul.innerHTML = list.length ? list.map( function ( p ) {
			return '<li><a href="' + U.esc( p.edit || '#' ) + '">' + U.esc( p.name ) + '</a><span class="codm-stock' + ( p.stock <= 0 ? ' is-out' : '' ) + '">' + U.esc( p.stock <= 0 ? __( 'Out of stock', 'cod-manager-pro' ) : sprintf(
				/* translators: %s: number of items left in stock. */
				_n( '%s left', '%s left', p.stock, 'cod-manager-pro' ), p.stock ) ) + '</span></li>';
		} ).join( '' ) : '<li class="codm-muted">' + U.esc( __( 'All tracked products are above the alert level.', 'cod-manager-pro' ) ) + '</li>';
	}

	function load() {
		document.body.classList.add( 'codm-loading' );
		U.api( 'dashboard', { query: state } ).then( function ( data ) {
			lastData = data;
			setKpis( data.summary );
			renderChart( data.series );
			renderStatus( data.summary.counts );
			renderRecent( data.recent );
			renderLowStock( data.low_stock );
			renderSources( data.sources );
		} ).catch( function ( e ) {
			U.toast( e.message, 'error' );
		} ).then( function () {
			document.body.classList.remove( 'codm-loading' );
		} );
	}

	function setRange( from, to, activeBtn ) {
		state.from = from; state.to = to;
		$( '#codm-from' ).value = from; $( '#codm-to' ).value = to;
		$$( '#codm-range [data-range]' ).forEach( function ( b ) { b.classList.toggle( 'is-active', b === activeBtn ); } );
		// Long ranges read better weekly or monthly.
		var days = ( new Date( to ) - new Date( from ) ) / 86400000;
		if ( state.group === 'day' && days > 92 ) { selectGroup( 'week', false ); }
		load();
	}

	function selectGroup( group, reload ) {
		state.group = group;
		$$( '#codm-group [data-group]' ).forEach( function ( b ) { b.classList.toggle( 'is-active', b.getAttribute( 'data-group' ) === group ); } );
		if ( reload ) { load(); }
	}

	document.addEventListener( 'click', function ( e ) {
		var r = e.target.closest( '#codm-range [data-range]' );
		if ( r ) { var p = preset( r.getAttribute( 'data-range' ) ); setRange( p[ 0 ], p[ 1 ], r ); return; }
		var g = e.target.closest( '#codm-group [data-group]' );
		if ( g ) { selectGroup( g.getAttribute( 'data-group' ), true ); }
	} );

	[ '#codm-from', '#codm-to' ].forEach( function ( id ) {
		$( id ).addEventListener( 'change', function () {
			if ( $( '#codm-from' ).value && $( '#codm-to' ).value ) { setRange( $( '#codm-from' ).value, $( '#codm-to' ).value, null ); }
		} );
	} );

	window.addEventListener( 'resize', U.debounce( function () { if ( lastData ) { renderChart( lastData.series ); } }, 200 ) );

	var init = preset( '30' );
	setRange( init[ 0 ], init[ 1 ], $( '#codm-range [data-range="30"]' ) );
}() );

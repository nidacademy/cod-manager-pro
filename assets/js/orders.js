/* COD Manager Pro – Orders screen */
( function () {
	'use strict';
	var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;
	var U = window.CODMUI, C = window.CODM;
	if ( ! U || ! C ) { return; }

	var $ = function ( s, ctx ) { return ( ctx || document ).querySelector( s ); };
	var $$ = function ( s, ctx ) { return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( s ) ); };
	var esc = U.esc;

	var params = new URLSearchParams( location.search );
	var state = {
		status: params.get( 'status' ) || 'open',
		search: '', city: '', source: '', company: '', from: '', to: '', page: 1, per_page: 25
	};
	var selected = new Set();
	var rowsById = {};
	var drawerId = null;

	/* The work queues shown as big tabs. */
	var QUEUES = [
		{ key: 'open', label: __( 'To call', 'cod-manager-pro' ), hint: __( 'Confirm by phone', 'cod-manager-pro' ), statuses: [ 'new', 'pending' ] },
		{ key: 'to_ship', label: __( 'To ship', 'cod-manager-pro' ), hint: __( 'Pack & hand to courier', 'cod-manager-pro' ), statuses: [ 'confirmed', 'processing', 'ready' ] },
		{ key: 'with_courier', label: __( 'With courier', 'cod-manager-pro' ), hint: __( 'Waiting for delivery', 'cod-manager-pro' ), statuses: [ 'shipped', 'out_for_delivery' ] },
		{ key: 'done', label: __( 'Done', 'cod-manager-pro' ), hint: __( 'Delivered, returned, cancelled', 'cod-manager-pro' ), statuses: [ 'delivered', 'returned', 'cancelled' ] },
		{ key: 'all', label: __( 'All', 'cod-manager-pro' ), hint: __( 'Every order', 'cod-manager-pro' ), statuses: [] }
	];
	var DONE_SUB = [ [ 'done', __( 'All', 'cod-manager-pro' ) ], [ 'delivered', __( 'Delivered', 'cod-manager-pro' ) ], [ 'returned', __( 'Returned', 'cod-manager-pro' ) ], [ 'cancelled', __( 'Cancelled', 'cod-manager-pro' ) ] ];

	/* Step of an order: call, ship, courier or done. */
	function stepOf( status ) {
		if ( status === 'new' || status === 'pending' ) { return 'call'; }
		if ( status === 'confirmed' || status === 'processing' || status === 'ready' ) { return 'ship'; }
		if ( status === 'shipped' || status === 'out_for_delivery' ) { return 'courier'; }
		return 'done';
	}

	/* Next step buttons used in the order panel: [target status, label, style] */
	var NEXT = {
		call: [ [ 'confirmed', __( 'Confirm', 'cod-manager-pro' ), 'primary' ], [ '__no_answer', __( 'No answer', 'cod-manager-pro' ), 'warn' ], [ 'cancelled', __( 'Cancel', 'cod-manager-pro' ), 'danger' ] ],
		ship: [ [ 'shipped', __( 'Mark shipped', 'cod-manager-pro' ), 'primary' ], [ 'cancelled', __( 'Cancel', 'cod-manager-pro' ), 'danger' ] ],
		courier: [ [ 'delivered', __( 'Delivered', 'cod-manager-pro' ), 'primary' ], [ 'returned', __( 'Returned', 'cod-manager-pro' ), 'danger' ] ],
		done: []
	};

	/* ------------------------------------------------------------ */
	/* Filters and tabs                                              */
	/* ------------------------------------------------------------ */

	function initFilters() {
		var city = $( '#codm-city' );
		( C.cities || [] ).forEach( function ( c ) {
			var o = document.createElement( 'option' ); o.value = c; o.textContent = c; city.appendChild( o );
		} );
		var source = $( '#codm-source' );
		if ( source ) {
			( C.sources || [] ).forEach( function ( s ) {
				var o = document.createElement( 'option' ); o.value = s; o.textContent = s; source.appendChild( o );
			} );
			var unk = document.createElement( 'option' ); unk.value = '__unknown'; unk.textContent = __( 'Unknown', 'cod-manager-pro' ); source.appendChild( unk );
		}
		var company = $( '#codm-company' );
		( C.companies || [] ).forEach( function ( c ) {
			var o = document.createElement( 'option' ); o.value = c.id; o.textContent = c.active ? c.name : sprintf(
				/* translators: %s: shipping company name. */
				__( '%s (inactive)', 'cod-manager-pro' ), c.name ); company.appendChild( o );
		} );
		var none = document.createElement( 'option' ); none.value = '0'; none.textContent = __( 'No company', 'cod-manager-pro' ); company.appendChild( none );

		var bulk = $( '#codm-bulk-status' );
		bulk.innerHTML = '<option value="">' + esc( __( 'Move to…', 'cod-manager-pro' ) ) + '</option>' + C.statuses.map( function ( s ) {
			return '<option value="' + esc( s.key ) + '">' + esc( s.label ) + '</option>';
		} ).join( '' );

		$( '#codm-search' ).addEventListener( 'input', U.debounce( function ( e ) { state.search = e.target.value.trim(); state.page = 1; load(); }, 300 ) );
		[ [ '#codm-city', 'city' ], [ '#codm-source', 'source' ], [ '#codm-company', 'company' ], [ '#codm-from', 'from' ], [ '#codm-to', 'to' ] ].forEach( function ( pair ) {
			$( pair[ 0 ] ).addEventListener( 'change', function ( e ) { state[ pair[ 1 ] ] = e.target.value; state.page = 1; load(); } );
		} );
		$( '#codm-reset' ).addEventListener( 'click', function () {
			state.search = state.city = state.source = state.company = state.from = state.to = '';
			state.page = 1;
			[ '#codm-search', '#codm-city', '#codm-source', '#codm-company', '#codm-from', '#codm-to' ].forEach( function ( id ) { $( id ).value = ''; } );
			load();
		} );
		if ( $( '#codm-export' ) ) $( '#codm-export' ).addEventListener( 'click', function ( e ) {
			e.preventDefault();
			window.location = C.urls.export + '&' + filterQuery();
		} );
	}

	function filterQuery( extra ) {
		var q = new URLSearchParams();
		[ 'status', 'search', 'city', 'source', 'company', 'from', 'to' ].forEach( function ( k ) {
			if ( state[ k ] !== '' && ! ( k === 'status' && state[ k ] === 'all' ) ) { q.set( k, state[ k ] ); }
		} );
		Object.keys( extra || {} ).forEach( function ( k ) { q.set( k, extra[ k ] ); } );
		return q.toString();
	}

	function renderTabs( counts ) {
		$( '#codm-status-tabs' ).innerHTML = QUEUES.map( function ( q ) {
			var n = q.key === 'all' ? ( counts.all || 0 ) : q.statuses.reduce( function ( s, k ) { return s + ( counts[ k ] || 0 ); }, 0 );
			var active = state.status === q.key || ( q.key === 'done' && [ 'delivered', 'returned', 'cancelled' ].indexOf( state.status ) > -1 );
			return '<button type="button" role="tab" aria-selected="' + active + '" class="codm-qtab codm-qtab--' + q.key + ( active ? ' is-active' : '' ) + ( n && q.key !== 'done' && q.key !== 'all' ? ' has-work' : '' ) + '" data-status="' + q.key + '">' +
				'<span class="codm-qtab-top"><span class="codm-qtab-label">' + esc( q.label ) + '</span><span class="codm-qtab-n">' + n + '</span></span>' +
				'<span class="codm-qtab-hint">' + esc( q.hint ) + '</span></button>';
		} ).join( '' );
		var sub = $( '#codm-subtabs' );
		var inDone = state.status === 'done' || [ 'delivered', 'returned', 'cancelled' ].indexOf( state.status ) > -1;
		sub.hidden = ! inDone;
		if ( inDone ) {
			sub.innerHTML = DONE_SUB.map( function ( d ) {
				var n = d[ 0 ] === 'done' ? ( counts.delivered || 0 ) + ( counts.returned || 0 ) + ( counts.cancelled || 0 ) : ( counts[ d[ 0 ] ] || 0 );
				return '<button type="button" class="codm-chip' + ( state.status === d[ 0 ] ? ' is-active' : '' ) + '" data-status="' + d[ 0 ] + '">' + esc( d[ 1 ] ) + ' <b>' + n + '</b></button>';
			} ).join( '' );
		}
	}

	/* ------------------------------------------------------------ */
	/* Table                                                         */
	/* ------------------------------------------------------------ */

	function actionButtons( o, compact ) {
		var list = NEXT[ stepOf( o.status ) ] || [];
		return list.map( function ( a ) {
			return '<button type="button" class="codm-btn codm-btn--sm' + ( a[ 2 ] ? ' codm-btn--' + a[ 2 ] : '' ) + '" data-act="' + esc( a[ 0 ] ) + '" data-id="' + o.id + '">' + esc( a[ 1 ] ) + '</button>';
		} ).join( compact ? '' : ' ' );
	}

	function contactButtons( o ) {
		if ( ! o.customer.phone ) { return '<span class="codm-muted">' + esc( __( 'No phone', 'cod-manager-pro' ) ) + '</span>'; }
		return '<a class="codm-btn codm-btn--sm codm-btn--call" href="tel:+' + esc( o.customer.phone ) + '" aria-label="' + esc( sprintf( /* translators: %s: customer name. */ __( 'Call %s', 'cod-manager-pro' ), o.customer.name ) ) + '"><span class="dashicons dashicons-phone"></span>' + esc( __( 'Call', 'cod-manager-pro' ) ) + '</a>' +
			'<a class="codm-btn codm-btn--sm codm-btn--wa" href="' + esc( o.wa ) + '" target="_blank" rel="noopener" aria-label="' + esc( sprintf( /* translators: %s: customer name. */ __( 'WhatsApp %s', 'cod-manager-pro' ), o.customer.name ) ) + '"><span class="dashicons dashicons-whatsapp"></span>WhatsApp</a>';
	}

	/* Order source chip (Facebook Ads, Instagram, Google, Direct...). */
	function srcChip( o ) {
		if ( ! o.source ) { return ''; }
		var k = o.source.toLowerCase().split( ' ' )[ 0 ].replace( /[^a-z]/g, '' );
		return '<span class="codm-src codm-src--' + esc( k ) + ( / ads$/i.test( o.source ) ? ' codm-src--paid' : '' ) + '"' + ( o.campaign ? ' title="' + esc( sprintf( /* translators: %s: ad campaign name. */ __( 'Campaign: %s', 'cod-manager-pro' ), o.campaign ) ) + '"' : '' ) + '>' + esc( o.source ) + '</span>';
	}

	function companyOptions( selectedId ) {
		var list = ( C.companies || [] ).filter( function ( c ) { return c.active || c.id === selectedId; } );
		return list.map( function ( c ) {
			return '<option value="' + c.id + '"' + ( c.id === selectedId ? ' selected' : '' ) + '>' + esc( c.name ) + '</option>';
		} ).join( '' ) + '<option value="0"' + ( ! selectedId ? ' selected' : '' ) + '>' + esc( __( 'Own delivery', 'cod-manager-pro' ) ) + '</option>';
	}

	/* The big buttons on each card: what to do next at this step. */
	function cardActions( o ) {
		var id = o.id;
		switch ( stepOf( o.status ) ) {
			case 'call':
				return '<div class="codm-oc-do">' +
					'<button type="button" class="codm-btn codm-btn--primary codm-btn--go" data-act="confirmed" data-id="' + id + '"><span class="dashicons dashicons-yes"></span>' + esc( __( 'Confirm', 'cod-manager-pro' ) ) + '</button>' +
					'<button type="button" class="codm-btn codm-btn--warn" data-act="__no_answer" data-id="' + id + '">' + esc( __( 'No answer', 'cod-manager-pro' ) ) + ( o.call_attempts ? ' <b class="codm-oc-try">' + o.call_attempts + '</b>' : '' ) + '</button>' +
					'<button type="button" class="codm-btn codm-btn--ghost-danger" data-act="cancelled" data-id="' + id + '">' + esc( __( 'Cancel', 'cod-manager-pro' ) ) + '</button>' +
				'</div>';
			case 'ship':
				var cid = o.company_id || C.default_company || ( ( C.companies || [] ).filter( function ( c ) { return c.active; } )[ 0 ] || {} ).id || 0;
				return '<div class="codm-oc-ship">' +
					'<select class="codm-input codm-input--sm" data-ship-company="' + id + '" aria-label="' + esc( __( 'Shipping company', 'cod-manager-pro' ) ) + '">' + companyOptions( cid ) + '</select>' +
					'<input class="codm-input codm-input--sm" data-ship-tracking="' + id + '" value="' + esc( o.tracking ) + '" placeholder="' + esc( __( 'Tracking (optional)', 'cod-manager-pro' ) ) + '" aria-label="' + esc( __( 'Tracking number', 'cod-manager-pro' ) ) + '">' +
				'</div>' +
				'<div class="codm-oc-do">' +
					'<button type="button" class="codm-btn codm-btn--primary codm-btn--go" data-ship="' + id + '"><span class="dashicons dashicons-car"></span>' + esc( __( 'Ship', 'cod-manager-pro' ) ) + '</button>' +
					'<a class="codm-btn" href="' + esc( C.urls.labels + '&ids=' + id ) + '" target="_blank" rel="noopener"><span class="dashicons dashicons-printer"></span>' + esc( __( 'Label', 'cod-manager-pro' ) ) + '</a>' +
					'<button type="button" class="codm-btn codm-btn--ghost-danger" data-act="cancelled" data-id="' + id + '">' + esc( __( 'Cancel', 'cod-manager-pro' ) ) + '</button>' +
				'</div>';
			case 'courier':
				return '<div class="codm-oc-meta">' + esc( o.company || __( 'Own delivery', 'cod-manager-pro' ) ) + ( o.tracking ? ' · <span class="codm-mono">' + esc( o.tracking ) + '</span>' : '' ) + ( o.shipped_days !== undefined ? '' : '' ) + '</div>' +
				'<div class="codm-oc-do">' +
					'<button type="button" class="codm-btn codm-btn--primary codm-btn--go codm-btn--ok" data-act="delivered" data-id="' + id + '"><span class="dashicons dashicons-yes-alt"></span>' + esc( __( 'Delivered', 'cod-manager-pro' ) ) + '</button>' +
					'<button type="button" class="codm-btn codm-btn--ghost-danger" data-act="returned" data-id="' + id + '"><span class="dashicons dashicons-undo"></span>' + esc( __( 'Returned', 'cod-manager-pro' ) ) + '</button>' +
				'</div>';
			default:
				return '<div class="codm-oc-meta">' + U.badge( o.status ) + ( o.company ? ' <span class="codm-muted">' + esc( o.company ) + '</span>' : '' ) + '</div>' +
					'<div class="codm-oc-do"><button type="button" class="codm-btn" data-open="' + id + '">' + esc( __( 'Details', 'cod-manager-pro' ) ) + '</button></div>';
		}
	}

	function rowHtml( o ) {
		var items = o.items.map( function ( i ) {
			return '<span class="codm-item"><b>' + i.qty + '×</b> <bdi>' + esc( i.name ) + '</bdi></span>';
		} ).join( '' );
		var flags = U.risk( o.customer ) + ( o.duplicate ? '<span class="codm-flag codm-flag--dup" title="' + esc( __( 'This phone has another order in progress', 'cod-manager-pro' ) ) + '">' + esc( __( 'Duplicate', 'cod-manager-pro' ) ) + '</span>' : '' ) +
			( o.customer.phone && ! o.customer.phone_ok ? '<span class="codm-flag codm-flag--bad" title="' + esc( __( 'This number does not have 10 digits. Check it before shipping.', 'cod-manager-pro' ) ) + '">' + esc( __( 'Check number', 'cod-manager-pro' ) ) + '</span>' : '' ) +
			( o.call_attempts && stepOf( o.status ) === 'call' ? '<span class="codm-flag codm-flag--try">' + esc( sprintf( /* translators: %s: number of unanswered call attempts. */ __( 'No answer ×%s', 'cod-manager-pro' ), o.call_attempts ) ) + '</span>' : '' );

		return '<article class="codm-oc codm-oc--' + stepOf( o.status ) + ( selected.has( o.id ) ? ' is-selected' : '' ) + '" data-id="' + o.id + '">' +
			'<label class="codm-oc-check"><input type="checkbox" class="codm-row-check" value="' + o.id + '"' + ( selected.has( o.id ) ? ' checked' : '' ) + ' aria-label="' + esc( sprintf( /* translators: %s: order number. */ __( 'Select order %s', 'cod-manager-pro' ), o.number ) ) + '"></label>' +
			'<div class="codm-oc-main">' +
				'<div class="codm-oc-top"><button type="button" class="codm-order-link" data-open="' + o.id + '">#' + esc( o.number ) + '</button>' +
					'<span class="codm-muted">' + esc( o.date_short ) + '</span>' + srcChip( o ) + ( state.status === 'all' ? U.badge( o.status ) : '' ) + '</div>' +
				'<div class="codm-oc-cust"><strong><bdi>' + esc( o.customer.name || '—' ) + '</bdi></strong><span class="codm-oc-phone">' + esc( o.customer.phone_display ) + '</span></div>' +
				'<div class="codm-oc-addr"><b><bdi>' + esc( o.city || '—' ) + '</bdi></b>' + ( o.address ? ' · <bdi>' + esc( o.address ) + '</bdi>' : '' ) + '</div>' +
				'<div class="codm-oc-items">' + items + '</div>' +
				'<div class="codm-flags">' + flags + '</div>' +
			'</div>' +
			'<div class="codm-oc-side">' +
				'<div class="codm-oc-amount">' + U.money( o.total ) + '<small>' + esc( __( 'to collect', 'cod-manager-pro' ) ) + '</small></div>' +
				'<div class="codm-oc-contact">' + contactButtons( o ) + '</div>' +
			'</div>' +
			'<div class="codm-oc-actions">' + cardActions( o ) + '</div>' +
		'</article>';
	}

	var EMPTY = {
		open: __( 'Nothing to call right now. New orders appear here as soon as customers order.', 'cod-manager-pro' ),
		to_ship: __( 'No confirmed orders waiting to be shipped.', 'cod-manager-pro' ),
		with_courier: __( 'No parcels with the courier right now.', 'cod-manager-pro' ),
		done: __( 'No finished orders yet.', 'cod-manager-pro' ),
		all: __( 'No orders yet.', 'cod-manager-pro' )
	};

	function renderTable( data ) {
		rowsById = {};
		data.rows.forEach( function ( o ) { rowsById[ o.id ] = o; } );
		var box = $( '#codm-orders' );
		if ( ! data.rows.length ) {
			var hasFilters = state.search || state.city || state.source || state.company || state.from || state.to;
			box.innerHTML = '<div class="codm-empty">' + ( hasFilters
				? esc( __( 'No orders match these filters.', 'cod-manager-pro' ) ) + ' <button type="button" class="codm-link" id="codm-empty-reset">' + esc( __( 'Clear filters', 'cod-manager-pro' ) ) + '</button>'
				: esc( EMPTY[ state.status ] || __( 'No orders here.', 'cod-manager-pro' ) ) ) + '</div>';
			var r = $( '#codm-empty-reset' );
			if ( r ) { r.addEventListener( 'click', function () { $( '#codm-reset' ).click(); } ); }
			return;
		}
		var allChecked = data.rows.every( function ( o ) { return selected.has( o.id ); } );
		box.innerHTML = '<div class="codm-queue-head"><label><input type="checkbox" id="codm-check-all"' + ( allChecked ? ' checked' : '' ) + '> ' + esc( __( 'Select all', 'cod-manager-pro' ) ) + '</label></div>' +
			data.rows.map( rowHtml ).join( '' );
	}

	/* Ship from the card: save company + tracking, then mark shipped. */
	function shipOrder( id, btn ) {
		var o = rowsById[ id ] || {};
		var sel = $( '[data-ship-company="' + id + '"]' ), trk = $( '[data-ship-tracking="' + id + '"]' );
		var body = {};
		var cid = sel ? parseInt( sel.value, 10 ) || 0 : 0;
		if ( sel && cid !== ( o.company_id || 0 ) ) { body.shipping_company_id = cid; }
		if ( trk && trk.value.trim() !== ( o.tracking || '' ) ) { body.tracking_number = trk.value.trim(); }
		btn.disabled = true;
		var first = Object.keys( body ).length ? U.api( 'orders/' + id, { method: 'POST', body: body } ) : Promise.resolve();
		return first.then( function () { return runAction( id, 'shipped' ); } )
			.catch( function ( e ) { U.toast( e.message, 'error' ); } )
			.then( function () { btn.disabled = false; } );
	}

	function renderPager( data ) {
		var p = $( '#codm-pager' );
		if ( data.pages <= 1 ) { p.innerHTML = data.total ? '<span class="codm-muted">' + esc( sprintf(
			/* translators: %s: number of orders. */
			_n( '%s order', '%s orders', data.total, 'cod-manager-pro' ), data.total ) ) + '</span>' : ''; return; }
		p.innerHTML = '<span class="codm-muted">' + esc( sprintf(
			/* translators: 1: number of orders, 2: current page number, 3: total number of pages. */
			_n( '%1$s order · page %2$s of %3$s', '%1$s orders · page %2$s of %3$s', data.total, 'cod-manager-pro' ), data.total, data.page, data.pages ) ) + '</span>' +
			'<button type="button" class="codm-btn codm-btn--sm" data-page="' + ( data.page - 1 ) + '"' + ( data.page <= 1 ? ' disabled' : '' ) + '>' + esc( __( 'Previous', 'cod-manager-pro' ) ) + '</button>' +
			'<button type="button" class="codm-btn codm-btn--sm" data-page="' + ( data.page + 1 ) + '"' + ( data.page >= data.pages ? ' disabled' : '' ) + '>' + esc( __( 'Next', 'cod-manager-pro' ) ) + '</button>';
	}

	function updateBulk() {
		var bar = $( '#codm-bulk' );
		bar.hidden = selected.size === 0;
		$( '#codm-bulk-count' ).textContent = sprintf(
			/* translators: %s: number of selected orders. */
			__( '%s selected', 'cod-manager-pro' ), selected.size );
	}

	var loading = 0;
	function load( quiet ) {
		var ticket = ++loading;
		if ( ! quiet ) { $( '#codm-orders' ).classList.add( 'is-loading' ); }
		var q = {
			status: state.status === 'all' ? '' : state.status, search: state.search, city: state.city, source: state.source,
			company: state.company, from: state.from, to: state.to, page: state.page, per_page: state.per_page
		};
		return U.api( 'orders', { query: q } ).then( function ( data ) {
			if ( ticket !== loading ) { return; }
			renderTabs( data.counts || {} );
			renderTable( data );
			renderPager( data );
			updateBulk();
		} ).catch( function ( e ) {
			U.toast( e.message, 'error' );
		} ).then( function () {
			$( '#codm-orders' ).classList.remove( 'is-loading' );
		} );
	}

	/* ------------------------------------------------------------ */
	/* Actions                                                       */
	/* ------------------------------------------------------------ */

	function runAction( id, act, note ) {
		var req;
		if ( act === '__no_answer' ) {
			req = U.api( 'orders/' + id + '/no-answer', { method: 'POST', body: {} } );
		} else {
			if ( act === 'cancelled' && note === undefined ) {
				var reason = window.prompt( __( 'Reason for cancelling (optional):', 'cod-manager-pro' ), '' );
				if ( reason === null ) { return Promise.resolve( null ); }
				note = reason;
			}
			req = U.api( 'orders/' + id + '/status', { method: 'POST', body: { status: act, note: note || '' } } );
		}
		return req.then( function ( order ) {
			U.toast( act === '__no_answer'
				/* translators: 1: order number, 2: number of unanswered call attempts. */
				? sprintf( __( '#%1$s: no answer noted (%2$s)', 'cod-manager-pro' ), order.number, order.call_attempts || 1 )
				/* translators: 1: order number, 2: new status label. */
				: sprintf( __( '#%1$s → %2$s', 'cod-manager-pro' ), order.number, order.status_label ) );
			load( true );
			if ( drawerId === id ) { openDrawer( id ); }
			return order;
		} ).catch( function ( e ) { U.toast( e.message, 'error' ); } );
	}

	document.addEventListener( 'click', function ( e ) {
		var t;
		if ( ( t = e.target.closest( '#codm-status-tabs [data-status], #codm-subtabs [data-status]' ) ) ) {
			state.status = t.getAttribute( 'data-status' ); state.page = 1; load(); return;
		}
		if ( ( t = e.target.closest( '#codm-pager [data-page]' ) ) ) {
			state.page = parseInt( t.getAttribute( 'data-page' ), 10 ); load(); window.scrollTo( 0, 0 ); return;
		}
		if ( ( t = e.target.closest( '#codm-orders [data-ship]' ) ) ) {
			shipOrder( parseInt( t.getAttribute( 'data-ship' ), 10 ), t ); return;
		}
		if ( ( t = e.target.closest( '#codm-orders [data-act]' ) ) ) {
			t.disabled = true;
			runAction( parseInt( t.getAttribute( 'data-id' ), 10 ), t.getAttribute( 'data-act' ) ).then( function () { t.disabled = false; } );
			return;
		}
		if ( ( t = e.target.closest( '[data-open]' ) ) ) {
			openDrawer( parseInt( t.getAttribute( 'data-open' ), 10 ) ); return;
		}
		if ( e.target.closest( '#codm-drawer [data-close]' ) ) { closeDrawer(); return; }
		// Clicking an empty part of a row opens it.
		var row = e.target.closest( '#codm-orders .codm-oc' );
		if ( row && ! e.target.closest( 'a, button, input, select, label' ) ) {
			openDrawer( parseInt( row.getAttribute( 'data-id' ), 10 ) );
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		if ( e.target.classList.contains( 'codm-row-check' ) ) {
			var id = parseInt( e.target.value, 10 );
			if ( e.target.checked ) { selected.add( id ); } else { selected.delete( id ); }
			e.target.closest( '.codm-oc' ).classList.toggle( 'is-selected', e.target.checked );
			updateBulk();
		}
		if ( e.target.id === 'codm-check-all' ) {
			$$( '.codm-row-check' ).forEach( function ( cb ) {
				cb.checked = e.target.checked;
				var id = parseInt( cb.value, 10 );
				if ( e.target.checked ) { selected.add( id ); } else { selected.delete( id ); }
				cb.closest( '.codm-oc' ).classList.toggle( 'is-selected', e.target.checked );
			} );
			updateBulk();
		}
	} );

	function initBulk() {
		$( '#codm-bulk-apply' ).addEventListener( 'click', function () {
			var status = $( '#codm-bulk-status' ).value;
			if ( ! status ) { U.toast( __( 'Choose a status first.', 'cod-manager-pro' ), 'error' ); return; }
			if ( ! window.confirm( sprintf(
				/* translators: 1: number of selected orders, 2: target status label. */
				_n( 'Move %1$s order to "%2$s"? WooCommerce will be updated too.', 'Move %1$s orders to "%2$s"? WooCommerce will be updated too.', selected.size, 'cod-manager-pro' ),
				selected.size, U.statusLabels[ status ]
			) ) ) { return; }
			var btn = this; btn.disabled = true;
			U.api( 'orders/bulk', { method: 'POST', body: { ids: Array.from( selected ), status: status } } ).then( function ( res ) {
				U.toast( res.errors.length
					/* translators: 1: number of updated orders, 2: number of orders that failed to update. */
					? sprintf( _n( '%1$s order updated, %2$s failed', '%1$s orders updated, %2$s failed', res.updated, 'cod-manager-pro' ), res.updated, res.errors.length )
					/* translators: %s: number of updated orders. */
					: sprintf( _n( '%s order updated', '%s orders updated', res.updated, 'cod-manager-pro' ), res.updated ), res.errors.length ? 'error' : '' );
				selected.clear();
				load();
			} ).catch( function ( e ) { U.toast( e.message, 'error' ); } ).then( function () { btn.disabled = false; } );
		} );
		$( '#codm-bulk-labels' ).addEventListener( 'click', function () {
			window.open( C.urls.labels + '&ids=' + Array.from( selected ).join( ',' ), '_blank' );
		} );
		if ( $( '#codm-bulk-export' ) ) $( '#codm-bulk-export' ).addEventListener( 'click', function () {
			window.location = C.urls.export + '&ids=' + Array.from( selected ).join( ',' );
		} );
		$( '#codm-bulk-clear' ).addEventListener( 'click', function () {
			selected.clear(); $$( '.codm-row-check, #codm-check-all' ).forEach( function ( cb ) { cb.checked = false; } );
			$$( '#codm-orders .codm-oc.is-selected' ).forEach( function ( r ) { r.classList.remove( 'is-selected' ); } );
			updateBulk();
		} );
	}

	/* ------------------------------------------------------------ */
	/* Drawer                                                        */
	/* ------------------------------------------------------------ */

	var lastFocus = null;

	function field( label, name, value, type, attrs ) {
		var id = 'codm-f-' + name;
		var input = type === 'textarea'
			? '<textarea id="' + id + '" name="' + name + '" class="codm-input" rows="2">' + esc( value ) + '</textarea>'
			: '<input id="' + id + '" name="' + name + '" class="codm-input" type="' + ( type || 'text' ) + '" value="' + esc( value ) + '"' + ( attrs || '' ) + '>';
		return '<div class="codm-field"><label for="' + id + '">' + esc( label ) + '</label>' + input + '</div>';
	}

	function drawerHtml( o ) {
		var finance = C.can.finance && o.profit !== undefined;
		var companies = '<option value="0">' + esc( __( 'No company', 'cod-manager-pro' ) ) + '</option>' + ( C.companies || [] ).filter( function ( c ) { return c.active || c.id === o.company_id; } ).map( function ( c ) {
			return '<option value="' + c.id + '"' + ( c.id === o.company_id ? ' selected' : '' ) + '>' + esc( c.name ) + '</option>';
		} ).join( '' );
		var statusOpts = C.statuses.map( function ( s ) {
			return '<option value="' + esc( s.key ) + '"' + ( s.key === o.status ? ' selected' : '' ) + '>' + esc( s.label ) + '</option>';
		} ).join( '' );
		var templates = ( o.templates || [] ).map( function ( t ) {
			return '<a class="codm-chip" href="' + esc( t.url ) + '" target="_blank" rel="noopener">' + esc( t.label ) + '</a>';
		} ).join( '' );
		var items = o.items.map( function ( i ) {
			return '<tr><td>' + esc( i.name ) + ( i.sku ? ' <span class="codm-muted">' + esc( i.sku ) + '</span>' : '' ) + '</td><td class="num">' + i.qty + '</td><td class="num">' + U.money( i.price ) + '</td><td class="num">' + U.money( i.price * i.qty ) + '</td></tr>';
		} ).join( '' );
		var c = o.customer;
		var history = ( o.history || [] ).map( function ( h ) {
			var head = h.type === 'status' ? ( h.from ? esc( h.from ) + ' → ' : '' ) + '<b>' + esc( h.to ) + '</b>' : '<b>' + esc( { note: __( 'Note', 'cod-manager-pro' ), edit: __( 'Edited', 'cod-manager-pro' ), call: __( 'No reply', 'cod-manager-pro' ), created: __( 'Created', 'cod-manager-pro' ), 'import': __( 'Imported', 'cod-manager-pro' ), payout: __( 'Payout', 'cod-manager-pro' ), customer: __( 'Customer', 'cod-manager-pro' ), whatsapp: 'WhatsApp' }[ h.type ] || h.type ) + '</b>';
			return '<li class="codm-tl codm-tl--' + esc( h.type ) + '"><div>' + head + ( h.message ? '<p>' + esc( h.message ) + '</p>' : '' ) + '</div><span class="codm-muted">' + esc( h.user ) + ' · ' + esc( h.date ) + '</span></li>';
		} ).join( '' );

		var profit = '';
		if ( finance ) {
			var lines = '<tr><th>' + esc( __( 'COD amount', 'cod-manager-pro' ) ) + '</th><td class="num">' + U.money( o.total ) + '</td></tr>';
			if ( o.status !== 'returned' && o.status !== 'cancelled' ) {
				lines += '<tr><th>' + esc( __( 'Product cost', 'cod-manager-pro' ) ) + '</th><td class="num">− ' + U.money( o.product_cost ) + ( o.product_cost ? '' : ' <span class="codm-warn">' + esc( __( 'no cost price set', 'cod-manager-pro' ) ) + '</span>' ) + '</td></tr>';
			}
			lines += '<tr><th>' + esc( __( 'Shipping fee', 'cod-manager-pro' ) ) + '</th><td class="num">− ' + U.money( o.shipping_cost ) + '</td></tr>';
			if ( o.status === 'returned' ) { lines += '<tr><th>' + esc( __( 'Return fee', 'cod-manager-pro' ) ) + '</th><td class="num">− ' + U.money( o.return_cost ) + '</td></tr>'; }
			profit = '<section class="codm-dsec"><h3>' + esc( o.profit_final ? __( 'Profit', 'cod-manager-pro' ) : __( 'Expected profit if delivered', 'cod-manager-pro' ) ) + '</h3><table class="codm-pl">' + lines +
				'<tr class="codm-pl-total"><th>' + esc( o.profit_final ? __( 'Profit', 'cod-manager-pro' ) : __( 'Expected', 'cod-manager-pro' ) ) + '</th><td class="num ' + ( o.profit < 0 ? 'codm-neg' : 'codm-pos' ) + '">' + U.money( o.profit ) + '</td></tr></table>' +
				( o.payout_id ? '<p class="codm-muted">' + esc( sprintf( /* translators: %s: courier payout ID. */ __( 'Settled in courier payout #%s', 'cod-manager-pro' ), o.payout_id ) ) + '</p>' : '' ) + '</section>';
		}

		return '<header class="codm-dhead">' +
				'<div><h2 id="codm-drawer-title">' + esc( sprintf( /* translators: %s: order number. */ __( 'Order #%s', 'cod-manager-pro' ), o.number ) ) + '</h2><span class="codm-muted">' + esc( o.date ) + ' · ' + esc( o.payment_method ) + '</span>' + ( o.source ? '<div class="codm-dsrc">' + esc( __( 'Source:', 'cod-manager-pro' ) ) + ' ' + srcChip( o ) + ( o.campaign ? ' <span class="codm-muted">' + esc( sprintf( /* translators: %s: ad campaign name. */ __( 'Campaign: %s', 'cod-manager-pro' ), o.campaign ) ) + '</span>' : '' ) + '</div>' : '' ) + '</div>' +
				'<div class="codm-dhead-right">' + U.badge( o.status ) + ( o.wc_edit ? '<a class="codm-btn codm-btn--sm" href="' + esc( o.wc_edit ) + '">' + esc( __( 'Open in WooCommerce', 'cod-manager-pro' ) ) + '</a>' : '' ) +
				'<button type="button" class="codm-icon-btn" data-close aria-label="' + esc( __( 'Close', 'cod-manager-pro' ) ) + '"><span class="dashicons dashicons-no-alt"></span></button></div>' +
			'</header>' +
			'<div class="codm-dbody">' +
			'<section class="codm-dsec codm-dsec--accent"><div class="codm-next">' + actionButtons( o ) + '</div>' +
				'<div class="codm-status-form"><select id="codm-d-status" class="codm-input" aria-label="' + esc( __( 'Status', 'cod-manager-pro' ) ) + '">' + statusOpts + '</select>' +
				'<input id="codm-d-status-note" class="codm-input" placeholder="' + esc( __( 'Note (optional)', 'cod-manager-pro' ) ) + '" aria-label="' + esc( __( 'Status note', 'cod-manager-pro' ) ) + '">' +
				'<button type="button" class="codm-btn" id="codm-d-status-save">' + esc( __( 'Update status', 'cod-manager-pro' ) ) + '</button></div></section>' +

			'<section class="codm-dsec"><h3>' + esc( __( 'Customer', 'cod-manager-pro' ) ) + '</h3>' +
				'<div class="codm-flags">' + U.risk( c ) + ( o.duplicate ? '<span class="codm-flag codm-flag--dup">' + esc( __( 'Another order in progress', 'cod-manager-pro' ) ) + '</span>' : '' ) + '</div>' +
				'<p class="codm-muted">' + esc( sprintf(
					/* translators: 1: number of orders, 2: delivered orders, 3: refused (returned) orders, 4: cancelled orders. */
					__( '%1$s orders · %2$s delivered · %3$s refused · %4$s cancelled', 'cod-manager-pro' ), c.orders, c.delivered, c.returned, c.cancelled
				) ) + ( c.blacklisted && c.reason ? ' · ' + esc( sprintf( /* translators: %s: reason the customer was blacklisted. */ __( 'Reason: %s', 'cod-manager-pro' ), c.reason ) ) : '' ) + '</p>' +
				( c.phone ? '<div class="codm-wa-box"><div class="codm-wa-head"><span class="dashicons dashicons-whatsapp"></span><strong>' + esc( c.phone_display ) + '</strong>' +
				( c.phone_ok ? '' : '<span class="codm-flag codm-flag--bad">' + esc( __( 'Check number', 'cod-manager-pro' ) ) + '</span>' ) +
				'<button type="button" class="codm-btn codm-btn--sm codm-btn--warn" data-act="__no_answer" data-id="' + o.id + '">' + esc( __( 'No reply', 'cod-manager-pro' ) ) + '</button></div>' +
				'<div class="codm-chips">' + templates + '</div></div>' : '' ) +
				'<form id="codm-d-form" class="codm-dgrid">' +
					field( __( 'Name', 'cod-manager-pro' ), 'customer_name', c.name ) +
					field( __( 'Phone', 'cod-manager-pro' ), 'phone', c.phone ? '+' + c.phone : '', 'tel' ) +
					field( __( 'City', 'cod-manager-pro' ), 'city', o.city ) +
					field( __( 'Address', 'cod-manager-pro' ), 'address', o.address, 'textarea' ) +
					'<div class="codm-field"><label for="codm-f-company">' + esc( __( 'Shipping company', 'cod-manager-pro' ) ) + '</label><select id="codm-f-company" name="shipping_company_id" class="codm-input">' + companies + '</select></div>' +
					field( __( 'Tracking number', 'cod-manager-pro' ), 'tracking_number', o.tracking ) +
					field( __( 'Number of parcels', 'cod-manager-pro' ), 'parcels', o.parcels || 1, 'number', ' min="1" max="20"' ) +
					'<div class="codm-field codm-field--check"><input type="hidden" name="allow_open" value="0"><label><input type="checkbox" name="allow_open" value="1"' + ( o.allow_open ? ' checked' : '' ) + '> ' + esc( __( 'Customer may open the parcel', 'cod-manager-pro' ) ) + '</label></div>' +
					( finance ? field( sprintf( /* translators: %s: currency code. */ __( 'Shipping fee (%s)', 'cod-manager-pro' ), C.currency ), 'shipping_cost', o.shipping_cost, 'text', ' inputmode="decimal"' ) + field( sprintf( /* translators: %s: currency code. */ __( 'Return fee (%s)', 'cod-manager-pro' ), C.currency ), 'return_cost', o.return_cost, 'text', ' inputmode="decimal"' ) : '' ) +
					'<div class="codm-dgrid-full"><button type="submit" class="codm-btn codm-btn--primary">' + esc( __( 'Save changes', 'cod-manager-pro' ) ) + '</button> ' +
					'<button type="button" class="codm-link ' + ( c.blacklisted ? '' : 'codm-danger' ) + '" id="codm-d-blacklist" data-customer="' + c.id + '" data-on="' + ( c.blacklisted ? 1 : 0 ) + '">' + esc( c.blacklisted ? __( 'Remove from blacklist', 'cod-manager-pro' ) : __( 'Blacklist this customer', 'cod-manager-pro' ) ) + '</button></div>' +
				'</form></section>' +

			'<section class="codm-dsec"><h3>' + esc( __( 'Products', 'cod-manager-pro' ) ) + '</h3><table class="codm-table codm-table--compact"><thead><tr><th>' + esc( __( 'Product', 'cod-manager-pro' ) ) + '</th><th class="num">' + esc( __( 'Qty', 'cod-manager-pro' ) ) + '</th><th class="num">' + esc( __( 'Price', 'cod-manager-pro' ) ) + '</th><th class="num">' + esc( __( 'Total', 'cod-manager-pro' ) ) + '</th></tr></thead><tbody>' + items + '</tbody>' +
				'<tfoot><tr><td colspan="3">' + esc( __( 'Delivery fee', 'cod-manager-pro' ) ) + '</td><td class="num">' + U.money( o.delivery_fee ) + '</td></tr>' +
				( o.discount ? '<tr><td colspan="3">' + esc( __( 'Discount', 'cod-manager-pro' ) ) + '</td><td class="num">− ' + U.money( o.discount ) + '</td></tr>' : '' ) +
				'<tr class="codm-pl-total"><td colspan="3">' + esc( __( 'To collect (COD)', 'cod-manager-pro' ) ) + '</td><td class="num">' + U.money( o.total ) + '</td></tr></tfoot></table>' +
				'<p class="codm-muted">' + esc( __( 'To change products or prices, open the order in WooCommerce.', 'cod-manager-pro' ) ) + '</p></section>' +

			profit +

			'<section class="codm-dsec"><h3>' + esc( __( 'Notes & history', 'cod-manager-pro' ) ) + '</h3>' +
				'<div class="codm-note-form"><textarea id="codm-d-note" class="codm-input" rows="2" placeholder="' + esc( __( 'Add a note for the team…', 'cod-manager-pro' ) ) + '" aria-label="' + esc( __( 'Note', 'cod-manager-pro' ) ) + '"></textarea><button type="button" class="codm-btn" id="codm-d-note-save">' + esc( __( 'Add note', 'cod-manager-pro' ) ) + '</button></div>' +
				'<ul class="codm-timeline">' + history + '</ul></section>' +
			'</div>';
	}

	function openDrawer( id ) {
		if ( ! id ) { return; }
		var drawer = $( '#codm-drawer' );
		var body = $( '#codm-drawer-body' );
		if ( drawerId !== id ) { body.innerHTML = '<div class="codm-dloading">' + esc( __( 'Loading order…', 'cod-manager-pro' ) ) + '</div>'; }
		if ( drawer.hidden ) { lastFocus = document.activeElement; }
		drawerId = id;
		drawer.hidden = false;
		document.body.classList.add( 'codm-drawer-open' );
		U.api( 'orders/' + id ).then( function ( o ) {
			if ( drawerId !== id ) { return; }
			body.innerHTML = drawerHtml( o );
			bindDrawer( o );
			$( '.codm-drawer-panel' ).focus();
		} ).catch( function ( e ) {
			body.innerHTML = '<div class="codm-dloading">' + esc( e.message ) + ' <button type="button" class="codm-link" data-close>' + esc( __( 'Close', 'cod-manager-pro' ) ) + '</button></div>';
		} );
	}

	function closeDrawer() {
		$( '#codm-drawer' ).hidden = true;
		document.body.classList.remove( 'codm-drawer-open' );
		drawerId = null;
		if ( lastFocus && lastFocus.focus ) { lastFocus.focus(); }
	}

	function bindDrawer( o ) {
		var body = $( '#codm-drawer-body' );
		// Picking another shipping company fills in that company's fees.
		var companySel = $( '#codm-f-company' );
		if ( companySel ) {
			companySel.addEventListener( 'change', function () {
				var id = parseInt( companySel.value, 10 ) || 0;
				var c = ( C.companies || [] ).filter( function ( x ) { return x.id === id; } )[ 0 ];
				var ship = body.querySelector( '[name="shipping_cost"]' ), ret = body.querySelector( '[name="return_cost"]' );
				if ( ship ) { ship.value = c ? c.cost : 0; }
				if ( ret && o.status === 'returned' ) { ret.value = c ? c.return : 0; }
			} );
		}
		body.querySelectorAll( '[data-act]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				b.disabled = true;
				runAction( o.id, b.getAttribute( 'data-act' ) ).then( function () { b.disabled = false; } );
			} );
		} );
		$( '#codm-d-status-save' ).addEventListener( 'click', function () {
			var st = $( '#codm-d-status' ).value, note = $( '#codm-d-status-note' ).value;
			if ( st === o.status && ! note ) { U.toast( __( 'Choose a different status or write a note.', 'cod-manager-pro' ), 'error' ); return; }
			runAction( o.id, st, note );
		} );
		$( '#codm-d-form' ).addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var data = {};
			new FormData( e.target ).forEach( function ( v, k ) { data[ k ] = v; } );
			var btn = e.target.querySelector( '[type=submit]' ); btn.disabled = true;
			U.api( 'orders/' + o.id, { method: 'POST', body: data } ).then( function () {
				U.toast( sprintf(
					/* translators: %s: order number. */
					__( 'Order #%s saved. WooCommerce updated.', 'cod-manager-pro' ), o.number ) );
				openDrawer( o.id ); load( true );
			} ).catch( function ( err ) { U.toast( err.message, 'error' ); btn.disabled = false; } );
		} );
		$( '#codm-d-note-save' ).addEventListener( 'click', function () {
			var note = $( '#codm-d-note' ).value.trim();
			if ( ! note ) { U.toast( __( 'Write a note first.', 'cod-manager-pro' ), 'error' ); return; }
			U.api( 'orders/' + o.id + '/notes', { method: 'POST', body: { note: note } } ).then( function () {
				U.toast( __( 'Note added', 'cod-manager-pro' ) ); openDrawer( o.id );
			} ).catch( function ( err ) { U.toast( err.message, 'error' ); } );
		} );
		$( '#codm-d-blacklist' ).addEventListener( 'click', function () {
			var on = this.getAttribute( 'data-on' ) === '1';
			var reason = '';
			if ( ! on ) {
				reason = window.prompt( __( 'Why blacklist this customer? (e.g. refused 3 deliveries)', 'cod-manager-pro' ), '' );
				if ( reason === null ) { return; }
			}
			U.api( 'customers/' + this.getAttribute( 'data-customer' ) + '/blacklist', { method: 'POST', body: { blacklisted: ! on, reason: reason, order_id: o.id } } ).then( function () {
				U.toast( on ? __( 'Customer removed from blacklist', 'cod-manager-pro' ) : __( 'Customer blacklisted', 'cod-manager-pro' ) );
				openDrawer( o.id ); load( true );
			} ).catch( function ( err ) { U.toast( err.message, 'error' ); } );
		} );
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && drawerId ) { closeDrawer(); }
	} );

	/* ------------------------------------------------------------ */
	/* Start                                                         */
	/* ------------------------------------------------------------ */

	initFilters();
	initBulk();
	load();
	if ( params.get( 'open' ) ) { openDrawer( parseInt( params.get( 'open' ), 10 ) ); }
}() );

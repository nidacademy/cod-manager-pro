/* COD Manager Pro – tiny dependency-free SVG charts */
( function () {
	'use strict';

	var __ = wp.i18n.__;

	var NS = 'http://www.w3.org/2000/svg';

	function el( name, attrs, parent ) {
		var n = document.createElementNS( NS, name );
		Object.keys( attrs || {} ).forEach( function ( k ) { n.setAttribute( k, attrs[ k ] ); } );
		if ( parent ) { parent.appendChild( n ); }
		return n;
	}

	function niceMax( v ) {
		if ( v <= 0 ) { return 1; }
		var p = Math.pow( 10, Math.floor( Math.log10( v ) ) );
		var f = v / p;
		var nice = f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10;
		return nice * p;
	}

	function short( v ) {
		var a = Math.abs( v );
		if ( a >= 1e6 ) { return ( v / 1e6 ).toFixed( 1 ).replace( '.0', '' ) + 'M'; }
		if ( a >= 1e3 ) { return ( v / 1e3 ).toFixed( 1 ).replace( '.0', '' ) + 'k'; }
		return String( Math.round( v ) );
	}

	/**
	 * Bars (left axis, counts) + lines (right axis, money). Hover shows a tooltip per bucket.
	 * opts: { labels:[], bars:{name, values, color}, lines:[{name, values, color}], formatLine: fn, formatBar: fn }
	 */
	function combo( container, opts ) {
		container.innerHTML = '';
		var W = Math.max( 320, container.clientWidth || 640 ), H = 280;
		var pad = { t: 16, r: 56, b: 34, l: 40 };
		var iw = W - pad.l - pad.r, ih = H - pad.t - pad.b;
		var n = opts.labels.length;
		if ( ! n ) { container.innerHTML = '<p class="codm-muted">' + CODMUI.esc( __( 'No data for this period.', 'cod-manager-pro' ) ) + '</p>'; return; }

		var barMax = niceMax( Math.max.apply( null, opts.bars.values.concat( [ 0 ] ) ) );
		var lineVals = [];
		opts.lines.forEach( function ( l ) { lineVals = lineVals.concat( l.values ); } );
		var lMax = Math.max.apply( null, lineVals.concat( [ 0 ] ) );
		var lMin = Math.min.apply( null, lineVals.concat( [ 0 ] ) );
		var span = niceMax( Math.max( lMax, 0 ) - Math.min( lMin, 0 ) || 1 );
		var lTop = lMin < 0 ? niceMax( lMax ) : span;
		var lBottom = lMin < 0 ? -niceMax( -lMin ) : 0;

		var svg = el( 'svg', { viewBox: '0 0 ' + W + ' ' + H, width: '100%', height: H, role: 'img', 'aria-label': opts.title || __( 'Chart', 'cod-manager-pro' ), 'class': 'codm-svg' }, container );
		var g = el( 'g', { transform: 'translate(' + pad.l + ',' + pad.t + ')' }, svg );
		var yLine = function ( v ) { return ih - ( ( v - lBottom ) / ( lTop - lBottom ) ) * ih; };

		// Grid + axes.
		for ( var i = 0; i <= 4; i++ ) {
			var y = ( ih / 4 ) * i;
			el( 'line', { x1: 0, x2: iw, y1: y, y2: y, 'class': 'codm-gridline' }, g );
			el( 'text', { x: -8, y: y + 4, 'text-anchor': 'end', 'class': 'codm-axis' }, g ).textContent = short( barMax - ( barMax / 4 ) * i );
			el( 'text', { x: iw + 8, y: y + 4, 'class': 'codm-axis' }, g ).textContent = short( lTop - ( ( lTop - lBottom ) / 4 ) * i );
		}
		if ( lBottom < 0 ) {
			el( 'line', { x1: 0, x2: iw, y1: yLine( 0 ), y2: yLine( 0 ), 'class': 'codm-zero' }, g );
		}

		var step = iw / n;
		var bw = Math.max( 2, Math.min( 28, step * 0.55 ) );
		opts.bars.values.forEach( function ( v, idx ) {
			var h = ( v / barMax ) * ih;
			el( 'rect', { x: idx * step + ( step - bw ) / 2, y: ih - h, width: bw, height: Math.max( 0, h ), rx: 2, fill: opts.bars.color, 'class': 'codm-bar' }, g );
		} );

		opts.lines.forEach( function ( line ) {
			var d = line.values.map( function ( v, idx ) {
				return ( idx ? 'L' : 'M' ) + ( idx * step + step / 2 ).toFixed( 1 ) + ',' + yLine( v ).toFixed( 1 );
			} ).join( ' ' );
			el( 'path', { d: d, fill: 'none', stroke: line.color, 'stroke-width': 2.25, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, g );
			if ( n <= 45 ) {
				line.values.forEach( function ( v, idx ) {
					el( 'circle', { cx: idx * step + step / 2, cy: yLine( v ), r: 2.5, fill: line.color }, g );
				} );
			}
		} );

		// X labels (thinned).
		var every = Math.ceil( n / Math.max( 1, Math.floor( iw / 64 ) ) );
		opts.labels.forEach( function ( lab, idx ) {
			if ( idx % every === 0 ) {
				el( 'text', { x: idx * step + step / 2, y: ih + 20, 'text-anchor': 'middle', 'class': 'codm-axis' }, g ).textContent = lab;
			}
		} );

		// Hover layer + tooltip.
		var hover = el( 'rect', { x: 0, y: 0, width: step, height: ih, 'class': 'codm-hover', visibility: 'hidden' }, g );
		var tip = document.createElement( 'div' );
		tip.className = 'codm-tip';
		tip.hidden = true;
		container.style.position = 'relative';
		container.appendChild( tip );

		svg.addEventListener( 'mousemove', function ( e ) {
			var rect = svg.getBoundingClientRect();
			var x = ( e.clientX - rect.left ) * ( W / rect.width ) - pad.l;
			var idx = Math.floor( x / step );
			if ( idx < 0 || idx >= n ) { hover.setAttribute( 'visibility', 'hidden' ); tip.hidden = true; return; }
			hover.setAttribute( 'x', idx * step );
			hover.setAttribute( 'visibility', 'visible' );
			var html = '<strong>' + CODMUI.esc( opts.labels[ idx ] ) + '</strong>';
			html += '<span><i style="background:' + opts.bars.color + '"></i>' + CODMUI.esc( opts.bars.name ) + ': ' + CODMUI.esc( opts.formatBar ? opts.formatBar( opts.bars.values[ idx ] ) : opts.bars.values[ idx ] ) + '</span>';
			opts.lines.forEach( function ( l ) {
				html += '<span><i style="background:' + l.color + '"></i>' + CODMUI.esc( l.name ) + ': ' + CODMUI.esc( opts.formatLine ? opts.formatLine( l.values[ idx ] ) : l.values[ idx ] ) + '</span>';
			} );
			tip.innerHTML = html;
			tip.hidden = false;
			var left = ( ( idx * step + step / 2 + pad.l ) / W ) * rect.width;
			tip.style.left = Math.min( Math.max( left, 70 ), rect.width - 70 ) + 'px';
		} );
		svg.addEventListener( 'mouseleave', function () { hover.setAttribute( 'visibility', 'hidden' ); tip.hidden = true; } );

		// Legend.
		var legend = document.createElement( 'div' );
		legend.className = 'codm-legend';
		legend.innerHTML = [ opts.bars ].concat( opts.lines ).map( function ( s ) {
			return '<span><i style="background:' + s.color + '"></i>' + CODMUI.esc( s.name ) + '</span>';
		} ).join( '' );
		container.appendChild( legend );
	}

	/** Donut with legend. items: [{label, value, color}] */
	function donut( container, items, centerLabel ) {
		container.innerHTML = '';
		var total = items.reduce( function ( s, i ) { return s + i.value; }, 0 );
		var size = 180, r = 70, sw = 26, c = 2 * Math.PI * r;
		var wrap = document.createElement( 'div' );
		wrap.className = 'codm-donut';
		container.appendChild( wrap );
		var svg = el( 'svg', { viewBox: '0 0 ' + size + ' ' + size, width: size, height: size, role: 'img', 'aria-label': centerLabel || __( 'Distribution', 'cod-manager-pro' ) }, wrap );
		var g = el( 'g', { transform: 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')' }, svg );
		el( 'circle', { cx: size / 2, cy: size / 2, r: r, fill: 'none', 'class': 'codm-donut-track', 'stroke-width': sw }, g );
		var offset = 0;
		items.forEach( function ( it ) {
			if ( ! it.value ) { return; }
			var len = ( it.value / total ) * c;
			var seg = el( 'circle', {
				cx: size / 2, cy: size / 2, r: r, fill: 'none', stroke: it.color, 'stroke-width': sw,
				'stroke-dasharray': len + ' ' + ( c - len ), 'stroke-dashoffset': -offset
			}, g );
			el( 'title', {}, seg ).textContent = it.label + ': ' + it.value;
			offset += len;
		} );
		el( 'text', { x: size / 2, y: size / 2 - 2, 'text-anchor': 'middle', 'class': 'codm-donut-total' }, svg ).textContent = total;
		el( 'text', { x: size / 2, y: size / 2 + 16, 'text-anchor': 'middle', 'class': 'codm-axis' }, svg ).textContent = centerLabel || '';

		var ul = document.createElement( 'ul' );
		ul.className = 'codm-donut-legend';
		ul.innerHTML = items.filter( function ( i ) { return i.value > 0; } ).map( function ( i ) {
			return '<li><i style="background:' + i.color + '"></i><span>' + CODMUI.esc( i.label ) + '</span><b>' + i.value + '</b><em>' + ( total ? Math.round( 100 * i.value / total ) : 0 ) + '%</em></li>';
		} ).join( '' ) || '<li class="codm-muted">' + CODMUI.esc( __( 'No orders in this period.', 'cod-manager-pro' ) ) + '</li>';
		wrap.appendChild( ul );
	}

	window.CODMCharts = { combo: combo, donut: donut };
}() );

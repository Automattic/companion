( function () {
	try {
		if ( ! window.frameElement || window.frameElement.name !== 'rehearsal' ) {
			return;
		}
	} catch ( e ) {
		return;
	}
	var box = ( window.__rehearsal = { errors: [] } );
	var leaving = false;

	// Marks this frame's same-origin requests, so a rehearsal's role applies to them and not to other tabs'.
	// Same-origin only: a custom header on a cross-origin request would need a CORS preflight.
	function sameOrigin( url ) {
		try {
			return new URL( url, location.href ).origin === location.origin;
		} catch ( e ) {
			return false;
		}
	}
	if ( window.fetch ) {
		var nativeFetch = window.fetch;
		window.fetch = function ( input, init ) {
			var isRequest = window.Request && input instanceof window.Request;
			if ( sameOrigin( isRequest ? input.url : String( input ) ) ) {
				init = Object.assign( {}, init );
				var headers = new Headers( init.headers || ( isRequest ? input.headers : undefined ) );
				headers.set( 'X-Rehearsal-Frame', '1' );
				init.headers = headers;
			}
			return nativeFetch.call( this, input, init );
		};
	}
	var nativeOpen = XMLHttpRequest.prototype.open;
	XMLHttpRequest.prototype.open = function ( method, url ) {
		nativeOpen.apply( this, arguments );
		if ( sameOrigin( url ) ) {
			this.setRequestHeader( 'X-Rehearsal-Frame', '1' );
		}
	};

	// Page-load metrics. Taken once, 1.5 s after the load event, so later runner clicks don't count.
	try {
		performance.setResourceTimingBufferSize( 2000 );
	} catch ( e ) {}
	var vitals = { lcp: null, cls: 0 };
	try {
		new PerformanceObserver( function ( list ) {
			var entries = list.getEntries();
			vitals.lcp = entries[ entries.length - 1 ].startTime;
		} ).observe( { type: 'largest-contentful-paint', buffered: true } );
		new PerformanceObserver( function ( list ) {
			list.getEntries().forEach( function ( e ) {
				if ( ! e.hadRecentInput ) {
					vitals.cls += e.value;
				}
			} );
		} ).observe( { type: 'layout-shift', buffered: true } );
	} catch ( e ) {}
	var frozen = null;
	box.metrics = function () {
		if ( frozen ) {
			return frozen;
		}
		var nav = performance.getEntriesByType( 'navigation' )[ 0 ];
		var fcp = performance.getEntriesByName( 'first-contentful-paint' )[ 0 ];
		// encodedBodySize is the compressed size even when cached; other sites hide it unless they opt in.
		var m = { url: location.pathname + location.search, requests: 1, weight: nav ? nav.encodedBodySize : 0, js: 0, downloaded: nav ? nav.transferSize : 0, unmeasured: 0 };
		performance.getEntriesByType( 'resource' ).forEach( function ( r ) {
			m.requests++;
			m.weight += r.encodedBodySize || 0;
			m.downloaded += r.transferSize || 0;
			if ( r.initiatorType === 'script' || /\.m?js(\?|$)/.test( r.name ) ) {
				m.js += r.encodedBodySize || 0;
			}
			if ( ! r.encodedBodySize && ! r.transferSize ) {
				m.unmeasured++;
			}
		} );
		m.ttfb = nav ? Math.round( nav.responseStart ) : null;
		m.fcp = fcp ? Math.round( fcp.startTime ) : null;
		m.lcp = vitals.lcp === null ? null : Math.round( vitals.lcp );
		m.cls = Math.round( vitals.cls * 1000 ) / 1000;
		m.load = nav && nav.loadEventEnd ? Math.round( nav.loadEventEnd ) : null;
		m.final = !! ( m.load && performance.now() > m.load + 1500 );
		if ( m.final ) {
			frozen = m;
		}
		return m;
	};
	// Navigating away aborts the old page's in-flight requests; those rejections aren't real page errors.
	window.addEventListener( 'pagehide', function () {
		leaving = true;
	} );
	window.addEventListener( 'beforeunload', function () {
		leaving = true;
	} );
	// Top stack frames, minus this file, so a report says which script logged the error.
	var where = function ( stack ) {
		return String( stack || '' )
			.split( '\n' )
			.map( function ( l ) {
				return l.trim();
			} )
			.filter( function ( l ) {
				return /^at |@/.test( l ) && l.indexOf( 'rehearsalRecorder' ) === -1;
			} )
			.slice( 0, 3 )
			.join( '\n' );
	};
	var push = function ( msg, source ) {
		if ( ! leaving && box.errors.length < 50 ) {
			box.errors.push( { msg: String( msg ).slice( 0, 4000 ), source: String( source || '' ).slice( 0, 1000 ) } );
		}
	};
	var original = console.error;
	console.error = function rehearsalRecorder() {
		var stack = '';
		[].forEach.call( arguments, function ( a ) {
			if ( ! stack && a && a.stack ) {
				stack = a.stack;
			}
		} );
		push(
			[].map
				.call( arguments, function ( a ) {
					return a && a.message ? a.message : String( a );
				} )
				.join( ' ' ),
			where( stack || new Error().stack )
		);
		return original.apply( console, arguments );
	};
	window.addEventListener( 'error', function ( e ) {
		if ( e.message ) {
			push( e.message, e.error && e.error.stack ? where( e.error.stack ) : 'at ' + e.filename + ':' + e.lineno + ':' + e.colno );
		}
	} );
	window.addEventListener( 'unhandledrejection', function ( e ) {
		var r = e.reason;
		push( 'Unhandled rejection: ' + ( r && r.message ? r.message : r ), r && r.stack ? where( r.stack ) : '' );
	} );
} )();

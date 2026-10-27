<?php
/**
 * Rehearsal: Translates a rehearsal into a standalone Playwright test.
 *
 * @package companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translates a rehearsal into a standalone Playwright test.
 */
final class Companion_Rehearsal_Playwright {
	/**
	 * A JS/TS string expression; {{run}} becomes the test's `run` constant.
	 *
	 * @param string $text Text from a step.
	 * @return string
	 */
	private static function pw_str( $text ) {
		$parts  = explode( '{{run}}', (string) $text );
		$pieces = array();
		foreach ( $parts as $i => $part ) {
			if ( '' !== $part ) {
				$pieces[] = wp_json_encode( $part, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
			if ( $i < count( $parts ) - 1 ) {
				$pieces[] = 'run';
			}
		}
		return $pieces ? implode( ' + ', $pieces ) : '""';
	}

	/**
	 * A Playwright locator for a rehearsal selector. `a >> b` chains, and an iframe part becomes frameLocator().
	 *
	 * @param string $selector  Rehearsal selector.
	 * @param bool   $first_visible Narrow to the first visible match, which is what the browser runner acts on.
	 * @return string
	 */
	private static function pw_locator( $selector, $first_visible = true ) {
		$parts = preg_split( '/\s+>>\s+/', (string) $selector );
		$expr  = 'page';
		foreach ( $parts as $i => $part ) {
			if ( 0 === strpos( $part, 'text=' ) ) {
				// The browser runner's text= also matches aria-label (icon-only buttons); Playwright's doesn't.
				$aria = '[aria-label*="' . addcslashes( substr( $part, 5 ), '"\\' ) . '"]';
				$expr = "$expr.locator( " . self::pw_str( $part ) . " ).or( $expr.locator( " . self::pw_str( $aria ) . ' ) )';
				continue;
			}
			$method = $i < count( $parts ) - 1 && preg_match( '/^iframe\b/', $part ) ? 'frameLocator' : 'locator';
			$expr  .= ".$method( " . self::pw_str( $part ) . ' )';
		}
		return $first_visible ? $expr . '.filter( { visible: true } ).first()' : $expr;
	}

	/**
	 * Translate a rehearsal into a Playwright test file.
	 *
	 * @param array $payload Share payload.
	 * @return string TypeScript source.
	 */
	public static function to_playwright( array $payload ) {
		$def      = $payload['definition'];
		$timeout  = (int) ( $def['timeout'] ?? 10000 );
		$json     = static function ( $value ) {
			return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		};
		$source   = (string) wp_json_encode( $def );
		$uses_run = false !== strpos( $source, '{{run}}' );
		$uses_err = false !== strpos( $source, '"expectNoConsoleErrors"' );
		$body     = array();
		foreach ( array_values( $def['steps'] ) as $step ) {
			$t    = empty( $step['timeout'] ) ? '' : '{ timeout: ' . (int) $step['timeout'] . ' }';
			$t2   = $t ? ", $t" : '';
			$code = '';
			if ( isset( $step['goto'] ) ) {
				$code = 'await page.goto( ' . self::pw_str( $step['goto'] ) . ' );';
			} elseif ( isset( $step['waitFor'] ) ) {
				$code = 'await expect( ' . self::pw_locator( $step['waitFor'] ) . " ).toBeVisible( $t );";
			} elseif ( isset( $step['expectText'] ) ) {
				$scope = isset( $step['in'] ) ? self::pw_locator( $step['in'] ) : "page.locator( 'body' )";
				$code  = "await expect( $scope ).toContainText( " . self::pw_str( $step['expectText'] ) . "$t2 );";
			} elseif ( isset( $step['expectCount'] ) ) {
				$code = 'await expect.poll( () => ' . self::pw_locator( $step['expectCount'], false ) . ".count()$t2 ).toBeGreaterThanOrEqual( " . (int) ( $step['min'] ?? 1 ) . ' );';
			} elseif ( isset( $step['expect'] ) ) {
				$matcher = current( array_intersect( Companion_Rehearsal_Schema::MATCHERS, array_keys( $step ) ) );
				$not     = empty( $step['not'] ) ? '' : '.not';
				$arg     = is_bool( $step[ $matcher ] ) ? '' : ( is_int( $step[ $matcher ] ) ? (string) $step[ $matcher ] : self::pw_str( $step[ $matcher ] ) );
				if ( 'toBeVisible' === $matcher && $not ) {
					$code = 'await expect( ' . self::pw_locator( $step['expect'], false ) . ".filter( { visible: true } ) ).toHaveCount( 0$t2 );";
				} elseif ( 'toHaveCount' === $matcher ) {
					$code = 'await expect( ' . self::pw_locator( $step['expect'], false ) . " )$not.toHaveCount( $arg$t2 );";
				} else {
					$code = 'await expect( ' . self::pw_locator( $step['expect'] ) . " )$not.$matcher( " . ( '' === $arg ? $t : $arg . $t2 ) . ' );';
				}
			} elseif ( isset( $step['fill'] ) ) {
				$code = 'await ' . self::pw_locator( $step['fill'] ) . '.fill( ' . self::pw_str( $step['value'] ?? '' ) . "$t2 );";
			} elseif ( isset( $step['click'] ) ) {
				$code = 'await click( ' . self::pw_locator( $step['click'] ) . ( $t ? ", {$step['timeout']}" : '' ) . ' );';
			} elseif ( isset( $step['check'] ) || isset( $step['uncheck'] ) ) {
				$action = isset( $step['check'] ) ? 'check' : 'uncheck';
				$code   = 'await ' . self::pw_locator( $step[ $action ] ) . ".$action( $t );";
			} elseif ( isset( $step['selectOption'] ) ) {
				$code = 'await ' . self::pw_locator( $step['selectOption'] ) . '.selectOption( ' . self::pw_str( $step['value'] ) . "$t2 );";
			} elseif ( isset( $step['hover'] ) ) {
				$code = 'await ' . self::pw_locator( $step['hover'] ) . ".hover( $t );";
			} elseif ( isset( $step['press'] ) ) {
				$code = isset( $step['on'] )
					? 'await ' . self::pw_locator( $step['on'] ) . '.press( ' . self::pw_str( $step['press'] ) . "$t2 );"
					: 'await page.keyboard.press( ' . self::pw_str( $step['press'] ) . ' );';
			} elseif ( isset( $step['expectNoConsoleErrors'] ) ) {
				$ignore = (array) ( $step['expectNoConsoleErrors']['ignore'] ?? array() );
				$code   = $ignore
					? 'expect( consoleErrors.filter( ( e ) => ! [ ' . implode( ', ', array_map( static fn( $p ) => 'new RegExp( ' . $json( $p ) . ' )', $ignore ) ) . ' ].some( ( re ) => re.test( e ) ) ) ).toEqual( [] );'
					: 'expect( consoleErrors ).toEqual( [] );';
			} elseif ( isset( $step['snapshot'] ) ) {
				$code = 'await test.info().attach( ' . $json( 'snapshot: ' . $step['snapshot'] ) . ', { body: await ' . self::pw_locator( $step['snapshot'] ) . ".evaluate( ( el ) => el.outerHTML ), contentType: 'text/html' } );";
			} elseif ( isset( $step['screenshot'] ) ) {
				$code = 'await page.screenshot( { path: test.info().outputPath( ' . self::pw_str( $step['screenshot'] . '.png' ) . ' ), fullPage: true } );';
			}
			$code   = preg_replace( '/\(\s+\)/', '()', $code );
			$body[] = empty( $step['optional'] ) ? "\t$code" : "\tawait optional( async () => {\n\t\t$code\n\t} );";
			if ( ! empty( $def['budgets'] ) && ( isset( $step['goto'] ) || isset( $step['click'] ) || isset( $step['press'] ) ) ) {
				$body[] = "\tawait measure();";
			}
		}
		if ( ! empty( $def['budgets'] ) ) {
			$body[] = "\tawait measure();";
		}

		$head = array(
			'/**',
			' * Rehearsal: ' . str_replace( '*/', '* /', $payload['title'] ),
			' * Rehearsal ' . substr( $payload['id'], 0, 7 ) . ' from ' . $payload['site'] . ', converted to Playwright.',
			' *',
			' * Requires plugins: ' . ( empty( $def['requires'] ) ? 'none' : implode( ', ', $def['requires'] ) ) . '.',
			' * Requires theme: ' . ( empty( $def['theme'] ) ? 'any' : implode( ' or ', (array) $def['theme'] ) ) . '.',
			' * Runs as: ' . ( empty( $def['role'] ) ? 'the logged-in user' : $def['role'] . ' (uses STORAGE_STATE_' . strtoupper( str_replace( '-', '_', $def['role'] ) ) . ' when set)' ) . '.',
			' * Needs a logged-in session: set STORAGE_STATE (or the role\'s variable above) to a Playwright storage state file,',
			' * and BASE_URL to the site to test (defaults to the one it was shared from).',
			' */',
			'import { test, expect as baseExpect, type Locator' . ( empty( $def['budgets'] ) ? '' : ', type Page' ) . " } from '@playwright/test';",
			'',
			"const expect = baseExpect.configure( { timeout: $timeout } );",
			'test.use( {',
			"\tbaseURL: process.env.BASE_URL || " . $json( $payload['site'] ) . ',',
			"\tstorageState: " . ( empty( $def['role'] ) ? '' : 'process.env.STORAGE_STATE_' . strtoupper( str_replace( '-', '_', $def['role'] ) ) . ' || ' ) . 'process.env.STORAGE_STATE,',
			"\tactionTimeout: $timeout,",
			'} );',
			'',
			'// Links that open a new tab load in the same tab instead, as in the Rehearsal runner.',
			'async function click( locator: Locator, timeout?: number ) {',
			"\tconst href = await locator.evaluate( ( el ) => ( el.closest( 'a[target=\"_blank\"][href]' ) as HTMLAnchorElement | null )?.href ?? null, undefined, { timeout } );",
			"\tawait ( href ? locator.page().goto( href ) : locator.click( { timeout } ) );",
			'}',
			'',
			'async function optional( step: () => Promise< unknown > ) {',
			"\ttry {",
			"\t\tawait step();",
			"\t} catch {",
			"\t\t// Optional step: skipped when it doesn't apply.",
			"\t}",
			'}',
			'',
		);
		// Only rehearsals with their own budgets measure page loads at all.
		$tracker = array(
			'// This rehearsal\'s page-load budgets: times in ms, sizes in compressed bytes. Over budget is a notice, never a failure.',
			'const BUDGETS: Record< string, number > = ' . $json( (object) ( $def['budgets'] ?? array() ) ) . ';',
			'',
			'// Measures each page load: timings from the page; sizes from Playwright, so other sites\' files count too.',
			'async function trackLoads( page: Page ) {',
			"\t// Every page load is a first visit, so its size is the full page weight (Chromium only).",
			"\ttry {",
			"\t\tconst cdp = await page.context().newCDPSession( page );",
			"\t\tawait cdp.send( 'Network.enable' );",
			"\t\tawait cdp.send( 'Network.setCacheDisabled', { cacheDisabled: true } );",
			"\t} catch {}",
			"\tawait page.addInitScript( () => {",
			"\t\tconst v = ( ( window as any ).__rhVitals = { lcp: null as number | null, cls: 0 } );",
			"\t\ttry {",
			"\t\t\tnew PerformanceObserver( ( l ) => { const e = l.getEntries(); v.lcp = e[ e.length - 1 ].startTime; } ).observe( { type: 'largest-contentful-paint', buffered: true } );",
			"\t\t\tnew PerformanceObserver( ( l ) => l.getEntries().forEach( ( e: any ) => { if ( ! e.hadRecentInput ) v.cls += e.value; } ) ).observe( { type: 'layout-shift', buffered: true } );",
			"\t\t} catch {}",
			"\t} );",
			"\tconst loads: { url: string; requests: number; weight: number; js: number; reported: boolean }[] = [];",
			"\tpage.on( 'request', ( r ) => {",
			"\t\tif ( r.isNavigationRequest() && r.frame() === page.mainFrame() ) {",
			"\t\t\tloads.push( { url: r.url(), requests: 0, weight: 0, js: 0, reported: false } );",
			"\t\t}",
			"\t} );",
			"\tpage.on( 'requestfinished', async ( r ) => {",
			"\t\tconst load = loads[ loads.length - 1 ];",
			"\t\tconst sizes = load ? await r.sizes().catch( () => null ) : null;",
			"\t\tif ( ! load || ! sizes ) return;",
			"\t\tload.requests++;",
			"\t\tload.weight += sizes.responseBodySize;",
			"\t\tif ( r.resourceType() === 'script' ) load.js += sizes.responseBodySize;",
			"\t} );",
			"\treturn async function measure() {",
			"\t\tconst load = loads[ loads.length - 1 ];",
			"\t\tif ( ! load || load.reported ) return;",
			"\t\tload.reported = true;",
			"\t\tawait page.waitForLoadState( 'load' ).catch( () => {} );",
			"\t\tawait page.waitForTimeout( 1500 ); // Let LCP and layout shifts settle.",
			"\t\tconst t = await page.evaluate( () => {",
			"\t\t\tconst nav = performance.getEntriesByType( 'navigation' )[ 0 ] as PerformanceNavigationTiming | undefined;",
			"\t\t\tconst fcp = performance.getEntriesByName( 'first-contentful-paint' )[ 0 ];",
			"\t\t\tconst v = ( window as any ).__rhVitals || {};",
			"\t\t\treturn { ttfb: nav ? Math.round( nav.responseStart ) : null, fcp: fcp ? Math.round( fcp.startTime ) : null, lcp: v.lcp == null ? null : Math.round( v.lcp ), cls: Math.round( ( v.cls || 0 ) * 1000 ) / 1000 };",
			"\t\t} ).catch( () => null );",
			"\t\tif ( ! t ) return;",
			"\t\tconst m: Record< string, number | null > = { ...t, requests: load.requests, weight: load.weight, js: load.js };",
			"\t\tconst over = Object.keys( BUDGETS ).filter( ( k ) => m[ k ] !== null && m[ k ] !== undefined && ( m[ k ] as number ) > BUDGETS[ k ] );",
			"\t\tconst size = ( b: number ) => ( b < 1048576 ? `\${ Math.round( b / 1024 ) } KB` : `\${ ( b / 1048576 ).toFixed( 1 ) } MB` );",
			"\t\tconst line = `\${ new URL( load.url ).pathname } · TTFB \${ m.ttfb } ms · FCP \${ m.fcp } ms · LCP \${ m.lcp } ms · CLS \${ m.cls } · \${ load.requests } requests · \${ size( load.weight ) } (JS \${ size( load.js ) })`;",
			"\t\tconst note = over.length ? `\${ line } · over budget: \${ over.join( ', ' ) }` : line;",
			"\t\ttest.info().annotations.push( { type: over.length ? 'over budget' : 'page load', description: note } );",
			"\t\tconsole.log( `\${ over.length ? '⚠' : '·' } \${ note }` );",
			"\t};",
			'}',
			'',
		);
		$head = array_merge(
			$head,
			empty( $def['budgets'] ) ? array() : $tracker,
			array(
				'test( ' . $json( $payload['title'] ) . ', async ( { page } ) => {',
				"\ttest.setTimeout( " . max( 60000, count( $def['steps'] ) * $timeout + 30000 ) . ' );',
			)
		);
		if ( ! empty( $def['budgets'] ) ) {
			$head[] = "\tconst measure = await trackLoads( page );";
		}
		if ( $uses_run ) {
			$head[] = "\tconst run = Date.now().toString( 36 );";
		}
		if ( $uses_err ) {
			$head[] = "\tconst consoleErrors: string[] = [];";
			$head[] = "\t// Script errors only, like the Rehearsal runner. Failed requests are the network's, so they go in the report.";
			$head[] = "\tpage.on( 'console', ( m ) => m.type() === 'error' && ! m.text().startsWith( 'Failed to load resource' ) && consoleErrors.push( m.text() ) );";
			$head[] = "\tpage.on( 'pageerror', ( e ) => consoleErrors.push( e.message ) );";
			$head[] = "\tpage.on( 'requestfailed', ( r ) => test.info().annotations.push( { type: 'request failed', description: `\${ r.failure()?.errorText } \${ r.url() }` } ) );";
		}
		$head[] = '';
		return implode( "\n", array_merge( $head, $body, array( '} );', '' ) ) );
	}
}

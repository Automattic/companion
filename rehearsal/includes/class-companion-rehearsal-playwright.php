<?php
/**
 * Rehearsal: Translates rehearsals into standalone Playwright tests.
 *
 * @package companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translates rehearsals into standalone Playwright tests.
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
			// Playwright's role= engine rejects pseudo-classes, so :has-text() on a role becomes a filter.
			if ( preg_match( '/^(role=[^:]+):has-text\((["\'])(.*?)\2\)$/', $part, $m ) ) {
				$expr .= '.locator( ' . self::pw_str( $m[1] ) . ' ).filter( { hasText: ' . self::pw_str( $m[3] ) . ' } )';
				continue;
			}
			$method = $i < count( $parts ) - 1 && preg_match( '/^iframe\b/', $part ) ? 'frameLocator' : 'locator';
			$expr  .= ".$method( " . self::pw_str( $part ) . ' )';
		}
		return $first_visible ? $expr . '.filter( { visible: true } ).first()' : $expr;
	}

	/**
	 * One rehearsal as a Playwright test file.
	 *
	 * @param array $payload Share payload.
	 * @return string TypeScript source.
	 */
	public static function to_playwright( array $payload ) {
		return self::file( array( $payload ), $payload['site'] );
	}

	/**
	 * Several rehearsals as one Playwright test file, one describe block each.
	 *
	 * @param array[] $payloads Share payloads.
	 * @param string  $site     Default BASE_URL.
	 * @return string TypeScript source.
	 */
	public static function to_playwright_bundle( array $payloads, $site ) {
		return self::file( $payloads, $site );
	}

	/**
	 * JSON for generated code.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function json( $value ) {
		return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * The environment variable holding a role's storage state, such as STORAGE_STATE_EDITOR.
	 *
	 * @param string $role Role slug.
	 * @return string
	 */
	public static function storage_env( $role ) {
		return 'STORAGE_STATE_' . strtoupper( str_replace( '-', '_', $role ) );
	}

	/**
	 * A test file: shared setup and helpers once, then each rehearsal.
	 *
	 * @param array[] $payloads Share payloads.
	 * @param string  $site     Default BASE_URL.
	 * @return string
	 */
	private static function file( array $payloads, $site ) {
		$measures = (bool) array_filter( $payloads, static fn( $p ) => ! empty( $p['definition']['budgets'] ) );
		$lines    = array(
			'/**',
			' * Rehearsals converted to Playwright: ' . count( $payloads ) . ( 1 === count( $payloads ) ? ' rehearsal.' : ' rehearsals.' ),
			' *',
			' * Needs a logged-in session: set STORAGE_STATE to a Playwright storage state file (rehearsals with',
			' * a role read STORAGE_STATE_<ROLE> first), and BASE_URL to the site to test.',
			' *',
			' * Each result is saved on the site as a run, like a run from Tools → Rehearsal, using the',
			' * STORAGE_STATE session (an administrator\'s). REHEARSAL_REF labels the runs; REHEARSAL_REPORT=0 turns it off.',
			' */',
			"import { test, expect as baseExpect, request, type Locator, type TestInfo" . ( $measures ? ', type Page' : '' ) . " } from '@playwright/test';",
			'',
			'const BASE_URL = process.env.BASE_URL || ' . self::json( $site ) . ';',
			'',
			'test.use( {',
			"\tbaseURL: BASE_URL,",
			"\tstorageState: process.env.STORAGE_STATE,",
			'} );',
			'',
			'// Links that open a new tab load in the same tab instead, as in the Rehearsal runner.',
			'async function click( locator: Locator, timeout?: number ) {',
			"\tconst href = await locator.evaluate( ( el ) => ( el.closest( 'a[target=\"_blank\"][href]' ) as HTMLAnchorElement | null )?.href ?? null, undefined, { timeout } );",
			"\tawait ( href ? locator.page().goto( href ) : locator.click( { timeout } ) );",
			'}',
			'',
			'// Each step is a step in the Playwright report and a line in the run saved on the site.',
			'function rehearsal( id: number, hash: string, viewport: string ) {',
			"\t// The error without colours or its call log, on one line: what failed, expected and received.",
			"\tconst brief = ( e: unknown ) => String( ( e as Error )?.message ?? e ).replace( /\\x1b\\[[0-9;]*m/g, '' ).split( /\\nCall log:/ )[ 0 ].split( '\\n' ).map( ( l ) => l.trim() ).filter( Boolean ).join( ' · ' ).slice( 0, 600 );",
			"\tlet log: { ok: boolean; msg: string; ms: number }[] = [];",
			"\tconst errors: string[] = [];",
			"\treturn {",
			"\t\terrors,",
			"\t\tstart() {",
			"\t\t\tlog = [];",
			"\t\t\terrors.length = 0;",
			"\t\t},",
			"\t\tasync step( n: number, label: string, body: () => Promise< unknown >, optional = false ) {",
			"\t\t\tconst t0 = Date.now();",
			"\t\t\ttry {",
			"\t\t\t\tawait test.step( label, body );",
			"\t\t\t\tlog.push( { ok: true, msg: label, ms: Date.now() - t0 } );",
			"\t\t\t} catch ( e ) {",
			"\t\t\t\t// An optional step is skipped when it doesn't apply.",
			"\t\t\t\tlog.push( { ok: optional, msg: `\${ optional ? 'Skipped optional step' : 'Step' } \${ n }: \${ label }: \${ brief( e ) }`, ms: Date.now() - t0 } );",
			"\t\t\t\tif ( ! optional ) throw e;",
			"\t\t\t}",
			"\t\t},",
			"\t\tasync report( info: TestInfo ) {",
			"\t\t\tif ( ! id || process.env.REHEARSAL_REPORT === '0' || info.status === 'skipped' ) return;",
			"\t\t\tconst status = info.status === 'passed' ? 'pass' : 'fail';",
			"\t\t\t// A timeout stops the test between log lines, so say why it ended.",
			"\t\t\tif ( status === 'fail' && ! log.some( ( l ) => ! l.ok ) ) log.push( { ok: false, msg: brief( info.error?.message ?? `Test \${ info.status }` ), ms: 0 } );",
			"\t\t\tconst note = ( type: string, description: string ) => info.annotations.push( { type, description } );",
			"\t\t\tif ( ! process.env.STORAGE_STATE ) return note( 'rehearsal run not saved', 'Set STORAGE_STATE to an administrator\\'s session to save runs on the site.' );",
			"\t\t\tconst site = await request.newContext( { baseURL: BASE_URL, storageState: process.env.STORAGE_STATE } );",
			"\t\t\ttry {",
			"\t\t\t\tconst nonce = await ( await site.get( 'wp-admin/admin-ajax.php?action=rest-nonce' ) ).text();",
			"\t\t\t\tconst res = await site.post( '?rest_route=/rehearsal/v1/runs', {",
			"\t\t\t\t\theaders: { 'X-WP-Nonce': nonce },",
			"\t\t\t\t\tdata: { recipe_id: id, recipe_hash: hash, status, log, runner: 'playwright', viewport, ref: process.env.REHEARSAL_REF || '',",
			"\t\t\t\t\t\terrors: status === 'fail' ? errors.map( ( msg ) => ( { msg, page: '', source: '', count: 1 } ) ) : [] },",
			"\t\t\t\t} );",
			"\t\t\t\tconst body = await res.json().catch( () => ( {} ) );",
			"\t\t\t\tif ( res.ok() ) note( 'rehearsal run', `Saved as run #\${ body.id } on \${ BASE_URL }` );",
			"\t\t\t\telse note( 'rehearsal run not saved', body.message || `HTTP \${ res.status() }` );",
			"\t\t\t} catch ( e ) {",
			"\t\t\t\tnote( 'rehearsal run not saved', brief( e ) );",
			"\t\t\t} finally {",
			"\t\t\t\tawait site.dispose();",
			"\t\t\t}",
			"\t\t},",
			"\t};",
			'}',
			'',
		);
		if ( $measures ) {
			$lines = array_merge( $lines, self::tracker() );
		}
		foreach ( $payloads as $payload ) {
			$lines = array_merge( $lines, self::block( $payload ), array( '' ) );
		}
		return implode( "\n", $lines );
	}

	/**
	 * The page-load measuring helper, for rehearsals with budgets.
	 *
	 * @return string[]
	 */
	private static function tracker() {
		return array(
			'// Measures each page load against a rehearsal\'s budgets (ms, compressed bytes). Over budget is a notice, never a failure.',
			'async function trackLoads( page: Page, budgets: Record< string, number > ) {',
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
			"\t\tconst over = Object.keys( budgets ).filter( ( k ) => m[ k ] !== null && m[ k ] !== undefined && ( m[ k ] as number ) > budgets[ k ] );",
			"\t\tconst size = ( b: number ) => ( b < 1048576 ? `\${ Math.round( b / 1024 ) } KB` : `\${ ( b / 1048576 ).toFixed( 1 ) } MB` );",
			"\t\tconst line = `\${ new URL( load.url ).pathname } · TTFB \${ m.ttfb } ms · FCP \${ m.fcp } ms · LCP \${ m.lcp } ms · CLS \${ m.cls } · \${ load.requests } requests · \${ size( load.weight ) } (JS \${ size( load.js ) })`;",
			"\t\tconst note = over.length ? `\${ line } · over budget: \${ over.join( ', ' ) }` : line;",
			"\t\ttest.info().annotations.push( { type: over.length ? 'over budget' : 'page load', description: note } );",
			"\t\tconsole.log( `\${ over.length ? '⚠' : '·' } \${ note }` );",
			"\t};",
			'}',
			'',
		);
	}

	/**
	 * One rehearsal as a describe block with its own timeout, role and budgets.
	 *
	 * @param array $payload Share payload.
	 * @return string[]
	 */
	private static function block( array $payload ) {
		$def      = $payload['definition'];
		$timeout  = (int) ( $def['timeout'] ?? 10000 );
		$source   = (string) wp_json_encode( $def );
		$uses_run = false !== strpos( $source, '{{run}}' );
		$uses_err = false !== strpos( $source, '"expectNoConsoleErrors"' );
		$budgets  = ! empty( $def['budgets'] );
		$title    = self::one_line( $payload['title'] );
		$body     = array();
		foreach ( array_values( $def['steps'] ) as $i => $step ) {
			$body[] = 'await rh.step( ' . ( $i + 1 ) . ', ' . self::pw_str( self::label( $step ) ) . ", async () => {\n\t" . self::step( $step ) . "\n}" . ( empty( $step['optional'] ) ? '' : ', true' ) . ' );';
			if ( $budgets && ( isset( $step['goto'] ) || isset( $step['click'] ) || isset( $step['press'] ) ) ) {
				$body[] = 'await measure();';
			}
		}
		if ( $budgets ) {
			$body[] = 'await measure();';
		}

		$setup = array( "test.setTimeout( " . max( 60000, count( $def['steps'] ) * $timeout + 30000 ) . ' );', 'rh.start();' );
		if ( $budgets ) {
			$setup[] = 'const measure = await trackLoads( page, ' . self::json( (object) $def['budgets'] ) . ' );';
		}
		if ( $uses_run ) {
			$setup[] = 'const run = Date.now().toString( 36 );';
		}
		if ( $uses_err ) {
			$setup[] = 'const consoleErrors = rh.errors;';
			$setup[] = "// Script errors only, like the Rehearsal runner. Failed requests are the network's, so they go in the report.";
			$setup[] = "page.on( 'console', ( m ) => m.type() === 'error' && ! m.text().startsWith( 'Failed to load resource' ) && consoleErrors.push( m.text() ) );";
			$setup[] = "page.on( 'pageerror', ( e ) => consoleErrors.push( e.message ) );";
			$setup[] = "page.on( 'requestfailed', ( r ) => test.info().annotations.push( { type: 'request failed', description: `\${ r.failure()?.errorText } \${ r.url() }` } ) );";
		}
		$use = "actionTimeout: $timeout";
		if ( ! empty( $def['viewport'] ) ) {
			// The browser runner's sizes for each viewport.
			$use .= ', viewport: ' . ( 'mobile' === $def['viewport'] ? '{ width: 390, height: 844 }' : '{ width: 1280, height: 800 }' );
		}
		if ( ! empty( $def['role'] ) ) {
			$use .= ', storageState: process.env.' . self::storage_env( $def['role'] ) . ' || process.env.STORAGE_STATE';
		}
		$indent = static fn( $lines, $tabs ) => array_map( static fn( $l ) => preg_replace( '/^(?=.)/m', str_repeat( "\t", $tabs ), $l ), $lines );

		return array_merge(
			array(
				'// Rehearsal: ' . $title,
				'// Rehearsal ' . substr( $payload['id'], 0, 7 ) . ' from ' . self::one_line( $payload['site'] ) . ', format ' . Companion_Rehearsal_Schema::format_of( $def ) . '.',
				'// Requires plugins: ' . ( empty( $def['requires'] ) ? 'none' : implode( ', ', $def['requires'] ) ) . '. Requires theme: ' . ( empty( $def['theme'] ) ? 'any' : implode( ' or ', (array) $def['theme'] ) ) . '. Runs as: ' . ( empty( $def['role'] ) ? 'the logged-in user' : $def['role'] . ' (' . self::storage_env( $def['role'] ) . ')' ) . '.',
				'test.describe( ' . self::json( $title ) . ', () => {',
				"\tconst expect = baseExpect.configure( { timeout: $timeout } );",
				"\ttest.use( { $use } );",
				"\tconst rh = rehearsal( " . (int) ( $payload['post_id'] ?? 0 ) . ', ' . self::json( $payload['id'] ) . ', ' . self::json( 'mobile' === ( $def['viewport'] ?? '' ) ? 'mobile' : 'desktop' ) . ' );',
				"\ttest.afterEach( ( {}, info ) => rh.report( info ) );",
				'',
				"\ttest( " . self::json( 'rehearsal ' . substr( $payload['id'], 0, 7 ) ) . ', async ( { page } ) => {',
			),
			$indent( $setup, 2 ),
			array( '' ),
			$indent( $body, 2 ),
			array( "\t} );", '} );' )
		);
	}

	/**
	 * Text for a `//` comment line. JS also ends a line at U+2028 and U+2029, so those go too.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function one_line( $text ) {
		return str_replace( array( "\r", "\n", "\u{2028}", "\u{2029}" ), ' ', (string) $text );
	}

	/**
	 * A step's line in the report and the saved run: its action and target, like the browser runner's log.
	 *
	 * @param array $step Step.
	 * @return string
	 */
	private static function label( array $step ) {
		$action = (string) current( array_intersect( Companion_Rehearsal_Schema::STEPS, array_keys( $step ) ) );
		$target = $step[ $action ];
		$label  = is_string( $target ) ? "$action $target" : $action;
		if ( 'expect' === $action ) {
			$matcher = (string) current( array_intersect( Companion_Rehearsal_Schema::MATCHERS, array_keys( $step ) ) );
			$label  .= ( empty( $step['not'] ) ? '' : ' not' ) . " $matcher" . ( is_bool( $step[ $matcher ] ) ? '' : ' ' . self::json( $step[ $matcher ] ) );
		}
		if ( isset( $step['value'] ) ) {
			$label .= ' = “' . $step['value'] . '”';
		}
		foreach ( array( 'on', 'in' ) as $key ) {
			$label .= isset( $step[ $key ] ) ? " $key {$step[ $key ]}" : '';
		}
		return self::one_line( $label );
	}

	/**
	 * One step as a line of Playwright code.
	 *
	 * @param array $step Step.
	 * @return string
	 */
	private static function step( array $step ) {
		$ms = empty( $step['timeout'] ) ? 0 : (int) $step['timeout'];
		$t  = $ms ? "{ timeout: $ms }" : '';
		$t1 = $ms ? "( $t )" : '()';
		$t2 = $ms ? ", $t" : '';
		if ( isset( $step['goto'] ) ) {
			return 'await page.goto( ' . self::pw_str( $step['goto'] ) . ' );';
		}
		if ( isset( $step['waitFor'] ) ) {
			return 'await expect( ' . self::pw_locator( $step['waitFor'] ) . " ).toBeVisible$t1;";
		}
		if ( isset( $step['expectText'] ) ) {
			$scope = isset( $step['in'] ) ? self::pw_locator( $step['in'] ) : "page.locator( 'body' )";
			return "await expect( $scope ).toContainText( " . self::pw_str( $step['expectText'] ) . "$t2 );";
		}
		if ( isset( $step['expectCount'] ) ) {
			return 'await expect.poll( () => ' . self::pw_locator( $step['expectCount'], false ) . ".count()$t2 ).toBeGreaterThanOrEqual( " . (int) ( $step['min'] ?? 1 ) . ' );';
		}
		if ( isset( $step['expect'] ) ) {
			$matcher = current( array_intersect( Companion_Rehearsal_Schema::MATCHERS, array_keys( $step ) ) );
			$not     = empty( $step['not'] ) ? '' : '.not';
			$arg     = is_bool( $step[ $matcher ] ) ? '' : ( is_int( $step[ $matcher ] ) ? (string) $step[ $matcher ] : self::pw_str( $step[ $matcher ] ) );
			if ( 'toBeVisible' === $matcher && $not ) {
				return 'await expect( ' . self::pw_locator( $step['expect'], false ) . ".filter( { visible: true } ) ).toHaveCount( 0$t2 );";
			}
			if ( 'toHaveCount' === $matcher ) {
				return 'await expect( ' . self::pw_locator( $step['expect'], false ) . " )$not.toHaveCount( $arg$t2 );";
			}
			return 'await expect( ' . self::pw_locator( $step['expect'] ) . " )$not.$matcher" . ( '' === $arg ? $t1 : "( $arg$t2 )" ) . ';';
		}
		if ( isset( $step['fill'] ) ) {
			return 'await ' . self::pw_locator( $step['fill'] ) . '.fill( ' . self::pw_str( $step['value'] ?? '' ) . "$t2 );";
		}
		if ( isset( $step['click'] ) ) {
			return 'await click( ' . self::pw_locator( $step['click'] ) . ( $ms ? ", $ms" : '' ) . ' );';
		}
		if ( isset( $step['check'] ) || isset( $step['uncheck'] ) ) {
			$action = isset( $step['check'] ) ? 'check' : 'uncheck';
			return 'await ' . self::pw_locator( $step[ $action ] ) . ".$action$t1;";
		}
		if ( isset( $step['selectOption'] ) ) {
			return 'await ' . self::pw_locator( $step['selectOption'] ) . '.selectOption( ' . self::pw_str( $step['value'] ) . "$t2 );";
		}
		if ( isset( $step['hover'] ) ) {
			return 'await ' . self::pw_locator( $step['hover'] ) . ".hover$t1;";
		}
		if ( isset( $step['press'] ) ) {
			return isset( $step['on'] )
				? 'await ' . self::pw_locator( $step['on'] ) . '.press( ' . self::pw_str( $step['press'] ) . "$t2 );"
				: 'await page.keyboard.press( ' . self::pw_str( $step['press'] ) . ' );';
		}
		if ( isset( $step['expectNoConsoleErrors'] ) ) {
			$ignore = (array) ( $step['expectNoConsoleErrors']['ignore'] ?? array() );
			return $ignore
				? 'expect( consoleErrors.filter( ( e ) => ! [ ' . implode( ', ', array_map( static fn( $p ) => 'new RegExp( ' . self::json( $p ) . ' )', $ignore ) ) . ' ].some( ( re ) => re.test( e ) ) ) ).toEqual( [] );'
				: 'expect( consoleErrors ).toEqual( [] );';
		}
		if ( isset( $step['snapshot'] ) ) {
			return 'await test.info().attach( ' . self::json( 'snapshot: ' . $step['snapshot'] ) . ', { body: await ' . self::pw_locator( $step['snapshot'] ) . ".evaluate( ( el ) => el.outerHTML ), contentType: 'text/html' } );";
		}
		if ( isset( $step['dialog'] ) ) {
			$answer = 'accept' === $step['dialog'] ? 'd.accept(' . ( isset( $step['value'] ) ? ' ' . self::pw_str( $step['value'] ) . ' ' : '' ) . ')' : 'd.dismiss()';
			return "page.once( 'dialog', ( d ) => $answer );";
		}
		if ( isset( $step['screenshot'] ) ) {
			return 'await page.screenshot( { path: test.info().outputPath( ' . self::pw_str( $step['screenshot'] . '.png' ) . ' ), fullPage: true } );';
		}
		return '';
	}
}

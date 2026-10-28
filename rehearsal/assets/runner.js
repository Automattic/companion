( function () {
	const data = window.rehearsalData;
	const root = document.getElementById( 'rh-app' );
	if ( ! data || ! root ) {
		return;
	}
	const apiFetch = window.wp.apiFetch;
	const DEFAULT_TIMEOUT = 10000;
	const STEP_PAUSE = 150;
	const IMPLICIT_ROLES = {
		button: 'button, input[type=submit], input[type=button]',
		link: 'a[href]',
		table: 'table',
		heading: 'h1, h2, h3, h4, h5, h6',
		textbox: 'input:not([type]), input[type=text], input[type=email], input[type=url], textarea',
		checkbox: 'input[type=checkbox]',
	};

	const state = {
		running: false,
		recipes: data.recipes.map( ( r ) => ( { ...r, checked: ! blocked( r ), open: false, live: null } ) ),
		runs: data.runs.runs,
		runTotal: data.runs.total,
		runPage: 1,
		runPages: data.runs.pages,
		runUsers: data.runUsers,
		filters: { search: '', recipe: '', status: '', user: '' },
	};

	const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );
	const norm = ( s ) => String( s ).replace( /\s+/g, ' ' ).trim();
	const esc = ( s ) =>
		String( s ?? '' ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ c ] );
	const short = ( hash ) => String( hash || '' ).slice( 0, 7 );

	root.innerHTML = `
		<div class="rh-toolbar">
			<button type="button" class="button button-primary" id="rh-run">Run selected</button>
			<button type="button" class="button" id="rh-run-failed">Run failed</button>
			<button type="button" class="button" id="rh-playwright" title="Export the selected rehearsals as one Playwright test file">Run in Playwright</button>
			<span class="rh-toolbar-sep" aria-hidden="true"></span>
			<button type="button" class="button" id="rh-add">Add rehearsal</button>
			<button type="button" class="button" id="rh-install">Install from link</button>
			<span class="rh-summary" id="rh-summary"></span>
		</div>
		<div class="rh-layout">
			<div class="rh-main" id="rh-recipes"></div>
			<aside class="rh-frame" aria-label="Test frame">
				<div class="rh-frame-bar"><span id="rh-frame-url">Waiting for a run</span><span id="rh-frame-step"></span></div>
				<div class="rh-frame-viewport" id="rh-frame-viewport">
					<iframe name="rehearsal" id="rh-frame" title="Test frame the runner drives" src="about:blank"></iframe>
					<p class="rh-frame-idle" id="rh-frame-idle">Each step's page loads here when you press Run.</p>
				</div>
			</aside>
		</div>
		<h2 id="rh-runs-heading">Recent runs</h2>
		<div class="rh-run-filters">
			<input type="search" id="rh-f-search" placeholder="Search by rehearsal or run #" aria-label="Search runs">
			<select id="rh-f-recipe" aria-label="Recipe"></select>
			<select id="rh-f-status" aria-label="Result">
				<option value="">Any result</option><option value="fail">Failed</option><option value="pass">Passed</option><option value="queued">Needs Playwright</option>
			</select>
			<select id="rh-f-user" aria-label="Run by"></select>
			<button type="button" class="button-link" id="rh-f-clear" hidden>Clear filters</button>
			<span class="rh-muted" id="rh-f-count"></span>
		</div>
		<div id="rh-history"></div>
		<p><button type="button" class="button" id="rh-more" hidden>Load more runs</button></p>
		<dialog class="rh-modal" id="rh-modal" aria-labelledby="rh-modal-title"></dialog>`;

	const frame = document.getElementById( 'rh-frame' );
	const viewport = document.getElementById( 'rh-frame-viewport' );
	const runButton = document.getElementById( 'rh-run' );
	const runFailedButton = document.getElementById( 'rh-run-failed' );

	// Pages render at least desktop width and are scaled to fit, so a narrow column doesn't trigger mobile layouts.
	const MIN_FRAME_WIDTH = 1280;
	const FRAME_HEIGHT = 800;
	frame.style.height = FRAME_HEIGHT + 'px';
	const fitFrame = () => {
		const width = Math.max( MIN_FRAME_WIDTH, viewport.clientWidth );
		const scale = viewport.clientWidth / width;
		frame.style.width = width + 'px';
		frame.style.transform = `scale(${ scale })`;
		viewport.style.height = FRAME_HEIGHT * scale + 'px';
	};
	new ResizeObserver( fitFrame ).observe( viewport );
	fitFrame();

	// Undo anything a previous page did to the frame's position, so every rehearsal starts from the same view.
	function resetFrame() {
		viewport.removeAttribute( 'style' );
		frame.removeAttribute( 'style' );
		viewport.scrollTo( 0, 0 );
		frame.style.height = FRAME_HEIGHT + 'px';
		fitFrame();
	}

	// ---------- Rendering ----------

	function statusOf( r ) {
		if ( r.live ) {
			if ( r.live.status === 'running' ) return [ 'running', 'Running…' ];
			if ( r.live.status === 'unsaved' ) return [ 'fail', 'Not saved' ];
		}
		if ( ! r.lastRun ) return [ '', 'Not run' ];
		if ( r.lastRun.hash !== r.hash ) return [ 'stale', 'Stale: rehearsal changed' ];
		return pillFor( r.lastRun.status );
	}

	function failedRecipes() {
		return state.recipes.filter( ( r ) => ! blocked( r ) && statusOf( r )[ 0 ] === 'fail' );
	}

	// A rehearsal whose required plugins are not all active here: shown, but it cannot run.
	function blocked( r ) {
		return !! ( r.missing && r.missing.length );
	}

	// Written for a newer Rehearsal than this one: it can run, but some steps may not work.
	function newerText( format ) {
		return `Made with a newer version of Rehearsal (format ${ format }; this site understands format ${ data.format }). Some steps may not work; update the Companion plugin to be sure.`;
	}

	function newerPill( r ) {
		return r.newer ? ` <span class="rh-pill stale" title="${ esc( newerText( r.format ) ) }">Newer format</span>` : '';
	}

	function needsPill( r ) {
		if ( ! blocked( r ) ) return '';
		const why = r.missing
			.map( ( m ) => m.kind === 'role' ? `there is no "${ m.name }" role on this site` : `${ m.name } ${ m.kind === 'theme' ? ( m.state === 'inactive' ? 'is installed but not the active theme' : 'is not installed' ) : m.state === 'inactive' ? 'is installed but not active' : 'is not installed' }` )
			.join( '; ' );
		return ` <span class="rh-pill fail" title="${ esc( why ) }">Needs ${ esc( r.missing.map( ( m ) => m.name ).join( ', ' ) ) }</span>`;
	}

	function pillFor( status ) {
		return (
			{ pass: [ 'pass', 'Passed' ], fail: [ 'fail', 'Failed' ], queued: [ 'queued', 'Needs Playwright' ] }[ status ] || [ '', status ]
		);
	}

	function ago( iso ) {
		const s = Math.round( ( Date.now() - new Date( iso ).getTime() ) / 1000 );
		if ( s < 60 ) return 'just now';
		if ( s < 3600 ) return `${ Math.round( s / 60 ) } min ago`;
		if ( s < 86400 ) return `${ Math.round( s / 3600 ) } h ago`;
		return new Date( iso ).toLocaleDateString();
	}

	// Same message from the same page counts once, with how often it happened.
	function groupErrors( errors ) {
		const groups = new Map();
		errors.forEach( ( e ) => {
			const page = e.page.split( '?' )[ 0 ];
			const key = page + '\n' + e.msg;
			if ( groups.has( key ) ) groups.get( key ).count++;
			else groups.set( key, { msg: e.msg, page, source: e.source || '', count: 1 } );
		} );
		return [ ...groups.values() ];
	}

	function errorsHtml( errors, copyAttr ) {
		if ( ! errors || ! errors.length ) return '';
		const items = errors
			.map(
				( e ) => `<li>
					<div class="rh-err-meta">${ e.count > 1 ? `<b>${ e.count }×</b> ` : '' }on <code>${ esc( e.page ) }</code></div>
					<pre class="rh-err-msg">${ esc( e.msg ) }</pre>
					${ e.source ? `<pre class="rh-err-src">${ esc( e.source ) }</pre>` : '' }
				</li>`
			)
			.join( '' );
		return `<div class="rh-errors">
			<div class="rh-errors-head"><strong>Console errors (${ errors.length })</strong>
			<button type="button" class="button button-small" ${ copyAttr }>Copy errors</button></div>
			<ol>${ items }</ol></div>`;
	}

	function errorsText( errors, run, title ) {
		const head = [
			`Rehearsal: console errors in "${ title }"`,
			`Site: ${ data.homeUrl }`,
			`Build: ${ run ? run.build : data.build }`,
			run ? `Run: #${ run.id } on ${ run.date } by ${ run.by }, rehearsal ${ short( run.hash ) }` : 'Run: not saved',
			'',
		];
		const body = errors.map( ( e, i ) =>
			[ `${ i + 1 }. ${ e.count > 1 ? `[${ e.count }×] ` : '' }${ e.page }`, indent( e.msg ), e.source ? indent( e.source ) : '' ]
				.filter( Boolean )
				.join( '\n' )
		);
		return head.concat( body.join( '\n\n' ) ).join( '\n' );
	}

	function indent( text ) {
		return text
			.split( '\n' )
			.map( ( l ) => '   ' + l )
			.join( '\n' );
	}

	// Shows a short status on a button, then its own label again, however often it's clicked.
	function flash( button, text, ms = 1500 ) {
		if ( ! button.dataset.label ) button.dataset.label = button.textContent;
		clearTimeout( button.flashTimer );
		button.textContent = text;
		if ( ms ) button.flashTimer = setTimeout( () => ( button.textContent = button.dataset.label ), ms );
	}

	async function copy( text, button ) {
		try {
			await navigator.clipboard.writeText( text );
		} catch ( e ) {
			// Clipboard API needs a secure context and focus; fall back to a selected textarea.
			const ta = document.createElement( 'textarea' );
			ta.value = text;
			document.body.appendChild( ta );
			ta.select();
			document.execCommand( 'copy' );
			ta.remove();
		}
		flash( button, 'Copied' );
	}

	// The clipboard gets a pending promise right away: Chrome refuses a write that waits on a request first.
	async function copyFetched( url, button ) {
		flash( button, 'Copying…', 0 );
		const text = fetch( url, { credentials: 'same-origin' } ).then( ( res ) => {
			if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
			return res.text();
		} );
		try {
			if ( window.ClipboardItem ) {
				await navigator.clipboard.write( [ new ClipboardItem( { 'text/plain': text.then( ( t ) => new Blob( [ t ], { type: 'text/plain' } ) ) } ) ] );
			} else {
				await navigator.clipboard.writeText( await text );
			}
			flash( button, 'Copied' );
		} catch ( e ) {
			flash( button, 'Copy failed' );
		}
	}

	function logHtml( log, cleared ) {
		if ( ! log || ! log.length ) {
			return `<p class="rh-muted">${ cleared ? 'Not part of the latest run. Its last log is under Recent runs.' : 'No steps run yet.' }</p>`;
		}
		return `<ol class="rh-log">${ log
			.map(
				( l ) =>
					`<li class="${ l.ok ? 'ok' : 'no' }"><span aria-hidden="true">${ l.ok ? '✓' : '✗' }</span><span class="msg">${ esc(
						l.msg
					) }${ ( l.pages || [] ).map( metricsHtml ).join( '' ) }</span><span class="ms">${ l.ms ? l.ms + ' ms' : '' }</span></li>`
			)
			.join( '' ) }</ol>`;
	}

	const ms = ( v ) => ( v < 1000 ? `${ v } ms` : `${ ( v / 1000 ).toFixed( 1 ) } s` );
	const mb = ( v ) => ( v < 1048576 ? `${ Math.round( v / 1024 ) } KB` : `${ ( v / 1048576 ).toFixed( 1 ) } MB` );
	const FORMAT = { ttfb: ms, fcp: ms, lcp: ms, cls: ( v ) => v.toFixed( 2 ), requests: String, weight: mb, js: mb };

	// One line per page load, with anything over budget called out.
	function metricsHtml( p ) {
		const warn = p.warn || [];
		const item = ( k, text ) => {
			if ( p[ k ] === null || p[ k ] === undefined ) return '';
			const over = warn.includes( k ) ? ` <small>(budget ${ FORMAT[ k ]( p.limits[ k ] ) })</small>` : '';
			return `<span class="rh-metric ${ over ? 'warn' : '' }">${ text }${ over }</span>`;
		};
		const items = [
			item( 'ttfb', `TTFB ${ ms( p.ttfb ) }` ),
			item( 'fcp', `FCP ${ ms( p.fcp ) }` ),
			item( 'lcp', `LCP ${ ms( p.lcp ) }` ),
			item( 'cls', `CLS ${ p.cls.toFixed( 2 ) }` ),
			item( 'requests', `${ p.requests } requests` ),
			item( 'weight', `${ mb( p.weight ) }` ),
			item( 'js', `JS ${ mb( p.js ) }` ),
		].filter( Boolean );
		const extra = [ `${ mb( p.downloaded ) } downloaded` ];
		if ( p.unmeasured ) extra.push( `${ p.unmeasured } other-site files not sized` );
		return `<span class="rh-metrics ${ warn.length ? 'warn' : '' }">
			<span class="rh-metrics-url">${ esc( p.url.split( '?' )[ 0 ] ) }${ warn.length ? ' · <b>Over budget</b>' : '' }</span>
			${ items.join( '<span aria-hidden="true"> · </span>' ) }<span class="rh-muted"> · ${ extra.join( ' · ' ) }</span></span>`;
	}

	// How many page loads in a run went over the rehearsal's budget.
	function slowPages( run ) {
		return ( ( run && run.log ) || [] ).flatMap( ( l ) => l.pages || [] ).filter( ( p ) => ( p.warn || [] ).length ).length;
	}

	function slowPill( run ) {
		const n = slowPages( run );
		return n ? ` <span class="rh-pill stale" title="Page loads over this rehearsal's budget">${ n } over budget</span>` : '';
	}

	function renderRecipes() {
		const all = state.recipes.filter( ( r ) => ! blocked( r ) ).every( ( r ) => r.checked );
		const rows = state.recipes
			.map( ( r, i ) => {
				const [ cls, label ] = statusOf( r );
				const log = r.live ? r.live.log : ! r.logCleared && r.lastRun && r.lastRun.log;
				const meta = [
					`rehearsal <code>${ short( r.hash ) }</code>`,
					`${ r.recipe.steps.length } step${ r.recipe.steps.length === 1 ? '' : 's' }`,
					r.recipe.covers ? `covers ${ r.recipe.covers.map( esc ).join( ', ' ) }` : 'manual only',
					r.recipe.requires ? `requires ${ r.recipe.requires.map( esc ).join( ', ' ) }` : '',
					r.recipe.theme ? `theme ${ esc( [].concat( r.recipe.theme ).join( ' or ' ) ) }` : '',
					r.recipe.role ? `as ${ esc( r.recipe.role ) }` : '',
					r.source ? 'installed from a link' : '',
					r.lastRun ? `last run ${ ago( r.lastRun.date ) } by ${ esc( r.lastRun.by ) }` : '',
				].filter( Boolean );
				const error = r.live && r.live.error ? `<p class="rh-error">${ esc( r.live.error ) }</p>` : '';
				return `
				<tr class="${ blocked( r ) ? 'rh-blocked' : '' }">
					<th scope="row" class="check-column"><input type="checkbox" data-i="${ i }" ${ r.checked && ! blocked( r ) ? 'checked' : '' } ${
						state.running || blocked( r ) ? 'disabled' : ''
					} aria-label="Include ${ esc( r.title ) }"></th>
					<td>
						<strong><button type="button" class="button-link rh-title" data-open="${ i }">${ esc( r.title ) }</button></strong>
						<div class="rh-meta">${ meta.join( ' · ' ) }</div>
					</td>
					<td><span class="rh-pill ${ cls }">${ label }</span>${ r.recipe.budgets ? slowPill( r.live || r.lastRun ) : '' }${ needsPill( r ) }${ newerPill( r ) }</td>
					<td class="rh-row-actions"><button type="button" class="button button-small" data-run-row="${ i }" ${
						state.running || blocked( r ) ? 'disabled' : ''
					} aria-label="Run ${ esc( r.title ) }">Run</button>
					<button type="button" class="button-link" data-toggle="${ i }" aria-expanded="${ r.open }">${
						r.open ? 'Hide log' : 'Log'
					}</button></td>
				</tr>
				<tr class="rh-detail" ${ r.open ? '' : 'hidden' }><td></td><td colspan="3">${ error }${ logHtml( log, r.logCleared && r.lastRun ) }${ errorsHtml(
					r.live ? r.live.errors : ! r.logCleared && r.lastRun && r.lastRun.errors,
					`data-copy-recipe="${ i }"`
				) }${ r.lastRun ? `<button type="button" class="button-link" data-runs-for="${ r.id }">See all runs of this rehearsal</button>` : '' }</td></tr>`;
			} )
			.join( '' );
		document.getElementById( 'rh-recipes' ).innerHTML = `
			<table class="widefat rh-recipes">
				<thead><tr>
					<td class="check-column"><input type="checkbox" data-all ${ all ? 'checked' : '' } ${
						state.running ? 'disabled' : ''
					} aria-label="Select all rehearsals"></td>
					<th>Rehearsal</th><th>Last result</th><th></th>
				</tr></thead>
				<tbody>${ rows || '<tr><td></td><td colspan="3" class="rh-muted">No rehearsals yet. Add one, or install one from a link.</td></tr>' }</tbody>
			</table>`;
		renderSummary();
	}

	function renderSummary() {
		const counts = {};
		state.recipes.forEach( ( r ) => {
			const [ cls ] = statusOf( r );
			if ( cls && cls !== 'running' ) counts[ cls ] = ( counts[ cls ] || 0 ) + 1;
		} );
		const order = [
			[ 'pass', 'passed' ],
			[ 'fail', 'failed' ],
			[ 'stale', 'stale' ],
			[ 'queued', 'need Playwright' ],
		];
		const parts = order
			.filter( ( [ k ] ) => counts[ k ] )
			.map( ( [ k, word ] ) => `<span class="rh-pill ${ k }">${ counts[ k ] } ${ word }</span>` );
		const slow = state.recipes.filter( ( r ) => r.recipe.budgets && r.lastRun && r.lastRun.hash === r.hash && slowPages( r.lastRun ) ).length;
		if ( slow ) parts.push( `<span class="rh-pill stale" title="Rehearsals whose last run had page loads over their budget">${ slow } over budget</span>` );
		const needs = state.recipes.filter( blocked ).length;
		if ( needs ) parts.push( `<span class="rh-pill" title="Rehearsals whose required plugins or theme are not active here">${ needs } can't run here</span>` );
		document.getElementById( 'rh-summary' ).innerHTML = parts.join( ' ' );
		const selected = state.recipes.filter( ( r ) => r.checked && ! blocked( r ) );
		runButton.disabled = state.running || ! selected.length;
		runButton.textContent = state.running ? 'Running…' : `Run selected (${ selected.length })`;
		const failed = failedRecipes().length;
		runFailedButton.disabled = state.running || ! failed;
		runFailedButton.textContent = `Run failed (${ failed })`;
	}

	const hasFilters = () => Object.values( state.filters ).some( Boolean );

	function renderFilterOptions() {
		const option = ( value, label, current ) => `<option value="${ value }" ${ String( current ) === String( value ) ? 'selected' : '' }>${ esc( label ) }</option>`;
		document.getElementById( 'rh-f-recipe' ).innerHTML =
			option( '', 'All recipes', state.filters.recipe ) + state.recipes.map( ( r ) => option( r.id, r.title, state.filters.recipe ) ).join( '' );
		document.getElementById( 'rh-f-user' ).innerHTML =
			option( '', 'Anyone', state.filters.user ) + state.runUsers.map( ( u ) => option( u.id, u.name, state.filters.user ) ).join( '' );
		document.getElementById( 'rh-f-status' ).value = state.filters.status;
		document.getElementById( 'rh-f-search' ).value = state.filters.search;
		document.getElementById( 'rh-f-clear' ).hidden = ! hasFilters();
	}

	let runsRequest = 0;
	async function loadRuns( append ) {
		const mine = ++runsRequest;
		const page = append ? state.runPage + 1 : 1;
		const query = new URLSearchParams( { page } );
		Object.entries( state.filters ).forEach( ( [ k, v ] ) => v && query.set( k, v ) );
		try {
			const res = await apiFetch( { path: `/rehearsal/v1/runs/search?${ query }` } );
			if ( mine !== runsRequest ) return; // A newer filter change already replaced this one.
			// A run saved since the last page shifts the server's pages, so the next page can repeat a row.
			const seen = new Set( state.runs.map( ( run ) => run.id ) );
			state.runs = append ? state.runs.concat( res.runs.filter( ( run ) => ! seen.has( run.id ) ) ) : res.runs;
			state.runTotal = res.total;
			state.runPages = res.pages;
			state.runPage = page;
		} catch ( e ) {
			state.runsError = e.message || 'Could not load runs.';
		}
		renderFilterOptions();
		renderHistory();
	}

	function filterRuns( changes ) {
		Object.assign( state.filters, changes );
		loadRuns( false );
	}

	function renderHistory() {
		const el = document.getElementById( 'rh-history' );
		document.getElementById( 'rh-f-count' ).textContent = `${ state.runTotal } run${ state.runTotal === 1 ? '' : 's' }${ hasFilters() ? ' match' : '' }`;
		document.getElementById( 'rh-more' ).hidden = state.runPage >= state.runPages;
		if ( state.runsError ) {
			el.innerHTML = `<p class="rh-error">${ esc( state.runsError ) }</p>`;
			state.runsError = '';
			return;
		}
		if ( ! state.runs.length ) {
			el.innerHTML = `<p class="rh-muted">${ hasFilters() ? 'No runs match these filters.' : 'Runs appear here once they are saved.' }</p>`;
			return;
		}
		el.innerHTML = `
			<table class="widefat striped rh-history">
				<thead><tr><th>When</th><th>Rehearsal</th><th>Result</th><th>Rehearsal ID</th><th>Build</th><th>By</th></tr></thead>
				<tbody>${ state.runs
					.map( ( run ) => {
						const [ cls, label ] = pillFor( run.status );
						const current = state.recipes.find( ( r ) => r.id === run.recipeId );
						const stale = current && current.hash !== run.hash ? ' <span class="rh-pill stale">older version</span>' : '';
						return `<tr>
							<td title="${ esc( run.date ) }">${ ago( run.date ) }</td>
							<td><details><summary>${ esc( run.title ) } <span class="rh-muted">#${ run.id }</span></summary>${ logHtml(
								run.log
							) }${ errorsHtml( run.errors, `data-copy-run="${ run.id }"` ) }${ run.snapshots ?`<p class="rh-muted">${ run.snapshots } HTML snapshot(s) stored with this run.</p>` : '' }</details></td>
							<td><span class="rh-pill ${ cls }">${ label }</span>${ slowPill( run ) }</td>
							<td><code>${ short( run.hash ) }</code>${ stale }</td>
							<td>${ esc( run.build ) }</td>
							<td><button type="button" class="button-link" data-filter-user="${ run.byId }" title="Show only runs by ${ esc( run.by ) }">${ esc( run.by ) }</button>${ run.role ? ` <span class="rh-muted">as ${ esc( run.role ) }</span>` : '' }</td>
						</tr>`;
					} )
					.join( '' ) }</tbody>
			</table>`;
	}

	root.addEventListener( 'change', ( e ) => {
		const t = e.target;
		if ( t.matches( '[data-all]' ) ) {
			state.recipes.forEach( ( r ) => ( r.checked = t.checked && ! blocked( r ) ) );
		} else if ( t.matches( '[data-i]' ) ) {
			state.recipes[ +t.dataset.i ].checked = t.checked;
		} else {
			return;
		}
		renderRecipes();
	} );
	// ---------- Recipe modal ----------

	const modal = document.getElementById( 'rh-modal' );
	const RECIPE_TEMPLATE = {
		steps: [ { goto: '/wp-admin/index.php' }, { waitFor: '#dashboard-widgets' }, { expectNoConsoleErrors: true } ],
	};

	function showModal( title, body, footer ) {
		modal.innerHTML = `<div class="rh-modal-inner">
			<header><h2 id="rh-modal-title">${ esc( title ) }</h2>
				<button type="button" class="rh-modal-close" data-close aria-label="Close">×</button></header>
			<div class="rh-modal-body">${ body }
				<p class="rh-modal-note" id="rh-modal-note" hidden></p>
				<p class="rh-error" id="rh-modal-error" role="alert" hidden></p></div>
			<footer>${ footer }</footer></div>`;
		if ( ! modal.open ) modal.showModal();
	}

	function modalMessage( id, text ) {
		const el = modal.querySelector( id );
		el.textContent = text;
		el.hidden = ! text;
	}

	function stepsHelp() {
		return `<details class="rh-help"><summary>What can a rehearsal do?</summary>
			<p><b>Steps:</b> ${ data.steps.map( ( s ) => `<code>${ s }</code>` ).join( ' ' ) }</p>
			<p><b><code>expect</code> matchers</b> (plus optional <code>"not": true</code>): ${ data.matchers.map( ( m ) => `<code>${ m }</code>` ).join( ' ' ) }</p>
			<p><b>Any step</b> takes <code>"optional": true</code> and <code>"timeout"</code> in ms. <code>{{run}}</code> becomes a value unique to each run.</p>
			<p><b><code>"requires"</code></b> lists plugin slugs (folder names, or text domains) that must be active, such as <code>["jetpack"]</code>. Without them the rehearsal is shown but can't run.</p>
			<p><b><code>"theme"</code></b> names the theme that must be active (or one of a list), such as <code>"twentytwentyfour"</code>; a child of it counts.</p>
			<p><b><code>"role"</code></b> runs the rehearsal as a test user with that role, such as <code>"editor"</code> (created as <code>rehearsal-editor</code> the first time). Without it, it runs as you.</p>
			<p><b><code>"format"</code></b> is the rehearsal format it was written in, added when it's saved. A rehearsal from a newer version of Rehearsal than this site's still runs, with a warning.</p>
			<p><b>Page loads</b> are measured only when the rehearsal sets <code>"budgets"</code>, e.g. <code>{ "lcp": 2500, "weight": 2097152 }</code>. Pages over a limit get a notice, never a failure. Keys: ${ data.budgetKeys.map( ( k ) => `<code>${ k }</code>` ).join( ' ' ) }; times in ms, CLS unitless, sizes in compressed bytes.</p>
			<p><b>Selectors:</b> CSS, <code>text=…</code>, <code>role=…</code>, <code>:has-text("…")</code>, and <code>a &gt;&gt; b</code> to look inside <code>a</code> or inside an iframe.</p>
			<p><b><code>"covers"</code></b> labels what the rehearsal exercises, such as <code>["packages/forms"]</code> or <code>["src/checkout/*"]</code>. A CI job can match them against what a change touched and run only the rehearsals that apply. Leave it out for a rehearsal you only run by hand.</p>
		</details>`;
	}

	function openRecipe( r ) {
		const [ cls, label ] = r ? statusOf( r ) : [ '', '' ];
		const info = r
			? `<dl class="rh-m-info">
				<dt>Rehearsal ID</dt><dd><code>${ short( r.hash ) }</code></dd>
				<dt>Format</dt><dd>${ r.format }${ r.newer ? ` <span class="rh-error">${ esc( newerText( r.format ) ) }</span>` : '' }</dd>
				${ r.recipe.requires ? `<dt>Requires plugins</dt><dd>${ esc( r.recipe.requires.join( ', ' ) ) }</dd>` : '' }
				${ r.recipe.theme ? `<dt>Requires theme</dt><dd>${ esc( [].concat( r.recipe.theme ).join( ' or ' ) ) }</dd>` : '' }
				${ r.recipe.role ? `<dt>Runs as</dt><dd>${ esc( r.recipe.role ) } (test user rehearsal-${ esc( r.recipe.role ) })</dd>` : '' }
				${ blocked( r ) ? `<dt>Can't run here</dt><dd>${ needsPill( r ) }</dd>` : '' }
				<dt>Last result</dt><dd><span class="rh-pill ${ cls }">${ label }</span>
					<button type="button" class="button-link" data-runs-for="${ r.id }">See all runs</button></dd>
				${ r.source ? `<dt>Installed from</dt><dd><a href="${ esc( r.source ) }" target="_blank" rel="noopener">${ esc( r.source ) }</a></dd>` : '' }
			</dl>
			<div class="rh-share">
				<label for="rh-m-share">Share</label>
				<div class="rh-share-link"><input id="rh-m-share" type="text" readonly value="${ esc( r.shareUrl ) }">
					<button type="button" class="button" data-copy-share>Copy link</button></div>
				<div class="rh-share-as"><span>Copy as:</span>
					<button type="button" class="button-link" data-copy-gh title="Summary and folded JSON, for a PR description">GitHub</button>
					<span aria-hidden="true">·</span>
					<button type="button" class="button-link" data-copy-raw title="The steps as JSON. For scripts, the link plus &amp;format=json returns the same.">JSON</button>
					<span aria-hidden="true">·</span>
					<button type="button" class="button-link" data-copy-pw title="A standalone .spec.ts file">Playwright test</button>
				</div>
				<p class="rh-muted">Anyone with the link can view and install this rehearsal. Copies use the saved version.</p>
			</div>`
			: '';
		showModal(
			r ? r.title : 'New rehearsal',
			`${ info }
			<label class="rh-field" for="rh-m-title">Title</label>
			<input id="rh-m-title" type="text" class="widefat" value="${ esc( r ? r.title : '' ) }" placeholder="What this checks, e.g. Forms: responses list loads">
			<label class="rh-field" for="rh-m-json">Steps (JSON)</label>
			<textarea id="rh-m-json" rows="16" spellcheck="false">${ esc( JSON.stringify( r ? r.recipe : RECIPE_TEMPLATE, null, 2 ) ) }</textarea>
			${ stepsHelp() }`,
			`${ r ? '<button type="button" class="button-link button-link-delete" data-delete>Delete rehearsal</button>' : '' }
			<span class="rh-spacer"></span>
			<button type="button" class="button" data-close>Cancel</button>
			${ r ? `<button type="button" class="button" data-run-one ${ state.running || blocked( r ) ? 'disabled' : '' }>Save and run</button>` : '' }
			<button type="button" class="button button-primary" data-save>Save</button>`
		);
		modal.dataset.recipe = r ? r.id : '';
	}

	const RESULT_WORDS = { pass: 'Passed', fail: 'Failed', queued: 'Needs Playwright (not run)' };

	function recipeJson( r ) {
		return JSON.stringify( r.recipe, null, 2 );
	}

	// For a PR description: a summary that reads well on GitHub, with the recipe folded away below it.
	function recipeMarkdown( r ) {
		const steps = r.recipe.steps.length;
		const facts = [
			`Rehearsal \`${ short( r.hash ) }\``,
			`${ steps } step${ steps === 1 ? '' : 's' }`,
			r.recipe.covers ? `covers ${ r.recipe.covers.map( ( c ) => `\`${ c }\`` ).join( ', ' ) }` : 'manual only',
		];
		const last =
			r.lastRun && r.lastRun.hash === r.hash
				? `Last run: **${ RESULT_WORDS[ r.lastRun.status ] || r.lastRun.status }** on ${ r.lastRun.build }, run #${ r.lastRun.id }, ${ r.lastRun.date.slice( 0, 10 ) }.`
				: 'Not run since its last change.';
		return [
			'<!-- rehearsal v1 -->',
			`#### Rehearsal: ${ r.title }`,
			'',
			`${ facts.join( ' · ' ) }  `,
			`${ last }  `,
			`[View or install this rehearsal](${ r.shareUrl }) · [JSON](${ r.shareUrl }&format=json) · [Playwright test](${ r.shareUrl }&format=playwright) (links work while ${ new URL( data.homeUrl ).host } exists)`,
			'',
			'<details>',
			'<summary>Rehearsal JSON</summary>',
			'',
			'```json',
			recipeJson( r ),
			'```',
			'',
			'</details>',
		].join( '\n' );
	}

	function showRunsFor( changes ) {
		if ( modal.open ) modal.close();
		filterRuns( changes );
		document.getElementById( 'rh-runs-heading' ).scrollIntoView( { behavior: 'smooth' } );
	}

	function upsertRecipe( saved ) {
		const existing = state.recipes.find( ( r ) => r.id === saved.id );
		if ( existing ) {
			Object.assign( existing, saved );
		} else {
			state.recipes.push( { ...saved, checked: ! blocked( saved ), open: false, live: null } );
		}
		state.recipes.sort( ( a, b ) => a.title.localeCompare( b.title ) );
		renderRecipes();
		renderFilterOptions();
		return state.recipes.find( ( r ) => r.id === saved.id );
	}

	async function saveFromModal() {
		modalMessage( '#rh-modal-error', '' );
		let recipe;
		try {
			recipe = JSON.parse( modal.querySelector( '#rh-m-json' ).value );
		} catch ( e ) {
			modalMessage( '#rh-modal-error', `The steps are not valid JSON: ${ e.message }` );
			return null;
		}
		const id = modal.dataset.recipe;
		try {
			const saved = await apiFetch( {
				path: id ? `/rehearsal/v1/rehearsals/${ id }` : '/rehearsal/v1/rehearsals',
				method: 'POST',
				data: { title: modal.querySelector( '#rh-m-title' ).value, recipe },
			} );
			return upsertRecipe( saved );
		} catch ( e ) {
			modalMessage( '#rh-modal-error', e.message || 'Could not save the rehearsal.' );
			return null;
		}
	}

	function openInstall( url ) {
		showModal(
			'Install a rehearsal from a link',
			`<label class="rh-field" for="rh-m-url">Rehearsal link</label>
			<input id="rh-m-url" type="url" class="widefat" value="${ esc( url || '' ) }" placeholder="https://some-site.jurassic.ninja/?rehearsal=…">
			<p class="rh-muted">Paste a share link from any site running Rehearsal. You'll see the rehearsal before anything is saved.</p>
			<div id="rh-m-preview"></div>`,
			`<span class="rh-spacer"></span>
			<button type="button" class="button" data-close>Cancel</button>
			<button type="button" class="button" data-preview>Preview</button>
			<button type="button" class="button button-primary" data-do-install disabled>Install</button>`
		);
		modal.dataset.recipe = '';
		if ( url ) previewInstall();
	}

	// The selected rehearsals as one Playwright file, with the steps to run it from a terminal or CI.
	async function openPlaywright() {
		const close = '<span class="rh-spacer"></span><button type="button" class="button" data-close>Close</button>';
		const picked = state.recipes.filter( ( r ) => r.checked );
		if ( ! picked.length ) {
			showModal( 'Run in Playwright', '<p>Select the rehearsals to export with the checkboxes in the list, then try again.</p>', close );
			return;
		}
		showModal( 'Run in Playwright', '<p class="rh-muted">Preparing the test file…</p>', close );
		let url;
		try {
			url = ( await apiFetch( { path: '/rehearsal/v1/bundle', method: 'POST', data: { ids: picked.map( ( r ) => r.id ) } } ) ).url;
		} catch ( e ) {
			modalMessage( '#rh-modal-error', e.message || 'Could not prepare the test file.' );
			return;
		}
		const site = data.homeUrl;
		const roles = [ ...new Set( picked.map( ( r ) => r.recipe.role ).filter( ( role ) => role && role !== 'administrator' ) ) ];
		const env = ( role ) => 'STORAGE_STATE_' + role.toUpperCase().replace( /-/g, '_' );
		const cmd = ( text ) => `<div class="rh-cmd"><code>${ esc( text ) }</code><button type="button" class="button button-small" data-copy-cmd>Copy</button></div>`;
		const run = [ 'STORAGE_STATE=auth.json', ...roles.map( ( role ) => `${ env( role ) }=auth-${ role }.json` ), 'npx playwright test rehearsals.spec.ts' ].join( ' ' );
		const roleLogins = roles
			.map( ( role ) => `<p class="rh-muted">Some rehearsals run as <b>${ esc( role ) }</b>, so save a session for a user with that role too:</p>${ cmd( `npx playwright open --save-storage=auth-${ role }.json ${ site }wp-login.php` ) }` )
			.join( '' );
		showModal(
			`Run ${ picked.length } rehearsal${ picked.length === 1 ? '' : 's' } in Playwright`,
			`<p>The selected rehearsals as one Playwright test file, to run from your computer or CI.</p>
			<ol class="rh-steps">
				<li><b>Get the tests.</b> <a class="button button-primary" href="${ esc( url ) }&amp;download=1">Download rehearsals.spec.ts</a>
					<span class="rh-muted">or fetch them, for example in CI:</span>${ cmd( `curl -o rehearsals.spec.ts "${ url }"` ) }</li>
				<li><b>Install Playwright</b>, once, in the same folder:${ cmd( 'npm i -D @playwright/test && npx playwright install chromium' ) }</li>
				<li><b>Save a logged-in session</b>, once: log in as an administrator in the browser that opens, then close it.${ cmd( `npx playwright open --save-storage=auth.json ${ site }wp-login.php` ) }${ roleLogins }</li>
				<li><b>Run them.</b>${ cmd( run ) }
					<p class="rh-muted">Add <code>--headed</code> to watch them, <code>BASE_URL=https://another-site/</code> to test another site, and see the results with <code>npx playwright show-report</code>.</p></li>
			</ol>
			<p class="rh-muted">The link returns exactly these rehearsals while this site exists. Rehearsals that change the site change it in Playwright too.</p>`,
			close
		);
	}

	// What the latest preview showed; Install installs exactly that, or the server refuses.
	let previewed = null;
	let previewRequest = 0;

	async function previewInstall() {
		const mine = ++previewRequest;
		previewed = null;
		modalMessage( '#rh-modal-error', '' );
		const box = modal.querySelector( '#rh-m-preview' );
		const button = modal.querySelector( '[data-do-install]' );
		button.disabled = true;
		box.innerHTML = '<p class="rh-muted">Fetching the rehearsal…</p>';
		const url = modal.querySelector( '#rh-m-url' ).value.trim();
		try {
			const p = await apiFetch( {
				path: '/rehearsal/v1/install',
				method: 'POST',
				data: { url, dry_run: true },
			} );
			if ( mine !== previewRequest ) return; // A later preview replaced this one.
			previewed = { url, id: p.id };
			box.innerHTML = `<dl class="rh-m-info">
					<dt>Title</dt><dd>${ esc( p.title ) }</dd>
					<dt>Rehearsal ID</dt><dd><code>${ short( p.id ) }</code></dd>
					<dt>Steps</dt><dd>${ p.recipe.steps.length }</dd>
					<dt>Runs in CI when</dt><dd>${ p.recipe.covers ? esc( p.recipe.covers.join( ', ' ) ) + ' changes' : 'never: manual only' }</dd>
					<dt>Shared from</dt><dd>${ esc( p.site ) } (${ esc( p.build ) })</dd>
					<dt>Requires plugins</dt><dd>${ p.recipe.requires ? esc( p.recipe.requires.join( ', ' ) ) : 'None' }</dd>
					<dt>Requires theme</dt><dd>${ p.recipe.theme ? esc( [].concat( p.recipe.theme ).join( ' or ' ) ) : 'Any' }</dd>
					<dt>Runs as</dt><dd>${ p.recipe.role ? esc( p.recipe.role ) : 'you (the logged-in admin)' }</dd>
					<dt>Format</dt><dd>${ p.format }${ p.shared_by ? ` (shared by a site running Rehearsal ${ esc( p.shared_by ) })` : '' }</dd>
				</dl>
				${ p.missing && p.missing.length ? `<p class="rh-error">This site is missing what it needs: ${ esc( p.missing.map( ( m ) => ( m.kind === 'role' ? `the "${ m.name }" role` : `${ m.kind === 'theme' ? 'theme ' : '' }${ m.name } (${ m.state === 'inactive' ? 'not active' : 'not installed' })` ) ).join( ', ' ) ) }. You can install it, but it won't run until they are active.</p>` : '' }
				${ p.newer ? `<p class="rh-error">${ esc( newerText( p.format ) ) }</p>` : '' }
				${ p.updates ? '<p class="rh-modal-note">You installed this link before. Installing again replaces your copy with this version.</p>' : '' }
				<details><summary>Steps</summary><pre class="rh-m-pre">${ esc( JSON.stringify( p.recipe, null, 2 ) ) }</pre></details>`;
			button.disabled = false;
			button.textContent = p.updates ? 'Update my copy' : 'Install';
		} catch ( e ) {
			if ( mine !== previewRequest ) return;
			box.innerHTML = '';
			modalMessage( '#rh-modal-error', e.message || 'Could not fetch that link.' );
		}
	}

	async function doInstall() {
		modalMessage( '#rh-modal-error', '' );
		if ( ! previewed ) return;
		try {
			const saved = await apiFetch( {
				path: '/rehearsal/v1/install',
				method: 'POST',
				data: { url: previewed.url, expect: previewed.id },
			} );
			const r = upsertRecipe( saved );
			openRecipe( r );
			modalMessage( '#rh-modal-note', saved.updated ? 'Updated your copy to this version.' : 'Installed. Run it from here or from the list.' );
		} catch ( e ) {
			modalMessage( '#rh-modal-error', e.message || 'Could not install the rehearsal.' );
		}
	}

	modal.addEventListener( 'click', async ( e ) => {
		if ( e.target === modal ) return modal.close(); // Backdrop.
		const t = e.target.closest( 'button' );
		if ( ! t || t.disabled ) return;
		const id = Number( modal.dataset.recipe );
		// REST calls on slow hosts can take seconds; show that the click registered.
		const busy = ( label ) => {
			const old = t.textContent;
			t.disabled = true;
			t.textContent = label;
			return () => {
				t.disabled = false;
				t.textContent = old;
			};
		};
		if ( t.matches( '[data-close]' ) ) modal.close();
		if ( t.matches( '[data-copy-share]' ) ) copy( modal.querySelector( '#rh-m-share' ).value, t );
		if ( t.matches( '[data-copy-cmd]' ) ) copy( t.previousElementSibling.textContent, t );
		const current = state.recipes.find( ( r ) => r.id === id );
		if ( t.matches( '[data-copy-gh]' ) ) copy( recipeMarkdown( current ), t );
		if ( t.matches( '[data-copy-raw]' ) ) copy( recipeJson( current ), t );
		if ( t.matches( '[data-copy-pw]' ) ) copyFetched( `${ current.shareUrl }&format=playwright`, t );
		if ( t.matches( '[data-runs-for]' ) ) showRunsFor( { recipe: t.dataset.runsFor } );
		if ( t.matches( '[data-preview]' ) ) previewInstall();
		if ( t.matches( '[data-do-install]' ) ) {
			const done = busy( 'Installing…' );
			await doInstall();
			done();
		}
		if ( t.matches( '[data-save]' ) ) {
			const done = busy( 'Saving…' );
			const r = await saveFromModal();
			done();
			if ( r ) {
				openRecipe( r );
				modalMessage( '#rh-modal-note', `Saved. Rehearsal ID is now ${ short( r.hash ) }.` );
			}
		}
		if ( t.matches( '[data-run-one]' ) ) {
			const r = await saveFromModal();
			if ( r ) {
				modal.close();
				runRecipes( [ r ] );
			}
		}
		if ( t.matches( '[data-delete]' ) ) {
			if ( ! t.dataset.armed ) {
				t.dataset.armed = '1';
				t.textContent = 'Click again to delete';
				return;
			}
			const done = busy( 'Deleting…' );
			try {
				await apiFetch( { path: `/rehearsal/v1/rehearsals/${ id }`, method: 'DELETE' } );
				state.recipes = state.recipes.filter( ( r ) => r.id !== id );
				renderRecipes();
				renderFilterOptions();
				modal.close();
			} catch ( err ) {
				done();
				modalMessage( '#rh-modal-error', err.message || 'Could not delete the rehearsal.' );
			}
		}
	} );
	modal.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Enter' && e.target.id === 'rh-m-url' ) {
			e.preventDefault();
			previewInstall();
		}
	} );
	modal.addEventListener( 'input', ( e ) => {
		if ( e.target.id === 'rh-m-url' && previewed && e.target.value.trim() !== previewed.url ) {
			previewed = null;
			modal.querySelector( '[data-do-install]' ).disabled = true;
		}
	} );
	document.getElementById( 'rh-add' ).addEventListener( 'click', () => openRecipe( null ) );
	document.getElementById( 'rh-install' ).addEventListener( 'click', () => openInstall( '' ) );
	document.getElementById( 'rh-playwright' ).addEventListener( 'click', openPlaywright );

	let searchTimer;
	document.getElementById( 'rh-f-search' ).addEventListener( 'input', ( e ) => {
		clearTimeout( searchTimer );
		searchTimer = setTimeout( () => filterRuns( { search: e.target.value.trim() } ), 300 );
	} );
	[
		[ 'rh-f-recipe', 'recipe' ],
		[ 'rh-f-status', 'status' ],
		[ 'rh-f-user', 'user' ],
	].forEach( ( [ id, key ] ) => document.getElementById( id ).addEventListener( 'change', ( e ) => filterRuns( { [ key ]: e.target.value } ) ) );
	document.getElementById( 'rh-f-clear' ).addEventListener( 'click', () => filterRuns( { search: '', recipe: '', status: '', user: '' } ) );
	document.getElementById( 'rh-more' ).addEventListener( 'click', () => loadRuns( true ) );

	root.addEventListener( 'click', ( e ) => {
		const runsFor = e.target.closest( '[data-runs-for]' );
		if ( runsFor && ! modal.contains( runsFor ) ) showRunsFor( { recipe: runsFor.dataset.runsFor } );
		const byUser = e.target.closest( '[data-filter-user]' );
		if ( byUser ) showRunsFor( { user: byUser.dataset.filterUser } );
		const runRow = e.target.closest( '[data-run-row]' );
		if ( runRow && ! runRow.disabled ) runRecipes( [ state.recipes[ +runRow.dataset.runRow ] ] );
		const opener = e.target.closest( '[data-open]' );
		if ( opener ) openRecipe( state.recipes[ +opener.dataset.open ] );
		const t = e.target.closest( '[data-toggle]' );
		if ( t ) {
			const r = state.recipes[ +t.dataset.toggle ];
			r.open = ! r.open;
			renderRecipes();
		}
		const fromRecipe = e.target.closest( '[data-copy-recipe]' );
		if ( fromRecipe ) {
			const r = state.recipes[ +fromRecipe.dataset.copyRecipe ];
			const run = r.live ? null : r.lastRun;
			copy( errorsText( r.live ? r.live.errors : run.errors, run, r.title ), fromRecipe );
		}
		const fromRun = e.target.closest( '[data-copy-run]' );
		if ( fromRun ) {
			const run = state.runs.find( ( x ) => String( x.id ) === fromRun.dataset.copyRun );
			copy( errorsText( run.errors, run, run.title ), fromRun );
		}
	} );

	// ---------- The runner ----------

	function frameDoc() {
		try {
			return frame.contentDocument;
		} catch ( e ) {
			return null;
		}
	}

	function onSiteDoc() {
		const doc = frameDoc();
		if ( ! doc ) {
			throw new Error( 'The frame left this site, so the browser cannot read it. This rehearsal needs the Playwright runner.' );
		}
		return doc;
	}

	function visible( el ) {
		return !! el && !! ( el.offsetWidth || el.offsetHeight || el.getClientRects().length );
	}

	// Every match, visible or not, in document order. `a >> b` finds b inside each a, and inside a's document when a is an iframe.
	function findAll( doc, sel ) {
		let scopes = [ doc ];
		for ( const part of sel.split( /\s+>>\s+/ ) ) {
			const next = new Set();
			for ( const scope of scopes ) {
				const root = scope.tagName === 'IFRAME' ? scope.contentDocument : scope;
				if ( root ) findIn( root, part ).forEach( ( el ) => next.add( el ) );
			}
			scopes = [ ...next ];
		}
		return scopes;
	}

	function findIn( root, sel ) {
		const base = root.body || root;
		if ( sel.startsWith( 'text=' ) ) {
			const want = norm( sel.slice( 5 ) );
			const found = new Set();
			const walker = ( root.ownerDocument || root ).createTreeWalker( base, 4 /* NodeFilter.SHOW_TEXT */ );
			for ( let n = walker.nextNode(); n; n = walker.nextNode() ) {
				if ( norm( n.nodeValue ).includes( want ) ) found.add( n.parentElement );
			}
			base.querySelectorAll( 'input[type=submit], input[type=button]' ).forEach( ( el ) => norm( el.value ).includes( want ) && found.add( el ) );
			base.querySelectorAll( '[aria-label]' ).forEach( ( el ) => norm( el.getAttribute( 'aria-label' ) ).includes( want ) && found.add( el ) );
			return [ ...found ];
		}
		let css = sel;
		let hasText = null;
		// Like Playwright's :has-text(), but only on the last compound selector of a part.
		const m = css.match( /:has-text\((["'])(.*?)\1\)/ );
		if ( m ) {
			hasText = norm( m[ 2 ] );
			css = css.replace( m[ 0 ], '' ) || '*';
		}
		if ( css.startsWith( 'role=' ) ) {
			const role = css.slice( 5 );
			css = `[role="${ CSS.escape( role ) }"]` + ( IMPLICIT_ROLES[ role ] ? `, ${ IMPLICIT_ROLES[ role ] }` : '' );
		}
		// Query the document itself, not its body, so `body` and `html` can match.
		const els = [ ...root.querySelectorAll( css ) ];
		return hasText === null ? els : els.filter( ( el ) => norm( el.innerText || el.textContent ).includes( hasText ) );
	}

	function find( doc, sel ) {
		return findAll( doc, sel ).find( visible ) || null;
	}

	function isDisabled( el ) {
		return !! ( el.disabled || el.getAttribute( 'aria-disabled' ) === 'true' || el.closest( 'fieldset[disabled]' ) );
	}

	// Labels and wrappers resolve to the checkbox or radio they control.
	function toggleTarget( el ) {
		if ( el.matches( 'input[type=checkbox], input[type=radio]' ) ) return el;
		return el.control || el.querySelector( 'input[type=checkbox], input[type=radio]' ) || el;
	}

	const MATCHERS = {
		toBeVisible: () => ( els ) => els.some( visible ),
		toHaveCount: ( n ) => ( els ) => els.length === n,
		toHaveText: ( text ) => ( els, el ) => !! el && norm( el.innerText ) === norm( text ),
		toContainText: ( text ) => ( els, el ) => !! el && norm( el.innerText ).includes( norm( text ) ),
		toBeEnabled: () => ( els, el ) => !! el && ! isDisabled( el ),
		toBeDisabled: () => ( els, el ) => !! el && isDisabled( el ),
		toBeChecked: () => ( els, el ) => !! el && !! toggleTarget( el ).checked,
		toHaveValue: ( value ) => ( els, el ) => !! el && String( el.value ) === String( value ),
	};

	// What the page showed, for timeout messages.
	function actual( name, els, el ) {
		if ( name === 'toHaveCount' ) return `${ els.length } found`;
		if ( name === 'toBeVisible' ) return els.length ? `${ els.length } found, none visible` : 'no match';
		if ( ! el ) return 'no visible match';
		if ( name === 'toHaveText' || name === 'toContainText' ) return `“${ norm( el.innerText ).slice( 0, 120 ) }”`;
		if ( name === 'toHaveValue' ) return `“${ el.value }”`;
		if ( name === 'toBeChecked' ) return toggleTarget( el ).checked ? 'checked' : 'unchecked';
		return isDisabled( el ) ? 'disabled' : 'enabled';
	}

	function keyEvent( win, type, combo ) {
		const parts = combo.split( '+' );
		const key = parts.pop();
		const mods = parts.map( ( m ) => m.toLowerCase() );
		return new win.KeyboardEvent( type, {
			key: key === 'Space' ? ' ' : key,
			code: key.length === 1 ? 'Key' + key.toUpperCase() : key,
			bubbles: true,
			cancelable: true,
			ctrlKey: mods.includes( 'control' ),
			metaKey: mods.includes( 'meta' ),
			shiftKey: mods.includes( 'shift' ),
			altKey: mods.includes( 'alt' ),
		} );
	}

	// Synthetic key events don't trigger browser defaults, so emulate the few rehearsals rely on.
	function keyDefault( doc, el, combo ) {
		const key = combo.split( '+' ).pop();
		const selectAll = /^(control|meta)\+a$/i.test( combo );
		if ( selectAll && typeof el.select === 'function' ) {
			el.select();
		} else if ( key === 'Enter' && el.matches( 'input:not([type=checkbox]):not([type=radio])' ) && el.form ) {
			el.form.requestSubmit();
		} else if ( ( key === 'Enter' || key === 'Space' || key === ' ' ) && el.matches( 'button, a[href], input[type=submit], input[type=button], input[type=checkbox], input[type=radio], [role=button]' ) ) {
			el.click();
		} else if ( key === 'Tab' ) {
			const focusable = [ ...doc.querySelectorAll( 'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])' ) ].filter(
				( f ) => visible( f ) && ! f.disabled
			);
			const next = focusable[ focusable.indexOf( el ) + ( combo.startsWith( 'Shift' ) ? -1 : 1 ) ];
			if ( next ) next.focus();
		}
	}

	// Copies new recorder entries into the run, so errors survive the page navigating away.
	function harvest( run ) {
		const doc = frameDoc();
		const win = frame.contentWindow;
		if ( ! doc || ! win || ! win.__rehearsal ) return;
		const from = win.__rehearsalSeen || 0;
		win.__rehearsal.errors
			.slice( from )
			.forEach( ( e ) => run.errors.push( { page: win.location.pathname + win.location.search, msg: e.msg, source: e.source } ) );
		win.__rehearsalSeen = win.__rehearsal.errors.length;
		// One metrics object per document (the frame keeps its window across navigations); it keeps updating until the recorder freezes it after load.
		const m = win.__rehearsal.metrics && win.__rehearsal.metrics();
		if ( m && run.pages ) {
			if ( run.pageDoc !== doc ) {
				run.pageDoc = doc;
				const page = { ...m };
				run.pages.push( page );
				run.newPages.push( page );
			} else if ( run.pages.length ) {
				// Same document as before; nothing to update when it's the page a previous rehearsal left behind.
				Object.assign( run.pages[ run.pages.length - 1 ], m );
			}
		}
	}


	function assess( page, budget ) {
		page.warn = Object.keys( budget ).filter( ( k ) => page[ k ] !== null && page[ k ] !== undefined && page[ k ] > budget[ k ] );
		page.limits = Object.fromEntries( page.warn.map( ( k ) => [ k, budget[ k ] ] ) );
	}

	async function until( run, fn, label, timeout ) {
		let spent = 0;
		let last = performance.now();
		for ( ;; ) {
			harvest( run );
			const value = fn();
			if ( value ) return value;
			const now = performance.now();
			// Browsers throttle background tabs; only count time while this tab is visible.
			if ( ! document.hidden ) spent += now - last;
			last = now;
			if ( spent > timeout ) {
				const err = new Error( `Timed out after ${ timeout / 1000 } s waiting for ${ label }` );
				err.timeout = true;
				throw err;
			}
			await sleep( 100 );
		}
	}

	function load( url, timeout ) {
		return new Promise( ( resolve, reject ) => {
			const timer = setTimeout( () => {
				frame.onload = null;
				reject( new Error( `Timed out after ${ timeout / 1000 } s loading ${ url }` ) );
			}, timeout );
			frame.onload = () => {
				clearTimeout( timer );
				frame.onload = null;
				resolve();
			};
			frame.src = url;
		} );
	}

	function resolve( path ) {
		const url = path.startsWith( '/wp-admin/' )
			? data.adminUrl + path.slice( '/wp-admin/'.length )
			: data.homeUrl + path.replace( /^\//, '' );
		if ( new URL( url ).origin !== window.location.origin ) {
			throw new Error( `${ url } is on another domain than wp-admin. This rehearsal needs the Playwright runner.` );
		}
		return url;
	}

	async function exec( run, step, timeout ) {
		if ( 'goto' in step ) {
			const url = resolve( step.goto );
			harvest( run );
			document.getElementById( 'rh-frame-url' ).textContent = url;
			document.getElementById( 'rh-frame-idle' ).hidden = true;
			// Going through about:blank forces a real load even when only the hash differs.
			await load( 'about:blank', timeout );
			await load( url, timeout );
			const doc = onSiteDoc();
			if ( /\/wp-login\.php/.test( frame.contentWindow.location.pathname ) ) {
				throw new Error( 'Redirected to the login screen. Are you still logged in?' );
			}
			// When measuring, give LCP and layout shifts time to settle; a page that never finishes is measured as it is.
			if ( run.pages ) await until( run, () => frame.contentWindow.__rehearsal?.metrics?.().final, 'page metrics', 4000 ).catch( () => {} );
			harvest( run );
			return `goto ${ step.goto } (${ doc.title || 'untitled' })`;
		}
		if ( 'waitFor' in step ) {
			await until( run, () => find( onSiteDoc(), step.waitFor ), step.waitFor, timeout );
			return `waitFor ${ step.waitFor }`;
		}
		if ( 'expectText' in step ) {
			const want = norm( step.expectText );
			await until(
				run,
				() => {
					const doc = onSiteDoc();
					const scope = step.in ? find( doc, step.in ) : doc.body;
					return scope && norm( scope.innerText ).includes( want );
				},
				`text “${ step.expectText }”${ step.in ? ' in ' + step.in : '' }`,
				timeout
			);
			return `expectText “${ step.expectText }”`;
		}
		if ( 'expectCount' in step ) {
			const min = step.min ?? 1;
			const count = () => findAll( onSiteDoc(), step.expectCount ).length;
			await until( run, () => count() >= min, `at least ${ min } × ${ step.expectCount }`, timeout );
			return `expectCount ${ step.expectCount } ≥ ${ min } (found ${ count() })`;
		}
		if ( 'fill' in step ) {
			const el = await until( run, () => find( onSiteDoc(), step.fill ), step.fill, timeout );
			const win = el.ownerDocument.defaultView;
			el.focus( { preventScroll: true } );
			// React tracks the value itself, so a plain `el.value =` is ignored; go through the native setter.
			Object.getOwnPropertyDescriptor( Object.getPrototypeOf( el ), 'value' ).set.call( el, String( step.value ?? '' ) );
			el.dispatchEvent( new win.Event( 'input', { bubbles: true } ) );
			el.dispatchEvent( new win.Event( 'change', { bubbles: true } ) );
			return `fill ${ step.fill } = “${ step.value }”`;
		}
		if ( 'click' in step ) {
			const el = await until( run, () => find( onSiteDoc(), step.click ), step.click, timeout );
			const link = el.closest( 'a[target="_blank"][href]' );
			if ( link ) {
				// A new tab would escape the runner, so load the link in the frame like Playwright following a popup.
				if ( new URL( link.href ).origin !== window.location.origin ) {
					throw new Error( `${ link.href } opens another site in a new tab. This rehearsal needs the Playwright runner.` );
				}
				harvest( run );
				document.getElementById( 'rh-frame-url' ).textContent = link.href;
				await load( link.href, timeout );
				return `click ${ step.click } (new-tab link loaded in the frame)`;
			}
			el.click();
			return `click ${ step.click }`;
		}
		if ( 'expect' in step ) {
			const name = Object.keys( MATCHERS ).find( ( m ) => m in step );
			if ( ! name ) throw new Error( `"expect" needs one of: ${ Object.keys( MATCHERS ).join( ', ' ) }` );
			const test = MATCHERS[ name ]( step[ name ] );
			const arg = typeof step[ name ] === 'boolean' ? '' : ` ${ JSON.stringify( step[ name ] ) }`;
			const label = `expect ${ step.expect }${ step.not ? ' not' : '' } ${ name }${ arg }`;
			let seen = '';
			try {
				await until(
					run,
					() => {
						const els = findAll( onSiteDoc(), step.expect );
						const el = els.find( visible ) || null;
						seen = actual( name, els, el );
						return test( els, el ) !== !! step.not;
					},
					label,
					timeout
				);
			} catch ( e ) {
				throw e.timeout ? new Error( `${ e.message } (page showed: ${ seen })` ) : e;
			}
			return label;
		}
		if ( 'check' in step || 'uncheck' in step ) {
			const want = 'check' in step;
			const sel = want ? step.check : step.uncheck;
			const el = toggleTarget( await until( run, () => find( onSiteDoc(), sel ), sel, timeout ) );
			if ( ! el.matches( 'input[type=checkbox], input[type=radio]' ) ) throw new Error( `${ sel } is not a checkbox or radio button` );
			if ( ! want && el.type === 'radio' ) throw new Error( `${ sel } is a radio button, which cannot be unchecked` );
			if ( el.checked !== want ) el.click();
			if ( el.checked !== want ) throw new Error( `${ sel } is still ${ el.checked ? 'checked' : 'unchecked' } after clicking it` );
			return `${ want ? 'check' : 'uncheck' } ${ sel }`;
		}
		if ( 'selectOption' in step ) {
			const el = await until( run, () => find( onSiteDoc(), step.selectOption ), step.selectOption, timeout );
			if ( el.tagName !== 'SELECT' ) throw new Error( `${ step.selectOption } is not a <select>` );
			const want = String( step.value );
			const option = [ ...el.options ].find( ( o ) => o.value === want ) || [ ...el.options ].find( ( o ) => norm( o.text ) === norm( want ) );
			if ( ! option ) throw new Error( `${ step.selectOption } has no option with value or label “${ want }”` );
			const win = el.ownerDocument.defaultView;
			Object.getOwnPropertyDescriptor( win.HTMLSelectElement.prototype, 'value' ).set.call( el, option.value );
			el.dispatchEvent( new win.Event( 'input', { bubbles: true } ) );
			el.dispatchEvent( new win.Event( 'change', { bubbles: true } ) );
			return `selectOption ${ step.selectOption } = “${ option.text.trim() }”`;
		}
		if ( 'hover' in step ) {
			const el = await until( run, () => find( onSiteDoc(), step.hover ), step.hover, timeout );
			const win = el.ownerDocument.defaultView;
			const r = el.getBoundingClientRect();
			const at = { bubbles: true, cancelable: true, clientX: r.left + r.width / 2, clientY: r.top + r.height / 2 };
			[ 'pointerover', 'pointerenter', 'pointermove' ].forEach( ( t ) =>
				el.dispatchEvent( new win.PointerEvent( t, { ...at, bubbles: t !== 'pointerenter' } ) )
			);
			[ 'mouseover', 'mouseenter', 'mousemove' ].forEach( ( t ) => el.dispatchEvent( new win.MouseEvent( t, { ...at, bubbles: t !== 'mouseenter' } ) ) );
			return `hover ${ step.hover }`;
		}
		if ( 'press' in step ) {
			const doc = onSiteDoc();
			const el = step.on ? await until( run, () => find( onSiteDoc(), step.on ), step.on, timeout ) : doc.activeElement || doc.body;
			if ( step.on ) el.focus( { preventScroll: true } );
			const win = el.ownerDocument.defaultView;
			const down = el.dispatchEvent( keyEvent( win, 'keydown', step.press ) );
			if ( down ) keyDefault( el.ownerDocument, el, step.press );
			el.dispatchEvent( keyEvent( win, 'keyup', step.press ) );
			return `press ${ step.press }${ step.on ? ' on ' + step.on : '' }`;
		}
		if ( 'expectNoConsoleErrors' in step ) {
			harvest( run );
			if ( ! frame.contentWindow.__rehearsal ) {
				throw new Error( 'This page has no error recorder, so console errors cannot be checked (it needs wp_head or wp-admin, and an admin login).' );
			}
			const ignore = ( ( step.expectNoConsoleErrors && step.expectNoConsoleErrors.ignore ) || [] ).map( ( p ) => new RegExp( p ) );
			const errors = run.errors.filter( ( e ) => ! ignore.some( ( re ) => re.test( e.msg ) ) );
			if ( errors.length ) {
				run.consoleErrors = groupErrors( errors );
				throw new Error(
					`${ errors.length } console error${ errors.length === 1 ? '' : 's' } (${ run.consoleErrors.length } distinct), listed below.`
				);
			}
			return 'expectNoConsoleErrors';
		}
		if ( 'snapshot' in step ) {
			const el = await until( run, () => find( onSiteDoc(), step.snapshot ), step.snapshot, timeout );
			run.snapshots.push( { selector: step.snapshot, html: el.outerHTML.slice( 0, 100000 ) } );
			return `snapshot ${ step.snapshot } (${ Math.round( el.outerHTML.length / 1024 ) } KB)`;
		}
		if ( 'screenshot' in step ) {
			throw new Error( 'The browser cannot take screenshots. Add "needs": "playwright" to this rehearsal.' );
		}
		throw new Error( `Unknown step: ${ Object.keys( step ).join( ', ' ) }` );
	}

	function clearRoleCookie() {
		document.cookie = 'rehearsal_role=; path=/; max-age=0';
	}

	// A run cut short by a reload or a closed tab leaves its cookies behind, so clear them on the way in and out.
	function clearCookies() {
		clearRoleCookie();
		document.cookie = 'rehearsal_frame=; path=/; max-age=0';
	}
	clearCookies();
	window.addEventListener( 'pagehide', clearCookies );

	// Errors the previous rehearsal's page logged after its last step are not this rehearsal's.
	function ignoreEarlierErrors() {
		try {
			const win = frame.contentWindow;
			if ( win && win.__rehearsal ) win.__rehearsalSeen = win.__rehearsal.errors.length;
		} catch ( e ) {}
	}

	async function runRecipe( r ) {
		// Saving an edit mid-run updates r in place; the run keeps the rehearsal it started with.
		const { id, hash, title, recipe, newer, format } = r;
		clearRoleCookie();
		// pageDoc starts as whatever the last rehearsal left in the frame, so it isn't measured as this run's page.
		// Page loads are measured only for a rehearsal with its own budgets; pages: null switches it off.
		const budget = recipe.budgets || null;
		const run = { log: [], errors: [], snapshots: [], pages: budget ? [] : null, newPages: [], pageDoc: frameDoc() };
		// Page loads seen while a step ran are shown under that step's log line.
		const attachPages = ( entry ) => {
			harvest( run );
			if ( ! budget ) return entry;
			if ( run.newPages.length ) entry.pages = run.newPages.splice( 0 );
			run.pages.forEach( ( p ) => assess( p, budget ) );
			return entry;
		};
		resetFrame();
		ignoreEarlierErrors();
		r.live = { status: 'running', log: run.log };
		r.open = true;
		renderRecipes();

		let status = 'pass';
		if ( newer ) run.log.push( { ok: true, msg: `Warning: ${ newerText( format ) }`, ms: 0 } );
		if ( recipe.needs === 'playwright' ) {
			run.log.push( { ok: true, msg: 'Needs Playwright, so the browser did not run it. Saved as waiting for the Playwright runner.', ms: 0 } );
			status = 'queued';
		} else {
			const timeout = recipe.timeout || DEFAULT_TIMEOUT;
			// {{run}} gives each run its own values, so it can find the form, page or response it created.
			const token = Date.now().toString( 36 );
			const steps = JSON.parse( JSON.stringify( recipe.steps ).split( '{{run}}' ).join( token ) );
			if ( JSON.stringify( recipe.steps ).includes( '{{run}}' ) ) {
				run.log.push( { ok: true, msg: `{{run}} = ${ token }`, ms: 0 } );
			}
			// A "role" makes the frame's requests act as that role's test user, until the cookie is cleared below.
			const role = recipe.role && recipe.role !== 'administrator' ? recipe.role : '';
			if ( role ) {
				try {
					const user = await apiFetch( { path: '/rehearsal/v1/role-user', method: 'POST', data: { role } } );
					document.cookie = `rehearsal_role=${ encodeURIComponent( role ) }; path=/; max-age=1800; SameSite=Strict${ window.location.protocol === 'https:' ? '; Secure' : '' }`;
					run.log.push( { ok: true, msg: `Running as ${ user.login } (${ role })`, ms: 0 } );
				} catch ( e ) {
					run.log.push( { ok: false, msg: `Could not prepare the ${ role } test user: ${ e.message }`, ms: 0 } );
					status = 'fail';
				}
			}
			for ( const [ i, step ] of ( status === 'pass' ? steps : [] ).entries() ) {
				document.getElementById( 'rh-frame-step' ).textContent = `${ title } · step ${ i + 1 }/${ steps.length }`;
				const t0 = performance.now();
				try {
					const msg = await exec( run, step, step.timeout || timeout );
					run.log.push( attachPages( { ok: true, msg, ms: Math.round( performance.now() - t0 ) } ) );
				} catch ( e ) {
					const ms = Math.round( performance.now() - t0 );
					if ( step.optional ) {
						run.log.push( attachPages( { ok: true, msg: `Skipped optional step ${ i + 1 }: ${ e.message }`, ms } ) );
						renderRecipes();
						continue;
					}
					run.log.push( attachPages( { ok: false, msg: `Step ${ i + 1 }: ${ e.message }`, ms } ) );
					status = 'fail';
					break;
				}
				renderRecipes();
				await sleep( STEP_PAUSE );
			}
			if ( run.log.length ) attachPages( run.log[ run.log.length - 1 ] );
			clearRoleCookie();
		}

		try {
			const saved = await apiFetch( {
				path: '/rehearsal/v1/runs',
				method: 'POST',
				data: { recipe_id: id, recipe_hash: hash, status, log: run.log, snapshots: run.snapshots, errors: run.consoleErrors || [] },
			} );
			r.lastRun = saved;
			r.live = null;
			r.logCleared = false;
			if ( ! state.runUsers.some( ( u ) => u.id === saved.byId ) ) {
				state.runUsers.push( { id: saved.byId, name: saved.by } );
			}
			if ( hasFilters() ) {
				loadRuns( false );
			} else {
				state.runs.unshift( saved );
				state.runTotal++;
			}
		} catch ( e ) {
			r.live = {
				status: 'unsaved',
				log: run.log,
				errors: run.consoleErrors,
				error: `The run ${ status === 'pass' ? 'passed' : 'finished' } but was not saved: ${ e.message || e.code }`,
			};
		}
		renderRecipes();
		renderHistory();
	}

	runButton.addEventListener( 'click', () => runRecipes( state.recipes.filter( ( r ) => r.checked && ! blocked( r ) ) ) );
	runFailedButton.addEventListener( 'click', () => runRecipes( failedRecipes() ) );

	async function runRecipes( picked ) {
		if ( ! picked.length || state.running ) return;
		state.running = true;
		// Tells the plugin which editor loads come from the runner's frame (see no_isolation_in_frame()).
		document.cookie = `rehearsal_frame=1; path=/; max-age=1800; SameSite=Strict${ window.location.protocol === 'https:' ? '; Secure' : '' }`;
		picked.forEach( ( r ) => {
			r.live = null;
			r.logCleared = true;
			r.open = false;
		} );
		renderRecipes();
		try {
			for ( const r of picked ) {
				try {
					await runRecipe( r );
				} catch ( e ) {
					// A bug in the runner must fail this rehearsal, not leave the page stuck on "Running…".
					r.live = { status: 'unsaved', log: [], error: `The runner itself failed: ${ e.message }` };
				}
			}
		} finally {
			state.running = false;
			clearCookies();
			document.getElementById( 'rh-frame-step' ).textContent = 'Done';
			renderRecipes();
		}
	}

	renderRecipes();
	renderFilterOptions();
	renderHistory();

	if ( data.install ) {
		// Drop ?install= so a reload doesn't prompt again.
		const url = new URL( window.location.href );
		url.searchParams.delete( 'install' );
		window.history.replaceState( null, '', url );
		openInstall( data.install );
	}
} )();

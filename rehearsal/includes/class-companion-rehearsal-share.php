<?php
/**
 * Rehearsal: Share links: the public page, and the JSON and Playwright versions of a rehearsal.
 *
 * @package companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Share links: the public page, and the JSON and Playwright versions of a rehearsal.
 */
final class Companion_Rehearsal_Share {
	/**
	 * Unguessable per-recipe key, so a share link reveals that one recipe and no other.
	 *
	 * @param int $id Recipe ID.
	 * @return string
	 */
	private static function share_key( $id ) {
		return substr( hash_hmac( 'sha256', 'rehearsal-share|' . $id, wp_salt( 'auth' ) ), 0, 20 );
	}

	/**
	 * The link to give a colleague. It always serves the recipe's current version.
	 *
	 * @param int $id Recipe ID.
	 * @return string
	 */
	public static function share_url( $id ) {
		return add_query_arg( 'rehearsal', $id . '.' . self::share_key( $id ), home_url( '/' ) );
	}

	/**
	 * The portable recipe document: what CI reads and what another site installs.
	 *
	 * @param WP_Post $post Recipe post.
	 * @return array
	 */
	public static function share_payload( $post ) {
		return array(
			'format' => 'rehearsal',
			'title'  => get_the_title( $post ),
			'id'     => Companion_Rehearsal_Schema::hash( $post->post_content ),
			// This site's number for it, so a Playwright test can save its result here as a run.
			'post_id' => $post->ID,
			'definition' => json_decode( $post->post_content, true ),
			'source' => self::share_url( $post->ID ),
			'site'   => home_url( '/' ),
			'build'  => Companion_Rehearsal_Admin::build(),
			'shared_by' => Companion_Rehearsal::VERSION,
		);
	}

	/**
	 * The rehearsal ID in a share token ("<id>.<key>"), or 0 when the key doesn't match that ID.
	 *
	 * @param string $token Value of the ?rehearsal= query arg.
	 * @return int
	 */
	public static function id_from_token( $token ) {
		list( $id, $key ) = array_pad( explode( '.', (string) $token, 2 ), 2, '' );
		$id               = (int) $id;
		return $id > 0 && hash_equals( self::share_key( $id ), $key ) ? $id : 0;
	}

	/**
	 * Serve ?rehearsal=<id>.<key>: a page for people, or JSON with &format=json for CI and other sites.
	 */
	public static function serve_share() {
		if ( ! empty( $_GET['rehearsal_bundle'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::serve_bundle( sanitize_text_field( wp_unslash( $_GET['rehearsal_bundle'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( empty( $_GET['rehearsal'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$id   = self::id_from_token( sanitize_text_field( wp_unslash( $_GET['rehearsal'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || Companion_Rehearsal::RECIPE !== $post->post_type || 'publish' !== $post->post_status ) {
			status_header( 404 );
			nocache_headers();
			wp_die( 'This rehearsal link is not valid. It may have been deleted, or copied incompletely.', 'Rehearsal not found', array( 'response' => 404 ) );
		}
		$payload = self::share_payload( $post );
		nocache_headers();
		$format = isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'json' === $format ) {
			header( 'Access-Control-Allow-Origin: *' );
			wp_send_json( $payload );
		}
		if ( 'github' === $format ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo self::to_github( $payload, Companion_Rehearsal_Runs::latest( $post->ID ) ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- Plain-text Markdown.
			exit;
		}
		if ( 'playwright' === $format ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: inline; filename="' . sanitize_title( $payload['title'] ) . '.spec.ts"' );
			echo Companion_Rehearsal_Playwright::to_playwright( $payload ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- Plain-text source code.
			exit;
		}
		self::render_share_page( $payload );
		exit;
	}

	/**
	 * For a PR description: a summary that reads well on GitHub, with the rehearsal JSON folded away.
	 * "Copy as GitHub", `&format=github` and `wp rehearsal block` all use this, so they can't drift apart.
	 *
	 * @param array      $payload  Share payload.
	 * @param array|null $last_run The rehearsal's latest run, as Runs::run_for_js() shapes it.
	 * @return string Markdown.
	 */
	public static function to_github( array $payload, $last_run ) {
		$def    = $payload['definition'];
		$steps  = count( $def['steps'] );
		$facts  = array(
			'Rehearsal `' . substr( $payload['id'], 0, 7 ) . '`',
			$steps . ( 1 === $steps ? ' step' : ' steps' ),
			empty( $def['covers'] ) ? 'manual only' : 'covers ' . implode( ', ', array_map( static fn( $c ) => "`$c`", $def['covers'] ) ),
		);
		$words  = array(
			'pass'   => 'Passed',
			'fail'   => 'Failed',
			'queued' => 'Needs Playwright (not run)',
		);
		$last   = $last_run && $last_run['hash'] === $payload['id']
			? sprintf(
				'Last run: **%s**%s on %s, run #%d, %s.',
				$words[ $last_run['status'] ] ?? $last_run['status'],
				'playwright' === $last_run['runner'] ? ' in Playwright' : '',
				$last_run['build'],
				$last_run['id'],
				substr( $last_run['date'], 0, 10 )
			)
			: 'Not run since its last change.';
		$source = $payload['source'];
		return implode(
			"\n",
			array(
				'<!-- rehearsal v1 -->',
				'#### Rehearsal: ' . $payload['title'],
				'',
				implode( ' · ', $facts ) . '  ',
				$last . '  ',
				sprintf(
					'[View or install this rehearsal](%1$s) · [JSON](%1$s&format=json) · [Playwright test](%1$s&format=playwright) (links work while %2$s exists)',
					$source,
					wp_parse_url( $payload['site'], PHP_URL_HOST )
				),
				'',
				'<details>',
				'<summary>Rehearsal JSON</summary>',
				'',
				'```json',
				wp_json_encode( $def, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'```',
				'',
				'</details>',
			)
		);
	}

	/**
	 * A link that opens Tools → Rehearsal and runs these rehearsals straight away, for agents and
	 * scripts. Signed, so another site can't make an admin's browser run a rehearsal by linking here.
	 *
	 * @param int[]  $ids Rehearsal IDs.
	 * @param string $ref Label saved with each run, to find the runs again.
	 * @return string
	 */
	public static function run_url( array $ids, $ref = '' ) {
		$ids = implode( '-', array_map( 'intval', $ids ) );
		$url = add_query_arg( 'run', $ids . '.' . self::run_key( $ids ), admin_url( 'tools.php?page=' . Companion_Rehearsal::SLUG ) );
		return '' === $ref ? $url : add_query_arg( 'ref', $ref, $url );
	}

	/**
	 * The rehearsal IDs in a run token ("<id>-<id>.<key>"), or none when the key doesn't match the list.
	 *
	 * @param string $token Value of the &run= query arg.
	 * @return int[]
	 */
	public static function ids_from_run_token( $token ) {
		list( $ids, $key ) = array_pad( explode( '.', (string) $token, 2 ), 2, '' );
		if ( ! preg_match( '/^\d+(-\d+)*$/', $ids ) || ! hash_equals( self::run_key( $ids ), $key ) ) {
			return array();
		}
		return array_map( 'intval', explode( '-', $ids ) );
	}

	/**
	 * Key for a run link. Not the bundle key: a Playwright link handed to CI must not double as a run link.
	 *
	 * @param string $ids IDs joined with "-".
	 * @return string
	 */
	private static function run_key( $ids ) {
		return substr( hash_hmac( 'sha256', 'rehearsal-run|' . $ids, wp_salt( 'auth' ) ), 0, 20 );
	}

	/**
	 * A link to several rehearsals as one Playwright file, for "Run in Playwright" and CI.
	 *
	 * @param int[] $ids Rehearsal IDs.
	 * @return string
	 */
	public static function bundle_url( array $ids ) {
		$ids = implode( '-', array_map( 'intval', $ids ) );
		return add_query_arg( 'rehearsal_bundle', $ids . '.' . self::bundle_key( $ids ), home_url( '/' ) );
	}

	/**
	 * The rehearsal IDs in a bundle token ("<id>-<id>.<key>"), or none when the key doesn't match the list.
	 *
	 * @param string $token Value of the ?rehearsal_bundle= query arg.
	 * @return int[]
	 */
	public static function ids_from_bundle_token( $token ) {
		list( $ids, $key ) = array_pad( explode( '.', (string) $token, 2 ), 2, '' );
		if ( ! preg_match( '/^\d+(-\d+)*$/', $ids ) || ! hash_equals( self::bundle_key( $ids ), $key ) ) {
			return array();
		}
		return array_map( 'intval', explode( '-', $ids ) );
	}

	/**
	 * Key for a list of rehearsal IDs, so a bundle link opens exactly that list.
	 *
	 * @param string $ids IDs joined with "-".
	 * @return string
	 */
	private static function bundle_key( $ids ) {
		return substr( hash_hmac( 'sha256', 'rehearsal-bundle|' . $ids, wp_salt( 'auth' ) ), 0, 20 );
	}

	/**
	 * Serve a bundle link as a Playwright file. &download=1 makes the browser save it.
	 *
	 * @param string $token Bundle token.
	 */
	private static function serve_bundle( $token ) {
		$payloads = array();
		foreach ( self::ids_from_bundle_token( $token ) as $id ) {
			$post = get_post( $id );
			if ( $post && Companion_Rehearsal::RECIPE === $post->post_type && 'publish' === $post->post_status && ! Companion_Rehearsal_Schema::validate( json_decode( $post->post_content, true ) ) ) {
				$payloads[] = self::share_payload( $post );
			}
		}
		nocache_headers();
		if ( ! $payloads ) {
			status_header( 404 );
			wp_die( 'This link is not valid, or its rehearsals were deleted.', 'Rehearsals not found', array( 'response' => 404 ) );
		}
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: ' . ( empty( $_GET['download'] ) ? 'inline' : 'attachment' ) . '; filename="rehearsals.spec.ts"' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo Companion_Rehearsal_Playwright::to_playwright_bundle( $payloads, home_url( '/' ) ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- Plain-text source code.
		exit;
	}

	/**
	 * Standalone page for a share link: what the recipe does, and a box to install it on your own site.
	 *
	 * @param array $payload Share payload.
	 */
	private static function render_share_page( array $payload ) {
		$json_url = add_query_arg( 'format', 'json', $payload['source'] );
		$covers   = empty( $payload['definition']['covers'] ) ? 'Nothing: run it by hand only.' : implode( ', ', $payload['definition']['covers'] );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $payload['title'] ); ?> · Rehearsal</title>
<style>
body { margin: 0; background: #f0f0f1; color: #1d2327; font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
main { max-inline-size: 760px; margin: 40px auto; padding-inline: 16px; }
.card { background: #fff; border: 1px solid #c3c4c7; padding: 20px 24px; margin-block-end: 16px; }
h1 { font-size: 22px; margin: 0 0 4px; } .muted { color: #646970; font-size: 13px; }
dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; margin: 12px 0 0; font-size: 14px; } dt { color: #646970; } dd { margin: 0; overflow-wrap: anywhere; }
code, pre { font: 12.5px/1.5 Menlo, Consolas, monospace; } pre { background: #f6f7f7; border: 1px solid #dcdcde; padding: 12px; overflow-x: auto; max-block-size: 420px; }
form { display: flex; flex-wrap: wrap; gap: 8px; margin-block-start: 8px; } input { flex: 1 1 260px; padding: 8px 10px; font: inherit; border: 1px solid #8c8f94; border-radius: 4px; }
button { padding: 8px 16px; font: inherit; font-weight: 600; color: #fff; background: #2271b1; border: 0; border-radius: 4px; cursor: pointer; }
</style></head>
<body><main>
	<div class="card">
		<div class="muted">Rehearsal</div>
		<h1><?php echo esc_html( $payload['title'] ); ?></h1>
		<dl>
			<dt>Rehearsal ID</dt><dd><code><?php echo esc_html( substr( $payload['id'], 0, 7 ) ); ?></code></dd>
			<dt>Steps</dt><dd><?php echo (int) count( $payload['definition']['steps'] ); ?></dd>
			<dt>Runs in CI when</dt><dd><?php echo esc_html( $covers ); ?></dd>
			<dt>Requires plugins</dt><dd><?php echo esc_html( empty( $payload['definition']['requires'] ) ? 'None' : implode( ', ', $payload['definition']['requires'] ) ); ?></dd>
			<dt>Requires theme</dt><dd><?php echo esc_html( empty( $payload['definition']['theme'] ) ? 'Any' : implode( ' or ', (array) $payload['definition']['theme'] ) ); ?></dd>
			<dt>Runs as</dt><dd><?php echo esc_html( empty( $payload['definition']['role'] ) ? 'The logged-in admin' : $payload['definition']['role'] ); ?></dd>
			<dt>Format</dt><dd><?php echo (int) Companion_Rehearsal_Schema::format_of( $payload['definition'] ); ?> (shared by a site running Rehearsal <?php echo esc_html( $payload['shared_by'] ); ?>)</dd>
			<dt>Shared from</dt><dd><?php echo esc_html( $payload['site'] ); ?> (<?php echo esc_html( $payload['build'] ); ?>)</dd>
			<dt>For CI</dt><dd><a href="<?php echo esc_url( $json_url ); ?>">JSON</a> · <a href="<?php echo esc_url( add_query_arg( 'format', 'playwright', $payload['source'] ) ); ?>">Playwright test</a> · <a href="<?php echo esc_url( add_query_arg( 'format', 'github', $payload['source'] ) ); ?>">GitHub block</a></dd>
		</dl>
	</div>
	<div class="card">
		<h2 style="margin:0;font-size:17px">Install on your site</h2>
		<p class="muted" style="margin:4px 0 0">Your site needs Rehearsal, which comes with the Jurassic Ninja Companion plugin. You'll see the rehearsal again before anything is saved.</p>
		<form id="install">
			<input id="site" name="site" aria-label="Your site" placeholder="your-site.jurassic.ninja" required autocomplete="url">
			<button type="submit">Install</button>
		</form>
	</div>
	<div class="card"><pre><?php echo esc_html( wp_json_encode( $payload['definition'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre></div>
</main>
<script>
document.getElementById( 'install' ).addEventListener( 'submit', function ( e ) {
	e.preventDefault();
	var site = document.getElementById( 'site' ).value.trim().replace( /^https?:\/\//, '' ).replace( /\/.*$/, '' );
	window.location.href = 'https://' + site + '/wp-admin/tools.php?page=rehearsal&install=' + encodeURIComponent( <?php echo wp_json_encode( $payload['source'] ); ?> );
} );
</script>
</body></html>
		<?php
	}
}

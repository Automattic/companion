<?php
/**
 * Rehearsal: The `wp rehearsal` command, for agents and scripts.
 *
 * @package companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Saves, lists and installs rehearsals, and reads their runs, from the command line.
 */
final class Companion_Rehearsal_Command {

	/**
	 * Lists the rehearsals on this site with their latest result.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format. JSON includes each rehearsal's steps and full ID.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp rehearsal list
	 *     wp rehearsal list --format=json
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_( $args, $assoc_args ) {
		$format     = $assoc_args['format'] ?? 'table';
		$rehearsals = Companion_Rehearsal_Store::recipes_for_js();
		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $rehearsals, 'id' ) ) );
			return;
		}
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $rehearsals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		$rows = array_map(
			static function ( $r ) {
				$last = $r['lastRun'];
				return array(
					'id'          => $r['id'],
					'title'       => $r['title'],
					'rehearsal'   => substr( $r['hash'], 0, 7 ),
					'steps'       => count( $r['recipe']['steps'] ),
					'last_result' => ! $last ? 'not run' : ( $last['hash'] === $r['hash'] ? $last['status'] : 'stale' ),
					'can_run'     => $r['missing'] ? 'needs ' . implode( ', ', wp_list_pluck( $r['missing'], 'name' ) ) : 'yes',
					'share_url'   => $r['shareUrl'],
					'run_url'     => $r['runUrl'],
				);
			},
			$rehearsals
		);
		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'title', 'rehearsal', 'steps', 'last_result', 'can_run', 'share_url', 'run_url' ) );
	}

	/**
	 * Saves a rehearsal from a JSON file or standard input.
	 *
	 * The JSON is a rehearsal ({ "steps": [ … ] }) or what a share link returns with &format=json.
	 * Saving the same title and steps again returns the rehearsal already saved instead of a copy.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : JSON file to read. Omit it, or pass "-", to read standard input.
	 *
	 * [--title=<title>]
	 * : The rehearsal's title. Required unless the JSON is a share payload, which has one.
	 *
	 * [--id=<id>]
	 * : Update this rehearsal instead of adding one.
	 *
	 * [--porcelain]
	 * : Print only the rehearsal's number.
	 *
	 * [--format=<format>]
	 * : Print the saved rehearsal as JSON instead of a message.
	 * ---
	 * options:
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp rehearsal save checkout.json --title="Checkout: pays with a test card"
	 *     curl -s "https://site/?rehearsal=12.abc&format=json" | wp rehearsal save --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function save( $args, $assoc_args ) {
		$file = $args[0] ?? '-';
		$json = '-' === $file ? stream_get_contents( STDIN ) : ( is_readable( $file ) ? file_get_contents( $file ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $json ) {
			WP_CLI::error( "Could not read $file." );
		}
		$data = json_decode( (string) $json, true );
		if ( null === $data ) {
			WP_CLI::error( 'The input is not valid JSON: ' . json_last_error_msg() );
		}
		// A share payload carries the rehearsal under "definition", with its title.
		$shared = is_array( $data ) && 'rehearsal' === ( $data['format'] ?? '' ) && isset( $data['definition'] );
		$recipe = $shared ? $data['definition'] : $data;
		$title  = trim( sanitize_text_field( (string) ( $assoc_args['title'] ?? ( $shared ? ( $data['title'] ?? '' ) : '' ) ) ) );
		$error  = Companion_Rehearsal_Schema::validate( $recipe );
		if ( $error ) {
			WP_CLI::error( $error );
		}
		if ( '' === $title ) {
			WP_CLI::error( 'Give the rehearsal a title with --title.' );
		}
		$id = (int) ( $assoc_args['id'] ?? 0 );
		if ( $id && Companion_Rehearsal::RECIPE !== get_post_type( $id ) ) {
			WP_CLI::error( "There is no rehearsal $id." );
		}
		$same = $id ? 0 : Companion_Rehearsal_Store::find_same( $title, $recipe );
		if ( ! $same ) {
			$id = Companion_Rehearsal_Store::save_recipe( $id, $title, $recipe );
			if ( is_wp_error( $id ) ) {
				WP_CLI::error( $id->get_error_message() );
			}
		}
		$saved = Companion_Rehearsal_Store::recipe_for_js( get_post( $same ? $same : $id ) );
		if ( ! empty( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( (string) $saved['id'] );
		} elseif ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
			WP_CLI::line( (string) wp_json_encode( $saved + array( 'existing' => (bool) $same ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		} else {
			WP_CLI::success( sprintf( '%s rehearsal %d (%s): %s', $same ? 'Already saved as' : 'Saved', $saved['id'], substr( $saved['hash'], 0, 7 ), $saved['shareUrl'] ) );
		}
	}

	/**
	 * Installs a rehearsal from a share link.
	 *
	 * Shows what the link holds first. Installing the same link again updates that copy.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The share link, such as https://some-site.jurassic.ninja/?rehearsal=12.abc…
	 *
	 * [--yes]
	 * : Install without asking.
	 *
	 * [--porcelain]
	 * : Print only the installed rehearsal's number.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rehearsal install "https://some-site.jurassic.ninja/?rehearsal=12.abc" --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function install( $args, $assoc_args ) {
		$preview = Companion_Rehearsal_Rest::install( $args[0], true, '' );
		if ( is_wp_error( $preview ) ) {
			WP_CLI::error( $preview->get_error_message() );
		}
		WP_CLI::log( sprintf( '%s (rehearsal %s, %d steps) from %s', $preview['title'], substr( $preview['id'], 0, 7 ), count( $preview['recipe']['steps'] ), $preview['site'] ) );
		if ( $preview['missing'] ) {
			WP_CLI::warning( 'This site is missing what it needs, so it will not run until they are active: ' . implode( ', ', wp_list_pluck( $preview['missing'], 'name' ) ) . '.' );
		}
		if ( $preview['newer'] ) {
			WP_CLI::warning( sprintf( 'Made with a newer version of Rehearsal (format %d; this site understands format %d). Some steps may not work.', $preview['format'], Companion_Rehearsal::FORMAT ) );
		}
		WP_CLI::confirm( $preview['updates'] ? "Replace your copy (rehearsal {$preview['updates']}) with this version?" : 'Install it?', $assoc_args );
		$saved = Companion_Rehearsal_Rest::install( $args[0], false, $preview['id'] );
		if ( is_wp_error( $saved ) ) {
			WP_CLI::error( $saved->get_error_message() );
		}
		if ( ! empty( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( (string) $saved['id'] );
			return;
		}
		WP_CLI::success( sprintf( '%s rehearsal %d.', $saved['updated'] ? 'Updated' : 'Installed as', $saved['id'] ) );
	}

	/**
	 * Lists saved runs, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--rehearsal=<id>]
	 * : Only runs of this rehearsal.
	 *
	 * [--status=<status>]
	 * : Only runs with this result.
	 * ---
	 * options:
	 *   - pass
	 *   - fail
	 *   - queued
	 * ---
	 *
	 * [--ref=<ref>]
	 * : Only runs started with this ref (from a run link's &ref=, or REHEARSAL_REF in Playwright).
	 *
	 * [--page=<page>]
	 * : Page of 25 runs.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format. JSON includes each run's log and errors.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp rehearsal runs --rehearsal=12 --format=json
	 *     wp rehearsal runs --ref=task-481 --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function runs( $args, $assoc_args ) {
		$found  = Companion_Rehearsal_Runs::find_runs(
			array(
				'recipe' => (int) ( $assoc_args['rehearsal'] ?? 0 ),
				'status' => $assoc_args['status'] ?? '',
				'ref'    => $assoc_args['ref'] ?? '',
				'page'   => (int) ( $assoc_args['page'] ?? 1 ),
			)
		);
		$format = $assoc_args['format'] ?? 'table';
		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $found['runs'], 'id' ) ) );
			return;
		}
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $found, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		$rows = array_map(
			static function ( $run ) {
				$failed = array_values( array_filter( (array) $run['log'], static fn( $l ) => empty( $l['ok'] ) ) );
				return array(
					'id'        => $run['id'],
					'date'      => $run['date'],
					'rehearsal' => $run['recipeId'],
					'title'     => $run['title'],
					'status'    => $run['status'],
					'runner'    => $run['runner'],
					'by'        => $run['by'],
					'ref'       => $run['ref'],
					'failure'   => $failed ? $failed[0]['msg'] : '',
				);
			},
			$found['runs']
		);
		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'date', 'rehearsal', 'title', 'status', 'runner', 'by', 'ref', 'failure' ) );
	}

	/**
	 * Prints a rehearsal as a block for a PR description: a summary with its latest result, and the JSON folded away.
	 *
	 * The same as "Copy as GitHub" in Tools → Rehearsal and the share link with &format=github.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The rehearsal's number.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rehearsal block 12 >> pr-body.md
	 *
	 * @param array $args Positional arguments.
	 */
	public function block( $args ) {
		$post = get_post( (int) $args[0] );
		if ( ! $post || Companion_Rehearsal::RECIPE !== $post->post_type || 'publish' !== $post->post_status ) {
			WP_CLI::error( "There is no rehearsal {$args[0]}." );
		}
		WP_CLI::line( Companion_Rehearsal_Share::to_github( Companion_Rehearsal_Share::share_payload( $post ), Companion_Rehearsal_Runs::latest( $post->ID ) ) );
	}
}

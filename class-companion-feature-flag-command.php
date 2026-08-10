<?php
/**
 * WP-CLI commands for the Jetpack feature flag overrides stored by this plugin.
 *
 * See feature-flags.php for the storage and filter layer these commands drive.
 */

/**
 * Turns Jetpack feature flags on and off on this site.
 */
class Companion_Feature_Flag_Command {

	/**
	 * Lists Jetpack feature flags and their current state.
	 *
	 * The `effective` column is resolved through the feature flags package itself, so it
	 * accounts for every filter on the flag. It can differ from `override` when something
	 * pins the flag in code.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp companion feature-flag list
	 *     wp companion feature-flag list --format=json
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		$flags     = companion_get_registered_feature_flags();
		$overrides = companion_get_feature_flag_overrides();

		if ( ! companion_has_feature_flags_package() ) {
			WP_CLI::warning( 'The Jetpack feature flags package is not loaded on this site, so no flags can be discovered.' );
		}

		$rows = array();

		foreach ( $flags as $name => $definition ) {
			$rows[] = array(
				'flag'        => $name,
				'default'     => $definition['default'] ? 'on' : 'off',
				'override'    => array_key_exists( $name, $overrides ) ? ( $overrides[ $name ] ? 'on' : 'off' ) : '-',
				'effective'   => companion_is_feature_flag_enabled( $name ) ? 'on' : 'off',
				'owner'       => '' === $definition['owner'] ? '-' : $definition['owner'],
				'description' => '' === $definition['description'] ? '-' : $definition['description'],
			);
		}

		// Overrides for flags nobody registers: either stale, or set ahead of the code landing.
		foreach ( array_diff_key( $overrides, $flags ) as $name => $enabled ) {
			$rows[] = array(
				'flag'        => $name,
				'default'     => '-',
				'override'    => $enabled ? 'on' : 'off',
				'effective'   => companion_is_feature_flag_enabled( $name ) ? 'on' : 'off',
				'owner'       => '-',
				'description' => 'Not registered on this site.',
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::log( 'No feature flags are registered, and no overrides are stored.' );
			return;
		}

		WP_CLI\Utils\format_items(
			WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ),
			$rows,
			array( 'flag', 'default', 'override', 'effective', 'owner', 'description' )
		);
	}

	/**
	 * Forces a Jetpack feature flag on.
	 *
	 * ## OPTIONS
	 *
	 * <flag>
	 * : Name of the feature flag.
	 *
	 * ## EXAMPLES
	 *
	 *     wp companion feature-flag enable newsletter-new-subscribe-form
	 *
	 * @param array $args Positional arguments.
	 *
	 * @return void
	 */
	public function enable( $args ) {
		$this->set_override( $args[0], true );
	}

	/**
	 * Forces a Jetpack feature flag off.
	 *
	 * ## OPTIONS
	 *
	 * <flag>
	 * : Name of the feature flag.
	 *
	 * ## EXAMPLES
	 *
	 *     wp companion feature-flag disable newsletter-new-subscribe-form
	 *
	 * @param array $args Positional arguments.
	 *
	 * @return void
	 */
	public function disable( $args ) {
		$this->set_override( $args[0], false );
	}

	/**
	 * Clears an override so the flag follows its registered default again.
	 *
	 * ## OPTIONS
	 *
	 * [<flag>]
	 * : Name of the feature flag. Omit when using --all.
	 *
	 * [--all]
	 * : Clear every stored override.
	 *
	 * ## EXAMPLES
	 *
	 *     wp companion feature-flag reset newsletter-new-subscribe-form
	 *     wp companion feature-flag reset --all
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function reset( $args, $assoc_args ) {
		$all = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );

		if ( $all ) {
			if ( ! empty( $args ) ) {
				WP_CLI::error( 'Pass either a flag name or --all, not both.' );
			}

			$count = count( companion_get_feature_flag_overrides() );
			companion_update_feature_flag_overrides( array() );
			WP_CLI::success( sprintf( 'Cleared %d feature flag override(s).', $count ) );

			return;
		}

		if ( empty( $args ) ) {
			WP_CLI::error( 'Pass a feature flag name, or --all to clear every override.' );
		}

		$name      = $args[0];
		$overrides = companion_get_feature_flag_overrides();

		if ( ! array_key_exists( $name, $overrides ) ) {
			WP_CLI::warning( sprintf( '%s has no stored override.', $name ) );

			return;
		}

		unset( $overrides[ $name ] );
		companion_update_feature_flag_overrides( $overrides );

		WP_CLI::success( sprintf( '%s now follows its registered default.', $name ) );
	}

	/**
	 * Stores an override for a single flag.
	 *
	 * Unregistered names are stored anyway, with a warning, so a flag can be set before
	 * the code that registers it lands on the site.
	 *
	 * @param string $name    Flag name.
	 * @param bool   $enabled Whether to force the flag on.
	 *
	 * @return void
	 */
	private function set_override( $name, $enabled ) {
		if ( ! companion_is_valid_feature_flag_name( $name ) ) {
			WP_CLI::error( sprintf( 'Invalid feature flag name "%s". Names must match /^[a-z0-9][a-z0-9_-]*$/.', $name ) );
		}

		$registered = companion_get_registered_feature_flags();

		if ( ! isset( $registered[ $name ] ) ) {
			WP_CLI::warning( sprintf( '%s is not registered on this site. Storing the override anyway.', $name ) );
		}

		$overrides          = companion_get_feature_flag_overrides();
		$overrides[ $name ] = $enabled;
		companion_update_feature_flag_overrides( $overrides );

		WP_CLI::success( sprintf( '%s is now forced %s.', $name, $enabled ? 'on' : 'off' ) );
	}
}

WP_CLI::add_command( 'companion feature-flag', 'Companion_Feature_Flag_Command' );

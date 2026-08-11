<?php
/**
 * WP-CLI commands for the Jetpack feature flag overrides stored by this plugin.
 *
 * See class-companion-feature-flags.php for the storage and filter layer these commands drive.
 */

/**
 * Turns Jetpack feature flags on and off on this site.
 */
class Companion_Feature_Flag_Command {

	/**
	 * Shared settings instance backing these commands.
	 *
	 * @var Companion_Feature_Flags
	 */
	private $flags;

	/**
	 * Binds the commands to the settings instance they operate on.
	 *
	 * @param Companion_Feature_Flags $flags Instance to operate on.
	 */
	public function __construct( Companion_Feature_Flags $flags ) {
		$this->flags = $flags;
	}

	/**
	 * Registers the command against a specific settings instance.
	 *
	 * Registers an already-constructed object rather than a class name, so the command is
	 * bound to the caller's instance instead of having to find one for itself.
	 *
	 * @param Companion_Feature_Flags $flags Instance the command should operate on.
	 * @param string                  $name  Command name to register under. Configurable so
	 *                                       a plugin reusing the settings class can expose
	 *                                       the same commands in its own namespace.
	 *
	 * @return void
	 */
	public static function register( Companion_Feature_Flags $flags, $name = 'companion feature-flag' ) {
		WP_CLI::add_command( $name, new self( $flags ) );
	}

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
		$flags       = Companion_Feature_Flags::get_registered_flags();
		$overrides   = $this->flags->get_overrides();
		$has_package = Companion_Feature_Flags::has_package();

		if ( ! $has_package ) {
			WP_CLI::warning( 'The Jetpack feature flags package is not loaded on this site, so no flags can be discovered.' );
		}

		/*
		 * Without the package there is nothing to resolve a flag against, and is_enabled()
		 * returns a hard false. Printing that as "off" next to an override of "on" reads as a
		 * resolved state rather than an unknowable one, so report it as unknown instead.
		 */
		$effective = function ( $name ) use ( $has_package ) {
			if ( ! $has_package ) {
				return '-';
			}

			return Companion_Feature_Flags::is_enabled( $name ) ? 'on' : 'off';
		};

		$rows = array();

		foreach ( $flags as $name => $definition ) {
			$rows[] = array(
				'flag'        => $name,
				'default'     => $definition['default'] ? 'on' : 'off',
				'override'    => array_key_exists( $name, $overrides ) ? ( $overrides[ $name ] ? 'on' : 'off' ) : '-',
				'effective'   => $effective( $name ),
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
				'effective'   => $effective( $name ),
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
	 * [--yes]
	 * : Skip the confirmation prompt when clearing every override.
	 *
	 * ## EXAMPLES
	 *
	 *     wp companion feature-flag reset newsletter-new-subscribe-form
	 *     wp companion feature-flag reset --all
	 *     wp companion feature-flag reset --all --yes
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

			$count = count( $this->flags->get_overrides() );

			// Auto-passes when --yes is supplied, so scripted callers are unaffected.
			WP_CLI::confirm( sprintf( 'Clear %d feature flag override(s)?', $count ), $assoc_args );

			$this->flags->update_overrides( array() );
			WP_CLI::success( sprintf( 'Cleared %d feature flag override(s).', $count ) );

			return;
		}

		if ( empty( $args ) ) {
			WP_CLI::error( 'Pass a feature flag name, or --all to clear every override.' );
		}

		$name      = $args[0];
		$overrides = $this->flags->get_overrides();

		if ( ! array_key_exists( $name, $overrides ) ) {
			WP_CLI::warning( sprintf( '%s has no stored override.', $name ) );

			return;
		}

		unset( $overrides[ $name ] );
		$stored = $this->flags->update_overrides( $overrides );

		// Same reasoning as set_override(): report what was stored, not what was asked for.
		if ( array_key_exists( $name, $stored ) ) {
			WP_CLI::error( sprintf( 'Could not clear the override for "%s".', $name ) );
		}

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
		if ( ! Companion_Feature_Flags::is_valid_name( $name ) ) {
			WP_CLI::error( sprintf( 'Invalid feature flag name "%s". Names must match /^[a-z0-9][a-z0-9_-]*$/.', $name ) );
		}

		$registered = Companion_Feature_Flags::get_registered_flags();

		if ( ! isset( $registered[ $name ] ) ) {
			WP_CLI::warning( sprintf( '%s is not registered on this site. Storing the override anyway.', $name ) );
		}

		$overrides          = $this->flags->get_overrides();
		$overrides[ $name ] = $enabled;
		$stored             = $this->flags->update_overrides( $overrides );

		// Report what was actually stored rather than what we asked for: normalization can
		// drop an entry, and a success message for a write that did not happen is worse
		// than an error.
		if ( ! array_key_exists( $name, $stored ) ) {
			WP_CLI::error( sprintf( 'Could not store an override for "%s".', $name ) );
		}

		WP_CLI::success( sprintf( '%s is now forced %s.', $name, $enabled ? 'on' : 'off' ) );
	}
}

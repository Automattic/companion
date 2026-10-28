<?php
/**
 * Rehearsal: What a rehearsal needs that this site lacks: plugins, a theme, a role.
 *
 * @package companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * What a rehearsal needs that this site lacks: plugins, a theme, a role.
 */
final class Companion_Rehearsal_Requirements {
	/**
	 * What the rehearsal needs that isn't active on this site.
	 *
	 * @param array $recipe Decoded rehearsal.
	 * @return array[] { kind: plugin|theme|role, slug, name, state: inactive|missing }
	 */
	public static function missing_requirements( array $recipe ) {
		$themes = array();
		foreach ( (array) ( $recipe['theme'] ?? array() ) as $slug ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				$themes[ $slug ] = $theme->get( 'Name' );
			}
		}
		return self::find_missing( $recipe, self::plugin_index(), array( get_stylesheet(), get_template() ), $themes, array_keys( wp_roles()->roles ) );
	}

	/**
	 * Plugins on this site, indexed by slug and text domain.
	 *
	 * @return array[] slug => { name, active }
	 */
	private static function plugin_index() {
		static $index = null;
		if ( null === $index ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$index = self::index_plugins( get_plugins(), 'is_plugin_active' );
		}
		return $index;
	}

	/**
	 * Index plugins by slug (folder, or file name for single-file plugins) and by text domain, so
	 * "jetpack" also matches a beta copy installed as jetpack-dev. An active copy wins over an inactive one.
	 *
	 * @param array[]  $plugins   get_plugins() output: file => headers.
	 * @param callable $is_active Called with a plugin file.
	 * @return array[] slug => { name, active }
	 */
	public static function index_plugins( array $plugins, callable $is_active ) {
		$index = array();
		foreach ( $plugins as $file => $data ) {
			$slug   = '.' === dirname( $file ) ? basename( $file, '.php' ) : dirname( $file );
			$active = (bool) $is_active( $file );
			foreach ( array_unique( array_filter( array( $slug, $data['TextDomain'] ?? '' ) ) ) as $key ) {
				if ( empty( $index[ $key ]['active'] ) ) {
					$index[ $key ] = array(
						'name'   => $data['Name'],
						'active' => $active,
					);
				}
			}
		}
		return $index;
	}

	/**
	 * The rehearsal's unmet requirements, given what the site has.
	 *
	 * @param array    $recipe           Decoded rehearsal.
	 * @param array[]  $plugins          index_plugins() output.
	 * @param string[] $active_themes    The active theme and its parent.
	 * @param string[] $installed_themes Required themes that are installed: slug => name.
	 * @param string[] $roles            Role slugs that exist on the site.
	 * @return array[] { kind: plugin|theme|role, slug, name, state: inactive|missing }
	 */
	public static function find_missing( array $recipe, array $plugins, array $active_themes, array $installed_themes, array $roles ) {
		$missing = array();
		foreach ( (array) ( $recipe['requires'] ?? array() ) as $requirement ) {
			// "a or b": any one of them active will do.
			$slugs = explode( ' or ', $requirement );
			$found = array_filter( array_map( static fn( $slug ) => $plugins[ $slug ] ?? null, array_combine( $slugs, $slugs ) ) );
			if ( ! array_filter( $found, static fn( $plugin ) => $plugin['active'] ) ) {
				$missing[] = array(
					'kind'  => 'plugin',
					'slug'  => $requirement,
					'name'  => implode( ' or ', array_map( static fn( $slug ) => isset( $found[ $slug ] ) ? $found[ $slug ]['name'] : $slug, $slugs ) ),
					'state' => $found ? 'inactive' : 'missing',
				);
			}
		}
		if ( ! empty( $recipe['role'] ) && ! in_array( $recipe['role'], $roles, true ) ) {
			$missing[] = array(
				'kind'  => 'role',
				'slug'  => $recipe['role'],
				'name'  => $recipe['role'],
				'state' => 'missing',
			);
		}
		$themes = (array) ( $recipe['theme'] ?? array() );
		// A child theme of a required theme counts: the parent is still what's running.
		if ( $themes && ! array_intersect( $themes, $active_themes ) ) {
			$missing[] = array(
				'kind'  => 'theme',
				'slug'  => implode( ' or ', $themes ),
				'name'  => implode( ' or ', array_map( static fn( $slug ) => $installed_themes[ $slug ] ?? $slug, $themes ) ),
				'state' => array_intersect_key( $installed_themes, array_flip( $themes ) ) ? 'inactive' : 'missing',
			);
		}
		return $missing;
	}
}

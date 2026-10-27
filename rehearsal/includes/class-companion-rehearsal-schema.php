<?php
/**
 * Rehearsal: Validates rehearsals and gives them a stable ID.
 *
 * @package companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validates rehearsals and gives them a stable ID.
 */
final class Companion_Rehearsal_Schema {
	const STEPS   = array( 'goto', 'waitFor', 'expect', 'expectText', 'expectCount', 'fill', 'click', 'check', 'uncheck', 'selectOption', 'hover', 'press', 'expectNoConsoleErrors', 'snapshot', 'screenshot' );
	// Named after Playwright's expect(locator) matchers, so recipes carry over to a Playwright runner.
	const MATCHERS = array( 'toBeVisible', 'toHaveText', 'toContainText', 'toHaveCount', 'toBeEnabled', 'toBeDisabled', 'toBeChecked', 'toHaveValue' );
	// What a rehearsal's "budgets" may limit: times in ms, CLS unitless, weight and JS in compressed bytes.
	// Page loads are only measured for rehearsals that set a budget.
	const BUDGET_KEYS = array( 'ttfb', 'fcp', 'lcp', 'cls', 'requests', 'weight', 'js' );

	/**
	 * Check a decoded recipe against the step vocabulary.
	 *
	 * @param mixed $recipe Decoded JSON.
	 * @return string Error message, or '' when valid.
	 */
	public static function validate( $recipe ) {
		if ( ! is_array( $recipe ) || ! isset( $recipe['steps'] ) || ! is_array( $recipe['steps'] ) || ! $recipe['steps'] ) {
			return 'The JSON must be an object with a non-empty "steps" array.';
		}
		// Free-form labels a CI job matches against what changed: project names, paths or tags.
		if ( isset( $recipe['covers'] ) ) {
			foreach ( is_array( $recipe['covers'] ) ? $recipe['covers'] : array( null ) as $label ) {
				if ( ! is_string( $label ) || ! preg_match( '#^[A-Za-z0-9._/*@-]{1,100}$#', $label ) ) {
					return '"covers" must be a list of short labels without spaces, such as ["packages/forms"] or ["src/checkout/*"].';
				}
			}
		}
		if ( isset( $recipe['requires'] ) ) {
			foreach ( is_array( $recipe['requires'] ) ? $recipe['requires'] : array( null ) as $slug ) {
				if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9._-]{1,100}$/', $slug ) ) {
					return '"requires" must be a list of plugin slugs (their folder names), such as ["jetpack"].';
				}
			}
		}
		if ( isset( $recipe['role'] ) && ( ! is_string( $recipe['role'] ) || ! preg_match( '/^[a-z0-9_-]{1,60}$/', $recipe['role'] ) ) ) {
			return '"role" must be a role slug, such as "editor".';
		}
		if ( isset( $recipe['theme'] ) ) {
			$themes = is_array( $recipe['theme'] ) ? $recipe['theme'] : array( $recipe['theme'] );
			foreach ( $themes ?: array( null ) as $slug ) {
				if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9._-]{1,100}$/', $slug ) ) {
					return '"theme" must be a theme slug (its folder name), such as "twentytwentyfour", or a list of them.';
				}
			}
		}
		if ( isset( $recipe['budgets'] ) ) {
			foreach ( is_array( $recipe['budgets'] ) ? $recipe['budgets'] : array( 'x' => null ) as $key => $limit ) {
				if ( ! in_array( $key, self::BUDGET_KEYS, true ) || ! is_numeric( $limit ) || $limit <= 0 ) {
					return sprintf( '"budgets" takes positive numbers for: %s.', implode( ', ', self::BUDGET_KEYS ) );
				}
			}
		}
		if ( isset( $recipe['needs'] ) && 'playwright' !== $recipe['needs'] ) {
			return '"needs" can only be "playwright".';
		}
		foreach ( array_values( $recipe['steps'] ) as $i => $step ) {
			$actions = is_array( $step ) ? array_intersect( array_keys( $step ), self::STEPS ) : array();
			if ( 1 !== count( $actions ) ) {
				return sprintf( 'Step %d must have exactly one of: %s.', $i + 1, implode( ', ', self::STEPS ) );
			}
			if ( isset( $step['goto'] ) && ( ! is_string( $step['goto'] ) || '/' !== substr( $step['goto'], 0, 1 ) || '//' === substr( $step['goto'], 0, 2 ) ) ) {
				return sprintf( 'Step %d: "goto" must be a path on this site, starting with a single "/".', $i + 1 );
			}
			if ( isset( $step['expect'] ) && 1 !== count( array_intersect( array_keys( $step ), self::MATCHERS ) ) ) {
				return sprintf( 'Step %d: "expect" needs exactly one of: %s.', $i + 1, implode( ', ', self::MATCHERS ) );
			}
			if ( isset( $step['selectOption'] ) && ! isset( $step['value'] ) ) {
				return sprintf( 'Step %d: "selectOption" needs a "value" (the option\'s value or its label).', $i + 1 );
			}
			if ( isset( $step['press'] ) && ( ! is_string( $step['press'] ) || '' === $step['press'] ) ) {
				return sprintf( 'Step %d: "press" needs a key, such as "Enter" or "Control+A".', $i + 1 );
			}
		}
		return '';
	}

	/**
	 * Canonical JSON for a recipe.
	 *
	 * @param array $recipe Decoded recipe.
	 * @return string
	 */
	public static function canonical( array $recipe ) {
		return wp_json_encode( $recipe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Recipe ID.
	 *
	 * @param string $content Canonical recipe JSON.
	 * @return string
	 */
	public static function hash( $content ) {
		return hash( 'sha256', $content );
	}
}

<?php
/**
 * Rehearsal: The example rehearsals, and adding them to a site.
 *
 * @package companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * The example rehearsals, and adding them to a site.
 */
final class Companion_Rehearsal_Examples {
	/**
	 * Rehearsal checking its own screen: the dialogs reject bad input, and the run filters respond.
	 * It saves nothing, so it can run any number of times.
	 *
	 * @return array Recipe.
	 */
	public static function self_test() {
		$close = array( 'click' => '#rh-modal button[data-close]' );
		$save  = array( 'click' => '#rh-modal [data-save]' );
		return array(
			'steps' => array(
				array( 'goto' => '/wp-admin/tools.php?page=' . Companion_Rehearsal::SLUG ),
				array(
					'expect'      => '#rh-run',
					'toBeVisible' => true,
				),
				array(
					'expect'        => '.rh-build',
					'toContainText' => 'WordPress',
				),
				array( 'click' => '#rh-add' ),
				array(
					'expect'     => '#rh-modal-title',
					'toHaveText' => 'New rehearsal',
				),
				array(
					'fill'  => '#rh-m-title',
					'value' => 'Self-test {{run}}',
				),
				array(
					'fill'  => '#rh-m-json',
					'value' => '{ not json',
				),
				$save,
				array(
					'expect'        => '#rh-modal-error',
					'toContainText' => 'not valid JSON',
				),
				array(
					'fill'  => '#rh-m-json',
					'value' => '{"steps":[{"hover":"#a","click":"#b"}]}',
				),
				$save,
				array(
					'expect'        => '#rh-modal-error',
					'toContainText' => 'exactly one of',
				),
				$close,
				array(
					'expect'      => '#rh-modal',
					'not'         => true,
					'toBeVisible' => true,
				),
				array( 'click' => '#rh-install' ),
				array(
					'fill'  => '#rh-m-url',
					'value' => 'https://example.com/',
				),
				array( 'click' => '#rh-modal [data-preview]' ),
				array(
					'expect'        => '#rh-modal-error',
					'toContainText' => 'not a rehearsal link',
				),
				$close,
				array(
					'selectOption' => '#rh-f-status',
					'value'        => 'fail',
				),
				array(
					'expect'      => '#rh-f-clear',
					'toBeVisible' => true,
				),
				array( 'expectNoConsoleErrors' => true ),
			),
		);
	}

	/**
	 * The whole Forms journey a site owner takes, from an empty dashboard to reading a response.
	 *
	 * @return array Recipe.
	 */
	public static function forms_journey() {
		$canvas = 'iframe[name="editor-canvas"]';
		$guide  = array(
			'click'    => '[role=dialog] button[aria-label="Close"]',
			'optional' => true,
			'timeout'  => 4000,
		);
		$row    = 'tr:has-text("Ada Verify {{run}}")';
		return array(
			'requires' => array( 'jetpack' ),
			'covers'   => array( 'packages/forms' ),
			// Below 600 px the Forms dashboard moves "Create a form" into a "More actions" menu.
			'viewport' => 'desktop',
			'timeout' => 20000,
			'steps'   => array(
				array( 'goto' => '/wp-admin/admin.php?page=jetpack-forms-responses-wp-admin&p=%2Fforms' ),
				array( 'click' => 'text=Create a form' ),
				array(
					'fill'  => '[role=dialog] input[placeholder="Enter form title"]',
					'value' => 'Rehearsal form {{run}}',
				),
				array( 'click' => '[role=dialog] button:has-text("Create")' ),
				array(
					'expect'      => "$canvas >> [data-type=\"jetpack/contact-form\"]",
					'toBeVisible' => true,
					'timeout'     => 40000,
				),
				$guide,
				array( 'click' => "$canvas >> text=Add a contact form to your page." ),
				array(
					'expect'      => "$canvas >> [data-type=\"jetpack/field-email\"]",
					'toBeVisible' => true,
				),
				array( 'click' => '.editor-header button:has-text("Publish")' ),
				array( 'click' => '.editor-post-publish-panel__header-publish-button button' ),
				array( 'click' => 'text=Add to a new page' ),
				array(
					'fill'  => '[role=dialog] input[placeholder="Untitled Form"]',
					'value' => 'Rehearsal page {{run}}',
				),
				array( 'click' => '[role=dialog] button:has-text("Create page")' ),
				array(
					'expect'        => '.editor-header',
					'toContainText' => 'Rehearsal page {{run}}',
					'timeout'       => 40000,
				),
				$guide,
				array(
					'expect'      => "$canvas >> [data-type=\"jetpack/field-email\"]",
					'toBeVisible' => true,
				),
				array( 'click' => '.editor-header button:has-text("Publish")' ),
				array( 'click' => '.editor-post-publish-panel__header-publish-button button' ),
				array( 'click' => '.post-publish-panel__postpublish-buttons a:has-text("View Page")' ),
				array(
					'fill'  => 'form.contact-form input[type=text]',
					'value' => 'Ada Verify {{run}}',
				),
				array(
					'fill'  => 'form.contact-form input[type=email]',
					'value' => 'ada+{{run}}@example.com',
				),
				array(
					'fill'  => 'form.contact-form textarea',
					'value' => 'Hello from Rehearsal {{run}}',
				),
				array( 'click' => 'form.contact-form button[type=submit]' ),
				array(
					'expect'        => '.contact-form-submission',
					'toContainText' => 'Thank you for your response',
				),
				array(
					'expect'        => '.contact-form-submission',
					'toContainText' => 'Ada Verify {{run}}',
				),
				// Akismet may flag test submissions; a site owner would rescue it from Spam.
				array( 'goto' => '/wp-admin/admin.php?page=jetpack-forms-responses-wp-admin&p=%2Fresponses%2Fspam' ),
				array(
					'click'    => "$row >> text=Not spam",
					'optional' => true,
					'timeout'  => 15000,
				),
				// The row disappears before the save finishes, and leaving the page would cancel it.
				array(
					'expect'      => 'text=Response marked as not spam.',
					'toBeVisible' => true,
					'optional'    => true,
					'timeout'     => 10000,
				),
				array(
					'expect'      => $row,
					'not'         => true,
					'toBeVisible' => true,
				),
				array( 'goto' => '/wp-admin/admin.php?page=jetpack-forms-responses-wp-admin&p=%2Fresponses%2Finbox' ),
				array(
					'expect'      => $row,
					'toBeVisible' => true,
					'timeout'     => 30000,
				),
				array( 'click' => 'text=Ada Verify {{run}}' ),
				array(
					'expect'      => 'text=Hello from Rehearsal {{run}}',
					'toBeVisible' => true,
				),
				array( 'expectNoConsoleErrors' => true ),
			),
		);
	}

	/**
	 * Add the examples to the site, from the "Add example rehearsals" button.
	 */
	public static function seed() {
		check_admin_referer( 'rehearsal_seed' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You are not allowed to add rehearsals.' );
		}
		foreach ( self::all( defined( 'JETPACK__VERSION' ), post_type_exists( 'jetpack_form' ) ) as $title => $recipe ) {
			Companion_Rehearsal_Store::save_recipe( 0, $title, $recipe );
		}
		wp_safe_redirect( admin_url( 'tools.php?page=' . Companion_Rehearsal::SLUG ) );
		exit;
	}

	/**
	 * The examples: ones that work on any site, plus Jetpack ones where Jetpack can run them.
	 *
	 * @param bool $has_jetpack Whether Jetpack is active.
	 * @param bool $has_forms   Whether Jetpack Forms' standalone forms are available.
	 * @return array[] title => rehearsal
	 */
	public static function all( $has_jetpack, $has_forms ) {
		$recipes = array(
			'Dashboard loads without console errors' => array(
				'steps' => array(
					array( 'goto' => '/wp-admin/index.php' ),
					array( 'waitFor' => '#dashboard-widgets' ),
					array( 'expectNoConsoleErrors' => true ),
				),
			),
			'General settings: tagline saves (changes the tagline)' => array(
				'steps' => array(
					array( 'goto' => '/wp-admin/options-general.php' ),
					array(
						'fill'  => '#blogdescription',
						'value' => 'Checked by Rehearsal',
					),
					array( 'click' => '#submit' ),
					array( 'expectText' => 'Settings saved.' ),
					array( 'expectNoConsoleErrors' => true ),
				),
			),
			'Discussion settings: checkbox, select and Enter (changes settings)' => array(
				'steps' => array(
					array( 'goto' => '/wp-admin/options-discussion.php' ),
					array(
						'expect'     => 'h1',
						'toHaveText' => 'Discussion Settings',
					),
					array(
						'expect'      => '#submit',
						'toBeEnabled' => true,
					),
					array( 'check' => '#thread_comments' ),
					array(
						'expect'      => '#thread_comments',
						'toBeChecked' => true,
					),
					array(
						'selectOption' => '#thread_comments_depth',
						'value'        => '3',
					),
					array(
						'press' => 'Enter',
						'on'    => '#comments_per_page',
					),
					array(
						'expect'        => '#setting-error-settings_updated',
						'toContainText' => 'Settings saved',
					),
					array(
						'expect'      => '#thread_comments_depth',
						'toHaveValue' => '3',
					),
					array( 'expectNoConsoleErrors' => true ),
				),
			),
			'Front page renders'                     => array(
				'steps' => array(
					array( 'goto' => '/' ),
					array( 'waitFor' => 'body' ),
					array( 'snapshot' => 'body' ),
					array( 'expectNoConsoleErrors' => true ),
				),
			),
			'Front page screenshot'                  => array(
				'needs' => 'playwright',
				'steps' => array(
					array( 'goto' => '/' ),
					array( 'screenshot' => 'front-page' ),
				),
			),
		);
		$recipes['Rehearsal: its own screen works'] = self::self_test();
		if ( $has_jetpack ) {
			$recipes['My Jetpack renders'] = array(
				'requires' => array( 'jetpack' ),
				'covers'   => array( 'packages/my-jetpack' ),
				'steps'    => array(
					array( 'goto' => '/wp-admin/admin.php?page=my-jetpack' ),
					array(
						'expect'      => '#wpbody-content [role=tab]:has-text("Overview")',
						'toBeVisible' => true,
					),
					array( 'expectNoConsoleErrors' => true ),
				),
			);
		}
		if ( $has_forms ) {
			$recipes['Forms: create a form, add it to a page, submit it, read the response (creates content)'] = self::forms_journey();
		}
		return $recipes;
	}
}

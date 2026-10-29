<?php
/**
 * The report scheduled for a minute after companion first loads on a new site.
 *
 * @package companion
 */

use PHPUnit\Framework\TestCase;

class JNLedgerFirstReportTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['jn_persistent_data'] = array( 'JN_API_TOKEN' => 'token' );
		$GLOBALS['jn_options']         = array();
		$GLOBALS['jn_actions']         = array();
		$GLOBALS['jn_cron_events']     = array();

		$queued = new ReflectionProperty( Companion_JN_Ledger::class, 'report_queued' );
		if ( PHP_VERSION_ID < 80100 ) {
			$queued->setAccessible( true );
		}
		$queued->setValue( null, false );
	}

	private function report_queued() {
		return in_array( array( 'shutdown', array( Companion_JN_Ledger::class, 'send_report' ), PHP_INT_MAX ), $GLOBALS['jn_actions'], true );
	}

	public function test_the_first_load_schedules_one_report_a_minute_out() {
		$before = time();
		Companion_JN_Ledger::schedule_first_report();

		$this->assertCount( 1, $GLOBALS['jn_cron_events'] );
		$event = $GLOBALS['jn_cron_events'][0];
		$this->assertSame( Companion_JN_Ledger::FIRST_REPORT_CRON_HOOK, $event['hook'] );
		$this->assertSame( array( 1 ), $event['args'] );
		$this->assertGreaterThanOrEqual( $before + 60, $event['timestamp'] );
		$this->assertLessThanOrEqual( time() + 60, $event['timestamp'] );
	}

	public function test_later_loads_schedule_nothing_more() {
		Companion_JN_Ledger::schedule_first_report();
		Companion_JN_Ledger::schedule_first_report();

		$this->assertCount( 1, $GLOBALS['jn_cron_events'] );
	}

	public function test_the_first_report_is_queued_when_the_token_is_there() {
		Companion_JN_Ledger::first_report( 1 );

		$this->assertTrue( $this->report_queued() );
		$this->assertSame( array(), $GLOBALS['jn_cron_events'] );
	}

	public function test_a_missing_token_retries_once_later() {
		$GLOBALS['jn_persistent_data'] = array();
		$before                        = time();

		Companion_JN_Ledger::first_report( 1 );

		$this->assertFalse( $this->report_queued() );
		$this->assertCount( 1, $GLOBALS['jn_cron_events'] );
		$event = $GLOBALS['jn_cron_events'][0];
		$this->assertSame( Companion_JN_Ledger::FIRST_REPORT_CRON_HOOK, $event['hook'] );
		$this->assertSame( array( 2 ), $event['args'] );
		$this->assertGreaterThanOrEqual( $before + Companion_JN_Ledger::FIRST_REPORT_RETRY_DELAY, $event['timestamp'] );
	}

	public function test_the_retry_reports_once_the_token_is_there() {
		Companion_JN_Ledger::first_report( 2 );

		$this->assertTrue( $this->report_queued() );
	}

	public function test_a_retry_without_the_token_gives_up() {
		$GLOBALS['jn_persistent_data'] = array( 'JN_API_TOKEN' => '' );

		Companion_JN_Ledger::first_report( 2 );

		$this->assertFalse( $this->report_queued() );
		$this->assertSame( array(), $GLOBALS['jn_cron_events'] );
	}
}

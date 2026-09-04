<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Tests\Unit;

use MediaWiki\Extension\Cowlender\Domain\EventFormatter;
use MediaWikiUnitTestCase;

/** @covers \MediaWiki\Extension\Cowlender\Domain\EventFormatter */
final class EventFormatterTest extends MediaWikiUnitTestCase {
	public function testTimedEventIsRenderedInItsTimezone(): void {
		$formatter = new EventFormatter();
		$formatted = $formatter->format( $this->event( [
			'startUtc' => '20260905150000',
			'endUtc' => '20260905200000',
		] ) );

		$this->assertSame( '2026-09-05T08:00:00-07:00', $formatted['start'] );
		$this->assertSame( '2026-09-05T13:00:00-07:00', $formatted['end'] );
	}

	public function testAllDayEventRemainsDateOnly(): void {
		$formatter = new EventFormatter();
		$formatted = $formatter->format( $this->event( [
			'allDay' => true,
			'startUtc' => null,
			'endUtc' => null,
			'startDate' => '20260905',
			'endDate' => '20260906',
		] ) );

		$this->assertSame( '2026-09-05', $formatted['start'] );
		$this->assertSame( '2026-09-06', $formatted['end'] );
	}

	private function event( array $overrides ): array {
		return array_replace( [
			'id' => 7,
			'title' => 'Davis Farmers Market',
			'description' => '',
			'location' => 'Central Park',
			'startUtc' => '20260905150000',
			'endUtc' => '20260905200000',
			'startDate' => null,
			'endDate' => null,
			'timezone' => 'America/Los_Angeles',
			'allDay' => false,
			'status' => 'scheduled',
			'category' => 'community',
			'externalUrl' => null,
			'recurrenceRule' => null,
			'createdById' => 2,
			'createdByName' => 'TestUser',
			'createdAt' => '20260901000000',
			'updatedById' => 2,
			'updatedByName' => 'TestUser',
			'updatedAt' => '20260901000000',
			'version' => 1,
		], $overrides );
	}
}

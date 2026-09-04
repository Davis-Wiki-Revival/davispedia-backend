<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Tests\Unit;

use HashConfig;
use MediaWiki\Extension\Cowlender\Domain\EventValidator;
use MediaWiki\Extension\Cowlender\Domain\ValidationException;
use MediaWikiUnitTestCase;

/** @covers \MediaWiki\Extension\Cowlender\Domain\EventValidator */
final class EventValidatorTest extends MediaWikiUnitTestCase {
	private EventValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new EventValidator( new HashConfig( [
			'CowlenderDefaultTimezone' => 'America/Los_Angeles',
			'CowlenderMaxRangeDays' => 370,
			'CowlenderMaxEventsPerRequest' => 2000,
			'CowlenderMaxEventDurationDays' => 3660,
			'CowlenderCategories' => [
				[ 'slug' => 'community', 'label' => 'Community', 'color' => '#2f6f4e' ],
			],
		] ) );
	}

	public function testTimedEventIsNormalizedToUtc(): void {
		$event = $this->validator->validateCreate( [
			'title' => 'Davis Farmers Market',
			'start' => '2026-09-05T08:00:00-07:00',
			'end' => '2026-09-05T13:00:00-07:00',
			'timezone' => 'America/Los_Angeles',
			'category' => 'community',
		] );

		$this->assertSame( '20260905150000', $event['startUtc'] );
		$this->assertSame( '20260905200000', $event['endUtc'] );
		$this->assertNull( $event['startDate'] );
		$this->assertFalse( $event['allDay'] );
	}

	public function testAllDayEventUsesExclusiveDateRange(): void {
		$event = $this->validator->validateCreate( [
			'title' => 'Whole Earth Festival',
			'start' => '2027-05-07',
			'end' => '2027-05-10',
			'allDay' => true,
		] );

		$this->assertSame( '20270507', $event['startDate'] );
		$this->assertSame( '20270510', $event['endDate'] );
		$this->assertNull( $event['startUtc'] );
	}

	public function testEndMustBeAfterStart(): void {
		$this->expectException( ValidationException::class );
		$this->validator->validateCreate( [
			'title' => 'Impossible Event',
			'start' => '2026-09-05T13:00:00-07:00',
			'end' => '2026-09-05T08:00:00-07:00',
		] );
	}

	public function testRangeDateIsInterpretedInConfiguredTimezone(): void {
		$range = $this->validator->validateRange( '2026-09-01', '2026-10-01', null );

		$this->assertSame( '20260901070000', $range['startUtc'] );
		$this->assertSame( '20261001070000', $range['endUtc'] );
		$this->assertSame( '20260901', $range['startDate'] );
		$this->assertSame( '20261001', $range['endDate'] );
	}

	public function testUnknownFieldsAreRejected(): void {
		$this->expectException( ValidationException::class );
		$this->validator->validateCreate( [
			'title' => 'Test',
			'start' => '2026-09-05T08:00:00-07:00',
			'end' => '2026-09-05T09:00:00-07:00',
			'createdBy' => 1,
		] );
	}

	public function testUnsafeExternalUrlIsRejected(): void {
		$this->expectException( ValidationException::class );
		$this->validator->validateCreate( [
			'title' => 'Test',
			'start' => '2026-09-05T08:00:00-07:00',
			'end' => '2026-09-05T09:00:00-07:00',
			'externalUrl' => 'javascript:alert(1)',
		] );
	}
}

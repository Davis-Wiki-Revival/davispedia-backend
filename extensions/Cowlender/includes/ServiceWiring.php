<?php

declare( strict_types=1 );

use MediaWiki\Extension\Cowlender\Domain\EventFormatter;
use MediaWiki\Extension\Cowlender\Domain\EventValidator;
use MediaWiki\Extension\Cowlender\EventService;
use MediaWiki\Extension\Cowlender\Store\EventStore;
use MediaWiki\MediaWikiServices;

return [
	'Cowlender.EventFormatter' => static function (): EventFormatter {
		return new EventFormatter();
	},

	'Cowlender.EventValidator' => static function ( MediaWikiServices $services ): EventValidator {
		return new EventValidator( $services->getMainConfig() );
	},

	'Cowlender.EventStore' => static function ( MediaWikiServices $services ): EventStore {
		return new EventStore( $services->getConnectionProvider() );
	},

	'Cowlender.EventService' => static function ( MediaWikiServices $services ): EventService {
		/** @var EventStore $store */
		$store = $services->getService( 'Cowlender.EventStore' );
		/** @var EventValidator $validator */
		$validator = $services->getService( 'Cowlender.EventValidator' );
		/** @var EventFormatter $formatter */
		$formatter = $services->getService( 'Cowlender.EventFormatter' );

		return new EventService( $store, $validator, $formatter );
	},
];

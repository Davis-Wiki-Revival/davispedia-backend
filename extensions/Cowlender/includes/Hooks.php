<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender;

use MediaWiki\Installer\DatabaseUpdater;
use RuntimeException;

/**
 * MediaWiki lifecycle hooks for the Cowlender extension.
 */
final class Hooks {
	/**
	 * Register the extension's database tables with update.php.
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ): void {
		$databaseType = $updater->getDB()->getType();
		$extensionRoot = dirname( __DIR__ );

		if ( $databaseType === 'mysql' ) {
			$sqlDirectory = $extensionRoot . '/sql/mysql';
		} elseif ( $databaseType === 'sqlite' ) {
			$sqlDirectory = $extensionRoot . '/sql/sqlite';
		} else {
			throw new RuntimeException(
				'Cowlender currently supports MySQL/MariaDB and SQLite databases; found ' . $databaseType
			);
		}

		$updater->addExtensionTable(
			'cowlender_event',
			$sqlDirectory . '/cowlender_event.sql'
		);
		$updater->addExtensionTable(
			'cowlender_event_revision',
			$sqlDirectory . '/cowlender_event_revision.sql'
		);
	}
}

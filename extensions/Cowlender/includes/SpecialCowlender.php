<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender;

use Html;
use SpecialPage;

/**
 * Hosts the React application at Special:Cowlender.
 */
final class SpecialCowlender extends SpecialPage {
	public function __construct() {
		parent::__construct( 'Cowlender', 'cowlender-view' );
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->checkPermissions();

		$output = $this->getOutput();
		$output->addModuleStyles( 'ext.cowlender.shell' );
		$output->addJsConfigVars( [
			'wgCowlenderRestBaseUrl' => wfScript( 'rest' ) . '/cowlender/v1',
			'wgCowlenderPageTitle' => $this->getPageTitle()->getPrefixedText(),
		] );
		$output->setPageTitle( $this->msg( 'cowlender' ) );
		$output->addWikiMsg( 'cowlender-summary' );
		$output->addHTML(
			Html::rawElement(
				'div',
				[
					'id' => 'cowlender-root',
					'data-cowlender-api' => wfScript( 'rest' ) . '/cowlender/v1',
				],
				Html::element(
					'div',
					[ 'class' => 'cowlender-shell-status' ],
					$this->msg( 'cowlender-loading' )->text()
				)
			)
		);
		$output->addHTML(
			Html::element( 'noscript', [], $this->msg( 'cowlender-noscript' )->text() )
		);
	}

	public function isListed(): bool {
		return true;
	}

	public function doesWrites(): bool {
		return false;
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Rest;

final class MetaHandler extends AbstractCowlenderHandler {
	public function execute() {
		return $this->handle( function () {
			$this->assertRight( 'cowlender-view' );
			$authority = $this->getAuthority();
			$user = $authority->getUser();

			return $this->jsonResponse( $this->service()->getMetadata() + [
				'user' => [
					'id' => $user->getId(),
					'name' => $user->getName(),
					'isRegistered' => $user->isRegistered(),
				],
				'permissions' => [
					'view' => $authority->isAllowed( 'cowlender-view' ),
					'create' => $authority->isAllowed( 'cowlender-create' ),
					'editOwn' => $authority->isAllowed( 'cowlender-edit-own' ),
					'editAll' => $authority->isAllowed( 'cowlender-edit-all' ),
					'delete' => $authority->isAllowed( 'cowlender-delete' ),
					'admin' => $authority->isAllowed( 'cowlender-admin' ),
				],
			] );
		} );
	}
}

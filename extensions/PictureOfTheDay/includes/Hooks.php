<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PictureOfTheDay;

use Parser;

final class Hooks {

    /**
     * Register the parser tags used by the extension.
     */
    public static function onParserFirstCallInit( Parser $parser ): void {
        $parser->setHook(
            'davispedia-picture-of-the-day',
            [ Renderer::class, 'renderCurrent' ]
        );

        $parser->setHook(
            'davispedia-picture-of-the-day-queue',
            [ Renderer::class, 'renderQueue' ]
        );

        $parser->setHook(
            'davispedia-picture-of-the-day-entry',
            [ Renderer::class, 'renderEntry' ]
        );
    }
}

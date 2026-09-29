<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PictureOfTheDay;

use DateTimeImmutable;
use DateTimeZone;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;
use Parser;
use PPFrame;
use Throwable;

final class Renderer {

    private const STYLE_MODULE = 'ext.PictureOfTheDay.styles';

    /**
     * Render the entry scheduled for the current local date.
     *
     * Usage: <davispedia-picture-of-the-day />
     *
     * @param string|null $input Unused tag body
     * @param array<string,string> $args Unused tag attributes
     */
    public static function renderCurrent(
        ?string $input,
        array $args,
        Parser $parser,
        PPFrame $frame
    ): string {
        global $wgDavispediaPictureOfTheDayTimeZone;
        global $wgDavispediaPictureOfTheDayPagePrefix;
        global $wgDavispediaPictureOfTheDayFallbackPage;

        $parserOutput = $parser->getOutput();
        $parserOutput->addModuleStyles( [ self::STYLE_MODULE ] );;

        try {
            $timeZone = new DateTimeZone(
                is_string( $wgDavispediaPictureOfTheDayTimeZone )
                    ? $wgDavispediaPictureOfTheDayTimeZone
                    : 'America/Los_Angeles'
            );
        } catch ( Throwable ) {
            $timeZone = new DateTimeZone( 'America/Los_Angeles' );
        }

        $now = new DateTimeImmutable( 'now', $timeZone );
        $date = $now->format( 'Y-m-d' );

        // Ensure the parser cache expires when the Davis-local date changes.
        $nextMidnight = $now->modify( 'tomorrow' )->setTime( 0, 0, 0 );
        $cacheSeconds = max( 60, $nextMidnight->getTimestamp() - $now->getTimestamp() );
        $parserOutput->updateCacheExpiry( $cacheSeconds );

        $prefix = is_string( $wgDavispediaPictureOfTheDayPagePrefix )
            ? rtrim( $wgDavispediaPictureOfTheDayPagePrefix, '/' )
            : 'MediaWiki:PictureOfTheDay';

        $scheduledTitle = Title::newFromText( $prefix . '/' . $date );
        $scheduledExists = $scheduledTitle && $scheduledTitle->exists();

        // If today's page has not been created yet, do not hold the fallback in
        // parser cache all day. A newly scheduled entry will appear within five
        // minutes even without manually purging the Main Page.
        if ( !$scheduledExists ) {
            $parserOutput->updateCacheExpiry( min( $cacheSeconds, 300 ) );
        }

        $selectedTitle = $scheduledExists
            ? $scheduledTitle
            : Title::newFromText(
                is_string( $wgDavispediaPictureOfTheDayFallbackPage )
                    ? $wgDavispediaPictureOfTheDayFallbackPage
                    : 'MediaWiki:PictureOfTheDay/default'
            );

        if ( !$selectedTitle || !$selectedTitle->exists() ) {
            return Html::element(
                'div',
                [ 'class' => 'davispedia-potd-error' ],
                wfMessage( 'davispediapotd-not-scheduled' )->text()
            );
        }

        // A normal transclusion lets MediaWiki track the dependency, so editing the
        // scheduled page invalidates pages that display it.
        $transclusion = '{{:' . $selectedTitle->getPrefixedText() . '}}';
        return $parser->recursiveTagParseFully( $transclusion, $frame );
    }

    /**
     * Render a single scheduled entry.
     *
     * Example:
     * <davispedia-picture-of-the-day-entry
     *     file="Example.jpg"
     *     alt="Accessible description"
     *     link="Related article">
     * Caption with [[wikitext]].
     * </davispedia-picture-of-the-day-entry>
     *
     * @param string|null $input Caption wikitext
     * @param array<string,string> $args Tag attributes
     */
    public static function renderEntry(
        ?string $input,
        array $args,
        Parser $parser,
        PPFrame $frame
    ): string {
        global $wgDavispediaPictureOfTheDayImageWidth;

        $parser->getOutput()->addModuleStyles( [ self::STYLE_MODULE ] );

        $fileName = trim( (string)( $args['file'] ?? '' ) );
        $fileTitle = $fileName !== '' ? Title::newFromText( $fileName, NS_FILE ) : null;

        if ( !$fileTitle || $fileTitle->getNamespace() !== NS_FILE ) {
            return Html::element(
                'div',
                [ 'class' => 'davispedia-potd-error' ],
                wfMessage( 'davispediapotd-invalid-file' )->text()
            );
        }

        $width = is_int( $wgDavispediaPictureOfTheDayImageWidth )
            ? $wgDavispediaPictureOfTheDayImageWidth
            : (int)$wgDavispediaPictureOfTheDayImageWidth;
        $width = max( 100, min( 1600, $width ) );

        $alt = self::sanitizeFileOption(
            trim( (string)( $args['alt'] ?? wfMessage( 'davispediapotd-default-alt' )->text() ) )
        );

        $fileOptions = [
            'frameless',
            $width . 'px',
            'alt=' . $alt,
        ];

        $linkText = trim( (string)( $args['link'] ?? '' ) );
        if ( $linkText !== '' ) {
            $linkTitle = Title::newFromText( $linkText );
            if ( $linkTitle ) {
                $fileOptions[] = 'link=' . self::sanitizeFileOption( $linkTitle->getPrefixedText() );
            }
        }

        $fileWikitext = '[[File:'
            . $fileTitle->getText()
            . '|'
            . implode( '|', $fileOptions )
            . ']]';

        $captionWikitext = trim( (string)$input );
        $captionHtml = $captionWikitext !== ''
            ? $parser->recursiveTagParseFully( $captionWikitext, $frame )
            : '';

        $creditWikitext = trim( (string)( $args['credit'] ?? '' ) );
        if ( $creditWikitext === '' ) {
            $creditWikitext = '[[:' . $fileTitle->getPrefixedText()
                . '|' . wfMessage( 'davispediapotd-file-details' )->text() . ']]';
        }

        $creditHtml = $parser->recursiveTagParseFully( $creditWikitext, $frame );
        $imageHtml = $parser->recursiveTagParseFully( $fileWikitext, $frame );

        return Html::rawElement(
            'div',
            [ 'class' => 'davispedia-potd-entry' ],
            Html::rawElement(
                'div',
                [ 'class' => 'davispedia-potd-caption' ],
                $captionHtml
            )
            . Html::rawElement(
                'div',
                [ 'class' => 'davispedia-potd-credit' ],
                $creditHtml
            )
            . Html::rawElement(
                'div',
                [ 'class' => 'davispedia-potd-image' ],
                $imageHtml
            )
        );
    }

    /**
     * Prevent parser-option delimiters from being injected through attributes.
     */
    private static function sanitizeFileOption( string $value ): string {
        return trim( str_replace(
            [ '|', ']]', "\r", "\n" ],
            [ ' ', ' ', ' ', ' ' ],
            $value
        ) );
    }
}

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

    private const STYLE_MODULE = 'ext.pictureOfTheDay.styles';

    /**
     * Render the entry for the current local date.
     *
     * Selection order:
     * 1. MediaWiki:PictureOfTheDay/YYYY-MM-DD
     * 2. MediaWiki:PictureOfTheDay/Queue
     * 3. MediaWiki:PictureOfTheDay/default
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
        global $wgPictureOfTheDayPagePrefix;
        global $wgPictureOfTheDayQueuePage;
        global $wgPictureOfTheDayFallbackPage;

        $parserOutput = $parser->getOutput();
        $parserOutput->addModuleStyles( [ self::STYLE_MODULE ] );

        $now = self::getLocalNow();
        self::expireAtNextLocalMidnight( $parser, $now );

        $prefix = is_string( $wgPictureOfTheDayPagePrefix )
            ? rtrim( $wgPictureOfTheDayPagePrefix, '/' )
            : 'MediaWiki:PictureOfTheDay';

        $scheduledTitle = Title::newFromText(
            $prefix . '/' . $now->format( 'Y-m-d' )
        );
        $scheduledExists = $scheduledTitle && $scheduledTitle->exists();

        if ( $scheduledExists ) {
            return self::transclude( $scheduledTitle, $parser, $frame );
        }

        // A newly created dated override should appear promptly even though the
        // Main Page currently depends on the queue rather than that missing page.
        $parserOutput->updateCacheExpiry( 300 );

        $queueTitle = Title::newFromText(
            is_string( $wgPictureOfTheDayQueuePage )
                ? $wgPictureOfTheDayQueuePage
                : 'MediaWiki:PictureOfTheDay/Queue'
        );

        if ( $queueTitle && $queueTitle->exists() ) {
            return self::transclude( $queueTitle, $parser, $frame );
        }

        return self::renderFallback( $parser, $frame );
    }

    /**
     * Select and render one child entry from a rotating queue.
     *
     * The first entry is selected on the configured start date. The next entry
     * is selected on the following local calendar day. The queue wraps when it
     * reaches the end.
     *
     * Usage:
     * <davispedia-picture-of-the-day-queue start="2026-09-28">
     *   <davispedia-picture-of-the-day-entry ...>Caption</...>
     *   <davispedia-picture-of-the-day-entry ...>Caption</...>
     * </davispedia-picture-of-the-day-queue>
     *
     * @param string|null $input Raw queue body
     * @param array<string,string> $args Queue attributes
     */
    public static function renderQueue(
        ?string $input,
        array $args,
        Parser $parser,
        PPFrame $frame
    ): string {
        global $wgPictureOfTheDayQueueStartDate;

        $parser->getOutput()->addModuleStyles( [ self::STYLE_MODULE ] );

        $now = self::getLocalNow();
        self::expireAtNextLocalMidnight( $parser, $now );

        $entries = self::extractQueueEntries( (string)$input );
        if ( $entries === [] ) {
            return self::renderFallback( $parser, $frame );
        }

        $configuredStart = is_string( $wgPictureOfTheDayQueueStartDate )
            ? $wgPictureOfTheDayQueueStartDate
            : '2026-09-28';
        $startText = trim( (string)( $args['start'] ?? $configuredStart ) );
        $startDate = self::parseLocalDate( $startText, $now->getTimezone() );

        if ( !$startDate ) {
            return Html::element(
                'div',
                [ 'class' => 'davispedia-potd-error' ],
                wfMessage( 'pictureoftheday-invalid-start-date', $startText )->text()
            );
        }

        $today = $now->setTime( 0, 0, 0 );
        $dayOffset = (int)$startDate->diff( $today )->format( '%r%a' );
        $entryCount = count( $entries );
        $index = ( ( $dayOffset % $entryCount ) + $entryCount ) % $entryCount;
        $selected = $entries[$index];

        return self::renderEntry(
            $selected['body'],
            $selected['args'],
            $parser,
            $frame
        );
    }

    /**
     * Render a single picture entry.
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
        global $wgPictureOfTheDayImageWidth;

        $parser->getOutput()->addModuleStyles( [ self::STYLE_MODULE ] );

        $fileName = trim( (string)( $args['file'] ?? '' ) );
        $fileTitle = $fileName !== '' ? Title::newFromText( $fileName, NS_FILE ) : null;

        if ( !$fileTitle || $fileTitle->getNamespace() !== NS_FILE ) {
            return Html::element(
                'div',
                [ 'class' => 'davispedia-potd-error' ],
                wfMessage( 'pictureoftheday-invalid-file' )->text()
            );
        }

        $width = is_int( $wgPictureOfTheDayImageWidth )
            ? $wgPictureOfTheDayImageWidth
            : (int)$wgPictureOfTheDayImageWidth;
        $width = max( 100, min( 1600, $width ) );

        $alt = self::sanitizeFileOption(
            trim( (string)(
                $args['alt'] ?? wfMessage( 'pictureoftheday-default-alt' )->text()
            ) )
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
                $fileOptions[] = 'link=' . self::sanitizeFileOption(
                    $linkTitle->getPrefixedText()
                );
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
                . '|' . wfMessage( 'pictureoftheday-file-details' )->text() . ']]';
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
     * Extract child entry tags from the unparsed queue body.
     *
     * @return array<int,array{args:array<string,string>,body:string}>
     */
    private static function extractQueueEntries( string $input ): array {
        $pattern = '~
            <davispedia-picture-of-the-day-entry\b
            (?P<attributes>(?:[^>"\']+|"[^"]*"|\'[^\']*\')*)
            >
            (?P<body>.*?)
            </davispedia-picture-of-the-day-entry\s*>
        ~isx';

        $matched = preg_match_all( $pattern, $input, $matches, PREG_SET_ORDER );
        if ( !$matched ) {
            return [];
        }

        $entries = [];
        foreach ( $matches as $match ) {
            $entries[] = [
                'args' => self::parseTagAttributes( (string)$match['attributes'] ),
                'body' => (string)$match['body'],
            ];
        }

        return $entries;
    }

    /**
     * Parse quoted attributes from one queue entry tag.
     *
     * @return array<string,string>
     */
    private static function parseTagAttributes( string $attributeText ): array {
        $pattern = '~
            ([A-Za-z_:][A-Za-z0-9_.:-]*)
            \s*=\s*
            (?:"([^"]*)"|\'([^\']*)\')
        ~sx';

        $matched = preg_match_all(
            $pattern,
            $attributeText,
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
        );

        if ( !$matched ) {
            return [];
        }

        $attributes = [];
        foreach ( $matches as $match ) {
            $name = strtolower( (string)$match[1] );
            $rawValue = $match[2] !== null ? (string)$match[2] : (string)$match[3];
            $attributes[$name] = html_entity_decode(
                $rawValue,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
        }

        return $attributes;
    }

    private static function getLocalNow(): DateTimeImmutable {
        global $wgPictureOfTheDayTimeZone;

        try {
            $timeZone = new DateTimeZone(
                is_string( $wgPictureOfTheDayTimeZone )
                    ? $wgPictureOfTheDayTimeZone
                    : 'America/Los_Angeles'
            );
        } catch ( Throwable ) {
            $timeZone = new DateTimeZone( 'America/Los_Angeles' );
        }

        return new DateTimeImmutable( 'now', $timeZone );
    }

    private static function parseLocalDate(
        string $date,
        DateTimeZone $timeZone
    ): ?DateTimeImmutable {
        $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timeZone );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            !$parsed
            || ( is_array( $errors )
                && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) )
            || $parsed->format( 'Y-m-d' ) !== $date
        ) {
            return null;
        }

        return $parsed;
    }

    private static function expireAtNextLocalMidnight(
        Parser $parser,
        DateTimeImmutable $now
    ): void {
        $nextMidnight = $now->modify( 'tomorrow' )->setTime( 0, 0, 0 );
        $cacheSeconds = max(
            60,
            $nextMidnight->getTimestamp() - $now->getTimestamp()
        );
        $parser->getOutput()->updateCacheExpiry( $cacheSeconds );
    }

    private static function renderFallback(
        Parser $parser,
        PPFrame $frame
    ): string {
        global $wgPictureOfTheDayFallbackPage;

        $fallbackTitle = Title::newFromText(
            is_string( $wgPictureOfTheDayFallbackPage )
                ? $wgPictureOfTheDayFallbackPage
                : 'MediaWiki:PictureOfTheDay/default'
        );

        if ( !$fallbackTitle || !$fallbackTitle->exists() ) {
            return Html::element(
                'div',
                [ 'class' => 'davispedia-potd-error' ],
                wfMessage( 'pictureoftheday-not-scheduled' )->text()
            );
        }

        return self::transclude( $fallbackTitle, $parser, $frame );
    }

    private static function transclude(
        Title $title,
        Parser $parser,
        PPFrame $frame
    ): string {
        return $parser->recursiveTagParseFully(
            '{{:' . $title->getPrefixedText() . '}}',
            $frame
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

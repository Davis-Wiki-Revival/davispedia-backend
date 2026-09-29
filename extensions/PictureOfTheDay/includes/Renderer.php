<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PictureOfTheDay;

use DateTimeImmutable;
use DateTimeZone;
use MediaWiki\Context\RequestContext;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;
use Parser;
use PPFrame;
use Throwable;

final class Renderer {

    private const STYLE_MODULE = 'ext.pictureOfTheDay.styles';
    private const DEFAULT_PREVIEW_DAYS = 14;
    private const MAX_PREVIEW_DAYS = 90;
    private const PREVIEW_IMAGE_WIDTH = 360;
    private const LOOKUP_PARAMETER = 'potd-date';

    public static function renderCurrent(
        ?string $input,
        array $args,
        Parser $parser,
        PPFrame $frame
    ): string {
        global $wgPictureOfTheDayPagePrefix;

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

        if ( $scheduledTitle && $scheduledTitle->exists() ) {
            return self::transclude( $scheduledTitle, $parser, $frame );
        }

        $parserOutput->updateCacheExpiry( 300 );

        $queueTitle = self::getQueueTitle();
        if ( $queueTitle && $queueTitle->exists() ) {
            return self::transclude( $queueTitle, $parser, $frame );
        }

        return self::renderFallback( $parser, $frame );
    }

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

        if ( self::isDirectQueuePageView( $parser ) ) {
            return self::renderSchedule(
                $entries,
                $startDate,
                $args,
                $parser,
                $frame,
                $now
            );
        }

        $today = $now->setTime( 0, 0, 0 );
        $selection = self::resolveSelectionForDate(
            $today,
            $entries,
            $startDate,
            $parser,
            $frame,
            false
        );

        return $selection['html'];
    }

    public static function renderEntry(
        ?string $input,
        array $args,
        Parser $parser,
        PPFrame $frame
    ): string {
        global $wgPictureOfTheDayImageWidth;

        $width = is_int( $wgPictureOfTheDayImageWidth )
            ? $wgPictureOfTheDayImageWidth
            : (int)$wgPictureOfTheDayImageWidth;

        return self::renderEntryAtWidth(
            $input,
            $args,
            $parser,
            $frame,
            $width
        );
    }

    private static function renderSchedule(
        array $entries,
        DateTimeImmutable $startDate,
        array $args,
        Parser $parser,
        PPFrame $frame,
        DateTimeImmutable $now
    ): string {
        $previewDays = self::parsePreviewDays(
            (string)( $args['preview-days'] ?? self::DEFAULT_PREVIEW_DAYS )
        );
        $today = $now->setTime( 0, 0, 0 );

        $parser->getOutput()->updateCacheExpiry( 0 );

        $items = '';
        for ( $day = 0; $day < $previewDays; $day++ ) {
            $date = $today->modify( '+' . $day . ' days' );
            $selection = self::resolveSelectionForDate(
                $date,
                $entries,
                $startDate,
                $parser,
                $frame,
                true
            );

            if ( $day === 0 ) {
                $relativeText = wfMessage( 'pictureoftheday-schedule-today' )->text();
            } elseif ( $day === 1 ) {
                $relativeText = wfMessage( 'pictureoftheday-schedule-tomorrow' )->text();
            } else {
                $relativeText = $date->format( 'l' );
            }

            $heading = Html::element(
                'h3',
                [ 'class' => 'davispedia-potd-schedule-day' ],
                $relativeText
            );
            $dateHtml = Html::element(
                'time',
                [
                    'class' => 'davispedia-potd-schedule-date',
                    'datetime' => $date->format( 'Y-m-d' ),
                ],
                $date->format( 'F j, Y' )
            );
            $sourceHtml = Html::element(
                'div',
                [ 'class' => 'davispedia-potd-schedule-source' ],
                $selection['source']
            );

            $items .= Html::rawElement(
                'section',
                [ 'class' => 'davispedia-potd-schedule-item' ],
                Html::rawElement(
                    'header',
                    [ 'class' => 'davispedia-potd-schedule-header' ],
                    $heading . $dateHtml . $sourceHtml
                ) . $selection['html']
            );
        }

        $timeZoneText = $now->getTimezone()->getName();
        $intro = Html::element(
            'p',
            [ 'class' => 'davispedia-potd-schedule-intro' ],
            wfMessage(
                'pictureoftheday-schedule-intro',
                $previewDays,
                $timeZoneText
            )->text()
        );

        $lookup = self::renderDateLookup(
            $entries,
            $startDate,
            $parser,
            $frame,
            $now
        );

        return Html::rawElement(
            'div',
            [ 'class' => 'davispedia-potd-schedule' ],
            $intro
            . $lookup
            . Html::rawElement(
                'div',
                [ 'class' => 'davispedia-potd-schedule-grid' ],
                $items
            )
        );
    }

    private static function renderDateLookup(
        array $entries,
        DateTimeImmutable $startDate,
        Parser $parser,
        PPFrame $frame,
        DateTimeImmutable $now
    ): string {
        $request = RequestContext::getMain()->getRequest();
        $requestedDateText = trim( $request->getText( self::LOOKUP_PARAMETER ) );
        $inputValue = $requestedDateText !== ''
            ? $requestedDateText
            : $now->format( 'Y-m-d' );

        $queueTitle = self::getQueueTitle();
        $action = $queueTitle ? $queueTitle->getLocalURL() : '';
        $inputId = 'davispedia-potd-date-lookup-input';

        $form = Html::rawElement(
            'form',
            [
                'class' => 'davispedia-potd-date-lookup-form',
                'method' => 'get',
                'action' => $action,
            ],
            Html::rawElement(
                'div',
                [ 'class' => 'davispedia-potd-date-lookup-field' ],
                Html::element(
                    'label',
                    [ 'for' => $inputId ],
                    wfMessage( 'pictureoftheday-lookup-label' )->text()
                )
                . Html::element(
                    'input',
                    [
                        'id' => $inputId,
                        'name' => self::LOOKUP_PARAMETER,
                        'type' => 'date',
                        'value' => $inputValue,
                        'required' => true,
                    ]
                )
            )
            . Html::element(
                'button',
                [
                    'class' => 'davispedia-potd-date-lookup-submit',
                    'type' => 'submit',
                ],
                wfMessage( 'pictureoftheday-lookup-submit' )->text()
            )
        );

        $result = '';
        if ( $requestedDateText !== '' ) {
            $requestedDate = self::parseLocalDate(
                $requestedDateText,
                $now->getTimezone()
            );

            if ( !$requestedDate ) {
                $result = Html::element(
                    'div',
                    [
                        'class' => 'davispedia-potd-error davispedia-potd-date-lookup-error',
                        'role' => 'alert',
                    ],
                    wfMessage(
                        'pictureoftheday-lookup-invalid-date',
                        $requestedDateText
                    )->text()
                );
            } else {
                $selection = self::resolveSelectionForDate(
                    $requestedDate,
                    $entries,
                    $startDate,
                    $parser,
                    $frame,
                    true
                );
                $dateLabel = $requestedDate->format( 'F j, Y' );

                $result = Html::rawElement(
                    'section',
                    [ 'class' => 'davispedia-potd-date-lookup-result' ],
                    Html::element(
                        'h3',
                        [ 'class' => 'davispedia-potd-date-lookup-result-heading' ],
                        wfMessage(
                            'pictureoftheday-lookup-result-heading',
                            $dateLabel
                        )->text()
                    )
                    . Html::element(
                        'div',
                        [ 'class' => 'davispedia-potd-schedule-source' ],
                        $selection['source']
                    )
                    . $selection['html']
                    . Html::element(
                        'p',
                        [ 'class' => 'davispedia-potd-date-lookup-assumption' ],
                        wfMessage( 'pictureoftheday-lookup-assumption' )->text()
                    )
                );
            }
        }

        return Html::rawElement(
            'section',
            [
                'class' => 'davispedia-potd-date-lookup',
                'aria-labelledby' => 'davispedia-potd-date-lookup-heading',
            ],
            Html::element(
                'h2',
                [
                    'id' => 'davispedia-potd-date-lookup-heading',
                    'class' => 'davispedia-potd-date-lookup-heading',
                ],
                wfMessage( 'pictureoftheday-lookup-heading' )->text()
            )
            . Html::element(
                'p',
                [ 'class' => 'davispedia-potd-date-lookup-description' ],
                wfMessage( 'pictureoftheday-lookup-description' )->text()
            )
            . $form
            . $result
        );
    }

    private static function resolveSelectionForDate(
        DateTimeImmutable $date,
        array $entries,
        DateTimeImmutable $startDate,
        Parser $parser,
        PPFrame $frame,
        bool $preview
    ): array {
        global $wgPictureOfTheDayPagePrefix;

        $prefix = is_string( $wgPictureOfTheDayPagePrefix )
            ? rtrim( $wgPictureOfTheDayPagePrefix, '/' )
            : 'MediaWiki:PictureOfTheDay';
        $overrideTitle = Title::newFromText(
            $prefix . '/' . $date->format( 'Y-m-d' )
        );

        if ( $overrideTitle && $overrideTitle->exists() ) {
            return [
                'html' => self::transclude( $overrideTitle, $parser, $frame ),
                'source' => wfMessage( 'pictureoftheday-schedule-override' )->text(),
            ];
        }

        $dayOffset = (int)$startDate->diff( $date )->format( '%r%a' );
        $index = self::positiveModulo( $dayOffset, count( $entries ) );
        $selected = $entries[$index];
        $width = $preview ? self::PREVIEW_IMAGE_WIDTH : self::getConfiguredImageWidth();

        return [
            'html' => self::renderEntryAtWidth(
                $selected['body'],
                $selected['args'],
                $parser,
                $frame,
                $width
            ),
            'source' => wfMessage(
                'pictureoftheday-schedule-queue-position',
                $index + 1,
                count( $entries )
            )->text(),
        ];
    }

    private static function renderEntryAtWidth(
        ?string $input,
        array $args,
        Parser $parser,
        PPFrame $frame,
        int $requestedWidth
    ): string {
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

        $width = max( 100, min( 1600, $requestedWidth ) );
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

    private static function parsePreviewDays( string $value ): int {
        $days = filter_var( trim( $value ), FILTER_VALIDATE_INT );
        if ( $days === false ) {
            return self::DEFAULT_PREVIEW_DAYS;
        }

        return max( 1, min( self::MAX_PREVIEW_DAYS, $days ) );
    }

    private static function positiveModulo( int $value, int $divisor ): int {
        return ( ( $value % $divisor ) + $divisor ) % $divisor;
    }

    private static function getConfiguredImageWidth(): int {
        global $wgPictureOfTheDayImageWidth;

        return is_int( $wgPictureOfTheDayImageWidth )
            ? $wgPictureOfTheDayImageWidth
            : (int)$wgPictureOfTheDayImageWidth;
    }

    private static function isDirectQueuePageView( Parser $parser ): bool {
        $queueTitle = self::getQueueTitle();
        $parserTitle = $parser->getTitle();

        return $queueTitle !== null
            && $parserTitle !== null
            && $queueTitle->getPrefixedDBkey() === $parserTitle->getPrefixedDBkey();
    }

    private static function getQueueTitle(): ?Title {
        global $wgPictureOfTheDayQueuePage;

        return Title::newFromText(
            is_string( $wgPictureOfTheDayQueuePage )
                ? $wgPictureOfTheDayQueuePage
                : 'MediaWiki:PictureOfTheDay/Queue'
        );
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

    private static function sanitizeFileOption( string $value ): string {
        return trim( str_replace(
            [ '|', ']]', "\r", "\n" ],
            [ ' ', ' ', ' ', ' ' ],
            $value
        ) );
    }
}

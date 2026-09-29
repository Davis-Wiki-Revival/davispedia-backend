# PictureOfTheDay

A MediaWiki extension for Davispedia that rotates through a queue of pictures,
with optional date-specific overrides, a two-week queue preview, a date lookup,
and a final fallback page.

## Install

Place the directory at `extensions/PictureOfTheDay` and add:

```php
wfLoadExtension( 'PictureOfTheDay' );
```

Put this on the Main Page:

```wiki
<davispedia-picture-of-the-day />
```

## Queue

Create `MediaWiki:PictureOfTheDay/Queue`:

```wiki
<davispedia-picture-of-the-day-queue start="2026-09-28">

<davispedia-picture-of-the-day-entry
 file="2023-03-09751.png"
 alt="A view of the Davis Arboretum"
 link="Davis Arboretum">
A view of the [[Davis Arboretum]].
</davispedia-picture-of-the-day-entry>

<davispedia-picture-of-the-day-entry
 file="Mycowpicstercero.jpg"
 alt="Cows near the Animal Sciences Teaching Facility"
 link="Animal Sciences Teaching Facility">
Cows near the [[Animal Sciences Teaching Facility]].
</davispedia-picture-of-the-day-entry>

</davispedia-picture-of-the-day-queue>
```

The first item appears on the `start` date, the second item the next day, and
so on. After the final item, the queue wraps back to the first. Reordering or
adding items changes the future rotation.

## Queue dashboard

Opening `MediaWiki:PictureOfTheDay/Queue` directly shows the next 14 days and a
form for checking any specific date. The lookup accounts for the current queue
order, queue start date, and any dated override that already exists. Its answer
is a prediction and can change after later edits.

The default preview length is 14 days. It can be changed on the queue wrapper:

```wiki
<davispedia-picture-of-the-day-queue start="2026-09-28" preview-days="30">
```

When the queue page is transcluded through `<davispedia-picture-of-the-day />`,
it renders only the entry selected for the current date.

## Dated override

A page such as `MediaWiki:PictureOfTheDay/2026-10-31` takes precedence over the
queue on that date. Its content is one normal entry tag.

## Fallback

`MediaWiki:PictureOfTheDay/default` is used only when the queue page is missing
or contains no entry tags.

## Configuration

```php
$wgPictureOfTheDayTimeZone = 'America/Los_Angeles';
$wgPictureOfTheDayPagePrefix = 'MediaWiki:PictureOfTheDay';
$wgPictureOfTheDayQueuePage = 'MediaWiki:PictureOfTheDay/Queue';
$wgPictureOfTheDayQueueStartDate = '2026-09-28';
$wgPictureOfTheDayFallbackPage = 'MediaWiki:PictureOfTheDay/default';
$wgPictureOfTheDayImageWidth = 600;
```

The `start` attribute on the queue page overrides
`$wgPictureOfTheDayQueueStartDate`.

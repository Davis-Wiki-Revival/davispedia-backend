# DavispediaPictureOfTheDay

A small MediaWiki extension that selects and renders a protected, date-named
Picture of the Day page using the Davis local date.

## Install

1. Copy this directory to `extensions/DavispediaPictureOfTheDay`.
2. Add to `LocalSettings.php`:

   ```php
   wfLoadExtension( 'DavispediaPictureOfTheDay' );
   ```

3. Create the fallback page `MediaWiki:PictureOfTheDay/default`.
4. Create scheduled pages using the name
   `MediaWiki:PictureOfTheDay/YYYY-MM-DD`.
5. Put `<davispedia-picture-of-the-day />` on the Main Page.

## Scheduled page example

Create `MediaWiki:PictureOfTheDay/2026-09-28` with:

```wiki
<davispedia-picture-of-the-day-entry
 file="2026-09-14-davis-arboretum.jpg"
 alt="A view of the Davis Arboretum"
 link="Davis Arboretum">
A view of the [[Davis Arboretum]].
</davispedia-picture-of-the-day-entry>
```

The optional `credit` attribute accepts wikitext. If omitted, the extension
adds a link to the file-description page.

## Main Page

```wiki
<div class="davispedia-feature-card davispedia-feature-red">
    <h2>[[Picture of the day]]</h2>
    <davispedia-picture-of-the-day />
</div>
```

## Configuration

```php
$wgDavispediaPictureOfTheDayTimeZone = 'America/Los_Angeles';
$wgDavispediaPictureOfTheDayPagePrefix = 'MediaWiki:PictureOfTheDay';
$wgDavispediaPictureOfTheDayFallbackPage = 'MediaWiki:PictureOfTheDay/default';
$wgDavispediaPictureOfTheDayImageWidth = 600;
```

The parser cache is set to expire at the next local midnight. When today's
page is missing, the fallback is cached for no more than five minutes, so a
newly created entry appears without waiting until tomorrow. Because the chosen
page is transcluded normally, editing it participates in MediaWiki's standard
dependency invalidation.

# Cowlender MediaWiki extension

Cowlender is Davispedia's community event calendar backend. It uses the
existing MediaWiki session, users, permission system, database abstraction,
and CSRF tokens. No second application service or account system is required.

## Enable the extension

Add the following to `LocalSettings.php` and run MediaWiki's update command:

```php
wfLoadExtension( 'Cowlender' );
```

```sh
php maintenance/run.php update --quick
```

The schema supports MariaDB/MySQL in production and SQLite in the repository's
local Compose environment.

## HTTP API

All routes are rooted at `/rest.php/cowlender/v1`.

| Method | Route | Purpose |
|---|---|---|
| `GET` | `/meta` | User, permissions, categories, statuses, and limits |
| `GET` | `/events?start=…&end=…` | Events overlapping a half-open interval |
| `POST` | `/events` | Create an event |
| `GET` | `/events/{id}` | Read one event |
| `PATCH` | `/events/{id}` | Update fields using optimistic versioning |
| `DELETE` | `/events/{id}?version=N` | Delete an event |
| `GET` | `/events/{id}/revisions` | Read immutable audit snapshots |

Write requests use `Content-Type: application/json` and require a standard
MediaWiki CSRF token in the `X-CSRF-Token` header. A browser client can obtain
one through the Action API:

```text
/api.php?action=query&meta=tokens&type=csrf&format=json
```

### Create a timed event

Timed values must be RFC 3339 timestamps with seconds and an explicit offset.
The backend stores the instants in UTC and separately preserves the intended
IANA timezone.

```json
{
  "title": "Davis Farmers Market",
  "description": "Saturday market",
  "location": "Central Park",
  "start": "2026-09-05T08:00:00-07:00",
  "end": "2026-09-05T13:00:00-07:00",
  "allDay": false,
  "timezone": "America/Los_Angeles",
  "status": "scheduled",
  "category": "community",
  "externalUrl": "https://example.org/events/farmers-market"
}
```

### Create an all-day event

All-day values remain dates. `end` is exclusive, matching FullCalendar and
iCalendar conventions; a one-day event on September 5 ends on September 6.

```json
{
  "title": "Community Festival",
  "start": "2026-09-05",
  "end": "2026-09-06",
  "allDay": true,
  "timezone": "America/Los_Angeles"
}
```

### Update and delete safely

Every event response includes `version`. Send the version originally loaded
when editing:

```json
{
  "title": "Updated title",
  "version": 3
}
```

If another user already changed version 3, the API returns `409 Conflict`
with the current version instead of overwriting it. Deletion similarly uses
`DELETE /events/123?version=3`.

## Permissions

| MediaWiki group | Default abilities |
|---|---|
| Everyone | View events and history |
| Logged-in `user` | Create events and edit their own |
| `cowlender-moderator` | Edit and delete every event |
| `sysop` | Edit/delete every event and administer Cowlender |

The concrete rights are `cowlender-view`, `cowlender-create`,
`cowlender-edit-own`, `cowlender-edit-all`, `cowlender-delete`, and
`cowlender-admin`. UI visibility is only a convenience; every write is checked
again by the REST handler.

## Configuration

The defaults can be overridden in `LocalSettings.php`:

```php
$wgCowlenderDefaultTimezone = 'America/Los_Angeles';
$wgCowlenderMaxRangeDays = 370;
$wgCowlenderMaxEventsPerRequest = 2000;
$wgCowlenderMaxEventDurationDays = 3660;
```

`$wgCowlenderCategories` is an ordered array of objects containing `slug`,
`label`, and six-digit hex `color` values. The API stores only the stable slug.

## Frontend contract

`Special:Cowlender` renders `#cowlender-root`, adds the API base URL as both
`data-cowlender-api` and `mw.config.get('wgCowlenderRestBaseUrl')`, and leaves
rendering to `davispedia-frontend`. The frontend bundle should check for this
root before importing/mounting the calendar application.

Recurring events are deliberately not accepted yet. The schema reserves a
recurrence-rule field and `/meta` reports `recurrenceSupported: false`, so RFC
5545 recurrence can be added without changing the core event representation.

## Tests

From a MediaWiki development checkout with this extension loaded:

```sh
php tests/phpunit/phpunit.php extensions/Cowlender/tests/phpunit/unit
```

# Davispedia backend

This repository builds Davispedia's MediaWiki 1.46 image. 

## General setup for local development

Local development uses Docker Compose, SQLite, and a local checkout of the
frontend. Clone the repositories next to each other:

```text
parent-directory/
    davispedia-backend/
    davispedia-frontend/
```

The folder names matter because `compose.yml` mounts the frontend from
`../davispedia-frontend`.

Copy the example settings file and start MediaWiki:

```sh
cp LocalSettings.php.example LocalSettings.php
docker compose up -d
```

Apply MediaWiki and Cowlender database updates:

```sh
docker compose exec mediawiki php maintenance/run.php update --quick
```

The local site is available at <http://localhost:8080>. Useful checks are:

- Cowlender page: <http://localhost:8080/index.php/Special:Cowlender>
- Cowlender metadata API: <http://localhost:8080/rest.php/cowlender/v1/meta>

You can also check the API from a terminal:

```sh
curl http://localhost:8080/rest.php/cowlender/v1/meta
```

The Compose file bind-mounts both extensions, so backend PHP changes are
available after a browser refresh. After changing the frontend, rebuild its
bundle from the frontend repository with `npm run build`.

To view logs or stop the local environment:

```sh
docker compose logs -f mediawiki
docker compose down
```

The SQLite database remains in `sqlite/` after the containers stop.

## Cowlender

The Cowlender extension provides the special page, REST API, permissions,
validation, event storage, and revision history. Its React interface lives in
`davispedia-frontend`.

See [extensions/Cowlender/README.md](extensions/Cowlender/README.md) for the
API and configuration contract.

## Production build help

The frontend revision is an explicit build argument:

```sh
docker build --build-arg FRONTEND_REF=<commit-sha> -t davispedia-backend .
```

The deployment workflow uses the frontend commit included in the frontend
repository dispatch event. A direct backend push falls back to frontend
`main`.
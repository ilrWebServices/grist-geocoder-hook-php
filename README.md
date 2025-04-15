# PHP Geocoding Webhook for Grist

This application geocodes address data in Grist webhook payloads and updates Latitude and Longitude fields in the record if possible.

It can use multiple geocoding providers to map strings like '1600 Pennsylvania Avenue NW, Washington, DC, 20500' to geographic coordinates.

## Requirements

- Although not absolutely required, this application is intended to run as a container (Docker, Podman, etc.) alongside a containerized, self-hosted version of Grist, and this documentation will reflect that.
- A Grist document with a table with the following columns:
  - `Address`
  - `Latitude`
  - `Longitude`
- A Grist API token with write permissions to the table.

## Setup

It's easiest to run this application via Docker compose. 

First, copy `.env.example` to `.env` and set `GRIST_ACCESS_TOKEN` with your API token for the Grist document and `ACCESS_TOKEN` to any unique value you choose (I use `pwgen 32` for a nice, long, random string like `oht1ejooMeeh6ThuB0gaphooshee9Usa`).

Next, create a `compose.yml` file alongside your `.env` file. Here is an example that includes Grist.

```
services:
  grist:
    image: gristlabs/grist:latest
    container_name: geocoder_grist
    volumes:
      # Where to store persistent data, such as documents.
      - ${PERSIST_DIR:-./persist}/grist:/persist
    ports:
      - 8585:8484
    environment:
      ALLOWED_WEBHOOK_DOMAINS: '*'
    # Uncomment to use a named private network.
    #networks:
    #  - grist

  geocoder:
    image: docker.io/ilrweb/grist-geocoder-hook-php
    container_name: geocoder_web
    restart: always
    # Uncomment the following for external access. If running in the same
    # compose file, the services will be on the same private network.
    #ports:
      # - "8181:8181"
    environment:
      GRIST_BASE_URL: http://geocoder_grist:8484
      GRIST_ACCESS_TOKEN: ${GRIST_ACCESS_TOKEN}
      ACCESS_TOKEN: ${ACCESS_TOKEN}
    # Uncomment to use a named private network.
    #networks:
    #  - grist

# Uncomment to use a named private network.
#networks:
#  grist:
```

### Grist webhook

[Add a Webhook to the Grist document](https://support.getgrist.com/webhooks/) with the following settings:

`Name`: 'Geocoder' or similar.

`Event Types`: `Add` and `Update`.

`Table`: The name of the data table with the `Address`, `Latitude`, and `Longitude` columns, e.g. `Locations`.

`Filter for changes in these columns`: (optional) `Address`

`Ready Column`: (optional) A boolean (toggle) column that must be `true` for the webhook to fire. `Geocode` is a good column name.

`URL`: The URL of this webhook application instance. If Grist is self-hosted via Docker or similar, you can run this application alongside Grist on the same [Docker network](https://docs.docker.com/compose/how-tos/networking/#specify-custom-networks) and avoid opening an external port.

You should also include two query parameters in the URL:

  - `grist_document`: The id of the current Grist document, e.g. `t1Z7KJvSKXj8vexu8RqtnA`.
  - `grist_table`: The name of the table set above, e.g. `Locations`.

A full `URL` example might look like `http://geocoder_web:8181/?grist_document=t1Z7KJvSKXj8vexu8RqtnA&grist_table=Locations`.

If hosting the webhook app on a qualified domain with open web access, the URL would look like `https://geocode.example.net/?grist_document=t1Z7KJvSKXj8vexu8RqtnA&grist_table=Locations`.

`Header Authorization`: Set this to the string 'Bearer `[ACCESS_TOKEN]`' (note that it starts with 'Bearer' followed by a single space) where `[ACCESS_TOKEN]` is the same as the value configured in the `.env` file. This is used to limit access to the webhook app.

`Enabled`: Set to `true` to enable. 

## Development

A nice option for local development of the PHP application is [Compose Watch](https://docs.docker.com/compose/how-tos/file-watch/).

```
docker compose up --watch
```

Any changes to the `docroot` or `src` directories will by copied into the running container.

## Build and publish the container image

Two simple scripts are included to build and publish the image:

`./scripts/build.sh` will create build the image with the `:latest` tag.

`./scripts/publish.sh` will push the image to Docker hub using the `ilrweb@cornell.edu` account. A `docker login` may be required before running this script.

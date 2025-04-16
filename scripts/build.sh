#!/usr/bin/env bash

set -e

if ! [ -z "$(git status --porcelain)" ]; then
  echo 'Please run this script in a clean working directory.' >&2
  exit 1
fi

docker build --tag ilrweb/grist-geocoder-hook-php:latest .

exit 0

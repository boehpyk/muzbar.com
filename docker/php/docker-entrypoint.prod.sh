#!/bin/sh
set -eu

# Publish THIS image's built public/ into the shared volume that nginx serves.
#
# Why copy on every start: Docker only seeds a named volume from the image when the
# volume is EMPTY. On a redeploy the volume already holds the previous image's assets,
# so Docker would NOT refresh them — nginx would serve stale assets that no longer match
# the running app. Copying here keeps the volume in lockstep with the deployed image.
TARGET="${PUBLIC_ASSETS_DIR:-/mnt/public}"
mkdir -p "$TARGET"
cp -a /var/www/html/public/. "$TARGET"/

exec "$@"

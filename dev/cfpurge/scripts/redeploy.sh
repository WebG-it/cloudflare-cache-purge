#!/bin/bash
# Dev-only: rebuild the package on the host and install it over the one in the container.
# Run from dev/cfpurge on the host:   bash scripts/redeploy.sh [compose project args...]
set -e
cd "$(dirname "$0")/../../.."
python -I build/build_plugin.py plugins/pkg_cfpurge | tail -1
cd dev/cfpurge
export MSYS_NO_PATHCONV=1
docker compose "$@" exec web bash -c 'cp /dist/pkg_cfpurge-2.0.0.zip tmp/ && bash /dev-scripts/install_zip.sh /var/www/html/tmp/pkg_cfpurge-2.0.0.zip' | grep -E "OK|ERROR|rror" | head -3

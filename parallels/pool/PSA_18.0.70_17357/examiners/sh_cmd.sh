#!/bin/sh
### Copyright 1999-2025. WebPros International GmbH. All rights reserved.

[ "X${PLESK_INSTALLER_DEBUG}" = "X" ] || set -x
[ "X${PLESK_INSTALLER_STRICT_MODE}" = "X" ] || set -e

exec "$@"

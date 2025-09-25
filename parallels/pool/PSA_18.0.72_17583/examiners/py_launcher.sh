#!/bin/sh
### Copyright 1999-2025. WebPros International GmbH. All rights reserved.

die()
{
	echo "$*"
	exit 1
}

[ -f "$1" ] || die "Usage: $0 PEX [args...]"

[ "X${PLESK_INSTALLER_DEBUG}" = "X" ] || set -x
[ "X${PLESK_INSTALLER_STRICT_MODE}" = "X" ] || set -e

find_python_bin()
{
	local bin
	for bin in "/opt/psa/bin/py3-python" "/usr/local/psa/bin/py3-python" "/usr/libexec/platform-python" "/usr/bin/python3" "/opt/psa/bin/python" "/usr/local/psa/bin/python" "/usr/bin/python2"; do
		[ -x "$bin" ] || continue
		python_bin="$bin"
		return 0
	done

	return 1
}

find_python_bin ||
	die "Unable to locate Python interpreter to execute the script."

exec "$python_bin" "$@"

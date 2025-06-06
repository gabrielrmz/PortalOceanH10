#!/bin/sh
### Copyright 1999-2024. WebPros International GmbH. All rights reserved.

# If env variable PLESK_INSTALLER_ERROR_REPORT=path_to_file is specified then in case of error
# check-broken-tz.sh writes single line json report into it with the following fields:
# - "stage": "timezonefix"
# - "level": "error"
# - "errtype": "failure"
# - "date": time of error occurance ("2024-07-24T06:59:43,127545441+0000")
# - "error": human readable error message

[ -z "$PLESK_INSTALLER_DEBUG" ] || set -x
[ -z "$PLESK_INSTALLER_STRICT_MODE" ] || set -e

export LC_ALL=C
unset GREP_OPTIONS

SKIP_FLAG="/tmp/plesk-installer-skip-check-broken-timezone.flag"
# following variables are designed to be used as bit flags
RET_WARN=1
RET_FATAL=2

# @params are tags in format "key=value"
# Report body (human readable information) is read from stdin
# and copied to stderr.
make_error_report()
{
	local report_file="${PLESK_INSTALLER_ERROR_REPORT:-}"

	local python_bin=
	for bin in "/opt/psa/bin/python" "/usr/local/psa/bin/python" "/usr/bin/python2" "/opt/psa/bin/py3-python" "/usr/local/psa/bin/py3-python" "/usr/libexec/platform-python" "/usr/bin/python3"; do
		if [ -x "$bin" ]; then
			python_bin="$bin"
			break
		fi
	done

	if [ -n "$report_file" -a -x "$python_bin" ]; then
		"$python_bin" -c 'import sys, json
report_file = sys.argv[1]
error = sys.stdin.read()

sys.stderr.write(error)

data = {
    "error": error,
}

for tag in sys.argv[2:]:
    k, v = tag.split("=", 1)
    data[k] = v

with open(report_file, "a") as f:
    json.dump(data, f)
    f.write("\n")
' "$report_file" "date=$(date --utc --iso-8601=ns)" "$@"
	else
		cat - >&2
	fi
}

report_dpkg_configure_fail()
{
	local pkgname="$1"
	make_error_report 'stage=timezonefix' 'level=error' 'errtype=dpkgconfigurefailed' <<-EOL
		Could not configure the packages ( $pkgname ). See https://support.plesk.com/hc/en-us/articles/24721507961623-Plesk-provides-error-on-update-Package-tzdata-is-not-configured-yet for more details.
	EOL
}

report_get_tz_fail()
{
	make_error_report 'stage=timezonefix' 'level=error' 'errtype=gettzfailed' <<-EOL
		Could not get the system timezone. See https://support.plesk.com/hc/en-us/articles/24721507961623-Plesk-provides-error-on-update-Package-tzdata-is-not-configured-yet for more details.
	EOL
}

report_set_tz_fail()
{
	local tz="$1"

	make_error_report 'stage=timezonefix' 'level=error' 'errtype=settzfailed' <<-EOL
		Could not set the system timezone ( $tz ). See https://support.plesk.com/hc/en-us/articles/24721507961623-Plesk-provides-error-on-update-Package-tzdata-is-not-configured-yet for more details.
	EOL
}

get_current_tz()
{
	[ -L /etc/localtime ] || return 1

	local tz
	tz="$(readlink -m /etc/localtime)" || return 1
	[ -f "$tz" ] || return 1
	case "$tz" in 
		/usr/share/zoneinfo/*) ;;
		*) return 1;;
	esac
	tz="${tz#/usr/share/zoneinfo/}"
	[ -n "$tz" ] || return 1

	echo -n "${tz}"
}

check_timezone_ubuntu()
{
	[ -n "$os_codename" ] || return 0
	local mode="$1"

	# PPP-65676: Plesk update fails on ubuntu if timezone is CET
	if dpkg-query --showformat='${db:Status-Status}\n' --show 'tzdata' | grep -wq 'half-configured'; then
		local origtz
		origtz=$(get_current_tz)
		if [ $? != 0 ]; then
			report_get_tz_fail
			return $RET_WARN
		fi
		if ! timedatectl set-timezone 'Etc/UTC'; then
			timedatectl set-timezone "$origtz"
			report_set_tz_fail 'Etc/UTC'
			return $RET_WARN
		fi
		if ! dpkg --configure 'tzdata'; then
			timedatectl set-timezone "$origtz"
			report_dpkg_configure_fail 'tzdata'
			return $RET_WARN
		fi
		if ! timedatectl set-timezone "$origtz"; then
			report_set_tz_fail "$origtz"
			return $RET_WARN
		fi
	fi

	return 0
}

detect_platform()
{
	. /etc/os-release
	os_name="$ID"
	os_version="${VERSION_ID%%.*}"
	os_arch="$(uname -m)"
	if [ -e /etc/debian_version ]; then
		case "$os_arch" in
			x86_64)  pkg_arch="amd64" ;;
			aarch64) pkg_arch="arm64" ;;
		esac
		if [ -n "$VERSION_CODENAME" ]; then
			os_codename="$VERSION_CODENAME"
		else
			case "$os_name$os_version" in
				debian10) os_codename="buster"   ;;
				debian11) os_codename="bullseye" ;;
				debian12) os_codename="bookworm" ;;
				ubuntu18) os_codename="bionic"   ;;
				ubuntu20) os_codename="focal"    ;;
				ubuntu22) os_codename="jammy"    ;;
				ubuntu24) os_codename="noble"    ;;
			esac
		fi
	fi

	case "$os_name$os_version" in
		rhel7|centos7|cloudlinux7|virtuozzo7)
			package_manager="yum"
		;;
		rhel*|centos*|cloudlinux*|almalinux*|rocky*)
			package_manager="dnf"
		;;
		debian*|ubuntu*)
			package_manager="apt"
		;;
	esac
}

check_timezone()
{
	detect_platform

	# try to execute checker only if all attributes are detected
	[ -n "$os_name" -a -n "$os_version" ] || return 0

	local mode="$1"
	local prefix="check_timezone"
	for checker in "${prefix}_${os_name}${os_version}" "${prefix}_${os_name}"; do
		case "`type "$checker" 2>/dev/null`" in
			*function*)
				local rc=0
				"$checker" "$mode" || rc=$?
				[ "$(( $rc & $RET_FATAL ))" = "0" ] || return $RET_FATAL
				[ "$(( $rc & $RET_WARN  ))" = "0" ] || return $RET_WARN
				return $rc
			;;
		esac
	done
	return 0
}

# ---

if [ -f "$SKIP_FLAG" ]; then
	echo "Broken timezone check was skipped due to flag file." >&2
	exit 0
fi

check_timezone "$1"

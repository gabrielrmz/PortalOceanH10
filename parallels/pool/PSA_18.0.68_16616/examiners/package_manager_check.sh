#!/bin/sh
### Copyright 1999-2025. WebPros International GmbH. All rights reserved.

[ -z "$PLESK_INSTALLER_DEBUG" ] || set -x
[ -z "$PLESK_INSTALLER_STRICT_MODE" ] || set -e

export LC_ALL=C
unset GREP_OPTIONS

RET_SUCCESS=0
RET_WARN=1
RET_FATAL=2

is_function_defined()
{
	local fn="$1"
	case "$(type $fn 2>/dev/null)" in
	*function*)
		return 0
		;;
	esac
	return 1
}

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

	if [ "$os_name" = "ubuntu" -o "$os_name" = "debian" ]; then
		PRODUCT_ROOT_D="/opt/psa"
	else
		PRODUCT_ROOT_D="/usr/local/psa"
	fi
}

has_os_impl_function()
{
	local prefix="$1"
	local fn="${prefix}_${os_name}${os_version}"
	is_function_defined "$fn"
}

call_os_impl_function()
{
	local prefix="$1"
	shift
	local fn="${prefix}_${os_name}${os_version}"
	"$fn" "$@"
}

skip_checker_on_flag()
{
	local name="$1"
	local flag="$2"

	if [ -f "$flag" ]; then
		echo "$name was skipped due to flag file." >&2
		exit $RET_SUCCESS
	fi
}

skip_checker_on_env()
{
	local name="$1"
	local env="$2"

	if [ -n "$env" ]; then
		echo "$name was skipped due to environment variable." >&2
		exit $RET_SUCCESS
	fi
}

checker_main()
{
	local fnprefix="$1"
	shift

	detect_platform
	# try to execute checker only if all attributes are detected
	[ -n "$os_name" -a -n "$os_version" ] || return $RET_SUCCESS

	for checker in "${fnprefix}_${os_name}${os_version}" "${fnprefix}_${os_name}" "${fnprefix}"; do
		if is_function_defined "$checker"; then
			local rc=$RET_SUCCESS
			"$checker" "$@" || rc=$?
			[ "$(( $rc & $RET_FATAL ))" = "0" ] || return $RET_FATAL
			[ "$(( $rc & $RET_WARN  ))" = "0" ] || return $RET_WARN
			return $rc
		fi
	done
	return $RET_SUCCESS
}

#!/bin/sh
### Copyright 1999-2025. WebPros International GmbH. All rights reserved.

check_package_manager_deb_based()
{
	local output=
	output="`dpkg --audit 2>&1`" || output="$output"$'\n'"'dpkg --audit' finished with error code $?."

	if [ -n "$output" ]; then
		make_error_report 'stage=packagemanagercheck' 'level=error' 'errtype=brokenpackages' <<-EOL
			The system package manager reports the following problems:

			$output

			To continue with the installation, you need to resolve these issues
			using the procedure below:

			1.   Make sure you have a full server snapshot. Although the
			     following steps are usually safe, they can still cause
			     data loss or irreversible changes.
			2.   Run 'dpkg --configure -a'. This command can fix some of the
			     issues. However, it may fail. Regardless if it fails or not,
			     proceed with the following steps.
			3.   Run 'PLESK_INSTALLER_SKIP_PACKAGE_MANAGER_CHECK=1 plesk installer update --skip-cleanup'.
			     Instead of 'update', you may need to use the command you used
			     previously (for example, 'upgrade' or 'install').
			4.   The next step depends on the outcome of the previous one:
			   - If step 3 was completed with the "You already have the latest
			     version of product(s) and all the selected components installed.
			     Installation will not continue." message,
			     run 'plesk repair installation'.
			   - If step 3 failed, run 'dpkg --audit'. This command can show you
			     packages that need to be reinstalled. To reinstall them, run
			     'apt-get install --reinstall <packages>'.
			5.   Run 'plesk installer update' to revert temporary changes and
			     validate that the issues are resolved. If the command fails or
			     triggers this check again, contact Plesk support.

			For more information, see
			https://support.plesk.com/hc/en-us/articles/12871173047447-Plesk-update-on-Debian-Ubuntu-fails-dpkg-was-interrupted-you-must-manually-run-dpkg-configure-a-to-correct-the-problem
		EOL
		return "$RET_FATAL"
	fi
}

check_package_manager_debian()
{
	check_package_manager_deb_based
}

check_package_manager_ubuntu()
{
	check_package_manager_deb_based
}

skip_checker_on_env "Package manager check" "$PLESK_INSTALLER_SKIP_PACKAGE_MANAGER_CHECK"
skip_checker_on_flag "Package manager check" "/tmp/plesk-installer-skip-package-manager-check.flag"
checker_main 'check_package_manager' "$@"

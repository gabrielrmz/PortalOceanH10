#!/bin/sh
### Copyright 1999-2024. WebPros International GmbH. All rights reserved.

# If env variable PLESK_INSTALLER_ERROR_REPORT=path_to_file is specified then in case of error
# disk_space_check.sh writes single line json report into it with the following fields:
# - "stage": "diskspacecheck"
# - "level": "error"
# - "errtype": "notenoughdiskspace"
# - "volume": volume with not enough diskspace (e.g. "/")
# - "required": required diskspace on the volume, human readable (e.g. "600 MB")
# - "available": available diskspace on the volume, human readable (e.g. "255 MB")
# - "needtofree": amount of diskspace which should be freed on the volume, human readable (e.g. "345 MB")
# - "date": time of error occurance ("2020-03-24T06:59:43,127545441+0000")
# - "error": human readable error message ("There is not enough disk space available in the / directory.")

# Required values below for Full installation are in MB. See 'du -cs -BM /*' and 'df -Pm'.

required_disk_space_cloudlinux7()
{
	case "$1" in
	/opt)	echo 900	;;
	/usr)	echo 4400	;;
	/var)	echo 600	;;
	/tmp)	echo 100	;;
	esac
}

required_disk_space_cloudlinux8()
{
	case "$1" in
	/opt)	echo 1200	;;
	/usr)	echo 4400	;;
	/var)	echo 700	;;
	/tmp)	echo 100 	;;
	esac
}

required_disk_space_centos7()
{
	case "$1" in
	/opt)	echo 900	;;
	/usr)	echo 4100	;;
	/var)	echo 600	;;
	/tmp)	echo 100	;;
	esac
}

required_disk_space_centos8()
{
	case "$1" in
	/opt)	echo 900	;;
	/usr)	echo 4500	;;
	/var)	echo 800	;;
	/tmp)	echo 100	;;
	esac
}

required_disk_space_virtuozzo7()
{
	required_disk_space_centos7 "$1"
}

required_disk_space_rhel7()
{
	required_disk_space_centos7 "$1"
}

required_disk_space_rhel8()
{
	required_disk_space_centos8 "$1"
}

required_disk_space_almalinux8()
{
	required_disk_space_centos8 "$1"
}

required_disk_space_rocky8()
{
	required_disk_space_centos8 "$1"
}

required_disk_space_rhel9()
{
	case "$1" in
	/opt)	echo 500	;;
	/usr)	echo 4000	;;
	/var)	echo 800	;;
	/tmp)	echo 100	;;
	esac
}

required_disk_space_almalinux9()
{
	required_disk_space_rhel9 "$1"
}

required_disk_space_debian10()
{
	case "$1" in
	/opt)	echo 1800	;;
	/usr)	echo 2300	;;
	/var)	echo 1700	;;
	/tmp)	echo 100	;;
	esac
}

required_disk_space_debian11()
{
	case "$1" in
	/opt)	echo 1500	;;
	/usr)	echo 3100	;;
	/var)	echo 1800	;;
	/tmp)	echo 100	;;
	esac
}

required_disk_space_debian12()
{
	case "$1" in
	/opt)	echo 2700	;;
	/usr)	echo 2500	;;
	/var)	echo 2200	;;
	/tmp)	echo 100	;;
	esac
}

required_disk_space_ubuntu18()
{
	case "$1" in
	/opt)	echo 900	;;
	/usr)	echo 1800	;;
	/var)	echo 600	;;
	/tmp)	echo 100	;;
	esac
}

required_disk_space_ubuntu20()
{
	case "$1" in
	/opt)	echo 1800	;;
	/usr)	echo 2900	;;
	/var)	echo 1600	;;
	/tmp)	echo 100	;;
	esac
}

required_disk_space_ubuntu22()
{
	case "$1" in
	/opt)	echo 1800	;;
	/usr)	echo 3900	;;
	/var)	echo 1900	;;
	/tmp)	echo 100	;;
	esac
}

required_update_upgrade_disk_space()
{
	case "$1" in
	/opt)	echo 100	;;
	/usr)	echo 300	;;
	/var)	echo 600	;;
	/tmp)	echo 100	;;
	esac
}

clean_tmp()
{
	local volume="$1"
	local path="/tmp"
	is_path_on_volume "$path" "$volume" || return 0

	echo "Cleaning $path via 'systemd-tmpfiles --clean --prefix $path'"
	systemd-tmpfiles --clean --prefix "$path" 2>&1
}

clean_yum()
{
	local volume="$1"
	local path="/var/cache/yum"
	is_path_on_volume "$path" "$volume" || return 0

	echo "Cleaning $path via 'yum clean all'"
	yum clean all 2>&1

	# The command above doesn't clean untracked repos (missing in configuration), clean if left > 2 Mb
	[ "`du -sm "$path" | awk '{ print $1 }'`" -gt 2 ] || return 0
	echo "Cleaning $path via 'rm -rf $path/*'"
	rm -rf "$path"/* 2>&1
}

clean_dnf()
{
	local volume="$1"
	local path="/var/cache/dnf"
	is_path_on_volume "$path" "$volume" || return 0

	echo "Cleaning $path via 'dnf clean all'"
	dnf clean all 2>&1
}

clean_apt()
{
	local volume="$1"
	local path="/var/cache/apt"
	is_path_on_volume "$path" "$volume" || return 0

	echo "Cleaning $path via 'apt-get clean'"
	apt-get clean 2>&1
}

clean_journal()
{
	local volume="$1"
	local path="/var/log/journal"
	is_path_on_volume "$path" "$volume" || return 0

	# Note that --rotate may cause more space to be freed, but may also cause more space to be used
	echo "Cleaning $path via 'journalctl --vacuum-time 1d'"
	journalctl --vacuum-time 1d 2>&1
}

clean_ext_packages()
{
	detect_platform

	local volume="$1"
	local path="$PRODUCT_ROOT_D/var/modules-packages"
	is_path_on_volume "$path" "$volume" || return 0

	echo "Cleaning $path via 'rm -rf $path/*'"
	rm -rf "$path"/* 2>&1
}

[ -z "$PLESK_INSTALLER_DEBUG" ] || set -x
[ -z "$PLESK_INSTALLER_STRICT_MODE" ] || set -e

RET_WARN=1
RET_FATAL=2

detect_platform()
{
	. /etc/os-release
	os_name="${ID:-unknown}"
	os_version="${VERSION_ID%%.*}"

	if [ "$os_name" = "ubuntu" -o "$os_name" = "debian" ]; then
		PRODUCT_ROOT_D="/opt/psa"
	else
		PRODUCT_ROOT_D="/usr/local/psa"
	fi
}

# @param $1 target directory
mount_point()
{
	df -Pm $1 | awk 'NR==2 { print $6 }'
}

# @param $1 target directory
available_disk_space()
{
	df -Pm $1 | awk 'NR==2 { print $4 }'
}

is_path_on_volume()
{
	local path="$1"
	local volume="$2"
	[ -d "$path" ] && [ "`mount_point "$path"`" = "$volume" ]
}

# @param $1 target directory
# @param $2 mode (install/upgrade/update)
required_disk_space()
{
	if [ "$2" != "install" ]; then
		required_update_upgrade_disk_space "$1"
		return
	fi

	detect_platform
	local f="required_disk_space_$os_name$os_version"

	case "$(type $f 2>/dev/null)" in
	*function*)
		$f "$1"
		;;
	*)
		echo "There are no requirements defined for $os_name$os_version." >&2
		echo "Disk space check cannot be performed." >&2
		exit 1
		;;
	esac
}

human_readable_size()
{
	echo "$1" | awk '
		function human(x) {
			s = "MGTEPYZ";
			while (x >= 1000 && length(s) > 1) {
				x /= 1024; s = substr(s, 2);
			}
			# 0.05 below will make sure the value is rounded up
			return sprintf("%.1f %sB", x + 0.05, substr(s, 1, 1));
		}
		{ print human($1); }'
}

# @param $1 target directory
# @param $2 required disk space
# @param $3 check only flag (don't emit errors)
check_available_disk_space()
{
	local volume="$1"
	local required="$2"
	local check_only="${3:-}"
	local available="$(available_disk_space "$volume")"
	if [ "$available" -lt "$required" ]; then
		local needtofree
		needtofree="`human_readable_size $((required - available))`"
		[ -n "$check_only" ] ||
			make_error_report 'stage=diskspacecheck' 'level=error' 'errtype=notenoughdiskspace' \
				"volume=$volume" "required=$required MB" "available=$available MB" "needtofree=$needtofree" \
				<<-EOL
					There is not enough disk space available in the $1 directory.
					You need to free up $needtofree.
				EOL
		return "$RET_FATAL"
	fi
}

# @param $1 target directory
# @param $2 required disk space
clean_and_check_available_disk_space()
{
	if [ -n "$PLESK_INSTALLER_FORCE_CLEAN_DISK_SPACE" ] || ! check_available_disk_space "$@" --check-only; then
		clean_disk_space "$1"
		check_available_disk_space "$@"
	fi
}

# Cleans up disk space on the volume
clean_disk_space()
{
	local volume="$1"
	for cleanup_func in clean_tmp clean_yum clean_dnf clean_apt clean_journal clean_ext_packages; do
		"$cleanup_func" "$volume"
	done
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

# @param $1 mode (install/upgrade/update)
clean_and_check_disk_space()
{
	local mode="$1"
	local shared=0

	for target_directory in /opt /usr /var /tmp; do
		local required=$(required_disk_space "$target_directory" "$mode")
		[ -n "$required" ] || return "$RET_WARN"

		if is_path_on_volume "$target_directory" "/"; then
			shared="$((shared + required))"
		else
			clean_and_check_available_disk_space "$target_directory" "$required" || return $?
		fi
	done

	clean_and_check_available_disk_space "/" "$shared" || return $?
}

clean_and_check_disk_space "$1"

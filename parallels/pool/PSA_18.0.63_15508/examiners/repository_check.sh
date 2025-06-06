#!/bin/sh
### Copyright 1999-2024. WebPros International GmbH. All rights reserved.

# If env variable PLESK_INSTALLER_ERROR_REPORT=path_to_file is specified then in case of error
# repository_check.sh writes single line json report into it with the following fields:
# - "stage": "repositorycheck"
# - "level": "error"
# - "errtype" is one of the following:
#   * "reponotcached" - repository is not cached (mostly due to unavailability).
#   * "reponotenabled" - required repository is not enabled.
#   * "reponotsupported" - unsupported repository is enabled.
#   * "configmanagernotinstalled" - dnf config-manager is disabled.
# - "repo": repository name.
# - "date": time of error occurance ("2020-03-24T06:59:43,127545441+0000")
# - "error": human readable error message.

[ -z "$PLESK_INSTALLER_DEBUG" ] || set -x
[ -z "$PLESK_INSTALLER_STRICT_MODE" ] || set -e

export LC_ALL=C
unset GREP_OPTIONS

SKIP_FLAG="/tmp/plesk-installer-skip-repository-check.flag"
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

report_no_repo()
{
	local repo="$1"

	make_error_report 'stage=repositorycheck' 'level=error' 'errtype=reponotenabled' "repo=$repo" <<-EOL
		Plesk installation requires '$repo' OS repository to be enabled.
		Make sure it is available and enabled, then try again.
	EOL
}

report_no_repo_cache()
{
	local repo="$1"

	make_error_report 'stage=repositorycheck' 'level=error' 'errtype=reponotcached' "repo=$repo" <<-EOL
		Unable to create $package_manager cache for '$repo' OS repository.
		Make sure the repository is available, otherwise either disable it or fix its configuration, then try again.
	EOL
}

report_unsupported_repo()
{
	local repo="$1"

	make_error_report 'stage=repositorycheck' 'level=error' 'errtype=reponotsupported' "repo=$repo" <<-EOL
		Plesk installation doesn't support '$repo' OS repository.
		Make sure it is disabled, then try again.
	EOL
}

report_rh_no_config_manager()
{
	local target
	case "$package_manager" in
		yum)
			target="yum-utils package"
		;;
		dnf)
			target="config-manager dnf plugin"
		;;
	esac

	make_error_report 'stage=repositorycheck' 'level=error' 'errtype=configmanagernotinstalled' <<-EOL
		Failed to install $target.
		Make sure repositories configuration of $package_manager package manager is correct
		(use '$package_manager repolist --verbose' to get its actual state), then try again.
	EOL
}

check_rh_broken_repos()
{
	local rh_enabled_repos rh_available_repos

	# 1. `yum repolist` and `dnf repolist` list all repos
	#    which were enabled before last cache creation
	#    even if cache for them was not created.
	#    If some repo is misconfigured and cache was created with `skip_if_unavailable=1`
	#    then such repo will be listed anyway despite on cache state.
	#    If some repo was enabled after last cache creation
	#    then `repolist --cacheonly` will fail.
	# 2. `yum repolist --verbose` and `dnf repoinfo` list only repos
	#    which were successfully cached before.
   	#    These commands fail if at least one repo is not available
	#    and the 'skip_if_unavailable' flag is not set.
	case "$package_manager" in
		yum)
			rh_enabled_repos="$(
				{
					yum repolist enabled --cacheonly -q 2>/dev/null \
					|| yum repolist enabled -q --setopt='*.skip_if_unavailable=1'
				} | sed -n -e '1d' -e 's/^\*\?!\?\([^/[:space:]]\+\).*/\1/p'
			)" || return $RET_FATAL

			rh_available_repos="$(
				yum repolist enabled --verbose --cacheonly -q --setopt='*.skip_if_unavailable=1' \
				| sed -n -e 's/^Repo-id\s*:\s*\([^/[:space:]]\+\).*/\1/p'
			)" || return $RET_FATAL
		;;
		dnf)
			rh_enabled_repos="$(
				{
					dnf repolist --enabled --cacheonly -q 2>/dev/null \
					|| dnf repolist --enabled -q --setopt='*.skip_if_unavailable=1'
				} | sed -n -e '1d' -e 's/^!\?\(\S\+\).*/\1/p'
			)" || return $RET_FATAL

			rh_available_repos="$( \
				dnf repoinfo --enabled --cacheonly -q --setopt='*.skip_if_unavailable=1' \
				| sed -n -e 's|^Repo-id\s*:\s*\(\S\+\)\s*$|\1|p'
			)" || return $RET_FATAL
		;;
	esac

	local rh_enabled_repos_f="$(mktemp /tmp/plesk-installer.preupgrade_checker.XXXXXX)"
	echo "$rh_enabled_repos" | sort > "$rh_enabled_repos_f"
	local rh_available_repos_f="$(mktemp /tmp/plesk-installer.preupgrade_checker.XXXXXX)"
	echo "$rh_available_repos" | sort > "$rh_available_repos_f"

	local repo rc=0
	for repo in $(comm -23 "$rh_enabled_repos_f" "$rh_available_repos_f"); do
		report_no_repo_cache "$repo"
		rc=$RET_WARN
	done

	rm -f "$rh_enabled_repos_f" "$rh_available_repos_f"

	return $rc
}

has_rh_enabled_repo()
{
	local repo="$1"

	# Try to get list of repos from cache first.
	# If some repo was enabled after last cache creation
	# or some repo is unavailable the query from cache will fail.
	# Try to fetch actual metadata in this case.
	case "$package_manager" in
		yum)
			# Repo-id may end with OS version and/or architecture
			# if baseurl of the repo refers to $releasever and/or $basearch variables
			# eg 'epel/7/x86_64', 'epel/7', 'epel/x86_64'
			{
				yum repolist enabled --verbose --cacheonly -q 2>/dev/null \
				|| yum repolist enabled --verbose -q --setopt='*.skip_if_unavailable=1'
			} | egrep -q "^Repo-id\s*: $repo(/.+)?\s*$"
		;;
		dnf)
			# note: --noplugins may cause failure and empty output on RedHat
			{
				dnf repoinfo --enabled --cacheonly -q 2>/dev/null \
				|| dnf repoinfo --enabled -q --setopt='*.skip_if_unavailable=1'
			} | egrep -q "^Repo-id\s*: $repo\s*$"
		;;
	esac
}

has_rh_config_manager()
{
	case "$package_manager" in
		yum) yum-config-manager --help >/dev/null 2>&1 ;;
		dnf) dnf config-manager --help >/dev/null 2>&1 ;;
	esac
}

install_rh_config_manager()
{
	case "$package_manager" in
		yum) yum install --disablerepo 'PLESK_*' -q -y 'yum-utils' --setopt='*.skip_if_unavailable=1' ;;
		dnf) dnf install --disablerepo 'PLESK_*' -q -y 'dnf-command(config-manager)' --setopt='*.skip_if_unavailable=1' ;;
	esac
}

check_rh_config_manager()
{
	if ! has_rh_config_manager && ! install_rh_config_manager; then
		report_rh_no_config_manager
		return $RET_FATAL
	fi
}

enable_rh_repo()
{
	case "$package_manager" in
		yum) yum-config-manager --enable "$@" && has_rh_enabled_repo "$@" ;;
		dnf) dnf config-manager --set-enabled "$@" && has_rh_enabled_repo "$@" ;;
	esac
}

enable_sm_repo()
{
	! has_rh_enabled_repo "$@" || return 0
	subscription-manager repos --enable "$@" || return $?
	# On RedHat 8 above command may return 0 on failure with "Repositories disabled by configuration."
	has_rh_enabled_repo "$@"
}

check_epel()
{
	! enable_rh_repo "epel" || return 0

	# try to install epel-release from centos/extras or plesk/thirdparty repo
	# and then try to update it to last version shipped by epel itself
	# to make package upgradable with pum
	"$package_manager" install --disablerepo 'PLESK_*' -q -y 'epel-release' --setopt='*.skip_if_unavailable=1' 2>/dev/null \
		|| "$package_manager" install --disablerepo='*' --enablerepo 'PLESK_18_*-thirdparty' -q -y 'epel-release' \
		|| "$package_manager" install -q -y "https://dl.fedoraproject.org/pub/epel/epel-release-latest-$os_version.noarch.rpm" \
		&& "$package_manager" update -q -y 'epel-release' --setopt='*.skip_if_unavailable=1' 2>/dev/null

	# Ensure any other EPEL repos have cache for subsequent check for broken repos (AL9)
	local epel_repos="$(
		[ "$package_manager" != "dnf" ] || {
			dnf repolist --enabled --cacheonly -q 2>/dev/null ||
			dnf repolist --enabled -q --setopt='*.skip_if_unavailable=1'
		} | sed -n -e '1d' -e 's/^!\?\(epel\S\+\).*/\1/p'
	)"
	for repo in $epel_repos; do
		"$package_manager" makecache --repo "$repo" -q
	done

	! has_rh_enabled_repo "epel" || return 0

	report_no_repo "epel"
	return $RET_FATAL
}

check_codeready()
{
	local repo_rhel="codeready-builder-for-rhel-$os_version-$os_arch-rpms"
	local repo_rhui="codeready-builder-for-rhel-$os_version-rhui-rpms"
	local repo_rhui_alt="codeready-builder-for-rhel-$os_version-$os_arch-rhui-rpms"
	local repo_rhui_alt2="rhui-codeready-builder-for-rhel-$os_version-$os_arch-rhui-rpms"

	! enable_sm_repo "$repo_rhel" || return 0
	! enable_rh_repo "$repo_rhui" || return 0
	! enable_rh_repo "$repo_rhui_alt" || return 0
	! enable_rh_repo "$repo_rhui_alt2" || return 0

	report_no_repo "$repo_rhel"
	return $RET_FATAL
}

check_optional()
{
	local repo_rhel="rhel-$os_version-server-optional-rpms"
	local repo_rhui="rhel-$os_version-server-rhui-optional-rpms"

	! enable_sm_repo "$repo_rhel" || return 0
	! enable_rh_repo "$repo_rhui" || return 0

	report_no_repo "$repo_rhel"
	return $RET_FATAL
}

check_repos_rhel9()
{
	check_rh_config_manager || return $?

	local rc=0

	check_epel || rc="$(( $rc | $? ))"
	check_codeready || rc="$(( $rc | $? ))"
	check_rh_broken_repos || rc="$(( $rc | $? ))"

	return $rc
}

check_repos_almalinux9()
{
	check_rh_config_manager || return $?

	local rc=0
	check_epel || rc="$(( $rc | $? ))"
	check_rh_broken_repos || rc="$(( $rc | $? ))"

	# powertools is renamed to crb since AlmaLinux 9
	! enable_rh_repo "crb" || return $rc

	report_no_repo "crb"
	return $RET_FATAL
}

check_repos_centos8()
{
	check_rh_config_manager || return $?

	local rc=0
	check_epel || rc="$(( $rc | $? ))"
	check_rh_broken_repos || rc="$(( $rc | $? ))"

	# names of repos are lowercased since 8.3
	! enable_rh_repo "powertools" || return $rc
	! enable_rh_repo "PowerTools" || return $rc

	report_no_repo "powertools"
	return $RET_FATAL
}

check_repos_cloudlinux8()
{
	check_rh_config_manager || return $?

	local rc=0
	check_epel || rc="$(( $rc | $? ))"
	check_rh_broken_repos || rc="$(( $rc | $? ))"

	# names of repos are changed since 8.5
	! enable_rh_repo "powertools" || return $rc
	! enable_rh_repo "cloudlinux-PowerTools" || return $rc

	report_no_repo "powertools"
	return $RET_FATAL
}

check_repos_rhel8()
{
	check_rh_config_manager || return $?

	local rc=0
	check_epel || rc="$(( $rc | $? ))"
	check_rh_broken_repos || rc="$(( $rc | $? ))"

	[ "$1" = "install" ] || return $rc

	check_codeready || rc="$(( $rc | $? ))"

	return $rc
}

check_repos_almalinux8()
{
	check_repos_centos8 "$@"
}

check_repos_rocky8()
{
	check_repos_centos8 "$@"
}

check_repos_rhel7()
{
	check_rh_config_manager || return $?

	local rc=0

	check_epel || rc="$(( $rc | $? ))"
	check_optional || rc="$(( $rc | $? ))"
	check_rh_broken_repos || rc="$(( $rc | $? ))"

	return $rc
}

check_repos_centos7_based()
{
	check_rh_config_manager || return $?

	local rc=0

	check_epel || rc="$(( $rc | $? ))"
	check_rh_broken_repos || rc="$(( $rc | $? ))"

	return $rc
}

sed_escape()
{
	# Note: this is not a full implementation
	echo -n "$1" | sed -e 's|\.|\\.|g'
}

switch_eol_centos_repos()
{
	local old_mirrorlist_host="mirrorlist.centos.org"
	local old_host="mirror.centos.org"
	local new_host="vault.centos.org"

	grep -qFw "$old_host" /etc/yum.repos.d/CentOS-*.repo 2>/dev/null || return 0
	local backup="`mktemp -d "/tmp/yum.repos.d-$(date --rfc-3339=date)-XXXXXX"`"
	! [ -d "$backup" ] || cp -raT /etc/yum.repos.d "$backup" || :

	sed -i \
		-e "s|^\s*\(mirrorlist\b[^/]*//`sed_escape "$old_mirrorlist_host"`/.*\)$|#\1|" \
		-e "s|^#*\s*baseurl\b\([^/]*\)//`sed_escape "$old_host"`/\(.*\)$|baseurl\1//$new_host/\2|" \
		/etc/yum.repos.d/CentOS-*.repo
	echo "YUM package manager repositories were backed up to '$backup' and switched from $old_host to $new_host ." >&2
}

check_repos_centos7()
{
	switch_eol_centos_repos

	check_repos_centos7_based "$@"
}

check_repos_cloudlinux7()
{
	check_repos_centos7_based "$@"
}

check_repos_virtuozzo7()
{
	check_repos_centos7_based "$@"
}

find_apt_repo()
{
	local repo="$1"

	local dist_tag=
	! [ "$os_name" = "ubuntu" ] || dist_tag="a"
	! [ "$os_name" = "debian" ] || dist_tag="n"

	if [ -z "$_apt_cache_policy" ]; then
		# extract info of each available release as a string which consists of 'tag=value'
		# filter out releases with priority less or equal to 100
		_apt_cache_policy="$(
			apt-cache policy \
			| grep "b=$pkg_arch" \
			| grep -Eo '([a-z]=[^,]+,?)*' \
		)"
	fi

	local l="$(echo "$repo" | cut -f1 -d'/')"
	local d="$(echo "$repo" | cut -f2 -d'/')"
	local c="$(echo "$repo" | cut -f3 -d'/')"

	# try to find releases by distribution and component
	echo "$_apt_cache_policy" \
		| grep -E "(^|,)l=$l(,|$)" \
		| grep -E "(^|,)$dist_tag=$d(,|$)" \
		| grep -E "(^|,)c=$c(,|$)" \
		| while IFS="$(printf '\n')" read rel && [ -n "$rel" ]; do
			l="$(echo "$rel" | grep -Eo "(^|,)l=[^,]+"         | cut -f2 -d"=")"
			d="$(echo "$rel" | grep -Eo "(^|,)$dist_tag=[^,]+" | cut -f2 -d"=")"
			c="$(echo "$rel" | grep -Eo "(^|,)c=[^,]+"         | cut -f2 -d"=")"
			echo "$l/$d/$c"
		done
}

apt_install_packages()
{
	DEBIAN_FRONTEND=noninteractive LANG=C PATH=/usr/sbin:/usr/bin:/sbin:/bin \
		apt-get -qq --assume-yes -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold -o APT::Install-Recommends=no \
		install "$@"
}

# Takes a list of suites and disables them in APT sources.
# Multiline deb822 format is supported.
disable_apt_suites_deb822()
{
	local python3=/usr/bin/python3

	"$python3" -c 'import aptsources.sourceslist' 2>/dev/null ||
		apt_install_packages python3-apt

	"$python3" -c '
import sys

from aptsources.sourceslist import SourcesList


suites_to_disable=set(sys.argv[1:])

sources_list = SourcesList(deb822=True)

sources_changed = False
for src in sources_list:
	if src.invalid:
		continue
	suites = getattr(src, "suites", ())
	if not suites:
		continue
	new_suites = [s for s in suites if s not in suites_to_disable]
	if len(new_suites) != len(suites):
		sources_changed = True
		if len(new_suites) == 0:
			src.disabled = True
		else:
			src.suites = new_suites

if sources_changed:
	sources_list.save()
' "$@"

	# Since we have changed the repositories list, we should re-read _apt_cache_policy on a next call
	# of the find_apt_repo function. Hence we have to reset the value of the variable
	_apt_cache_policy=""
}

disable_apt_repo()
{
	local repos_to_disable="$(find_apt_repo "$1" | cut -d '/' -f 2,3 | sort | uniq)"
	if [ -z "$repos_to_disable" ]; then
		return 0
	fi

	echo "$repos_to_disable" \
		| while IFS= read -r repo_to_disable && [ -n "$repo_to_disable" ]; do
			local distrib=${repo_to_disable%%/*}
			local component=${repo_to_disable##*/}
			find /etc/apt -name "*.list" -exec \
				sed -i -e "/^\s*#/! s/.*\s$distrib\s\+$component\b/# &/" {} +
		done

	# Since we have changed the repositories list, we should re-read _apt_cache_policy on a next call
	# of the find_apt_repo function. Hence we have to reset the value of the variable
	_apt_cache_policy=""

	return 0
}

check_required_apt_repo()
{
	local repo="$1"
	[ -z "$(find_apt_repo "$repo")" ] || return 0
	report_no_repo "$repo"
	return $RET_FATAL
}

check_unsupported_apt_repos_ubuntu()
{
	[ -n "$os_codename" ] || return 0
	local mode="$1"

	local repos="$(
		find_apt_repo "Ubuntu/[^,]+/[^,]+" | grep -v "Ubuntu/$os_codename.*/.*"
		find_apt_repo "Debian[^,]*/[^,]+/[^,]+"
	)"
	[ -n "$repos" ] || return 0

	echo "$repos" | while IFS="$(printf '\n')" read repo; do
		report_unsupported_repo "$repo"
	done

	[ "$mode" = "install" ] || return $RET_WARN
	return $RET_FATAL
}

check_repos_ubuntu18()
{
	[ -n "$os_codename" ] || return 0
	local mode="$1"
	local rc=0

	check_required_apt_repo "Ubuntu/$os_codename/main" || rc="$(( $rc | $? ))"
	check_required_apt_repo "Ubuntu/$os_codename/universe" || rc="$(( $rc | $? ))"
	check_required_apt_repo "Ubuntu/$os_codename-updates/main" || rc="$(( $rc | $? ))"
	check_required_apt_repo "Ubuntu/$os_codename-updates/universe" || rc="$(( $rc | $? ))"
	check_unsupported_apt_repos_ubuntu "$mode" || rc="$(( $rc | $? ))"

	return $rc
}


check_repos_ubuntu()
{
	[ -n "$os_codename" ] || return 0
	local mode="$1"
	local rc=0

	check_required_apt_repo "Ubuntu/$os_codename/main" || rc="$(( $rc | $? ))"
	check_required_apt_repo "Ubuntu/$os_codename/universe" || rc="$(( $rc | $? ))"
	check_unsupported_apt_repos_ubuntu "$mode" || rc="$(( $rc | $? ))"

	return $rc
}

check_unsupported_apt_repos_debian()
{
	[ -n "$os_codename" ] || return 0
	local mode="$1"

	local repos="$(
		find_apt_repo "Debian Backports/$os_codename-backports/[^,]+"
		find_apt_repo "Debian[^,]*/[^,]+/[^,]+" | grep -v "Debian.*/$os_codename.*/.*"
		find_apt_repo "Ubuntu/[^,]+/[^,]+"
	)"
	[ -n "$repos" ] || return 0

	echo "$repos" | while IFS="$(printf '\n')" read repo; do
		report_unsupported_repo "$repo"
	done

	[ "$mode" = "install" ] || return $RET_WARN
	return $RET_FATAL
}

check_repos_debian()
{
	[ -n "$os_codename" ] || return 0
	local mode="$1"
	local rc=0

	if [ "$os_name" = "debian" -a "$os_version" -ge 12 ]; then
		disable_apt_suites_deb822 "$os_codename-backports"
	else
		disable_apt_repo "Debian Backports/$os_codename-backports/[^,]+"
	fi

	check_required_apt_repo "Debian/$os_codename/main" || rc="$(( $rc | $? ))"
	check_unsupported_apt_repos_debian "$mode" || rc="$(( $rc | $? ))"

	return $rc
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

check_repos()
{
	detect_platform

	# try to execute checker only if all attributes are detected
	[ -n "$os_name" -a -n "$os_version" ] || return 0

	local mode="$1"
	local prefix="check_repos"
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
	echo "Repository check was skipped due to flag file." >&2
	exit 0
fi

check_repos "$1"

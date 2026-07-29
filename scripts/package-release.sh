#!/usr/bin/env bash

set -Eeuo pipefail

APP_ID="storageusage"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

usage() {
	cat <<'EOF'
Usage:
  package-release.sh stage <version> <stage-root>
  package-release.sh package <version> <stage-root> <output-directory> <source-date-epoch>
EOF
}

validate_version() {
	local version="$1"
	local semver_pattern='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-((0|[1-9][0-9]*)|([0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))(\.((0|[1-9][0-9]*)|([0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)))*)?$'

	if [[ ! "${version}" =~ ${semver_pattern} ]]; then
		echo "Invalid semantic version: ${version}" >&2
		exit 1
	fi
}

metadata_version() {
	local info_xml="$1"
	local versions

	versions="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "${info_xml}")"
	if [[ -z "${versions}" || "${versions}" == *$'\n'* ]]; then
		echo "Unable to read exactly one app version from ${info_xml}" >&2
		exit 1
	fi
	printf '%s' "${versions}"
}

reject_secret_material() {
	local app_directory="$1"

	if find "${app_directory}" -type f \
		\( -name '*.key' -o -name '*.crt' -o -name '*.csr' -o -name '*.pem' \
		-o -name '*.p12' -o -name '*.pfx' -o -name '*.der' -o -name '*.signature' \) \
		-print -quit | grep -q .; then
		echo "The staged app contains certificate or signature source files" >&2
		exit 1
	fi
	if grep -RIlE -- '-----BEGIN ([A-Z0-9 ]+ )?PRIVATE KEY-----' "${app_directory}" | grep -q .; then
		echo "The staged app contains private key material" >&2
		exit 1
	fi
}

stage_app() {
	local version="$1"
	local stage_root="$2"
	local app_directory="${stage_root}/${APP_ID}"

	validate_version "${version}"
	if [[ -e "${stage_root}" ]] && find "${stage_root}" -mindepth 1 -print -quit | grep -q .; then
		echo "Stage root must not exist or must be empty: ${stage_root}" >&2
		exit 1
	fi
	mkdir -p "${app_directory}"

	git -C "${REPOSITORY_ROOT}" archive --format=tar HEAD -- \
		appinfo img l10n lib CHANGELOG.md LICENSE README.md \
		| tar -xf - -C "${app_directory}"

	rm -f "${app_directory}/appinfo/signature.json"

	local packaged_version
	packaged_version="$(metadata_version "${app_directory}/appinfo/info.xml")"
	if [[ "${packaged_version}" != "${version}" ]]; then
		echo "Version mismatch: requested ${version}, info.xml contains ${packaged_version}" >&2
		exit 1
	fi

	reject_secret_material "${app_directory}"

	echo "Staged ${APP_ID} ${version} at ${app_directory}"
}

package_app() {
	local version="$1"
	local stage_root="$2"
	local output_directory="$3"
	local source_date_epoch="$4"
	local app_directory="${stage_root}/${APP_ID}"
	local archive_base="${APP_ID}-v${version}"
	local tar_archive="${output_directory}/${archive_base}.tar.gz"
	local zip_archive="${output_directory}/${archive_base}.zip"

	validate_version "${version}"
	if [[ ! "${source_date_epoch}" =~ ^[0-9]+$ ]]; then
		echo "SOURCE_DATE_EPOCH must be an integer" >&2
		exit 1
	fi
	if [[ ! -f "${app_directory}/appinfo/signature.json" ]]; then
		echo "Missing signed app metadata: ${app_directory}/appinfo/signature.json" >&2
		exit 1
	fi
	if [[ "$(metadata_version "${app_directory}/appinfo/info.xml")" != "${version}" ]]; then
		echo "The staged app version changed before packaging" >&2
		exit 1
	fi
	reject_secret_material "${app_directory}"

	mkdir -p "${output_directory}"
	find "${app_directory}" -exec touch -h -d "@${source_date_epoch}" {} +

	tar \
		--sort=name \
		--format=posix \
		--owner=0 \
		--group=0 \
		--numeric-owner \
		--mtime="@${source_date_epoch}" \
		--pax-option=delete=atime,delete=ctime \
		-C "${stage_root}" \
		-cf - "${APP_ID}" \
		| gzip -n -9 > "${tar_archive}"

	if command -v zip >/dev/null 2>&1; then
		(
			cd "${stage_root}"
			zip -X -q -9 -r "$(realpath "${zip_archive}")" "${APP_ID}"
		)
	elif command -v python3 >/dev/null 2>&1; then
		(
			cd "${stage_root}"
			python3 -m zipfile -c "$(realpath "${zip_archive}")" "${APP_ID}"
		)
	else
		echo "Creating the ZIP archive requires zip or python3" >&2
		exit 1
	fi

	if tar -tzf "${tar_archive}" | grep -Ev "^${APP_ID}(/|$)" | grep -q .; then
		echo "The tar archive contains an invalid top-level path" >&2
		exit 1
	fi

	echo "Created ${tar_archive}"
	echo "Created ${zip_archive}"
}

if [[ $# -lt 1 ]]; then
	usage
	exit 1
fi

command="$1"
shift

case "${command}" in
	stage)
		if [[ $# -ne 2 ]]; then
			usage
			exit 1
		fi
		stage_app "$1" "$2"
		;;
	package)
		if [[ $# -ne 4 ]]; then
			usage
			exit 1
		fi
		package_app "$1" "$2" "$3" "$4"
		;;
	*)
		usage
		exit 1
		;;
esac

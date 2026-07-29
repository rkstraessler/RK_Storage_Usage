#!/usr/bin/env bash

set -Eeuo pipefail

if [[ $# -ne 1 ]]; then
	echo "Usage: prepend-changelog.sh <version>" >&2
	exit 1
fi

VERSION="$1"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
CHANGELOG_PATH="${REPOSITORY_ROOT}/CHANGELOG.md"
SEMVER_PATTERN='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-((0|[1-9][0-9]*)|([0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))(\.((0|[1-9][0-9]*)|([0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)))*)?$'

if [[ ! "${VERSION}" =~ ${SEMVER_PATTERN} ]]; then
	echo "Invalid semantic version: ${VERSION}" >&2
	exit 1
fi

if grep -Fqx "## ${VERSION}" "${CHANGELOG_PATH}"; then
	echo "CHANGELOG.md already contains ${VERSION}"
	exit 0
fi

previous_tag="$(git -C "${REPOSITORY_ROOT}" describe --tags --abbrev=0 HEAD 2>/dev/null || true)"
if [[ -n "${previous_tag}" ]]; then
	mapfile -t changes < <(
		git -C "${REPOSITORY_ROOT}" log \
			--no-merges \
			--pretty='- %s (`%h`)' \
			"${previous_tag}..HEAD" \
			| grep -v -- '- chore(release): prepare' \
			|| true
	)
else
	mapfile -t changes < <(
		git -C "${REPOSITORY_ROOT}" log \
			--no-merges \
			--pretty='- %s (`%h`)' \
			HEAD \
			| grep -v -- '- chore(release): prepare' \
			|| true
	)
fi

if [[ ${#changes[@]} -eq 0 ]]; then
	changes=('- Maintenance release')
fi

temporary_changelog="$(mktemp "${CHANGELOG_PATH}.XXXXXX")"
cleanup() {
	if [[ -f "${temporary_changelog}" ]]; then
		rm -f -- "${temporary_changelog}"
	fi
}
trap cleanup EXIT

{
	printf '# Changelog\n\n'
	printf '## %s\n\n' "${VERSION}"
	printf '### Changes\n\n'
	printf '%s\n' "${changes[@]}"
	printf '\n'
	tail -n +2 "${CHANGELOG_PATH}" | sed '1{/^$/d;}'
} > "${temporary_changelog}"

mv "${temporary_changelog}" "${CHANGELOG_PATH}"
trap - EXIT
echo "Prepended CHANGELOG.md section for ${VERSION}"

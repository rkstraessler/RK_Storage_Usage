#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
CERTIFICATE_DIRECTORY="${1:-${HOME}/.nextcloud/certificates}"
NEXTCLOUD_IMAGE="${2:-nextcloud:34.0.2-apache@sha256:e93ccfc952c95f18175f3d297fb2f60c35070c05ca976050c250a9ddab793e75}"
APP_ID="storageusage"
TEST_ROOT="$(mktemp -d /tmp/storageusage-release-test.XXXXXX)"
CONTAINER_NAME="storageusage-release-test-$$"

cleanup() {
	docker rm -f "${CONTAINER_NAME}" >/dev/null 2>&1 || true
	case "${TEST_ROOT}" in
		/tmp/storageusage-release-test.*)
			rm -rf -- "${TEST_ROOT}"
			;;
		*)
			echo "Refusing to remove unexpected test path: ${TEST_ROOT}" >&2
			;;
	esac
}
trap cleanup EXIT

hash_unsigned_tree() {
	tar \
		--sort=name \
		--format=posix \
		--owner=0 \
		--group=0 \
		--numeric-owner \
		--mtime=@0 \
		--pax-option=delete=atime,delete=ctime \
		--exclude="${APP_ID}/appinfo/signature.json" \
		-C "${TEST_ROOT}/stage" \
		-cf - "${APP_ID}" \
		| sha256sum \
		| cut -d ' ' -f1
}

for command_name in docker git gzip openssl sed tar; do
	if ! command -v "${command_name}" >/dev/null 2>&1; then
		echo "Missing required command: ${command_name}" >&2
		exit 1
	fi
done
if ! command -v zip >/dev/null 2>&1 && ! command -v python3 >/dev/null 2>&1; then
	echo "Creating the ZIP archive requires zip or python3" >&2
	exit 1
fi

private_key="${CERTIFICATE_DIRECTORY}/${APP_ID}.key"
public_certificate="${CERTIFICATE_DIRECTORY}/${APP_ID}.crt"
if [[ ! -s "${private_key}" || ! -s "${public_certificate}" ]]; then
	echo "Expected ${APP_ID}.key and ${APP_ID}.crt in ${CERTIFICATE_DIRECTORY}" >&2
	exit 1
fi

cd "${REPOSITORY_ROOT}"
version="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' appinfo/info.xml)"
source_date_epoch="$(git show -s --format=%ct HEAD)"

bash scripts/package-release.sh stage "${version}" "${TEST_ROOT}/stage"
hash_unsigned_tree > "${TEST_ROOT}/unsigned-tree.sha256"

docker run --detach \
	--name "${CONTAINER_NAME}" \
	--network none \
	--volume "${TEST_ROOT}/stage:/release" \
	--volume "${CERTIFICATE_DIRECTORY}:/certificates:ro" \
	--volume "${REPOSITORY_ROOT}/scripts:/release-tools:ro" \
	"${NEXTCLOUD_IMAGE}" >/dev/null

for attempt in {1..60}; do
	if docker exec --user www-data "${CONTAINER_NAME}" php occ --version >/dev/null 2>&1; then
		break
	fi
	if [[ "${attempt}" == "60" ]]; then
		echo "Nextcloud did not finish initializing in time." >&2
		exit 1
	fi
	sleep 1
done

docker exec "${CONTAINER_NAME}" php occ integrity:sign-app \
	--privateKey=/certificates/storageusage.key \
	--certificate=/certificates/storageusage.crt \
	--path=/release/storageusage
docker exec "${CONTAINER_NAME}" php /release-tools/validate-integrity-signature.php \
	/release/storageusage/appinfo/signature.json \
	/certificates/storageusage.crt \
	/release/storageusage
docker exec "${CONTAINER_NAME}" chown -R "$(id -u):$(id -g)" /release
docker rm -f "${CONTAINER_NAME}" >/dev/null

hash_unsigned_tree > "${TEST_ROOT}/signed-tree.sha256"
if ! cmp -s "${TEST_ROOT}/unsigned-tree.sha256" "${TEST_ROOT}/signed-tree.sha256"; then
	echo "The signer changed app files other than appinfo/signature.json." >&2
	exit 1
fi

bash scripts/package-release.sh package \
	"${version}" \
	"${TEST_ROOT}/stage" \
	"${TEST_ROOT}/output" \
	"${source_date_epoch}"

archive="${TEST_ROOT}/output/${APP_ID}-v${version}.tar.gz"
binary_signature="${TEST_ROOT}/${APP_ID}-v${version}.signature.bin"
public_key="${TEST_ROOT}/${APP_ID}.public.pem"

openssl dgst -sha512 -sign "${private_key}" -out "${binary_signature}" "${archive}"
openssl x509 -in "${public_certificate}" -pubkey -noout > "${public_key}"
openssl dgst -sha512 -verify "${public_key}" -signature "${binary_signature}" "${archive}"

echo "Release test succeeded for ${APP_ID} ${version}:"
sha256sum "${TEST_ROOT}/output"/*

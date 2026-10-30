#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
NEXTCLOUD_IMAGE="${1:-$(tr -d '\r\n' < "${SCRIPT_DIR}/nextcloud-image.txt")}"
CONTAINER_NAME="storageusage-compatibility-$$-${RANDOM}"
container_created=false

cleanup() {
	local status=$?
	if [[ "${container_created}" == true ]]; then
		if [[ "${status}" -ne 0 ]]; then
			docker logs --tail 40 "${CONTAINER_NAME}" >&2 || true
		fi
		docker rm --force --volumes "${CONTAINER_NAME}" >/dev/null 2>&1 || true
	fi
}
trap cleanup EXIT

occ() {
	docker exec --user www-data "${CONTAINER_NAME}" php occ "$@"
}

echo "Testing storageusage with ${NEXTCLOUD_IMAGE}"
docker run --detach \
	--name "${CONTAINER_NAME}" \
	--network none \
	--env STORAGEUSAGE_COMPATIBILITY_TEST=1 \
	"${NEXTCLOUD_IMAGE}" >/dev/null
container_created=true

for attempt in {1..120}; do
	if docker exec --user www-data "${CONTAINER_NAME}" \
		sh -c 'test -w /var/www/html/config && php occ --version' >/dev/null 2>&1; then
		break
	fi
	if [[ "${attempt}" == 120 ]]; then
		echo "Nextcloud did not finish initializing in time." >&2
		exit 1
	fi
	sleep 1
done

# All data belongs to this disposable, network-isolated container.
occ maintenance:install \
	--database=sqlite \
	--admin-user=storageusage-test \
	"--admin-pass=storageusage-${CONTAINER_NAME}" \
	--no-interaction >/dev/null
occ config:system:set memcache.local --value='\OC\Memcache\APCu' >/dev/null
docker exec "${CONTAINER_NAME}" sh -c \
	'printf "apc.enable_cli=1\n" > /usr/local/etc/php/conf.d/storageusage-test.ini'
docker exec "${CONTAINER_NAME}" mkdir -p /var/www/html/custom_apps/storageusage

# Copy the working tree so local checks also cover uncommitted changes.
tar -C "${REPOSITORY_ROOT}" -cf - appinfo css img js l10n lib templates \
	| docker exec -i "${CONTAINER_NAME}" tar -xf - -C /var/www/html/custom_apps/storageusage
docker exec "${CONTAINER_NAME}" chown -R www-data:www-data /var/www/html/custom_apps/storageusage
occ app:enable storageusage --no-interaction
occ status --output=json

docker exec -i "${CONTAINER_NAME}" sh -c 'cat > /tmp/storageusage-runtime-test.php' \
	< "${SCRIPT_DIR}/test-nextcloud-runtime.php"
docker exec --user www-data "${CONTAINER_NAME}" php /tmp/storageusage-runtime-test.php
docker exec --user www-data "${CONTAINER_NAME}" php /tmp/storageusage-runtime-test.php --snapshot
docker exec "${CONTAINER_NAME}" test -s /tmp/storageusage-expected.json

docker exec "${CONTAINER_NAME}" curl \
	--fail --silent --show-error \
	--retry 15 --retry-delay 1 --retry-all-errors \
	--dump-header /tmp/storageusage-response.headers \
	--output /tmp/storageusage-response.json \
	http://localhost/index.php/apps/storageusage/api/v1/usage
docker exec "${CONTAINER_NAME}" php /tmp/storageusage-runtime-test.php --check-response

for route in GET:admin/folders PUT:admin/folder-settings; do
	method="${route%%:*}"
	endpoint="${route#*:}"
	status="$(docker exec "${CONTAINER_NAME}" curl --silent --output /dev/null \
		--request "${method}" --header 'Accept: application/json' \
		--write-out '%{http_code}' "http://localhost/index.php/apps/storageusage/${endpoint}")"
	if [[ "${status}" != 401 && "${status}" != 403 && "${status}" != 412 ]]; then
		echo "Anonymous access to ${endpoint} returned unexpected HTTP ${status}." >&2
		exit 1
	fi
done

docker exec "${CONTAINER_NAME}" mkdir -p /tmp/storageusage-signer/app /tmp/storageusage-signer/certificates
docker exec "${CONTAINER_NAME}" cp -a \
	/var/www/html/custom_apps/storageusage/. /tmp/storageusage-signer/app/
# Disposable credentials exercise signing without access to real release secrets.
docker exec "${CONTAINER_NAME}" openssl req -x509 -newkey rsa:2048 -nodes \
	-keyout /tmp/storageusage-signer/certificates/storageusage.key \
	-out /tmp/storageusage-signer/certificates/storageusage.crt \
	-subj /CN=storageusage -days 1 >/dev/null 2>&1
docker exec "${CONTAINER_NAME}" chown -R www-data:www-data /tmp/storageusage-signer
docker exec "${CONTAINER_NAME}" chmod 600 \
	/tmp/storageusage-signer/certificates/storageusage.key \
	/tmp/storageusage-signer/certificates/storageusage.crt
docker exec --user www-data "${CONTAINER_NAME}" test -r /tmp/storageusage-signer/certificates/storageusage.key
docker exec --user www-data "${CONTAINER_NAME}" php occ integrity:sign-app \
	--privateKey=/tmp/storageusage-signer/certificates/storageusage.key \
	--certificate=/tmp/storageusage-signer/certificates/storageusage.crt \
	--path=/tmp/storageusage-signer/app
docker exec -i "${CONTAINER_NAME}" sh -c 'cat > /tmp/storageusage-validate-signature.php' \
	< "${SCRIPT_DIR}/validate-integrity-signature.php"
docker exec --user www-data "${CONTAINER_NAME}" php /tmp/storageusage-validate-signature.php \
	/tmp/storageusage-signer/app/appinfo/signature.json \
	/tmp/storageusage-signer/certificates/storageusage.crt \
	/tmp/storageusage-signer/app

echo "Nextcloud compatibility test succeeded for ${NEXTCLOUD_IMAGE}"

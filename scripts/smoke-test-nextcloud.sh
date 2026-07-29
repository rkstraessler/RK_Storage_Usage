#!/usr/bin/env bash

set -Eeuo pipefail

APP_ID="storageusage"
DEFAULT_NEXTCLOUD_IMAGE="nextcloud:34.0.2-apache@sha256:e93ccfc952c95f18175f3d297fb2f60c35070c05ca976050c250a9ddab793e75"

if [[ $# -lt 1 || $# -gt 3 ]]; then
	echo "Usage: smoke-test-nextcloud.sh <app-directory> [nextcloud-image] [require-integrity-signature]" >&2
	exit 1
fi

APP_DIRECTORY="$(cd "$1" && pwd)"
NEXTCLOUD_IMAGE="${2:-${DEFAULT_NEXTCLOUD_IMAGE}}"
REQUIRE_INTEGRITY_SIGNATURE="${3:-false}"
if [[ "${REQUIRE_INTEGRITY_SIGNATURE}" != "true" && "${REQUIRE_INTEGRITY_SIGNATURE}" != "false" ]]; then
	echo "require-integrity-signature must be true or false" >&2
	exit 1
fi
if [[ ! -f "${APP_DIRECTORY}/appinfo/info.xml" ]]; then
	echo "The app directory does not contain appinfo/info.xml: ${APP_DIRECTORY}" >&2
	exit 1
fi
if [[ "${REQUIRE_INTEGRITY_SIGNATURE}" == "true" \
	&& ! -f "${APP_DIRECTORY}/appinfo/signature.json" ]]; then
	echo "The signed runtime test requires appinfo/signature.json" >&2
	exit 1
fi

container_suffix="${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-$$-${RANDOM}"
container_name="storageusage-runtime-${container_suffix}"
cleanup() {
	docker rm -f "${container_name}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker run --detach \
	--name "${container_name}" \
	--network none \
	"${NEXTCLOUD_IMAGE}" >/dev/null

for attempt in {1..60}; do
	if docker exec --user www-data "${container_name}" php occ --version >/dev/null 2>&1; then
		break
	fi
	if [[ "${attempt}" == "60" ]]; then
		echo "Nextcloud did not finish initializing in time." >&2
		exit 1
	fi
	sleep 1
done

admin_password="storageusage-${container_suffix}"
docker exec --user www-data "${container_name}" php occ maintenance:install \
	--database=sqlite \
	--admin-user=storageusage-admin \
	--admin-pass="${admin_password}" \
	--no-interaction >/dev/null

docker exec "${container_name}" mkdir -p "/var/www/html/custom_apps/${APP_ID}"
docker cp "${APP_DIRECTORY}/." \
	"${container_name}:/var/www/html/custom_apps/${APP_ID}/"
docker exec "${container_name}" chown -R www-data:www-data \
	"/var/www/html/custom_apps/${APP_ID}"
docker exec --user www-data "${container_name}" php occ app:enable "${APP_ID}" --no-interaction

if [[ "${REQUIRE_INTEGRITY_SIGNATURE}" == "true" ]]; then
	docker exec --user www-data "${container_name}" php occ integrity:check-app "${APP_ID}"
fi

response=''
for attempt in {1..30}; do
	if response="$(docker exec "${container_name}" curl \
		--fail \
		--silent \
		--show-error \
		http://localhost/index.php/apps/storageusage/api/v1/usage 2>/dev/null)"; then
		break
	fi
	if [[ "${attempt}" == "30" ]]; then
		echo "The public storage usage endpoint did not become available." >&2
		exit 1
	fi
	sleep 1
done

printf '%s' "${response}" \
	| docker exec --interactive "${container_name}" php -r '
		$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data)
			|| !isset($data["totalUsage"], $data["unit"], $data["totalUsageBytes"], $data["cacheTtl"])
			|| (!is_int($data["totalUsage"]) && !is_float($data["totalUsage"]))
			|| !is_string($data["unit"])
			|| $data["unit"] === ""
			|| !is_int($data["totalUsageBytes"])
			|| $data["totalUsageBytes"] < 0
			|| !is_int($data["cacheTtl"])
			|| $data["cacheTtl"] < 1) {
			fwrite(STDERR, "Unexpected storage usage API response\n");
			exit(1);
		}
	'

echo "Nextcloud 34 runtime smoke test succeeded for ${APP_ID}"

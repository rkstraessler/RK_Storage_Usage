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

empty_response=''
for attempt in {1..30}; do
	if empty_response="$(docker exec "${container_name}" curl \
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

printf '%s' "${empty_response}" \
	| docker exec --interactive "${container_name}" php -r '
		$data = json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR);
		if (!isset($data->folders)
			|| !is_object($data->folders)
			|| get_object_vars($data->folders) !== []) {
			fwrite(STDERR, "Empty folder configuration must be returned as a JSON object\n");
			exit(1);
		}
	'

# Create two folders owned by the administrator and one folder shared by a
# second user. This exercises regular folder identities, exclusions, and the
# wrapper-safe source identity used for real Nextcloud shares.
docker exec --user www-data "${container_name}" php -r '
	require "/var/www/html/lib/base.php";

	$userManager = \OC::$server->get(\OCP\IUserManager::class);
	$user = $userManager->get("storageusage-admin");
	if ($user === null) {
		throw new RuntimeException("Smoke-test admin user is unavailable");
	}
	$user->updateLastLoginTimestamp();
	$shareOwner = $userManager->createUser(
		"storageusage-share-owner",
		"storageusage-share-owner-password",
	);
	$shareOwner->updateLastLoginTimestamp();

	$rootFolder = \OC::$server->get(\OCP\Files\IRootFolder::class);
	$root = $rootFolder->getUserFolder("storageusage-admin");
	$fixture = $root->newFolder("storageusage-smoke");
	$included = $fixture->newFolder("included");
	$excluded = $fixture->newFolder("excluded");
	$included->newFile("included.bin")->putContent(str_repeat("i", 4096));
	$excluded->newFile("excluded.bin")->putContent(str_repeat("e", 8192));

	$ownerRoot = $rootFolder->getUserFolder("storageusage-share-owner");
	$shared = $ownerRoot->newFolder("storageusage-shared");
	$shared->newFile("shared.bin")->putContent(str_repeat("s", 16384));
	$shareManager = \OC::$server->get(\OCP\Share\IManager::class);
	$share = $shareManager->newShare();
	$share->setNode($shared);
	$share->setShareType(\OCP\Share\IShare::TYPE_USER);
	$share->setSharedWith("storageusage-admin");
	$share->setSharedBy("storageusage-share-owner");
	$share->setShareOwner("storageusage-share-owner");
	$share->setPermissions(\OCP\Constants::PERMISSION_READ);
	$shareManager->createShare($share);
' >/dev/null

# Resolve the new share in a fresh PHP process. Nextcloud builds the recipient's
# mount view per request, so the process that creates a share cannot reliably
# see the new mount yet.
docker exec --user www-data "${container_name}" php -r '
	require "/var/www/html/lib/base.php";

	$root = \OC::$server
		->get(\OCP\Files\IRootFolder::class)
		->getUserFolder("storageusage-admin");
	$included = $root->get("storageusage-smoke/included");
	$excluded = $root->get("storageusage-smoke/excluded");
	$sharedView = $root->get("storageusage-shared");

	if (!$included instanceof \OCP\Files\Folder
		|| !$excluded instanceof \OCP\Files\Folder
		|| !$sharedView instanceof \OCP\Files\Folder) {
		throw new RuntimeException("Shared smoke-test folder is unavailable");
	}

	$entries = [
		[
			"id" => "11111111-1111-4111-8111-111111111111",
			"key" => "included",
			"viewUserId" => "storageusage-admin",
			"fileId" => $included->getId(),
			"storageId" => $included->getStorage()->getId(),
			"path" => "/storageusage-smoke/included",
			"unit" => "KiB",
			"excludeFromTotal" => false,
		],
		[
			"id" => "22222222-2222-4222-8222-222222222222",
			"key" => "excluded",
			"viewUserId" => "storageusage-admin",
			"fileId" => $excluded->getId(),
			"storageId" => $excluded->getStorage()->getId(),
			"path" => "/storageusage-smoke/excluded",
			"unit" => "KiB",
			"excludeFromTotal" => true,
		],
		[
			"id" => "33333333-3333-4333-8333-333333333333",
			"key" => "shared",
			"viewUserId" => "storageusage-admin",
			"fileId" => $sharedView->getId(),
			"storageId" => $sharedView->getStorage()->getId(),
			"path" => "/storageusage-shared",
			"unit" => "KiB",
			"excludeFromTotal" => true,
		],
	];

	\OC::$server->get(\OCP\IConfig::class)->setAppValue(
		"storageusage",
		"folder_entries",
		json_encode($entries, JSON_THROW_ON_ERROR),
	);
' >/dev/null

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
		$raw = stream_get_contents(STDIN);
		$object = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
		$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data)
			|| !isset(
				$data["totalUsage"],
				$data["unit"],
				$data["totalUsageBytes"],
				$data["baseTotalUsageBytes"],
				$data["excludedUsageBytes"],
				$data["cacheTtl"],
				$data["folders"],
			)
			|| (!is_int($data["totalUsage"]) && !is_float($data["totalUsage"]))
			|| !is_string($data["unit"])
			|| $data["unit"] === ""
			|| !is_int($data["totalUsageBytes"])
			|| $data["totalUsageBytes"] < 0
			|| !is_int($data["baseTotalUsageBytes"])
			|| !is_int($data["excludedUsageBytes"])
			|| !is_int($data["cacheTtl"])
			|| $data["cacheTtl"] < 0
			|| !is_object($object->folders ?? null)) {
			fwrite(STDERR, "Unexpected storage usage API response\n");
			exit(1);
		}

		if ($data["excludedUsageBytes"] !== 24576
			|| $data["baseTotalUsageBytes"] < 28672
			|| $data["totalUsageBytes"] !== $data["baseTotalUsageBytes"] - 24576) {
			fwrite(STDERR, "Unexpected folder exclusion calculation\n");
			exit(1);
		}

		$expectations = [
			"included" => [4096, false, false],
			"excluded" => [8192, true, true],
			"shared" => [16384, true, true],
		];
		foreach ($expectations as $key => [$bytes, $exclude, $excluded]) {
			$folder = $data["folders"][$key] ?? null;
			if (!is_array($folder)
				|| $folder["usageBytes"] !== $bytes
				|| $folder["unit"] !== "KiB"
				|| (!is_int($folder["usage"]) && !is_float($folder["usage"]))
				|| abs((float) $folder["usage"] - ($bytes / 1024)) > 0.001
				|| $folder["excludeFromTotal"] !== $exclude
				|| $folder["excludedFromTotal"] !== $excluded
				|| $folder["status"] !== "ok") {
				fwrite(STDERR, "Unexpected separately reported folder {$key}\n");
				exit(1);
			}
		}

		$privateKeys = [
			"fileId",
			"storageId",
			"viewUserId",
			"sourceFileId",
			"sourceStorageId",
			"sourceNumericStorageId",
			"sourceUserId",
			"userId",
			"path",
			"pathSnapshot",
		];
		foreach ($data["folders"] as $folder) {
			if (array_intersect($privateKeys, array_keys($folder)) !== []) {
				fwrite(STDERR, "The public API exposed internal folder metadata\n");
				exit(1);
			}
		}
	'

echo "Nextcloud 34 runtime smoke test succeeded for ${APP_ID}"

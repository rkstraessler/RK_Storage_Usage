#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${REPOSITORY_ROOT}"

while IFS= read -r -d '' file; do
	php -l "${file}"
done < <(find appinfo lib scripts templates -type f -name '*.php' -print0)

php -r '
	libxml_use_internal_errors(true);
	$xml = simplexml_load_file("appinfo/info.xml");
	if ($xml === false || trim((string) $xml->id) !== "storageusage") {
		fwrite(STDERR, "Invalid appinfo/info.xml\n");
		exit(1);
	}
'

version="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' appinfo/info.xml)"
php scripts/set-version.php --validate "${version}"

php -r '
	json_decode(file_get_contents("l10n/de.json"), true, 512, JSON_THROW_ON_ERROR);
'

for script in scripts/*.sh; do
	bash -n "${script}"
done
if [[ ! "$(tr -d '\r\n' < scripts/nextcloud-image.txt)" =~ ^nextcloud:[0-9]+\.[0-9]+\.[0-9]+-apache@sha256:[a-f0-9]{64}$ ]]; then
	echo "The release signer must use a versioned, digest-pinned official Nextcloud image." >&2
	exit 1
fi
echo "Source validation succeeded for storageusage ${version}"

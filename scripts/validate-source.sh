#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${REPOSITORY_ROOT}"

while IFS= read -r -d '' file; do
	php -l "${file}"
done < <(find appinfo lib scripts -type f -name '*.php' -print0)

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

bash -n scripts/*.sh
echo "Source validation succeeded for storageusage ${version}"

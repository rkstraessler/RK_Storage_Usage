<?php

declare(strict_types=1);

if ($argc < 2 || $argc > 3 || ($argc === 3 && $argv[1] !== '--validate')) {
	fwrite(STDERR, "Usage: php scripts/set-version.php [--validate] <version>\n");
	exit(1);
}

$validateOnly = $argc === 3;
$version = $validateOnly ? $argv[2] : $argv[1];
$semverPattern = '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-(?:(?:0|[1-9][0-9]*)|(?:[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))(?:\.(?:(?:0|[1-9][0-9]*)|(?:[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)))*)?$/';
if (preg_match($semverPattern, $version) !== 1) {
	fwrite(STDERR, "Invalid semantic version: {$version}\n");
	exit(1);
}
if ($validateOnly) {
	echo "Valid semantic version: {$version}\n";
	exit(0);
}

$repositoryRoot = dirname(__DIR__);
$infoXmlPath = $repositoryRoot . '/appinfo/info.xml';
$contents = file_get_contents($infoXmlPath);
if ($contents === false) {
	fwrite(STDERR, "Unable to read {$infoXmlPath}\n");
	exit(1);
}

$matches = [];
if (preg_match_all('/<version>[^<]+<\/version>/', $contents, $matches) !== 1) {
	fwrite(STDERR, "Expected exactly one <version> element in appinfo/info.xml\n");
	exit(1);
}

$updatedContents = preg_replace(
	'/<version>[^<]+<\/version>/',
	'<version>' . $version . '</version>',
	$contents,
	1,
);
if ($updatedContents === null || file_put_contents($infoXmlPath, $updatedContents) === false) {
	fwrite(STDERR, "Unable to update {$infoXmlPath}\n");
	exit(1);
}

echo "Updated appinfo/info.xml to {$version}\n";

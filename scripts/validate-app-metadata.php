<?php

declare(strict_types=1);

$schemaPath = $argv[1] ?? '';
if ($schemaPath === '' || !is_file($schemaPath)) {
    fwrite(STDERR, "Usage: php scripts/validate-app-metadata.php <info.xsd>\n");
    exit(1);
}

libxml_use_internal_errors(true);
$document = new DOMDocument();
$valid = $document->load(__DIR__ . '/../appinfo/info.xml', LIBXML_NONET)
    && $document->schemaValidate($schemaPath);

if (!$valid) {
    foreach (libxml_get_errors() as $error) {
        fwrite(STDERR, trim($error->message) . "\n");
    }
    exit(1);
}

echo "App Store XML schema validation succeeded.\n";

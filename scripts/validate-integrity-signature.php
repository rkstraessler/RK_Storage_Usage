<?php

declare(strict_types=1);

if ($argc !== 4) {
	fwrite(STDERR, "Usage: php scripts/validate-integrity-signature.php <signature.json> <certificate.crt> <app-directory>\n");
	exit(1);
}

[$script, $signaturePath, $expectedCertificatePath, $appDirectory] = $argv;

try {
	$signatureContents = file_get_contents($signaturePath);
	$expectedCertificateContents = file_get_contents($expectedCertificatePath);
	if ($signatureContents === false || $expectedCertificateContents === false) {
		throw new RuntimeException('Unable to read signature or certificate');
	}

	$data = json_decode($signatureContents, true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($data)) {
		throw new RuntimeException('signature.json must contain an object');
	}

	$keys = array_keys($data);
	sort($keys);
	if ($keys !== ['certificate', 'hashes', 'signature']) {
		throw new RuntimeException('signature.json contains unexpected fields');
	}
	if (!is_array($data['hashes']) || $data['hashes'] === []) {
		throw new RuntimeException('signature.json contains no file hashes');
	}
	if (!is_string($data['certificate']) || !is_string($data['signature'])) {
		throw new RuntimeException('signature.json contains invalid field types');
	}

	$decodedSignature = base64_decode($data['signature'], true);
	if ($decodedSignature === false || $decodedSignature === '') {
		throw new RuntimeException('signature.json contains an invalid signature');
	}

	$embeddedCertificate = openssl_x509_read($data['certificate']);
	$expectedCertificate = openssl_x509_read($expectedCertificateContents);
	if ($embeddedCertificate === false || $expectedCertificate === false) {
		throw new RuntimeException('Unable to parse the signing certificate');
	}
	$embeddedFingerprint = openssl_x509_fingerprint($embeddedCertificate, 'sha256');
	$expectedFingerprint = openssl_x509_fingerprint($expectedCertificate, 'sha256');
	if ($embeddedFingerprint === false
		|| $expectedFingerprint === false
		|| !hash_equals($expectedFingerprint, $embeddedFingerprint)) {
		throw new RuntimeException('signature.json contains an unexpected certificate');
	}

	$resolvedAppDirectory = realpath($appDirectory);
	if ($resolvedAppDirectory === false || !is_dir($resolvedAppDirectory)) {
		throw new RuntimeException('Unable to resolve the staged app directory');
	}

	$expectedHashes = [];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($resolvedAppDirectory, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::LEAVES_ONLY,
	);
	foreach ($iterator as $file) {
		if (!$file instanceof SplFileInfo || $file->isLink() || !$file->isFile()) {
			throw new RuntimeException('The staged app contains an unsupported filesystem entry');
		}
		$relativePath = str_replace(
			DIRECTORY_SEPARATOR,
			'/',
			substr($file->getPathname(), strlen($resolvedAppDirectory) + 1),
		);
		if ($relativePath === 'appinfo/signature.json') {
			continue;
		}
		if (preg_match('~^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/+@ -]+$~', $relativePath) !== 1) {
			throw new RuntimeException("Invalid staged file path: {$relativePath}");
		}
		$hash = hash_file('sha512', $file->getPathname());
		if ($hash === false) {
			throw new RuntimeException("Unable to hash staged file: {$relativePath}");
		}
		$expectedHashes[$relativePath] = $hash;
	}

	foreach ($data['hashes'] as $file => $hash) {
		if (!is_string($file)
			|| preg_match('~^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/+@ -]+$~', $file) !== 1
			|| $file === 'appinfo/signature.json'
			|| !is_string($hash)
			|| preg_match('/^[a-f0-9]{128}$/', $hash) !== 1) {
			throw new RuntimeException('signature.json contains an invalid file hash entry');
		}
	}

	ksort($expectedHashes);
	ksort($data['hashes']);
	if ($data['hashes'] !== $expectedHashes) {
		throw new RuntimeException('signature.json does not exactly match the staged app files');
	}
} catch (Throwable $exception) {
	fwrite(STDERR, "Invalid Nextcloud integrity signature: {$exception->getMessage()}\n");
	exit(1);
}

echo "Validated Nextcloud integrity signature for " . count($expectedHashes) . " files\n";

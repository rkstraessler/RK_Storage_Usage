param(
	[string] $WslDistribution = 'Ubuntu',
	[string] $WslCertificateDirectory = '/home/kstraessler/.nextcloud/certificates',
	[string] $NextcloudImage = 'nextcloud:34.0.2-apache@sha256:e93ccfc952c95f18175f3d297fb2f60c35070c05ca976050c250a9ddab793e75'
)

$ErrorActionPreference = 'Stop'

$appId = 'storageusage'
$repositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$systemTempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
$testRoot = Join-Path $systemTempRoot ("storageusage-release-test-{0}" -f [guid]::NewGuid().ToString('N'))
$containerName = "storageusage-release-test-{0}" -f [guid]::NewGuid().ToString('N').Substring(0, 12)
$containerCreated = $false

function Invoke-WslBash {
	param([Parameter(Mandatory)][string] $Command)

	& wsl.exe -d $WslDistribution -- bash -lc $Command
	if ($LASTEXITCODE -ne 0) {
		throw "WSL command failed with exit code $LASTEXITCODE"
	}
}

function Assert-NativeSuccess {
	param([Parameter(Mandatory)][string] $Operation)

	if ($LASTEXITCODE -ne 0) {
		throw "$Operation failed with exit code $LASTEXITCODE"
	}
}

function Convert-ToWslPath {
	param([Parameter(Mandatory)][string] $WindowsPath)

	$portableWindowsPath = $WindowsPath.Replace('\', '/')
	$convertedPath = & wsl.exe -d $WslDistribution -- wslpath -a -u $portableWindowsPath
	Assert-NativeSuccess "Converting $WindowsPath to a WSL path"
	return ($convertedPath | Out-String).Trim()
}

function Convert-ToBashLiteral {
	param([Parameter(Mandatory)][string] $Value)

	$singleQuoteEscape = [string]::Concat("'", [char]34, "'", [char]34, "'")
	return "'" + $Value.Replace("'", $singleQuoteEscape) + "'"
}

try {
	New-Item -ItemType Directory -Path $testRoot -Force | Out-Null
	$wslRepositoryRoot = Convert-ToWslPath $repositoryRoot
	$wslTestRoot = Convert-ToWslPath $testRoot
	$quotedRepositoryRoot = Convert-ToBashLiteral $wslRepositoryRoot
	$quotedTestRoot = Convert-ToBashLiteral $wslTestRoot
	$quotedPrivateKey = Convert-ToBashLiteral "$WslCertificateDirectory/$appId.key"
	$quotedPublicCertificate = Convert-ToBashLiteral "$WslCertificateDirectory/$appId.crt"

	$version = (& wsl.exe -d $WslDistribution -- bash -lc "sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' $quotedRepositoryRoot/appinfo/info.xml").Trim()
	Assert-NativeSuccess 'Reading the app version'
	if ($version -notmatch '^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-(?:(?:0|[1-9][0-9]*)|(?:[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))(?:\.(?:(?:0|[1-9][0-9]*)|(?:[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)))*)?$') {
		throw "Invalid app version: $version"
	}
	$quotedVersion = Convert-ToBashLiteral $version

	Invoke-WslBash "cd $quotedRepositoryRoot && bash scripts/package-release.sh stage $quotedVersion $quotedTestRoot/stage"

	& docker run --detach --name $containerName --network none $NextcloudImage | Out-Null
	Assert-NativeSuccess 'Starting the temporary Nextcloud signer'
	$containerCreated = $true
	$occReady = $false
	for ($attempt = 1; $attempt -le 60; $attempt++) {
		& docker exec --user www-data $containerName sh -c 'test -w /var/www/html/config && php occ --version' *> $null
		if ($LASTEXITCODE -eq 0) {
			$occReady = $true
			break
		}
		Start-Sleep -Seconds 1
	}
	if (-not $occReady) {
		throw 'Nextcloud did not finish initializing in time.'
	}

	& docker exec $containerName mkdir -p /tmp/storageusage-certificates
	Assert-NativeSuccess 'Preparing the certificate directory in Docker'
	& docker cp (Join-Path $testRoot 'stage\storageusage') "${containerName}:/tmp/storageusage"
	Assert-NativeSuccess 'Copying the staged app into Docker'

	& wsl.exe -d $WslDistribution -- bash -lc "cat $quotedPrivateKey" |
		& docker exec -i $containerName sh -c "umask 077; cat > /tmp/storageusage-certificates/$appId.key"
	Assert-NativeSuccess 'Streaming the private key directly into Docker'
	& wsl.exe -d $WslDistribution -- bash -lc "cat $quotedPublicCertificate" |
		& docker exec -i $containerName sh -c "cat > /tmp/storageusage-certificates/$appId.crt"
	Assert-NativeSuccess 'Streaming the certificate directly into Docker'
	& docker exec $containerName sh -c "test -s /tmp/storageusage-certificates/$appId.key && test -s /tmp/storageusage-certificates/$appId.crt"
	Assert-NativeSuccess 'Checking the streamed signing credentials'
	& docker exec $containerName chown -R www-data:www-data /tmp/storageusage /tmp/storageusage-certificates
	Assert-NativeSuccess 'Applying temporary signer permissions'
	& docker exec $containerName chmod 600 "/tmp/storageusage-certificates/$appId.key"
	Assert-NativeSuccess 'Restricting the private key permissions'

	& docker cp (Join-Path $repositoryRoot 'scripts\validate-integrity-signature.php') "${containerName}:/tmp/validate-integrity-signature.php"
	Assert-NativeSuccess 'Copying the signature validator into Docker'

	& docker exec --user www-data $containerName php occ integrity:sign-app `
		"--privateKey=/tmp/storageusage-certificates/$appId.key" `
		"--certificate=/tmp/storageusage-certificates/$appId.crt" `
		"--path=/tmp/storageusage"
	Assert-NativeSuccess 'Creating appinfo/signature.json'
	& docker exec $containerName php /tmp/validate-integrity-signature.php `
		/tmp/storageusage/appinfo/signature.json `
		"/tmp/storageusage-certificates/$appId.crt" `
		/tmp/storageusage
	Assert-NativeSuccess 'Validating the complete integrity signature'

	& docker exec --user www-data $containerName php occ maintenance:install `
		--database=sqlite `
		--admin-user=storageusage-admin `
		"--admin-pass=storageusage-$containerName" `
		--no-interaction | Out-Null
	Assert-NativeSuccess 'Installing the temporary Nextcloud instance'
	$statusResponse = (& docker exec --user www-data $containerName php occ status --output=json | Out-String).Trim()
	Assert-NativeSuccess 'Checking the temporary Nextcloud installation'
	$statusData = $statusResponse | ConvertFrom-Json
	if ($statusData.installed -ne $true) {
		throw 'The temporary Nextcloud instance was not installed successfully.'
	}
	& docker exec $containerName mkdir -p /var/www/html/custom_apps/storageusage
	Assert-NativeSuccess 'Preparing the runtime app directory'
	& docker exec $containerName cp -a /tmp/storageusage/. /var/www/html/custom_apps/storageusage/
	Assert-NativeSuccess 'Installing the signed app into Nextcloud'
	& docker exec $containerName chown -R www-data:www-data /var/www/html/custom_apps/storageusage
	Assert-NativeSuccess 'Applying runtime app permissions'
	& docker exec --user www-data $containerName php occ app:enable $appId --no-interaction
	Assert-NativeSuccess 'Enabling the signed app'
	& docker exec --user www-data $containerName php occ integrity:check-app $appId
	Assert-NativeSuccess 'Checking the installed app integrity'
	$endpointReady = $false
	$endpointResponse = ''
	for ($attempt = 1; $attempt -le 30; $attempt++) {
		$endpointResponse = (& docker exec $containerName curl --fail --silent --show-error `
			http://localhost/index.php/apps/storageusage/api/v1/usage 2>$null | Out-String).Trim()
		if ($LASTEXITCODE -eq 0) {
			$endpointReady = $true
			break
		}
		Start-Sleep -Seconds 1
	}
	if (-not $endpointReady) {
		throw 'The public storage usage endpoint did not become available.'
	}
	$endpointData = $endpointResponse | ConvertFrom-Json
	if (
		$null -eq $endpointData.totalUsage -or
		[string]::IsNullOrWhiteSpace([string] $endpointData.unit) -or
		$null -eq $endpointData.totalUsageBytes -or
		$null -eq $endpointData.cacheTtl
	) {
		throw 'The storage usage endpoint returned an unexpected response.'
	}

	& docker cp "${containerName}:/tmp/storageusage/appinfo/signature.json" (Join-Path $testRoot 'stage\storageusage\appinfo\signature.json')
	Assert-NativeSuccess 'Copying signature.json out of Docker'

	$sourceDateEpoch = (& git -C $repositoryRoot show -s --format=%ct HEAD).Trim()
	Assert-NativeSuccess 'Reading SOURCE_DATE_EPOCH'
	$quotedSourceDateEpoch = Convert-ToBashLiteral $sourceDateEpoch
	Invoke-WslBash "cd $quotedRepositoryRoot && bash scripts/package-release.sh package $quotedVersion $quotedTestRoot/stage $quotedTestRoot/output $quotedSourceDateEpoch"

	$archiveBase = "$appId-v$version"
	$quotedBinarySignature = Convert-ToBashLiteral "$wslTestRoot/$archiveBase.signature.bin"
	$quotedArchive = Convert-ToBashLiteral "$wslTestRoot/output/$archiveBase.tar.gz"
	$quotedPublicKey = Convert-ToBashLiteral "$wslTestRoot/$appId.public.pem"
	$quotedOutputPrefix = Convert-ToBashLiteral "$wslTestRoot/output/"
	Invoke-WslBash "openssl dgst -sha512 -sign $quotedPrivateKey -out $quotedBinarySignature $quotedArchive && openssl x509 -in $quotedPublicCertificate -pubkey -noout > $quotedPublicKey && openssl dgst -sha512 -verify $quotedPublicKey -signature $quotedBinarySignature $quotedArchive && sha256sum $quotedOutputPrefix*"

	Write-Host "Release test succeeded for $appId $version." -ForegroundColor Green
}
finally {
	if ($containerCreated) {
		& docker rm -f $containerName 2>$null | Out-Null
	}

	$resolvedTestRoot = [IO.Path]::GetFullPath($testRoot)
	$testLeaf = Split-Path -Leaf $resolvedTestRoot
	if (
		$resolvedTestRoot.StartsWith($systemTempRoot, [StringComparison]::OrdinalIgnoreCase) -and
		$testLeaf.StartsWith('storageusage-release-test-', [StringComparison]::Ordinal)
	) {
		Remove-Item -LiteralPath $resolvedTestRoot -Recurse -Force -ErrorAction SilentlyContinue
	}
	else {
		Write-Warning "Temporary path was not removed because it failed the safety check: $resolvedTestRoot"
	}
}

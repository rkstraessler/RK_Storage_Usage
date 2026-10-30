<?php

declare(strict_types=1);

use OCA\StorageUsage\AppInfo\Application;
use OCA\StorageUsage\Controller\FolderSettingsController;
use OCA\StorageUsage\Controller\UsageController;
use OCA\StorageUsage\Service\FolderBrowserService;
use OCA\StorageUsage\Service\SettingsService;
use OCA\StorageUsage\Service\UsageService;
use OCA\StorageUsage\Settings\AdminSettings;
use OCA\StorageUsage\Settings\FolderAdminSettings;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Refuse to create fixtures in an existing Nextcloud installation.
check(getenv('STORAGEUSAGE_COMPATIBILITY_TEST') === '1', 'Run through test-nextcloud-compatibility.sh.');

if (($argv[1] ?? '') === '--check-response') {
    $actual = json_decode(file_get_contents('/tmp/storageusage-response.json'), true, 512, JSON_THROW_ON_ERROR);
    $expected = json_decode(file_get_contents('/tmp/storageusage-expected.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($actual !== $expected) {
        fwrite(STDERR, 'Expected: ' . json_encode($expected) . "\nActual: " . json_encode($actual) . "\n");
    }
    check($actual === $expected, 'The anonymous endpoint differs from the verified usage snapshot.');

    $headers = file_get_contents('/tmp/storageusage-response.headers');
    check((bool) preg_match('/^Content-Type: application\/json/im', $headers), 'Missing JSON content type.');
    check((bool) preg_match('/^Cache-Control:.*no-store/im', $headers), 'Missing no-store header.');
    check((bool) preg_match('/^X-Content-Type-Options: nosniff/im', $headers), 'Missing nosniff header.');

    echo "Anonymous JSON endpoint and response headers verified.\n";
    exit(0);
}

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, (string) $exception . "\n");
    exit(1);
});

$application = new Application();
$container = $application->getContainer();
$settings = $container->get(SettingsService::class);
$browser = $container->get(FolderBrowserService::class);
$usage = $container->get(UsageService::class);
$folderController = $container->get(FolderSettingsController::class);
$usageController = $container->get(UsageController::class);
$appConfig = Server::get(IAppConfig::class);

if (($argv[1] ?? '') === '--snapshot') {
    // Start a fresh request after fixture writes, as the real HTTP endpoint does.
    $response = $usageController->get()->getData();
    check($response['folders']->fixture['usageBytes'] === 6144, 'Final fixture size is incorrect.');
    check($response['folders']->fixture['usage'] === 6.0, 'KiB formatting is incorrect.');
    check($response['excludedUsageBytes'] === 6144, 'Final exclusions are incorrect.');
    check($response['totalUsageBytes'] === $response['baseTotalUsageBytes'] - 6144, 'Final total is incorrect.');

    $expectedKeys = ['usage', 'unit', 'usageBytes', 'excludeFromTotal', 'excludedFromTotal', 'status'];
    foreach ((array) $response['folders'] as $folder) {
        check(array_keys($folder) === $expectedKeys, 'The public response exposes unexpected folder metadata.');
    }
    file_put_contents('/tmp/storageusage-expected.json', json_encode($response, JSON_THROW_ON_ERROR));
    exit(0);
}

// Existing installations store these values through the old, untyped API.
$legacyConfig = Server::get(IConfig::class);
$legacyConfig->setAppValue(Application::APP_ID, 'output_unit', 'KiB');
$legacyConfig->setAppValue(Application::APP_ID, 'cache_ttl', '0');
$legacyConfig->setAppValue(Application::APP_ID, 'folder_entries', '[]');
check($settings->getUnit() === 'KiB', 'Legacy output unit was not preserved.');
check($settings->getCacheTtl() === 0, 'Legacy cache duration was not preserved.');
check($settings->getFolderEntries() === [], 'Legacy folder settings were not preserved.');

$user = Server::get(IUserManager::class)->get('storageusage-test');
check($user !== null, 'The disposable test administrator is missing.');
$user->updateLastLoginTimestamp();
Server::get(IUserSession::class)->setUser($user);
$userFolder = Server::get(IRootFolder::class)->getUserFolder($user->getUID());
$parent = $userFolder->newFolder('storageusage-fixture');
$parent->newFile('parent.txt', str_repeat('a', 2048));
$child = $parent->newFolder('nested');
$child->newFile('child.txt', str_repeat('b', 4096));
$gone = $userFolder->newFolder('storageusage-unavailable');

$entries = [];
foreach (['fixture' => '/storageusage-fixture', 'child' => '/storageusage-fixture/nested', 'gone' => '/storageusage-unavailable'] as $key => $path) {
    $selection = $browser->browse($path);
    $entries[] = [
        'id' => 'test-' . $key,
        'key' => $key,
        'viewUserId' => $selection['viewUserId'],
        'fileId' => $selection['fileId'],
        'storageId' => $selection['storageId'],
        'path' => $selection['path'],
        'unit' => 'KiB',
        'excludeFromTotal' => true,
    ];
}
$saveResponse = $folderController->save($entries);
check($saveResponse->getStatus() === 200, 'Saving folder selections failed.');
check(count($settings->getFolderEntries()) === 3, 'Folder settings were not persisted.');
$storedEntries = $settings->getFolderEntries();

$snapshot = $usage->getUsage();
check($snapshot['folders']['fixture']['usageBytes'] === 6144, 'Parent folder size is incorrect.');
check($snapshot['folders']['child']['usageBytes'] === 4096, 'Nested folder size is incorrect.');
check($snapshot['excludedUsageBytes'] === 6144, 'Overlapping folders were excluded more than once.');
check($snapshot['totalUsageBytes'] === $snapshot['baseTotalUsageBytes'] - 6144, 'Adjusted total is incorrect.');

$gone->delete();
$snapshot = $usage->getUsage();
check($snapshot['folders']['gone']['status'] === 'unavailable', 'Deleted folders must be unavailable.');
check($snapshot['folders']['gone']['usageBytes'] === null, 'Deleted folder usage must be null.');

$schema = $container->get(AdminSettings::class)->getSchema();
check($schema['section_id'] === Application::APP_ID, 'Declarative admin settings failed to load.');
$container->get(FolderAdminSettings::class)->getForm();

$duplicateEntries = $entries;
$duplicateEntries[1]['key'] = 'fixture';
check($folderController->save($duplicateEntries)->getStatus() === 400, 'Duplicate JSON keys were accepted.');
check(count($settings->getFolderEntries()) === 3, 'Rejected settings changed the saved configuration.');

$appConfig->setValueString(Application::APP_ID, 'cache_ttl', '60');
$cachedSnapshot = $usage->getUsage();
$cacheFile = $parent->newFile('cache.txt', str_repeat('c', 2048));
check($usage->getUsage() === $cachedSnapshot, 'The local usage cache did not retain its snapshot.');
$cacheFile->delete();
$settings->setFolderEntries([]);
$uncachedSnapshot = $usage->getUsage();
check($uncachedSnapshot['excludedUsageBytes'] === 0, 'Changed folder settings did not invalidate the cache.');
check($uncachedSnapshot['totalUsageBytes'] === $cachedSnapshot['baseTotalUsageBytes'], 'Recalculation did not restore the unadjusted total.');

$appConfig->setValueString(Application::APP_ID, 'cache_ttl', '0');
$settings->setFolderEntries($storedEntries);

echo "Legacy settings, folder selection, exclusions, unavailable folders, admin settings and cache verified.\n";

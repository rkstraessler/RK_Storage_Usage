<?php

declare(strict_types=1);

namespace OCA\StorageUsage\AppInfo;

use OCA\StorageUsage\Settings\AdminSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap
{
    public const APP_ID = 'storageusage';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        $context->registerDeclarativeSettings(AdminSettings::class);
    }

    public function boot(IBootContext $context): void
    {
    }
}

<?php

declare(strict_types=1);

namespace OCA\StorageUsage\Service;

use OCP\Files\IRootFolder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

final class UsageService
{
    private const CACHE_KEY_PREFIX = 'total-usage-bytes-v2';

    private readonly ICache $cache;

    public function __construct(
        private readonly IUserManager $userManager,
        private readonly IRootFolder $rootFolder,
        ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService,
    ) {
        $this->cache = $cacheFactory->createLocal('storageusage');
    }

    public function getTotalUsage(): int
    {
        $cacheTtl = $this->settingsService->getCacheTtl();

        if ($cacheTtl === 0) {
            return $this->calculateTotalUsage();
        }

        $cacheKey = self::CACHE_KEY_PREFIX . '-' . $cacheTtl;
        $cachedUsage = $this->cache->get($cacheKey);

        if (is_int($cachedUsage)) {
            return $cachedUsage;
        }

        if (is_string($cachedUsage) && ctype_digit($cachedUsage)) {
            return (int) $cachedUsage;
        }

        $totalUsage = $this->calculateTotalUsage();

        $this->cache->set(
            $cacheKey,
            $totalUsage,
            $cacheTtl,
        );

        return $totalUsage;
    }

    private function calculateTotalUsage(): int
    {
        $users = [];

        foreach ($this->userManager->getSeenUsers() as $user) {
            $users[$user->getUID()] = $user;
        }

        try {
            foreach ($this->userManager->getDisabledUsers() as $user) {
                $users[$user->getUID()] = $user;
            }
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Disabled users could not be loaded while calculating storage usage.',
                ['exception' => $exception],
            );
        }

        $totalUsage = 0;

        foreach ($users as $user) {
            $totalUsage += $this->getUserUsage($user);
        }

        return $totalUsage;
    }

    private function getUserUsage(IUser $user): int
    {
        try {
            $usage = $this->rootFolder
                ->getUserFolder($user->getUID())
                ->getSize(false);

            return max(0, (int) $usage);
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Storage usage could not be determined for user {userId}.',
                [
                    'userId' => $user->getUID(),
                    'exception' => $exception,
                ],
            );

            return 0;
        }
    }
}

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
    private const CACHE_KEY = 'total-usage-bytes-v1';
    private const CACHE_TTL_SECONDS = 60;

    private readonly ICache $cache;

    public function __construct(
        private readonly IUserManager $userManager,
        private readonly IRootFolder $rootFolder,
        ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
        // Verwendet bei deiner Installation APCu (memcache.local).
        $this->cache = $cacheFactory->createLocal('storageusage');
    }

    public function getTotalUsage(): int
    {
        $cachedUsage = $this->cache->get(self::CACHE_KEY);

        if (is_int($cachedUsage)) {
            return $cachedUsage;
        }

        if (is_string($cachedUsage) && ctype_digit($cachedUsage)) {
            return (int) $cachedUsage;
        }

        $totalUsage = $this->calculateTotalUsage();

        $this->cache->set(
            self::CACHE_KEY,
            $totalUsage,
            self::CACHE_TTL_SECONDS,
        );

        return $totalUsage;
    }

    private function calculateTotalUsage(): int
    {
        /** @var array<string, IUser> $users */
        $users = [];

        // Bekannte Benutzer, die Nextcloud bereits gesehen hat.
        foreach ($this->userManager->getSeenUsers() as $user) {
            $users[$user->getUID()] = $user;
        }

        // Deaktivierte Benutzer können weiterhin Dateien belegen.
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
            // getSize(false) liest die von Nextcloud gepflegte Dateicache-Größe.
            // Es findet kein rekursiver Festplatten-Scan statt.
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

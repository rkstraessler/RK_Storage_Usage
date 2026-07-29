<?php

declare(strict_types=1);

namespace OCA\StorageUsage\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

final class UsageService
{
    private const CACHE_KEY_PREFIX = 'usage-snapshot-v4';

    private const STATUS_OK = 'ok';
    private const STATUS_UNAVAILABLE = 'unavailable';
    private const STATUS_NOT_IN_TOTAL = 'not_in_total';

    private readonly ICache $cache;

    public function __construct(
        private readonly IUserManager $userManager,
        private readonly IRootFolder $rootFolder,
        ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService,
        private readonly FolderBrowserService $folderBrowserService,
    ) {
        $this->cache = $cacheFactory->createLocal('storageusage');
    }

    /**
     * @return array{
     *     totalUsageBytes: int,
     *     baseTotalUsageBytes: int,
     *     excludedUsageBytes: int,
     *     folders: array<string, array{
     *         usageBytes: int|null,
     *         unit: string,
     *         excludeFromTotal: bool,
     *         excludedFromTotal: bool,
     *         status: string
     *     }>
     * }
     */
    public function getUsage(): array
    {
        $cacheTtl = $this->settingsService->getCacheTtl();

        if ($cacheTtl === 0) {
            return $this->calculateUsage();
        }

        $cacheKey = sprintf(
            '%s-%d-%s',
            self::CACHE_KEY_PREFIX,
            $cacheTtl,
            $this->settingsService->getFolderEntriesRevision(),
        );
        $cachedUsage = $this->cache->get($cacheKey);

        if (is_string($cachedUsage)) {
            $snapshot = json_decode($cachedUsage, true);

            if ($this->isValidSnapshot($snapshot)) {
                return $snapshot;
            }
        }

        $usage = $this->calculateUsage();
        $encodedUsage = json_encode($usage);

        if (is_string($encodedUsage)) {
            $this->cache->set($cacheKey, $encodedUsage, $cacheTtl);
        }

        return $usage;
    }

    public function getTotalUsage(): int
    {
        return $this->getUsage()['totalUsageBytes'];
    }

    /**
     * @return array{
     *     totalUsageBytes: int,
     *     baseTotalUsageBytes: int,
     *     excludedUsageBytes: int,
     *     folders: array<string, array{
     *         usageBytes: int|null,
     *         unit: string,
     *         excludeFromTotal: bool,
     *         excludedFromTotal: bool,
     *         status: string
     *     }>
     * }
     */
    private function calculateUsage(): array
    {
        [$baseTotalUsage, $baseScopes] = $this->calculateBaseTotalUsage();
        $folderResults = [];
        $exclusionCandidates = [];

        foreach ($this->settingsService->getFolderEntries() as $entry) {
            $key = $entry['key'];
            $excludeFromTotal = $entry['excludeFromTotal'];
            $folderResults[$key] = [
                'usageBytes' => null,
                'unit' => $entry['unit'],
                'excludeFromTotal' => $excludeFromTotal,
                'excludedFromTotal' => false,
                'status' => self::STATUS_UNAVAILABLE,
            ];

            try {
                $folder = $this->folderBrowserService->resolveEntry($entry);

                if (!$folder instanceof Folder) {
                    continue;
                }

                $usage = max(0, (int) $folder->getSize(false));
                $numericStorageId = (int) $folder
                    ->getStorage()
                    ->getCache()
                    ->getNumericStorageId();
                $internalPath = $this->normalizeInternalPath($folder->getInternalPath());
                $folderResults[$key]['usageBytes'] = $usage;
                $folderResults[$key]['status'] = self::STATUS_OK;

                if (!$excludeFromTotal) {
                    continue;
                }

                if (!$this->isWithinBaseScope(
                    $numericStorageId,
                    $internalPath,
                    $baseScopes,
                )) {
                    $folderResults[$key]['status'] = self::STATUS_NOT_IN_TOTAL;
                    continue;
                }

                $exclusionCandidates[] = [
                    'key' => $key,
                    'numericStorageId' => $numericStorageId,
                    'internalPath' => $internalPath,
                    'usageBytes' => $usage,
                ];
            } catch (Throwable $exception) {
                $this->logger->warning(
                    'Configured folder usage could not be determined for key {key}.',
                    [
                        'key' => $key,
                        'exception' => $exception,
                    ],
                );
            }
        }

        usort(
            $exclusionCandidates,
            static fn (array $left, array $right): int => [
                $left['numericStorageId'],
                substr_count($left['internalPath'], '/'),
                strlen($left['internalPath']),
                $left['internalPath'],
            ] <=> [
                $right['numericStorageId'],
                substr_count($right['internalPath'], '/'),
                strlen($right['internalPath']),
                $right['internalPath'],
            ],
        );

        $selectedExclusions = [];
        $excludedUsage = 0;

        foreach ($exclusionCandidates as $candidate) {
            $coveredByParent = false;

            foreach ($selectedExclusions as $selectedExclusion) {
                if ($candidate['numericStorageId'] !== $selectedExclusion['numericStorageId']) {
                    continue;
                }

                if ($this->isSameOrDescendant(
                    $candidate['internalPath'],
                    $selectedExclusion['internalPath'],
                )) {
                    $coveredByParent = true;
                    break;
                }
            }

            if ($coveredByParent) {
                $folderResults[$candidate['key']]['excludedFromTotal'] = true;
                continue;
            }

            $selectedExclusions[] = $candidate;
            $excludedUsage += $candidate['usageBytes'];
            $folderResults[$candidate['key']]['excludedFromTotal'] = true;
        }

        $excludedUsage = min($baseTotalUsage, max(0, $excludedUsage));

        return [
            'totalUsageBytes' => max(0, $baseTotalUsage - $excludedUsage),
            'baseTotalUsageBytes' => $baseTotalUsage,
            'excludedUsageBytes' => $excludedUsage,
            'folders' => $folderResults,
        ];
    }

    /**
     * @return array{0: int, 1: array<int, list<string>>}
     */
    private function calculateBaseTotalUsage(): array
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
        $baseScopes = [];

        foreach ($users as $user) {
            try {
                $userFolder = $this->rootFolder->getUserFolder($user->getUID());
                $totalUsage += max(0, (int) $userFolder->getSize(false));
                $numericStorageId = (int) $userFolder
                    ->getStorage()
                    ->getCache()
                    ->getNumericStorageId();
                $baseScopes[$numericStorageId] ??= [];
                $baseScopes[$numericStorageId][] = $this->normalizeInternalPath(
                    $userFolder->getInternalPath(),
                );
            } catch (Throwable $exception) {
                $this->logUnavailableUser($user, $exception);
            }
        }

        return [$totalUsage, $baseScopes];
    }

    private function logUnavailableUser(IUser $user, Throwable $exception): void
    {
        $this->logger->warning(
            'Storage usage could not be determined for user {userId}.',
            [
                'userId' => $user->getUID(),
                'exception' => $exception,
            ],
        );
    }

    private function normalizeInternalPath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    private function isSameOrDescendant(string $path, string $parentPath): bool
    {
        if ($path === $parentPath) {
            return true;
        }

        if ($parentPath === '') {
            return true;
        }

        return str_starts_with($path, $parentPath . '/');
    }

    /**
     * @param array<int, list<string>> $baseScopes
     */
    private function isWithinBaseScope(
        int $numericStorageId,
        string $internalPath,
        array $baseScopes,
    ): bool {
        foreach ($baseScopes[$numericStorageId] ?? [] as $basePath) {
            if ($this->isSameOrDescendant($internalPath, $basePath)) {
                return true;
            }
        }

        return false;
    }

    private function isValidSnapshot(mixed $snapshot): bool
    {
        return is_array($snapshot)
            && isset(
                $snapshot['totalUsageBytes'],
                $snapshot['baseTotalUsageBytes'],
                $snapshot['excludedUsageBytes'],
                $snapshot['folders'],
            )
            && is_int($snapshot['totalUsageBytes'])
            && is_int($snapshot['baseTotalUsageBytes'])
            && is_int($snapshot['excludedUsageBytes'])
            && is_array($snapshot['folders']);
    }
}

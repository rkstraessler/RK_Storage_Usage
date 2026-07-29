<?php

declare(strict_types=1);

namespace OCA\StorageUsage\Service;

use InvalidArgumentException;
use OCA\StorageUsage\AppInfo\Application;
use OCP\IConfig;
use Throwable;

final class SettingsService
{
    public const UNIT_AUTO = 'Auto';
    public const UNIT_BYTES = 'B';
    public const UNIT_KILOBYTES = 'kB';
    public const UNIT_KIBIBYTES = 'KiB';
    public const UNIT_MEGABYTES = 'MB';
    public const UNIT_MEBIBYTES = 'MiB';
    public const UNIT_GIGABYTES = 'GB';
    public const UNIT_GIBIBYTES = 'GiB';
    public const UNIT_TERABYTES = 'TB';
    public const UNIT_TEBIBYTES = 'TiB';

    public const DEFAULT_UNIT = self::UNIT_BYTES;
    public const DEFAULT_CACHE_TTL = 60;

    private const FOLDER_ENTRIES_CONFIG_KEY = 'folder_entries';
    private const FOLDER_KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D';
    private const ENTRY_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D';

    public const AVAILABLE_UNITS = [
        self::UNIT_AUTO,
        self::UNIT_BYTES,
        self::UNIT_KILOBYTES,
        self::UNIT_KIBIBYTES,
        self::UNIT_MEGABYTES,
        self::UNIT_MEBIBYTES,
        self::UNIT_GIGABYTES,
        self::UNIT_GIBIBYTES,
        self::UNIT_TERABYTES,
        self::UNIT_TEBIBYTES,
    ];

    public const AVAILABLE_CACHE_TTLS = [
        0,
        30,
        60,
        300,
        900,
        3600,
    ];

    private const UNIT_FACTORS = [
        self::UNIT_BYTES => 1,
        self::UNIT_KILOBYTES => 1000,
        self::UNIT_KIBIBYTES => 1024,
        self::UNIT_MEGABYTES => 1000 ** 2,
        self::UNIT_MEBIBYTES => 1024 ** 2,
        self::UNIT_GIGABYTES => 1000 ** 3,
        self::UNIT_GIBIBYTES => 1024 ** 3,
        self::UNIT_TERABYTES => 1000 ** 4,
        self::UNIT_TEBIBYTES => 1024 ** 4,
    ];

    public function __construct(
        private readonly IConfig $config,
    ) {
    }

    public function getUnit(): string
    {
        $unit = $this->config->getAppValue(
            Application::APP_ID,
            'output_unit',
            self::DEFAULT_UNIT,
        );

        return in_array($unit, self::AVAILABLE_UNITS, true)
            ? $unit
            : self::DEFAULT_UNIT;
    }

    public function getCacheTtl(): int
    {
        $cacheTtl = (int) $this->config->getAppValue(
            Application::APP_ID,
            'cache_ttl',
            (string) self::DEFAULT_CACHE_TTL,
        );

        return in_array($cacheTtl, self::AVAILABLE_CACHE_TTLS, true)
            ? $cacheTtl
            : self::DEFAULT_CACHE_TTL;
    }

    /**
     * @return list<array{
     *     id: string,
     *     key: string,
     *     viewUserId: string,
     *     fileId: int,
     *     storageId: string,
     *     sourceUserId: string,
     *     sourceFileId: int,
     *     sourceStorageId: string,
     *     sourceNumericStorageId: int,
     *     path: string,
     *     unit: string,
     *     excludeFromTotal: bool
     * }>
     */
    public function getFolderEntries(): array
    {
        $encodedEntries = $this->config->getAppValue(
            Application::APP_ID,
            self::FOLDER_ENTRIES_CONFIG_KEY,
            '[]',
        );

        try {
            $entries = json_decode($encodedEntries, true, 512, JSON_THROW_ON_ERROR);

            return is_array($entries)
                ? $this->normalizeFolderEntries($entries)
                : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<array-key, mixed> $entries
     * @return list<array{
     *     id: string,
     *     key: string,
     *     viewUserId: string,
     *     fileId: int,
     *     storageId: string,
     *     sourceUserId: string,
     *     sourceFileId: int,
     *     sourceStorageId: string,
     *     sourceNumericStorageId: int,
     *     path: string,
     *     unit: string,
     *     excludeFromTotal: bool
     * }>
     */
    public function setFolderEntries(array $entries): array
    {
        $normalizedEntries = $this->normalizeFolderEntries($entries);
        $encodedEntries = json_encode($normalizedEntries, JSON_THROW_ON_ERROR);

        $this->config->setAppValue(
            Application::APP_ID,
            self::FOLDER_ENTRIES_CONFIG_KEY,
            $encodedEntries,
        );

        return $normalizedEntries;
    }

    public function getFolderEntriesRevision(): string
    {
        $encodedEntries = json_encode($this->getFolderEntries());

        return substr(hash('sha256', is_string($encodedEntries) ? $encodedEntries : '[]'), 0, 32);
    }

    /**
     * @return array{value: int|float, unit: string}
     */
    public function formatBytes(int $bytes): array
    {
        return $this->formatBytesForUnit($bytes, $this->getUnit());
    }

    /**
     * @return array{value: int|float, unit: string}
     */
    public function formatBytesForUnit(int $bytes, string $unit): array
    {
        if (!in_array($unit, self::AVAILABLE_UNITS, true)) {
            throw new InvalidArgumentException('Unsupported output unit.');
        }

        if ($unit === self::UNIT_AUTO) {
            $unit = $this->getAutomaticUnit($bytes);
        }

        $factor = self::UNIT_FACTORS[$unit];

        return [
            'value' => $factor === 1
                ? $bytes
                : round($bytes / $factor, 2),
            'unit' => $unit,
        ];
    }

    /**
     * @param array<array-key, mixed> $entries
     * @return list<array{
     *     id: string,
     *     key: string,
     *     viewUserId: string,
     *     fileId: int,
     *     storageId: string,
     *     sourceUserId: string,
     *     sourceFileId: int,
     *     sourceStorageId: string,
     *     sourceNumericStorageId: int,
     *     path: string,
     *     unit: string,
     *     excludeFromTotal: bool
     * }>
     */
    private function normalizeFolderEntries(array $entries): array
    {
        if (!array_is_list($entries)) {
            throw new InvalidArgumentException('Folder entries must be a list.');
        }

        $normalizedEntries = [];
        $entryIds = [];
        $keys = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Every folder entry must be an object.');
            }

            $id = trim((string) ($entry['id'] ?? ''));
            $key = trim((string) ($entry['key'] ?? ''));
            $viewUserId = trim((string) ($entry['viewUserId'] ?? ''));
            $fileId = filter_var($entry['fileId'] ?? null, FILTER_VALIDATE_INT);
            $storageId = (string) ($entry['storageId'] ?? '');
            $sourceUserId = trim((string) ($entry['sourceUserId'] ?? $viewUserId));
            $sourceFileId = filter_var(
                $entry['sourceFileId'] ?? $entry['fileId'] ?? null,
                FILTER_VALIDATE_INT,
            );
            $sourceStorageId = (string) ($entry['sourceStorageId'] ?? $storageId);
            // Entries created before source identities were introduced use 0
            // and are upgraded the next time an administrator saves them.
            $sourceNumericStorageId = filter_var(
                $entry['sourceNumericStorageId'] ?? 0,
                FILTER_VALIDATE_INT,
            );
            $path = $this->normalizeDisplayPath((string) ($entry['path'] ?? ''));
            $unit = (string) ($entry['unit'] ?? '');
            $excludeFromTotal = $entry['excludeFromTotal'] ?? null;

            if (!preg_match(self::ENTRY_ID_PATTERN, $id)) {
                throw new InvalidArgumentException('Every folder entry needs a valid stable ID.');
            }

            if (isset($entryIds[$id])) {
                throw new InvalidArgumentException('Folder entry IDs must be unique.');
            }

            if (!preg_match(self::FOLDER_KEY_PATTERN, $key)) {
                throw new InvalidArgumentException(
                    'JSON keys must start with a letter and contain only letters, numbers, underscores, or hyphens (maximum 64 characters).',
                );
            }

            if (isset($keys[$key])) {
                throw new InvalidArgumentException('JSON keys must be unique.');
            }

            if ($viewUserId === ''
                || strlen($viewUserId) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $viewUserId)) {
                throw new InvalidArgumentException('Every folder entry needs a valid user identity.');
            }

            if ($fileId === false || $fileId < 1) {
                throw new InvalidArgumentException('Every folder entry needs a valid file ID.');
            }

            if ($storageId === ''
                || strlen($storageId) > 1024
                || preg_match('/[\x00-\x1F\x7F]/', $storageId)) {
                throw new InvalidArgumentException('Every folder entry needs a valid storage identity.');
            }

            if ($sourceUserId === ''
                || strlen($sourceUserId) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $sourceUserId)
                || $sourceFileId === false
                || $sourceFileId < 1
                || $sourceStorageId === ''
                || strlen($sourceStorageId) > 1024
                || preg_match('/[\x00-\x1F\x7F]/', $sourceStorageId)
                || $sourceNumericStorageId === false
                || $sourceNumericStorageId < 0) {
                throw new InvalidArgumentException('Every folder entry needs a valid source identity.');
            }

            if ($path === '' || strlen($path) > 4096) {
                throw new InvalidArgumentException('Every folder entry needs a valid display path.');
            }

            if (!in_array($unit, self::AVAILABLE_UNITS, true)) {
                throw new InvalidArgumentException('Every folder entry needs a supported output unit.');
            }

            if (!is_bool($excludeFromTotal)) {
                throw new InvalidArgumentException('Every folder entry needs an exclusion setting.');
            }

            $entryIds[$id] = true;
            $keys[$key] = true;
            $normalizedEntries[] = [
                'id' => $id,
                'key' => $key,
                'viewUserId' => $viewUserId,
                'fileId' => (int) $fileId,
                'storageId' => $storageId,
                'sourceUserId' => $sourceUserId,
                'sourceFileId' => (int) $sourceFileId,
                'sourceStorageId' => $sourceStorageId,
                'sourceNumericStorageId' => (int) $sourceNumericStorageId,
                'path' => $path,
                'unit' => $unit,
                'excludeFromTotal' => $excludeFromTotal,
            ];
        }

        return $normalizedEntries;
    }

    private function normalizeDisplayPath(string $path): string
    {
        if ($path === ''
            || str_contains($path, "\0")
            || preg_match('/[\x01-\x1F\x7F]/', $path)) {
            return '';
        }

        $path = str_replace('\\', '/', trim($path));
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return '';
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    private function getAutomaticUnit(int $bytes): string
    {
        foreach ([
            self::UNIT_TEBIBYTES,
            self::UNIT_GIBIBYTES,
            self::UNIT_MEBIBYTES,
            self::UNIT_KIBIBYTES,
        ] as $unit) {
            if ($bytes >= self::UNIT_FACTORS[$unit]) {
                return $unit;
            }
        }

        return self::UNIT_BYTES;
    }
}

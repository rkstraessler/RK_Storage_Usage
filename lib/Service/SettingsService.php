<?php

declare(strict_types=1);

namespace OCA\StorageUsage\Service;

use OCA\StorageUsage\AppInfo\Application;
use OCP\IConfig;

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
     * @return array{value: int|float, unit: string}
     */
    public function formatBytes(int $bytes): array
    {
        $unit = $this->getUnit();

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

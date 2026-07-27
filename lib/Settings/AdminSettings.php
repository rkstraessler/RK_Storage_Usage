<?php

declare(strict_types=1);

namespace OCA\StorageUsage\Settings;

use OCA\StorageUsage\Service\SettingsService;
use OCP\IL10N;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

final class AdminSettings implements IDeclarativeSettingsForm
{
    public function __construct(
        private readonly IL10N $l10n,
    ) {
    }

    public function getSchema(): array
    {
        return [
            'id' => 'storageusage_admin_settings',
            'priority' => 10,
            'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
            'section_id' => 'storageusage',
            'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
            'title' => $this->l10n->t('Storage Usage API'),
            'description' => $this->l10n->t(
                'Choose how the public API formats total storage usage and how long calculated values are cached.',
            ),
            'doc_url' => 'https://github.com/rkstraessler/RK_Storage_Usage#readme',
            'fields' => [
                [
                    'id' => 'output_unit',
                    'title' => $this->l10n->t('Output unit'),
                    'description' => $this->l10n->t(
                        'The selected unit is used for totalUsage. Auto chooses B, KiB, MiB, GiB, or TiB based on the size. The exact byte value remains available as totalUsageBytes.',
                    ),
                    'type' => DeclarativeSettingsTypes::SELECT,
                    'options' => SettingsService::AVAILABLE_UNITS,
                    'placeholder' => $this->l10n->t('Select an output unit'),
                    'default' => SettingsService::DEFAULT_UNIT,
                ],
                [
                    'id' => 'cache_ttl',
                    'title' => $this->l10n->t('Cache time in seconds'),
                    'description' => $this->l10n->t(
                        'Use 0 to recalculate the total for every request.',
                    ),
                    'type' => DeclarativeSettingsTypes::SELECT,
                    'options' => array_map(
                        static fn (int $cacheTtl): string => (string) $cacheTtl,
                        SettingsService::AVAILABLE_CACHE_TTLS,
                    ),
                    'placeholder' => $this->l10n->t('Select a cache time'),
                    'default' => (string) SettingsService::DEFAULT_CACHE_TTL,
                ],
            ],
        ];
    }
}

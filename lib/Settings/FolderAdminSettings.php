<?php

declare(strict_types=1);

namespace OCA\StorageUsage\Settings;

use OCA\StorageUsage\AppInfo\Application;
use OCA\StorageUsage\Service\SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\Settings\ISettings;
use OCP\Util;

final class FolderAdminSettings implements ISettings
{
    public function __construct(
        private readonly IInitialState $initialState,
        private readonly SettingsService $settingsService,
        private readonly IL10N $l10n,
    ) {
    }

    public function getForm(): TemplateResponse
    {
        $translations = [];
        foreach ([
            'No separate folders have been configured yet.',
            'Separate folder {number}',
            'Remove',
            'The folder was removed from the configuration. Save to apply the change.',
            'Remove folder {path}',
            'JSON key',
            'For example: project_files',
            'Use 1–64 characters. Start with a letter; then use letters, numbers, underscores, or hyphens.',
            'Output unit',
            'Auto selects a binary unit that fits the folder size.',
            'Selected folder',
            'Change folder',
            'Exclude from total',
            'When enabled, this folder is returned separately and its size is subtracted from totalUsage.',
            'Loading folders…',
            'The folders could not be loaded.',
            'Files',
            'This folder does not contain any subfolders.',
            'Folder',
            'Open folder {name}',
            'Select',
            'Select folder {name}',
            'This folder cannot be selected.',
            'The folder was added. Save to apply the change.',
            'Enter a JSON key for every selected folder.',
            'Every JSON key must be unique.',
            'Select a valid folder for every entry.',
            'Saving folder settings…',
            'Folder settings saved.',
            'The folder settings could not be saved.',
            'The request failed.',
        ] as $source) {
            $translations[$source] = $this->l10n->t($source);
        }

        $entries = array_map(
            static fn (array $entry): array => [
                'id' => $entry['id'],
                'key' => $entry['key'],
                'viewUserId' => $entry['viewUserId'],
                'fileId' => $entry['fileId'],
                'storageId' => $entry['storageId'],
                'path' => $entry['path'],
                'unit' => $entry['unit'],
                'excludeFromTotal' => $entry['excludeFromTotal'],
            ],
            $this->settingsService->getFolderEntries(),
        );

        $this->initialState->provideInitialState('folderAdminSettings', [
            'entries' => $entries,
            'availableUnits' => SettingsService::AVAILABLE_UNITS,
            'defaultUnit' => $this->settingsService->getUnit(),
            'translations' => $translations,
        ]);

        Util::addScript(Application::APP_ID, 'admin-settings');
        Util::addStyle(Application::APP_ID, 'admin-settings');

        return new TemplateResponse(
            Application::APP_ID,
            'admin-folders',
            [],
            '',
        );
    }

    public function getSection(): string
    {
        return Application::APP_ID;
    }

    public function getPriority(): int
    {
        return 20;
    }
}

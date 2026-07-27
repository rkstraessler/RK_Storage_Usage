<?php

declare(strict_types=1);

namespace OCA\StorageUsage\Controller;

use OCA\StorageUsage\Service\UsageService;
use OCA\StorageUsage\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class UsageController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly UsageService $usageService,
        private readonly SettingsService $settingsService,
    ) {
        parent::__construct($appName, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function get(): JSONResponse
    {
        $totalUsageBytes = $this->usageService->getTotalUsage();
        $formattedUsage = $this->settingsService->formatBytes($totalUsageBytes);

        $response = new JSONResponse([
            'totalUsage' => $formattedUsage['value'],
            'unit' => $formattedUsage['unit'],
            'totalUsageBytes' => $totalUsageBytes,
            'cacheTtl' => $this->settingsService->getCacheTtl(),
        ]);

        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}

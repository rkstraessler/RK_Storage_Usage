<?php

declare(strict_types=1);

namespace OCA\StorageUsage\Controller;

use OCA\StorageUsage\Service\UsageService;
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
    ) {
        parent::__construct($appName, $request);
    }

    public function get(): JSONResponse
    {
        $response = new JSONResponse([
            'totalUsage' => $this->usageService->getTotalUsage(),
        ]);

        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}

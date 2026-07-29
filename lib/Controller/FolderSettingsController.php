<?php

declare(strict_types=1);

namespace OCA\StorageUsage\Controller;

use InvalidArgumentException;
use OCA\StorageUsage\Service\FolderBrowserService;
use OCA\StorageUsage\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

final class FolderSettingsController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly FolderBrowserService $folderBrowserService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    public function browse(string $path = '/'): JSONResponse
    {
        try {
            $response = new JSONResponse($this->folderBrowserService->browse($path));
            $response->addHeader('Cache-Control', 'no-store');

            return $response;
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (Throwable $exception) {
            $this->logger->error(
                'The folder browser failed.',
                ['exception' => $exception],
            );

            return $this->errorResponse(
                'The folders could not be loaded.',
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * @param array<array-key, mixed> $entries
     */
    public function save(array $entries = []): JSONResponse
    {
        try {
            $existingEntries = [];
            foreach ($this->settingsService->getFolderEntries() as $entry) {
                $existingEntries[$entry['id']] = $entry;
            }

            $validatedEntries = [];
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    throw new InvalidArgumentException('Every folder entry must be an object.');
                }

                $id = trim((string) ($entry['id'] ?? ''));
                $existingEntry = $existingEntries[$id] ?? null;

                if (is_array($existingEntry) && $this->hasSameStoredIdentity($entry, $existingEntry)) {
                    $identity = $this->folderBrowserService->refreshStoredIdentity($existingEntry);
                } else {
                    $identity = $this->folderBrowserService->validateSelectionForCurrentUser($entry);
                }

                $validatedEntries[] = [
                    'id' => $id,
                    'key' => $entry['key'] ?? null,
                    ...$identity,
                    'unit' => $entry['unit'] ?? null,
                    'excludeFromTotal' => $entry['excludeFromTotal'] ?? null,
                ];
            }

            return new JSONResponse([
                'entries' => $this->settingsService->setFolderEntries($validatedEntries),
            ]);
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (Throwable $exception) {
            $this->logger->error(
                'The folder settings could not be saved.',
                ['exception' => $exception],
            );

            return $this->errorResponse(
                'The folder settings could not be saved.',
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * @param array<string, mixed> $submittedEntry
     * @param array<string, mixed> $storedEntry
     */
    private function hasSameStoredIdentity(array $submittedEntry, array $storedEntry): bool
    {
        return (string) ($submittedEntry['viewUserId'] ?? '') === $storedEntry['viewUserId']
            && (int) ($submittedEntry['fileId'] ?? 0) === $storedEntry['fileId']
            && (string) ($submittedEntry['storageId'] ?? '') === $storedEntry['storageId'];
    }

    private function errorResponse(string $message, int $status): JSONResponse
    {
        $response = new JSONResponse(['message' => $message], $status);
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}

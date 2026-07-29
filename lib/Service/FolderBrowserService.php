<?php

declare(strict_types=1);

namespace OCA\StorageUsage\Service;

use InvalidArgumentException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Throwable;

final class FolderBrowserService
{
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly IUserSession $userSession,
    ) {
    }

    /**
     * @return array{
     *     name: string,
     *     path: string,
     *     viewUserId: string,
     *     fileId: int,
     *     storageId: string,
     *     breadcrumbs: list<array{name: string, path: string}>,
     *     folders: list<array{
     *         name: string,
     *         path: string,
     *         viewUserId: string,
     *         fileId: int,
     *         storageId: string
     *     }>
     * }
     */
    public function browse(string $path = '/'): array
    {
        $userId = $this->getCurrentUserId();
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $normalizedPath = $this->normalizeRequestedPath($path);
        $currentFolder = $this->getFolderAtPath($userFolder, $normalizedPath);
        $currentMetadata = $this->getFolderMetadata($userFolder, $currentFolder, $userId);
        $folders = [];

        foreach ($currentFolder->getDirectoryListing() as $node) {
            if (!$node instanceof Folder || $node->getId() === null) {
                continue;
            }

            $folders[] = $this->getFolderMetadata($userFolder, $node, $userId);
        }

        usort(
            $folders,
            static fn (array $left, array $right): int => strnatcasecmp(
                $left['name'],
                $right['name'],
            ),
        );

        return [
            ...$currentMetadata,
            'breadcrumbs' => $this->buildBreadcrumbs($currentMetadata['path']),
            'folders' => $folders,
        ];
    }

    /**
     * Resolve an entry through the configured user's accessible file view and
     * return its physical source node. Shared-folder mount names can therefore
     * change without changing the measured identity. The display path is never
     * used as a fallback lookup.
     *
     * @param array<string, mixed> $entry
     */
    public function resolveEntry(array $entry): ?Folder
    {
        try {
            $viewFolder = $this->resolveAccessibleFolder($entry);
            if (!$viewFolder instanceof Folder) {
                return null;
            }

            $sourceIdentity = $this->getSourceIdentity(
                $viewFolder,
                (string) ($entry['viewUserId'] ?? ''),
            );

            return $sourceIdentity['folder'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{
     *     viewUserId: string,
     *     fileId: int,
     *     storageId: string,
     *     sourceUserId: string,
     *     sourceFileId: int,
     *     sourceStorageId: string,
     *     sourceNumericStorageId: int,
     *     path: string
     * }
     */
    public function validateSelectionForCurrentUser(array $entry): array
    {
        $userId = $this->getCurrentUserId();
        $candidate = [
            'viewUserId' => $userId,
            'fileId' => $entry['fileId'] ?? null,
            'storageId' => $entry['storageId'] ?? null,
            'path' => $entry['path'] ?? '/',
        ];
        $folder = $this->resolveAccessibleFolder($candidate, true);

        if (!$folder instanceof Folder) {
            throw new InvalidArgumentException(
                'The selected folder is no longer available or is not accessible to this administrator.',
            );
        }

        $userFolder = $this->rootFolder->getUserFolder($userId);
        $sourceIdentity = $this->getSourceIdentity($folder, $userId);

        return [
            'viewUserId' => $userId,
            'fileId' => (int) $folder->getId(),
            'storageId' => $this->getStorageId($folder),
            'sourceUserId' => $sourceIdentity['userId'],
            'sourceFileId' => (int) $sourceIdentity['folder']->getId(),
            'sourceStorageId' => $this->getStorageId($sourceIdentity['folder']),
            'sourceNumericStorageId' => $sourceIdentity['numericStorageId'],
            'path' => $this->getDisplayPath($userFolder, $folder),
        ];
    }

    /**
     * Refresh the non-authoritative path of an already trusted stored entry.
     * Unavailable entries remain configurable and retain their last display
     * path until they are removed or a different folder is selected.
     *
     * @param array<string, mixed> $entry
     * @return array{
     *     viewUserId: string,
     *     fileId: int,
     *     storageId: string,
     *     sourceUserId: string,
     *     sourceFileId: int,
     *     sourceStorageId: string,
     *     sourceNumericStorageId: int,
     *     path: string
     * }
     */
    public function refreshStoredIdentity(array $entry): array
    {
        $identity = [
            'viewUserId' => (string) ($entry['viewUserId'] ?? ''),
            'fileId' => (int) ($entry['fileId'] ?? 0),
            'storageId' => (string) ($entry['storageId'] ?? ''),
            'sourceUserId' => (string) ($entry['sourceUserId'] ?? $entry['viewUserId'] ?? ''),
            'sourceFileId' => (int) ($entry['sourceFileId'] ?? $entry['fileId'] ?? 0),
            'sourceStorageId' => (string) ($entry['sourceStorageId'] ?? $entry['storageId'] ?? ''),
            'sourceNumericStorageId' => (int) ($entry['sourceNumericStorageId'] ?? 0),
            'path' => (string) ($entry['path'] ?? '/'),
        ];
        $folder = $this->resolveAccessibleFolder($identity);

        if (!$folder instanceof Folder) {
            return $identity;
        }

        $userFolder = $this->rootFolder->getUserFolder($identity['viewUserId']);
        $sourceIdentity = $this->getSourceIdentity($folder, $identity['viewUserId']);
        $identity['fileId'] = (int) $folder->getId();
        $identity['storageId'] = $this->getStorageId($folder);
        $identity['sourceUserId'] = $sourceIdentity['userId'];
        $identity['sourceFileId'] = (int) $sourceIdentity['folder']->getId();
        $identity['sourceStorageId'] = $this->getStorageId($sourceIdentity['folder']);
        $identity['sourceNumericStorageId'] = $sourceIdentity['numericStorageId'];
        $identity['path'] = $this->getDisplayPath($userFolder, $folder);

        return $identity;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function resolveAccessibleFolder(
        array $entry,
        bool $requireViewStorageIdentity = false,
    ): ?Folder {
        $userId = (string) ($entry['viewUserId'] ?? '');
        $fileId = filter_var($entry['fileId'] ?? null, FILTER_VALIDATE_INT);
        $storageId = (string) ($entry['storageId'] ?? '');

        if ($userId === '' || $fileId === false || $fileId < 1 || $storageId === '') {
            return null;
        }

        $userFolder = $this->rootFolder->getUserFolder($userId);
        $matches = [];
        $hasSourceIdentity = isset($entry['sourceUserId'], $entry['sourceFileId'])
            && (int) ($entry['sourceNumericStorageId'] ?? 0) > 0;

        foreach ($userFolder->getById((int) $fileId) as $node) {
            if (!$node instanceof Folder) {
                continue;
            }

            if ($requireViewStorageIdentity && $this->getStorageId($node) !== $storageId) {
                continue;
            }

            if ($hasSourceIdentity) {
                $sourceIdentity = $this->getSourceIdentity($node, $userId);
                if ($sourceIdentity['userId'] !== (string) $entry['sourceUserId']
                    || (int) $sourceIdentity['folder']->getId() !== (int) $entry['sourceFileId']
                    || $sourceIdentity['numericStorageId'] !== (int) $entry['sourceNumericStorageId']) {
                    continue;
                }
            } elseif ($this->getStorageId($node) !== $storageId) {
                continue;
            }

            $matches[] = $node;
        }

        if ($matches === []) {
            return null;
        }

        $storedPath = $this->normalizeRequestedPath((string) ($entry['path'] ?? '/'));
        foreach ($matches as $match) {
            if ($this->getDisplayPath($userFolder, $match) === $storedPath) {
                return $match;
            }
        }

        usort(
            $matches,
            fn (Folder $left, Folder $right): int => strcmp(
                $this->getDisplayPath($userFolder, $left),
                $this->getDisplayPath($userFolder, $right),
            ),
        );

        return $matches[0];
    }

    /**
     * @return array{userId: string, folder: Folder, numericStorageId: int}
     */
    private function getSourceIdentity(Folder $folder, string $viewUserId): array
    {
        $sourceUserId = $folder->getOwner()?->getUID() ?? $viewUserId;
        $numericStorageId = (int) $folder
            ->getStorage()
            ->getCache()
            ->getNumericStorageId();
        $sourceUserFolder = $this->rootFolder->getUserFolder($sourceUserId);
        $matches = [];

        foreach ($sourceUserFolder->getById((int) $folder->getId()) as $sourceNode) {
            if (!$sourceNode instanceof Folder) {
                continue;
            }

            $candidateNumericStorageId = (int) $sourceNode
                ->getStorage()
                ->getCache()
                ->getNumericStorageId();
            if ($candidateNumericStorageId === $numericStorageId) {
                $matches[] = $sourceNode;
            }
        }

        if ($matches !== []) {
            usort(
                $matches,
                fn (Folder $left, Folder $right): int => [
                    str_starts_with($this->getStorageId($left), 'shared::'),
                    $left->getPath(),
                ] <=> [
                    str_starts_with($this->getStorageId($right), 'shared::'),
                    $right->getPath(),
                ],
            );

            return [
                'userId' => $sourceUserId,
                'folder' => $matches[0],
                'numericStorageId' => $numericStorageId,
            ];
        }

        throw new InvalidArgumentException('The source of the selected share is not available.');
    }

    public function getStorageId(Folder $folder): string
    {
        return $folder->getStorage()->getId();
    }

    public function getDisplayPath(Folder $userFolder, Folder $folder): string
    {
        $userFolderPath = rtrim($userFolder->getPath(), '/');
        $folderPath = rtrim($folder->getPath(), '/');

        if ($folderPath === $userFolderPath) {
            return '/';
        }

        $prefix = $userFolderPath . '/';
        if (!str_starts_with($folderPath, $prefix)) {
            throw new InvalidArgumentException('The folder is outside the current file view.');
        }

        return '/' . substr($folderPath, strlen($prefix));
    }

    private function getCurrentUserId(): string
    {
        $user = $this->userSession->getUser();

        if ($user === null) {
            throw new InvalidArgumentException('An authenticated administrator is required.');
        }

        return $user->getUID();
    }

    private function normalizeRequestedPath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        if (str_contains($path, "\0")
            || str_contains($path, '\\')
            || preg_match('/[\x01-\x1F\x7F]/', $path)) {
            throw new InvalidArgumentException('The requested folder path is invalid.');
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('The requested folder path is invalid.');
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    private function getFolderAtPath(Folder $userFolder, string $path): Folder
    {
        if ($path === '/') {
            return $userFolder;
        }

        try {
            $node = $userFolder->get(ltrim($path, '/'));
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The requested folder is not available.', 0, $exception);
        }

        if (!$node instanceof Folder) {
            throw new InvalidArgumentException('The requested path is not a folder.');
        }

        return $node;
    }

    /**
     * @return array{name: string, path: string, viewUserId: string, fileId: int, storageId: string}
     */
    private function getFolderMetadata(
        Folder $userFolder,
        Folder $folder,
        string $userId,
    ): array {
        $fileId = $folder->getId();

        if ($fileId === null) {
            throw new InvalidArgumentException('The folder does not have a stable file ID.');
        }

        $path = $this->getDisplayPath($userFolder, $folder);

        return [
            'name' => $path === '/' ? 'Files' : $folder->getName(),
            'path' => $path,
            'viewUserId' => $userId,
            'fileId' => (int) $fileId,
            'storageId' => $this->getStorageId($folder),
        ];
    }

    /**
     * @return list<array{name: string, path: string}>
     */
    private function buildBreadcrumbs(string $path): array
    {
        $breadcrumbs = [[
            'name' => 'Files',
            'path' => '/',
        ]];
        $currentPath = '';

        foreach (array_filter(explode('/', $path), 'strlen') as $segment) {
            $currentPath .= '/' . $segment;
            $breadcrumbs[] = [
                'name' => $segment,
                'path' => $currentPath,
            ];
        }

        return $breadcrumbs;
    }
}

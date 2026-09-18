<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\AccessGuard;
use OCA\AudioArchive\Service\AudioFolder;
use OCA\AudioArchive\Service\ContentScope;
use OCA\AudioArchive\Service\MetadataReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Liefert den Inhalt EINES Ordners als JSON.
 *
 * Die Ordnerstruktur wird 1:1 so abgebildet, wie sie angelegt ist - beliebig
 * tief verschachtelt, ohne feste Ebenen. Unterordner zuerst, dann Dateien,
 * beides natuerlich sortiert (2 vor 10).
 */
class ListController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private AccessGuard $guard,
        private AudioFolder $audioFolder,
        private MetadataReader $metadata,
        private IAppConfig $appConfig,
        private ContentScope $scope,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string $source 'shared' (gemeinsamer Ordner, Vorgabe) oder
     *                       'home' (eigene Dateien, nur angemeldet)
     * @param string $s      Token einer Freigabe (oeffentliche Seite einer
     *                       Nutzer-Freigabe); hat Vorrang vor $source
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function index(string $path = '', string $source = AudioFolder::SOURCE_SHARED, string $s = ''): DataResponse {
        $scope = $this->scope->resolve($source, $s);
        if (is_int($scope)) {
            return new DataResponse(
                ['error' => $scope === Http::STATUS_UNAUTHORIZED ? 'not_authenticated' : 'Ordner nicht gefunden.'],
                $scope
            );
        }
        $root = $scope['root'];
        $source = $s !== '' ? AudioFolder::SOURCE_SHARED
            : ($source === AudioFolder::SOURCE_HOME ? AudioFolder::SOURCE_HOME : AudioFolder::SOURCE_SHARED);

        $node = $this->audioFolder->resolveIn($root, $path);
        if (!$node instanceof Folder) {
            return new DataResponse(['error' => 'Ordner nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }

        $relative = $this->audioFolder->relativePath($root, $node);

        $dirs = [];
        $files = [];

        foreach ($node->getDirectoryListing() as $child) {
            $name = $child->getName();
            if (str_starts_with($name, '.')) {
                continue;
            }

            $childRelative = ltrim($relative . '/' . $name, '/');

            if ($child instanceof Folder) {
                $dirs[] = [
                    'type' => 'dir',
                    'name' => $name,
                    'path' => $childRelative,
                    // null = nicht gezaehlt (eigene Dateien, siehe ContentScope)
                    'count' => $scope['countFolders'] ? $this->audioFolder->countRecursive($child) : null,
                ];
                continue;
            }

            if (!$this->audioFolder->isAllowedFile($child)) {
                continue;
            }

            /** @var File $child */
            $meta = $this->metadata->read($child);

            $files[] = [
                'type' => 'file',
                'name' => pathinfo($name, PATHINFO_FILENAME),
                'file' => $name,
                'path' => $childRelative,
                'size' => $child->getSize(),
                'duration' => $meta['duration'],
                'artist' => $meta['artist'],
                'album' => $meta['album'],
                'title' => $meta['title'],
            ];
        }

        usort($dirs, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        usort($files, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        $parent = null;
        if ($relative !== '') {
            $pos = strrpos($relative, '/');
            $parent = ($pos === false) ? '' : substr($relative, 0, $pos);
        }

        return new DataResponse([
            'source' => $source,
            'path' => $relative,
            'parent' => $parent,
            'entries' => array_merge($dirs, $files),
            'features' => [
                'offline' => $scope['offline'],
                'download' => $scope['download'],
            ],
        ]);
    }

    /**
     * Nur die Unterordner eines Ordners - fuer den Ordnerbaum in der
     * Seitenleiste. hasChildren steuert, ob der Eintrag aufklappbar ist.
     * Nur fuer angemeldete Nutzer; die oeffentliche Seite hat keinen Baum.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function tree(string $path = '', string $source = AudioFolder::SOURCE_SHARED): DataResponse {
        $source = $source === AudioFolder::SOURCE_HOME ? AudioFolder::SOURCE_HOME : AudioFolder::SOURCE_SHARED;

        if (!$this->guard->isLoggedInUser()) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $root = $this->audioFolder->rootFor($source);
        $node = $root === null ? null : $this->audioFolder->resolveIn($root, $path);
        if (!$node instanceof Folder) {
            return new DataResponse(['error' => 'Ordner nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }

        $relative = $this->audioFolder->relativePath($root, $node);
        $dirs = [];

        foreach ($node->getDirectoryListing() as $child) {
            if (!$child instanceof Folder || str_starts_with($child->getName(), '.')) {
                continue;
            }

            $hasChildren = false;
            try {
                foreach ($child->getDirectoryListing() as $grandChild) {
                    if ($grandChild instanceof Folder && !str_starts_with($grandChild->getName(), '.')) {
                        $hasChildren = true;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // Nicht lesbar (z.B. externer Speicher offline) - dann eben nicht aufklappbar
            }

            $dirs[] = [
                'name' => $child->getName(),
                'path' => ltrim($relative . '/' . $child->getName(), '/'),
                'hasChildren' => $hasChildren,
            ];
        }

        usort($dirs, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return new DataResponse(['source' => $source, 'path' => $relative, 'dirs' => $dirs]);
    }
}

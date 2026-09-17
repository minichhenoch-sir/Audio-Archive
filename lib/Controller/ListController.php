<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\AccessGuard;
use OCA\AudioArchive\Service\AudioFolder;
use OCA\AudioArchive\Service\MetadataReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
    ) {
        parent::__construct($appName, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function index(string $path = ''): DataResponse {
        if (!$this->guard->hasAccess()) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $root = $this->audioFolder->getRoot();
        if ($root === null) {
            return new DataResponse(
                ['error' => 'Es ist noch kein Quellordner eingerichtet.'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        $node = $this->audioFolder->resolve($path);
        if (!$node instanceof Folder) {
            return new DataResponse(['error' => 'Ordner nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }

        $relative = $this->relativePath($root, $node);

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
                    'count' => $this->audioFolder->countRecursive($child),
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
            'path' => $relative,
            'parent' => $parent,
            'entries' => array_merge($dirs, $files),
            'features' => [
                'offline' => $this->appConfig->getValueBool(
                    Application::APP_ID, Application::SETTING_FEATURE_OFFLINE, true
                ),
                'download' => $this->appConfig->getValueBool(
                    Application::APP_ID, Application::SETTING_FEATURE_DOWNLOAD, false
                ),
            ],
        ]);
    }

    /** Pfad eines Knotens relativ zur Wurzel des Quellordners. */
    private function relativePath(Folder $root, Folder $node): string {
        $rootPath = rtrim($root->getPath(), '/');
        return trim(substr($node->getPath(), strlen($rootPath)), '/');
    }
}

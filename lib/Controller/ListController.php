<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\AccessGuard;
use OCA\AudioArchive\Service\AudioFolder;
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
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string $source 'shared' (gemeinsamer Ordner, Vorgabe) oder
     *                       'home' (eigene Dateien, nur angemeldet)
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function index(string $path = '', string $source = AudioFolder::SOURCE_SHARED): DataResponse {
        $source = $source === AudioFolder::SOURCE_HOME ? AudioFolder::SOURCE_HOME : AudioFolder::SOURCE_SHARED;

        if (!$this->guard->canUseSource($source)) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $root = $this->audioFolder->rootFor($source);
        if ($root === null) {
            return new DataResponse(
                ['error' => $source === AudioFolder::SOURCE_HOME
                    ? 'Die eigenen Dateien sind nicht erreichbar.'
                    : 'Es ist noch kein Quellordner eingerichtet.'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        $node = $this->audioFolder->resolveIn($root, $path);
        if (!$node instanceof Folder) {
            return new DataResponse(['error' => 'Ordner nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }

        $relative = $this->audioFolder->relativePath($root, $node);
        $isHome = $source === AudioFolder::SOURCE_HOME;

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
                    /*
                     * In den eigenen Dateien wird bewusst nicht gezaehlt:
                     * Das hiesse, den kompletten Dateibestand des Nutzers
                     * rekursiv zu durchlaufen - bei jedem Oeffnen eines
                     * Ordners. Im gemeinsamen Ordner mit seinen Aufnahmen
                     * ist das ueberschaubar.
                     */
                    'count' => $isHome ? null : $this->audioFolder->countRecursive($child),
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
                'offline' => $this->appConfig->getValueBool(
                    Application::APP_ID, Application::SETTING_FEATURE_OFFLINE, true
                ),
                // Die eigenen Dateien darf man immer herunterladen - sie
                // gehoeren einem ohnehin.
                'download' => $isHome || $this->appConfig->getValueBool(
                    Application::APP_ID, Application::SETTING_FEATURE_DOWNLOAD, false
                ),
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

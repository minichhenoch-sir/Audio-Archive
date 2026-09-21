<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\AccessGuard;
use OCA\AudioArchive\Service\AudioFolder;
use OCA\AudioArchive\Service\ContentScope;
use OCA\AudioArchive\Service\CoverFinder;
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
        private CoverFinder $covers,
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
        $source = $s !== '' ? AudioFolder::SOURCE_SHARED : ContentScope::canonicalSource($source);

        $node = $this->audioFolder->resolveIn($root, $path);
        if (!$node instanceof Folder) {
            return new DataResponse(['error' => 'Ordner nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }

        $relative = $this->audioFolder->relativePath($root, $node);

        $dirs = [];
        $files = [];
        // Ordnerbild (beliebiger Name, nur dieser Ordner) - erst suchen, wenn eine Aufnahme
        // kein eingebettetes Cover hat, und dann nur einmal je Ordner
        $folderCover = false;

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

            // Cover: 'v' ist die Versionskennung fuer die Adresse, damit ein
            // geaendertes Bild nicht aus dem Browser-Speicher kommt
            $coverVersion = null;
            if ($meta['cover'] ?? false) {
                $coverVersion = 'e' . $child->getMTime();
            } else {
                if ($folderCover === false) {
                    $folderCover = $this->covers->imageFor($node, $root);
                }
                if ($folderCover !== null) {
                    $coverVersion = 'f' . $folderCover->getId() . '-' . $folderCover->getMTime();
                }
            }

            $files[] = [
                'type' => 'file',
                'cover' => $coverVersion,
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
        if (!$this->guard->isLoggedInUser()) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Gleiche Pruefung wie bei der Ordnerliste - gilt auch fuer
        // Freigaben, die mit dem Nutzer geteilt sind ('in:<id>')
        $scope = $this->scope->resolve($source, '');
        if (is_int($scope)) {
            return new DataResponse(['error' => 'Ordner nicht gefunden.'], $scope);
        }
        $source = ContentScope::canonicalSource($source);
        $root = $scope['root'];
        $node = $this->audioFolder->resolveIn($root, $path);
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

    /**
     * Ausfuehrliche Angaben zu EINER Aufnahme fuer die Info-Ansicht im
     * Vollbild-Player (ab 0.15). Gleiche Zugangspruefung wie die Liste.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function info(string $path = '', string $source = AudioFolder::SOURCE_SHARED, string $s = ''): DataResponse {
        $scope = $this->scope->resolve($source, $s);
        if (is_int($scope)) {
            return new DataResponse(['error' => 'Aufnahme nicht gefunden.'], $scope);
        }
        $node = $this->audioFolder->resolveIn($scope['root'], $path);
        if (!$node instanceof File || !$this->audioFolder->isAllowedFile($node)) {
            return new DataResponse(['error' => 'Aufnahme nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }

        $relative = $this->audioFolder->relativePath($scope['root'], $node);
        $folder = str_contains($relative, '/') ? substr($relative, 0, (int)strrpos($relative, '/')) : '';

        return new DataResponse([
            'tags' => $this->metadata->readDetails($node),
            'file' => [
                'name' => $node->getName(),
                'folder' => $folder,
                'size' => $node->getSize(),
                'mtime' => $node->getMTime(),
            ],
        ]);
    }

    /** Hoechstens so viele Ordner werden bei der Suche angesehen. */
    private const NEXT_FOLDER_LIMIT = 3000;

    /**
     * Der naechste Ordner mit Aufnahmen in Baum-Reihenfolge (ab 0.15) - fuer
     * "danach mit dem naechsten Ordner weiter".
     *
     * Reihenfolge wie ein Inhaltsverzeichnis: zuerst die Unterordner des
     * gerade gehoerten Ordners, dann der naechste Ordner daneben, und am
     * Ende einer Ebene geht es eine Ebene hoeher weiter. Ordner ohne
     * Aufnahmen werden uebersprungen, ihre Unterordner aber durchsucht. Nie
     * ausserhalb der Wurzel der Quelle.
     *
     * Antwort: {path} oder {path: null}, wenn danach nichts mehr kommt.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function next(string $path = '', string $source = AudioFolder::SOURCE_SHARED, string $s = ''): DataResponse {
        $scope = $this->scope->resolve($source, $s);
        if (is_int($scope)) {
            return new DataResponse(['error' => 'Ordner nicht gefunden.'], $scope);
        }
        $root = $scope['root'];
        $node = $this->audioFolder->resolveIn($root, $path);
        if (!$node instanceof Folder) {
            return new DataResponse(['error' => 'Ordner nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }

        $budget = self::NEXT_FOLDER_LIMIT;

        // 1. Unterordner des aktuellen Ordners
        $found = $this->firstWithAudio($this->subfolders($node), $budget);

        // 2. Danach Geschwister, von innen nach aussen
        $current = $node;
        $rootPath = rtrim($root->getPath(), '/');
        while ($found === null && $budget > 0 && rtrim($current->getPath(), '/') !== $rootPath) {
            try {
                $parent = $current->getParent();
            } catch (\Throwable $e) {
                break;
            }
            $siblings = $this->subfolders($parent);
            $after = [];
            $seen = false;
            foreach ($siblings as $sibling) {
                if ($seen) {
                    $after[] = $sibling;
                } elseif ($sibling->getId() === $current->getId()) {
                    $seen = true;
                }
            }
            $found = $this->firstWithAudio($after, $budget);
            $current = $parent;
        }

        return new DataResponse([
            'path' => $found !== null ? $this->audioFolder->relativePath($root, $found) : null,
        ]);
    }

    /** @return list<Folder> sichtbare Unterordner, natuerlich sortiert */
    private function subfolders(Folder $folder): array {
        $dirs = [];
        try {
            foreach ($folder->getDirectoryListing() as $child) {
                if ($child instanceof Folder && !str_starts_with($child->getName(), '.')) {
                    $dirs[] = $child;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        usort($dirs, static fn ($a, $b) => strnatcasecmp($a->getName(), $b->getName()));
        return $dirs;
    }

    /**
     * Erster Ordner mit Aufnahmen in Vorwaerts-Tiefensuche ueber die
     * uebergebenen Ordner (jeweils erst der Ordner selbst, dann seine
     * Unterordner).
     *
     * @param list<Folder> $folders
     */
    private function firstWithAudio(array $folders, int &$budget): ?Folder {
        foreach ($folders as $folder) {
            if (--$budget < 0) {
                return null;
            }
            $hasAudio = false;
            try {
                foreach ($folder->getDirectoryListing() as $child) {
                    if (!str_starts_with($child->getName(), '.') && $this->audioFolder->isAllowedFile($child)) {
                        $hasAudio = true;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
            if ($hasAudio) {
                return $folder;
            }
            $deeper = $this->firstWithAudio($this->subfolders($folder), $budget);
            if ($deeper !== null) {
                return $deeper;
            }
        }
        return null;
    }
}

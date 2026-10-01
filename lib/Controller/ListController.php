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
use OCP\IConfig;
use OCP\IRequest;
use OCP\ITagManager;
use OCP\IUserSession;

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
        private ITagManager $tagManager,
        private IUserSession $userSession,
        private IConfig $config,
    ) {
        parent::__construct($appName, $request);
    }

    // ------------------------------------------------------------------
    // Favoriten (ab 0.24.0, Vikunja #3)
    //
    // Angemeldete Nutzer: echte Nextcloud-Favoriten - derselbe Stern wie in
    // "Dateien" (dort sichtbar, soweit die Datei in den eigenen Dateien des
    // Nutzers liegt). Ohne Konto (Links) merkt sich das Geraet die
    // Favoriten selbst, siehe app.js. Abschaltbar in der Verwaltung und je
    // Nutzer.
    // ------------------------------------------------------------------

    /** Sind Favoriten fuer diesen Aufruf eingeschaltet? */
    public function favoritesEnabled(): bool {
        if (!$this->appConfig->getValueBool(Application::APP_ID, Application::SETTING_FEATURE_FAVORITES, true)) {
            return false;
        }
        $user = $this->userSession->getUser();
        return $user === null
            || $this->config->getUserValue($user->getUID(), Application::APP_ID, Application::USER_FAVORITES, '1') !== '0';
    }

    /** @return array<int, true>|null Kennungen der Nextcloud-Favoriten, null = nicht angemeldet */
    private function favoriteIds(string $s): ?array {
        if ($s !== '' || $this->userSession->getUser() === null || !$this->favoritesEnabled()) {
            return null;
        }
        try {
            $tags = $this->tagManager->load('files');
            $ids = $tags !== null ? $tags->getFavorites() : [];
        } catch (\Throwable $e) {
            return [];
        }
        return is_array($ids) ? array_fill_keys(array_map('intval', $ids), true) : [];
    }

    /**
     * Favoriten der Quelle (angemeldet): Ordner und Aufnahmen, die als
     * Favorit markiert sind und in dieser Quelle liegen.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function favorites(string $source = AudioFolder::SOURCE_SHARED): DataResponse {
        $scope = $this->scope->resolve($source, '');
        if (is_int($scope)) {
            return new DataResponse(['error' => 'Nicht gefunden.'], $scope);
        }
        $ids = $this->favoriteIds('');
        if ($ids === null) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $root = $scope['root'];
        $dirs = [];
        $files = [];
        $covers = [];
        foreach (array_keys($ids) as $id) {
            if (count($dirs) + count($files) >= 500) {
                break;
            }
            try {
                $node = method_exists($root, 'getFirstNodeById') ? $root->getFirstNodeById($id) : ($root->getById($id)[0] ?? null);
            } catch (\Throwable $e) {
                $node = null;
            }
            if ($node === null || $node->getPath() === $root->getPath()) {
                continue;
            }
            $relative = $this->audioFolder->relativePath($root, $node);
            if ($relative === '' || str_starts_with($node->getName(), '.')) {
                continue;
            }
            if ($node instanceof Folder) {
                // Nur Ordner mit Aufnahmen - sonst stuenden hier alle
                // Nextcloud-Favoriten wie "Dokumente"
                $budget = 300;
                if ($this->firstWithAudio([$node], $budget) === null) {
                    continue;
                }
                $dirs[] = ['type' => 'dir', 'name' => $node->getName(), 'path' => $relative,
                    'count' => null, 'added' => self::folderAdded($node), 'fav' => true, 'id' => $node->getId()];
            } elseif ($node instanceof File && $this->audioFolder->isAllowedFile($node)) {
                $parent = $node->getParent();
                $pid = $parent->getId();
                $covers[$pid] ??= false;
                $entry = $this->fileEntry($node, $parent, $root, $relative, $covers[$pid]);
                $entry['fav'] = true;
                // Kennung: dieselbe Datei kann ueber zwei Quellen erreichbar sein
                $entry['id'] = $node->getId();
                $files[] = $entry;
            }
        }
        usort($dirs, static fn ($a, $b) => strnatcasecmp($a['path'], $b['path']));
        usort($files, static fn ($a, $b) => strnatcasecmp($a['path'], $b['path']));
        return new DataResponse(['results' => array_merge($dirs, $files)]);
    }

    /** Stern setzen oder entfernen (angemeldet). */
    #[NoAdminRequired]
    public function setFavorite(string $path = '', string $source = AudioFolder::SOURCE_SHARED, bool $on = true): DataResponse {
        if ($this->userSession->getUser() === null) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->favoritesEnabled()) {
            return new DataResponse(['error' => 'Favoriten sind abgeschaltet.'], Http::STATUS_FORBIDDEN);
        }
        $scope = $this->scope->resolve($source, '');
        if (is_int($scope)) {
            return new DataResponse(['error' => 'Nicht gefunden.'], $scope);
        }
        $node = $this->audioFolder->resolveIn($scope['root'], $path);
        if ($node === null || trim($path, '/') === ''
            || !($node instanceof Folder || $this->audioFolder->isAllowedFile($node))) {
            return new DataResponse(['error' => 'Nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }
        try {
            $tags = $this->tagManager->load('files');
            if ($tags === null) {
                throw new \RuntimeException('Favoriten sind auf diesem Server nicht verfügbar.');
            }
            $ok = $on ? $tags->addToFavorites($node->getId()) : $tags->removeFromFavorites($node->getId());
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Favorit konnte nicht gespeichert werden.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new DataResponse(['fav' => $on, 'ok' => (bool)$ok]);
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
        // Nextcloud-Favoriten des Nutzers (ab 0.24.0), null = keine
        $favIds = $this->favoriteIds($s);

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
                    // Datum fuer Anzeige und Sortierung (ab 0.22.0, Vikunja #30)
                    'added' => self::folderAdded($child),
                    'fav' => $favIds !== null && isset($favIds[$child->getId()]),
                ];
                continue;
            }

            if (!$this->audioFolder->isAllowedFile($child)) {
                continue;
            }

            /** @var File $child */
            $entry = $this->fileEntry($child, $node, $root, $childRelative, $folderCover);
            $entry['fav'] = $favIds !== null && isset($favIds[$child->getId()]);
            $files[] = $entry;
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
                'folderDownload' => $scope['folderDownload'] ?? false,
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

    /**
     * Eintrag einer Aufnahme fuer Liste und Suche.
     *
     * @param File|false|null $folderCover Ordnerbild des Ordners, einmal je
     *                                     Ordner gesucht (false = noch nicht)
     */
    private function fileEntry(File $file, Folder $parent, Folder $root, string $relative, mixed &$folderCover): array {
        $meta = $this->metadata->read($file);

        // Cover: 'v' ist die Versionskennung fuer die Adresse, damit ein
        // geaendertes Bild nicht aus dem Browser-Speicher kommt
        $coverVersion = null;
        if ($meta['cover'] ?? false) {
            $coverVersion = 'e' . $file->getMTime();
        } else {
            if ($folderCover === false) {
                $folderCover = $this->covers->imageFor($parent, $root);
            }
            if ($folderCover !== null) {
                $coverVersion = 'f' . $folderCover->getId() . '-' . $folderCover->getMTime();
            }
        }

        $name = $file->getName();
        return [
            'type' => 'file',
            'cover' => $coverVersion,
            'name' => pathinfo($name, PATHINFO_FILENAME),
            'file' => $name,
            'path' => $relative,
            'size' => $file->getSize(),
            'duration' => $meta['duration'],
            'artist' => $meta['artist'],
            'album' => $meta['album'],
            'title' => $meta['title'],
            // Zuletzt geaendert - Anzeige und Sortierung (ab 0.22.0)
            'mtime' => (int)$file->getMTime(),
        ];
    }

    /**
     * "Hinzugefuegt" eines Ordners (ab 0.22.0, Vikunja #30): Erstellzeit,
     * sonst Zeitpunkt des Hochladens, sonst Aenderungszeit. Die ersten
     * beiden kennt Nextcloud nur, wenn der Ordner ueber Nextcloud angelegt
     * wurde; die Aenderungszeit eines Ordners steigt, sobald darin etwas
     * hinzukommt.
     */
    private static function folderAdded(Folder $folder): int {
        try {
            $created = (int)$folder->getCreationTime();
            if ($created > 0) {
                return $created;
            }
            $uploaded = (int)$folder->getUploadTime();
            if ($uploaded > 0) {
                return $uploaded;
            }
        } catch (\Throwable $e) {
            // aeltere Speicher ohne diese Angaben
        }
        return (int)$folder->getMTime();
    }

    // ------------------------------------------------------------------
    // Suche (ab 0.22.0, Vikunja #32)
    // ------------------------------------------------------------------

    private const SEARCH_MAX_FOLDERS = 4000;
    private const SEARCH_MAX_FILES = 40000;
    private const SEARCH_MAX_RESULTS = 150;
    /** Sekunden, in denen noch nicht zwischengespeicherte Angaben gelesen werden. */
    private const SEARCH_READ_BUDGET = 4.0;

    /**
     * Sucht in der ganzen Quelle (gemeinsamer Ordner, eigene Dateien bzw.
     * Freigabe): Ordner nach Namen, Aufnahmen nach Datei- und Ordnername
     * sowie Titel, Kuenstler und Album. Mehrere Woerter muessen alle
     * vorkommen ("predigt 2024"), Gross-/Kleinschreibung egal.
     *
     * Die Angaben aus den Dateien kommen aus dem Zwischenspeicher; noch
     * nicht gelesene Dateien werden nur innerhalb eines Zeitbudgets gelesen.
     * Reicht es nicht, meldet die Antwort complete=false.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function search(string $q = '', string $source = AudioFolder::SOURCE_SHARED, string $s = ''): DataResponse {
        $scope = $this->scope->resolve($source, $s);
        if (is_int($scope)) {
            return new DataResponse(['error' => 'Nicht gefunden.'], $scope);
        }
        $terms = self::searchTerms($q);
        if ($terms === []) {
            return new DataResponse(['results' => [], 'complete' => true]);
        }
        $root = $scope['root'];
        $deadline = microtime(true) + self::SEARCH_READ_BUDGET;

        $dirs = [];
        $files = [];
        $complete = true;
        // Angaben noch nicht gelesener Dateien fehlen nur wegen des Zeitbudgets;
        // die naechste Anfrage liest weiter (die Oberflaeche fragt dann selbst nach)
        $pending = false;
        $folders = 0;
        $seenFiles = 0;
        $queue = [[$root, '']];

        while ($queue !== []) {
            [$folder, $relative] = array_shift($queue);
            if (++$folders > self::SEARCH_MAX_FOLDERS) {
                $complete = false;
                break;
            }
            try {
                $listing = $folder->getDirectoryListing();
            } catch (\Throwable $e) {
                continue;
            }
            $folderCover = false;
            $subfolders = [];
            // Bekannte Angaben aller Aufnahmen dieses Ordners mit einer Abfrage (ab 0.25.1)
            $known = $this->metadata->peekMany(array_values(array_filter(
                $listing,
                fn ($c) => !($c instanceof Folder) && $this->audioFolder->isAllowedFile($c)
            )));
            foreach ($listing as $child) {
                $name = $child->getName();
                if (str_starts_with($name, '.')) {
                    continue;
                }
                $childRelative = ltrim($relative . '/' . $name, '/');
                if ($child instanceof Folder) {
                    $subfolders[] = [$child, $childRelative];
                    if (count($dirs) < self::SEARCH_MAX_RESULTS && self::matches($terms, $childRelative)) {
                        $dirs[] = [
                            'type' => 'dir',
                            'name' => $name,
                            'path' => $childRelative,
                            'count' => null,
                            'added' => self::folderAdded($child),
                        ];
                    }
                    continue;
                }
                if (!$this->audioFolder->isAllowedFile($child) || count($files) >= self::SEARCH_MAX_RESULTS) {
                    continue;
                }
                if (++$seenFiles > self::SEARCH_MAX_FILES) {
                    $complete = false;
                    break 2;
                }
                /** @var File $child */
                $haystack = $childRelative;
                if (!self::matches($terms, $haystack)) {
                    // Angaben aus der Datei: zwischengespeichert, sonst nur mit Zeit
                    $meta = $known[(int)$child->getId()] ?? null;
                    if ($meta === null) {
                        if (microtime(true) > $deadline) {
                            $complete = false;
                            $pending = true;
                            continue;
                        }
                        $meta = $this->metadata->read($child);
                    }
                    $haystack .= ' ' . ($meta['title'] ?? '') . ' ' . ($meta['artist'] ?? '') . ' ' . ($meta['album'] ?? '');
                    if (!self::matches($terms, $haystack)) {
                        continue;
                    }
                }
                $files[] = $this->fileEntry($child, $folder, $root, $childRelative, $folderCover);
            }
            usort($subfolders, static fn ($a, $b) => strnatcasecmp($a[1], $b[1]));
            foreach ($subfolders as $sub) {
                $queue[] = $sub;
            }
        }

        usort($dirs, static fn ($a, $b) => strnatcasecmp($a['path'], $b['path']));
        usort($files, static fn ($a, $b) => strnatcasecmp($a['path'], $b['path']));

        return new DataResponse([
            'results' => array_merge($dirs, $files),
            'complete' => $complete && count($files) < self::SEARCH_MAX_RESULTS && count($dirs) < self::SEARCH_MAX_RESULTS,
            // ab 0.25.1: true = erneut fragen lohnt sich (Angaben werden noch gelesen)
            'pending' => $pending && count($files) < self::SEARCH_MAX_RESULTS,
        ]);
    }

    /** @return list<string> */
    private static function searchTerms(string $q): array {
        $q = self::fold(trim(substr($q, 0, 200)));
        $terms = array_values(array_filter(preg_split('/\s+/u', $q) ?: [], static fn ($t) => $t !== ''));
        return strlen(implode('', $terms)) < 2 ? [] : $terms;
    }

    private static function matches(array $terms, string $haystack): bool {
        $h = self::fold($haystack);
        foreach ($terms as $t) {
            if (!str_contains($h, $t)) {
                return false;
            }
        }
        return true;
    }

    /** Kleinschreibung ohne Akzente ("Über" -> "uber", "é" -> "e"), ohne mbstring-Pflicht. */
    private static function fold(string $s): string {
        $map = [
            'Ä' => 'a', 'ä' => 'a', 'Ö' => 'o', 'ö' => 'o', 'Ü' => 'u', 'ü' => 'u', 'ß' => 'ss',
            'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'à' => 'a', 'á' => 'a', 'â' => 'a',
            'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Ì' => 'i', 'Í' => 'i', 'Î' => 'i', 'Ï' => 'i', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o',
            'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'ù' => 'u', 'ú' => 'u', 'û' => 'u',
            'Ç' => 'c', 'ç' => 'c', 'Ñ' => 'n', 'ñ' => 'n', '´' => "'", '`' => "'", '’' => "'",
        ];
        $s = strtr($s, $map);
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
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

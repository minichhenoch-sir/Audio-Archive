<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IUserSession;

/**
 * Zugriff auf den vom Administrator gewaehlten Quellordner.
 *
 * Die Dateien werden ueber Nextclouds Datei-API gelesen, nicht ueber rohe
 * Serverpfade. Das ist fuer den App Store Voraussetzung und funktioniert
 * nebenbei auch mit eingebundenem externem Speicher.
 */
class AudioFolder {

    /**
     * Endungen, die als Aufnahme gelten, mit ihrem Medientyp (ab 0.21.0).
     *
     * Aufgenommen ist, was mindestens ein verbreiteter Browser selbst
     * abspielt. Umgewandelt wird nichts: Kann ein Browser ein Format nicht
     * (z. B. AIFF ausserhalb von Safari), sagt die Oberflaeche das.
     */
    public const AUDIO_TYPES = [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'm4b' => 'audio/mp4',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'webm' => 'audio/webm',
        'weba' => 'audio/webm',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
        'aif' => 'audio/aiff',
        'aiff' => 'audio/aiff',
        'aifc' => 'audio/aiff',
        'caf' => 'audio/x-caf',
    ];

    /** Quellen: der gemeinsame Ordner des Administrators, die eigenen Dateien. */
    public const SOURCE_SHARED = 'shared';
    public const SOURCE_HOME = 'home';
    /** Weitere Quellen des Administrators: 'src:<id>' (ab 0.32.0, Vikunja #8) */
    public const SOURCE_EXTRA_PREFIX = 'src:';
    public const MAX_EXTRA_SOURCES = 50;

    public function __construct(
        private IAppConfig $appConfig,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
    ) {
    }

    /**
     * Wurzel einer Quelle.
     *
     * 'home' sind die eigenen Nextcloud-Dateien des angemeldeten Nutzers -
     * einschliesslich der Ordner, die andere mit ihm geteilt haben. Ohne
     * Anmeldung gibt es diese Quelle nicht. Alles andere ist der gemeinsame
     * Quellordner des Administrators.
     */
    public function rootFor(string $source): ?Folder {
        $extraId = self::extraId($source);
        if ($extraId !== null) {
            $extra = $this->extraSource($extraId);
            return $extra !== null ? $this->folderOf($extra['owner'], $extra['path']) : null;
        }
        if ($source === self::SOURCE_HOME) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return null;
            }
            try {
                return $this->rootFolder->getUserFolder($user->getUID());
            } catch (\Throwable $e) {
                return null;
            }
        }
        return $this->getRoot();
    }

    // ------------------------------------------------------------------
    // Weitere Quellen (ab 0.32.0, Vikunja #8)
    // ------------------------------------------------------------------

    /** Kennung einer weiteren Quelle: 'src:<id>' -> id, sonst null. */
    public static function extraId(string $source): ?int {
        return preg_match('/^src:(\d{1,9})$/', $source, $m) ? (int)$m[1] : null;
    }

    /** @return list<array{id: int, name: string, owner: string, path: string}> */
    public function extraSources(): array {
        $raw = json_decode($this->appConfig->getValueString(
            Application::APP_ID, Application::SETTING_EXTRA_SOURCES, '[]'
        ), true);
        return self::normalizeExtraSources(is_array($raw) ? $raw : []);
    }

    /** @return array{id: int, name: string, owner: string, path: string}|null */
    public function extraSource(int $id): ?array {
        foreach ($this->extraSources() as $extra) {
            if ($extra['id'] === $id) {
                return $extra;
            }
        }
        return null;
    }

    /**
     * Nur gueltige Eintraege: positive, eindeutige Kennung, Besitzer und
     * Pfad gesetzt. Namen werden gekuerzt.
     *
     * @return list<array{id: int, name: string, owner: string, path: string}>
     */
    public static function normalizeExtraSources(array $list): array {
        $out = [];
        $seen = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int)($item['id'] ?? 0);
            $owner = trim((string)($item['owner'] ?? ''));
            $path = '/' . trim(str_replace('\\', '/', (string)($item['path'] ?? '')), '/');
            if ($id <= 0 || $id > 999999999 || isset($seen[$id]) || $owner === '') {
                continue;
            }
            $seen[$id] = true;
            $name = mb_substr(trim((string)($item['name'] ?? '')), 0, 60);
            $out[] = ['id' => $id, 'name' => $name !== '' ? $name : 'Quelle ' . $id, 'owner' => $owner, 'path' => $path];
            if (count($out) >= self::MAX_EXTRA_SOURCES) {
                break;
            }
        }
        return $out;
    }

    /** Ordner eines Nutzers zu einem Pfad, oder null. */
    public function folderOf(string $owner, string $path): ?Folder {
        if ($owner === '' || $path === '') {
            return null;
        }
        try {
            $userFolder = $this->rootFolder->getUserFolder($owner);
            $node = ($path === '/') ? $userFolder : $userFolder->get($path);
        } catch (\Throwable $e) {
            return null;
        }
        return $node instanceof Folder ? $node : null;
    }

    /** Besitzer einer Quelle ('home' = angemeldeter Nutzer). */
    public function ownerOf(string $source): string {
        $extraId = self::extraId($source);
        if ($extraId !== null) {
            return $this->extraSource($extraId)['owner'] ?? '';
        }
        if ($source === self::SOURCE_HOME) {
            return $this->userSession->getUser()?->getUID() ?? '';
        }
        return $this->sharedOwner();
    }

    /**
     * Einheitliche Schreibweise der eigenen Quellen: 'home', 'src:<id>'
     * (nur wenn es die Quelle gibt) oder 'shared'.
     */
    public function normalizeOwnSource(string $source): string {
        if ($source === self::SOURCE_HOME) {
            return self::SOURCE_HOME;
        }
        $extraId = self::extraId($source);
        if ($extraId !== null && $this->extraSource($extraId) !== null) {
            return self::SOURCE_EXTRA_PREFIX . $extraId;
        }
        return self::SOURCE_SHARED;
    }

    /** Besitzer des gemeinsamen Ordners (aus den Einstellungen). */
    public function sharedOwner(): string {
        return $this->appConfig->getValueString(
            Application::APP_ID, Application::SETTING_FOLDER_OWNER, ''
        );
    }

    /** Ist 'shared' ueberhaupt eingerichtet? */
    public function hasSharedRoot(): bool {
        return $this->getRoot() !== null;
    }

    /**
     * Wurzel des Quellordners.
     *
     * Der Besitzer stammt aus den Einstellungen, nicht aus der laufenden
     * Sitzung: Beim oeffentlichen Zugang gibt es keinen angemeldeten Nutzer.
     */
    public function getRoot(): ?Folder {
        $owner = $this->appConfig->getValueString(
            Application::APP_ID, Application::SETTING_FOLDER_OWNER, ''
        );
        $path = $this->appConfig->getValueString(
            Application::APP_ID, Application::SETTING_FOLDER, ''
        );

        if ($owner === '' || $path === '') {
            return null;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($owner);
            $node = ($path === '/') ? $userFolder : $userFolder->get($path);
        } catch (NotFoundException | \Throwable $e) {
            return null;
        }

        return $node instanceof Folder ? $node : null;
    }

    /**
     * Loest einen relativen Pfad innerhalb des Quellordners auf.
     *
     * Schutz gegen Ausbrechen aus dem Ordner: Jeder Pfadabschnitt wird
     * geprueft, und zusaetzlich muss der gefundene Knoten tatsaechlich
     * unterhalb der Wurzel liegen. Entspricht dem realpath-Vergleich der
     * eigenstaendigen Fassung.
     */
    public function resolve(string $relative): ?Node {
        $root = $this->getRoot();
        if ($root === null) {
            return null;
        }
        return $this->resolveIn($root, $relative);
    }

    /** Wie resolve(), aber innerhalb einer beliebigen Wurzel. */
    public function resolveIn(Folder $root, string $relative): ?Node {

        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '') {
            return $root;
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        try {
            $node = $root->get($relative);
        } catch (NotFoundException | \Throwable $e) {
            return null;
        }

        // Zweite, unabhaengige Absicherung ueber den tatsaechlichen Pfad
        $rootPath = rtrim($root->getPath(), '/');
        if (strpos($node->getPath(), $rootPath . '/') !== 0) {
            return null;
        }

        return $node;
    }

    /** Pfad eines Knotens relativ zu einer Wurzel. */
    public function relativePath(Folder $root, Node $node): string {
        $rootPath = rtrim($root->getPath(), '/');
        return trim(substr($node->getPath(), strlen($rootPath)), '/');
    }

    /** Ist die Datei eine Aufnahme im erlaubten Format? */
    public function isAllowedFile(Node $node): bool {
        if (!$node instanceof File) {
            return false;
        }
        $ext = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
        return isset(self::AUDIO_TYPES[$ext]);
    }

    /** Medientyp fuer die Auslieferung (Content-Type) anhand der Endung. */
    public static function mimeFor(string $name): string {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return self::AUDIO_TYPES[$ext] ?? 'application/octet-stream';
    }

    /**
     * Zaehlt rekursiv die Aufnahmen unterhalb eines Ordners, damit in der
     * Ordnerliste "N Aufnahmen" stehen kann. Tiefe begrenzt, damit eine sehr
     * verschachtelte Struktur den Server nicht ausbremst.
     */
    public function countRecursive(Folder $folder, int $depth = 0): int {
        if ($depth > 8) {
            return 0;
        }

        $count = 0;
        foreach ($folder->getDirectoryListing() as $node) {
            if (str_starts_with($node->getName(), '.')) {
                continue;
            }
            if ($node instanceof Folder) {
                $count += $this->countRecursive($node, $depth + 1);
            } elseif ($this->isAllowedFile($node)) {
                $count++;
            }
        }

        return $count;
    }
}

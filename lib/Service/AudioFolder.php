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

    /** Endungen, die als Aufnahme gelten. */
    public const ALLOWED_EXTENSIONS = ['mp3'];

    /** Quellen: der gemeinsame Ordner des Administrators, die eigenen Dateien. */
    public const SOURCE_SHARED = 'shared';
    public const SOURCE_HOME = 'home';

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
        return in_array($ext, self::ALLOWED_EXTENSIONS, true);
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

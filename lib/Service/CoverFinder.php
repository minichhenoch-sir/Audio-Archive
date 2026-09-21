<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Findet das Cover einer Aufnahme (ab 0.14, Suche geaendert in 0.17.2).
 *
 * Reihenfolge:
 *   1. das in der mp3 eingebettete Bild (ID3, siehe MetadataReader)
 *   2. ein Bild im Ordner der Aufnahme - mit BELIEBIGEM Dateinamen
 *   3. sonst nichts: Die App zeigt dann das App-Symbol
 *
 * Vom Nutzer 2026-09-21 festgelegt: jeder Dateiname zaehlt, gesucht wird
 * NUR im Ordner der Aufnahme. (Bis 0.17.1 nur cover/folder/front/album/
 * albumart und zusaetzlich aufwaerts bis zur Wurzel der Quelle.)
 *
 * Liegen mehrere Bilder im Ordner, gewinnt ein ueblicher Cover-Name
 * (cover, folder, front, album, albumart), sonst das alphabetisch erste
 * ("natuerlich" sortiert, also bild2 vor bild10).
 */
class CoverFinder {

    /** Uebliche Namen - haben Vorrang, wenn mehrere Bilder im Ordner liegen. */
    private const NAMES = ['cover', 'folder', 'front', 'album', 'albumart'];
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const MAX_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private MetadataReader $metadata,
        private AudioFolder $audioFolder,
    ) {
    }

    /**
     * Bild in genau diesem Ordner, oder null. Endung zaehlt, nicht der Name;
     * Gross-/Kleinschreibung egal. Versteckte Dateien (Punkt am Anfang)
     * zaehlen nicht - dazu gehoeren auch die "._name.jpg"-Begleitdateien,
     * die macOS auf manchen Laufwerken anlegt und die gar keine Bilder sind.
     */
    public function imageIn(Folder $folder): ?File {
        $preferred = [];
        $others = [];
        try {
            foreach ($folder->getDirectoryListing() as $node) {
                if (!$node instanceof File) {
                    continue;
                }
                $name = $node->getName();
                if (str_starts_with($name, '.')) {
                    continue;
                }
                $lower = strtolower($name);
                $dot = strrpos($lower, '.');
                if ($dot === false) {
                    continue;
                }
                $ext = substr($lower, $dot + 1);
                if (!in_array($ext, self::EXTENSIONS, true)) {
                    continue;
                }
                if ($node->getSize() <= 0 || $node->getSize() > self::MAX_BYTES) {
                    continue;
                }
                $rank = array_search(substr($lower, 0, $dot), self::NAMES, true);
                if ($rank !== false) {
                    $preferred[$rank] ??= $node;
                } else {
                    $others[$name] = $node;
                }
            }
        } catch (\Throwable $e) {
            return null;
        }
        if ($preferred !== []) {
            ksort($preferred);
            return reset($preferred);
        }
        if ($others === []) {
            return null;
        }
        uksort($others, 'strnatcasecmp');
        return reset($others);
    }

    /**
     * Ordnerbild fuer die Aufnahmen in diesem Ordner - bewusst nur in ihm
     * selbst, nicht in uebergeordneten Ordnern. $root bleibt als Parameter,
     * damit die Aufrufer unveraendert bleiben.
     */
    public function imageFor(Folder $folder, Folder $root): ?File {
        return $this->imageIn($folder);
    }

    /**
     * Das Cover einer Aufnahme als Bilddaten.
     *
     * @return array{mime: string, data: string, mtime: int}|null
     */
    public function coverFor(File $file, Folder $root): ?array {
        $embedded = $this->metadata->readEmbeddedCover($file);
        if ($embedded !== null) {
            return $embedded + ['mtime' => (int)$file->getMTime()];
        }

        $parent = $file->getParent();
        $image = $this->imageFor($parent, $root);
        if ($image === null) {
            return null;
        }
        try {
            $data = $image->getContent();
        } catch (\Throwable $e) {
            return null;
        }
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return null;
        }
        return ['mime' => $mime, 'data' => $data, 'mtime' => (int)$image->getMTime()];
    }
}

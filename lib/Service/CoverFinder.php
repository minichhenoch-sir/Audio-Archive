<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Findet das Cover einer Aufnahme (ab 0.14).
 *
 * Reihenfolge:
 *   1. das in der mp3 eingebettete Bild (ID3, siehe MetadataReader)
 *   2. ein Bild im Ordner der Aufnahme, etwa cover.jpg oder folder.jpg
 *   3. dasselbe in den uebergeordneten Ordnern, bis zur Wurzel der Quelle -
 *      so genuegt EIN Bild fuer eine ganze Reihe mit Unterordnern
 *
 * Nie oberhalb der Wurzel: Bei einer Freigabe darf kein Bild aus Ordnern
 * auftauchen, die gar nicht freigegeben sind.
 */
class CoverFinder {

    /** Uebliche Namen, in dieser Rangfolge. */
    private const NAMES = ['cover', 'folder', 'front', 'album', 'albumart'];
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    private const MAX_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private MetadataReader $metadata,
        private AudioFolder $audioFolder,
    ) {
    }

    /**
     * Bild in genau diesem Ordner, oder null. Gross-/Kleinschreibung egal
     * ("Cover.JPG" zaehlt auch).
     */
    public function imageIn(Folder $folder): ?File {
        $candidates = [];
        try {
            foreach ($folder->getDirectoryListing() as $node) {
                if (!$node instanceof File) {
                    continue;
                }
                $name = strtolower($node->getName());
                $dot = strrpos($name, '.');
                if ($dot === false) {
                    continue;
                }
                $base = substr($name, 0, $dot);
                $ext = substr($name, $dot + 1);
                $rank = array_search($base, self::NAMES, true);
                if ($rank === false || !in_array($ext, self::EXTENSIONS, true)) {
                    continue;
                }
                if ($node->getSize() <= 0 || $node->getSize() > self::MAX_BYTES) {
                    continue;
                }
                $candidates[$rank] ??= $node;
            }
        } catch (\Throwable $e) {
            return null;
        }
        if ($candidates === []) {
            return null;
        }
        ksort($candidates);
        return reset($candidates);
    }

    /** Bild in diesem Ordner oder einem uebergeordneten, hoechstens bis $root. */
    public function imageFor(Folder $folder, Folder $root): ?File {
        $rootPath = rtrim($root->getPath(), '/');
        $current = $folder;
        for ($depth = 0; $depth < 32; $depth++) {
            $image = $this->imageIn($current);
            if ($image !== null) {
                return $image;
            }
            if (rtrim($current->getPath(), '/') === $rootPath) {
                return null;
            }
            try {
                $parent = $current->getParent();
            } catch (\Throwable $e) {
                return null;
            }
            // Nicht aus der Wurzel hinaus
            if (!str_starts_with(rtrim($parent->getPath(), '/') . '/', $rootPath . '/')) {
                return null;
            }
            $current = $parent;
        }
        return null;
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

<?php
declare(strict_types=1);

namespace OCA\AudioArchive\BackgroundJob;

use OCA\AudioArchive\Service\AudioFolder;
use OCA\AudioArchive\Service\MetadataReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * Liest im Hintergrund die Angaben (Laenge, Titel, Kuenstler, Album, Cover)
 * aller Aufnahmen der Quellen des Administrators (ab 0.34.0, Vikunja #43).
 *
 * Vorher wurden sie erst gelesen, wenn jemand einen Ordner zum ersten Mal
 * oeffnete oder suchte - das dauerte dann ein paar Sekunden. Jetzt sind sie
 * meist schon da. Gespeichert wird wie gewohnt in der Tabelle
 * audioarchive_meta; schon bekannte Dateien kosten nur eine Abfrage je Ordner.
 *
 * Laeuft mit Nextclouds Hintergrundaufgaben (Cron) alle 15 Minuten, je Lauf
 * hoechstens LIMIT_SECONDS lang. Bei einem grossen Archiv ist es also nach
 * einigen Laeufen vollstaendig.
 */
class ReadMetadata extends TimedJob {

    private const LIMIT_SECONDS = 40.0;
    private const MAX_FOLDERS = 20000;

    public function __construct(
        ITimeFactory $time,
        private AudioFolder $audioFolder,
        private MetadataReader $metadata,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(15 * 60);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $deadline = microtime(true) + self::LIMIT_SECONDS;
        $read = 0;
        foreach ($this->roots() as $root) {
            $read += $this->walk($root, $deadline);
            if (microtime(true) > $deadline) {
                break;
            }
        }
        if ($read > 0) {
            $this->logger->debug('Audio Archive: Angaben von ' . $read . ' Aufnahmen im Hintergrund gelesen', ['app' => 'audioarchive']);
        }
    }

    /** @return list<Folder> gemeinsamer Ordner und weitere Quellen, ohne doppelte */
    private function roots(): array {
        $roots = [];
        $seen = [];
        $add = static function (?Folder $folder) use (&$roots, &$seen): void {
            if ($folder !== null && !isset($seen[$folder->getId()])) {
                $seen[$folder->getId()] = true;
                $roots[] = $folder;
            }
        };
        try {
            $add($this->audioFolder->getRoot());
            foreach ($this->audioFolder->extraSources() as $extra) {
                $add($this->audioFolder->folderOf($extra['owner'], $extra['path']));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Audio Archive: Quellen fuer das Lesen im Hintergrund nicht verfuegbar', ['app' => 'audioarchive', 'exception' => $e]);
        }
        return $roots;
    }

    /** Geht den Ordner durch und liest noch unbekannte Dateien; gibt die Anzahl gelesener zurueck. */
    private function walk(Folder $root, float $deadline): int {
        $read = 0;
        $folders = 0;
        $queue = [$root];
        while ($queue !== [] && ++$folders <= self::MAX_FOLDERS) {
            $folder = array_shift($queue);
            try {
                $listing = $folder->getDirectoryListing();
            } catch (\Throwable $e) {
                continue;
            }
            $files = [];
            foreach ($listing as $child) {
                if (str_starts_with($child->getName(), '.')) {
                    continue;
                }
                if ($child instanceof Folder) {
                    $queue[] = $child;
                } elseif ($child instanceof File && $this->audioFolder->isAllowedFile($child)) {
                    $files[] = $child;
                }
            }
            if ($files === []) {
                continue;
            }
            $known = $this->metadata->peekMany($files);
            foreach ($files as $file) {
                if (isset($known[(int)$file->getId()])) {
                    continue;
                }
                if (microtime(true) > $deadline) {
                    return $read;
                }
                $this->metadata->read($file);
                $read++;
            }
        }
        return $read;
    }
}

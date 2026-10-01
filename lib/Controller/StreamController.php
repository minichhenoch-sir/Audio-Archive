<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\AccessGuard;
use OCA\AudioArchive\Response\RangeStreamResponse;
use OCA\AudioArchive\Service\AudioFolder;
use OCA\AudioArchive\Service\ContentScope;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\ZipResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\ISession;

/**
 * Liefert eine Aufnahme aus.
 *
 * Unterstuetzt HTTP-Range-Requests. Das ist keine Feinheit, sondern
 * Voraussetzung: Das <audio>-Element fordert Audio praktisch immer
 * abschnittsweise an - ohne Range laesst sich nicht spulen, und auf manchen
 * Geraeten startet die Wiedergabe gar nicht erst.
 */
class StreamController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private AccessGuard $guard,
        private AudioFolder $audioFolder,
        private IAppConfig $appConfig,
        private ISession $session,
        private ContentScope $scope,
    ) {
        parent::__construct($appName, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function index(string $path, string $download = '', string $source = AudioFolder::SOURCE_SHARED, string $s = ''): Response {
        $scope = $this->scope->resolve($source, $s);
        if (is_int($scope)) {
            return new DataResponse(
                ['error' => $scope === Http::STATUS_UNAUTHORIZED ? 'not_authenticated' : 'Aufnahme nicht gefunden.'],
                $scope
            );
        }

        $node = $this->audioFolder->resolveIn($scope['root'], $path);
        if (!$node instanceof File || !$this->audioFolder->isAllowedFile($node)) {
            return new DataResponse(['error' => 'Aufnahme nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }

        $wantsDownload = ($download === '1');

        /*
         * Der Schalter wird auch hier geprueft, nicht nur beim Anzeigen des
         * Knopfes: Sonst liesse sich das Herunterladen durch Anhaengen von
         * "&download=1" an die Adresse umgehen.
         */
        if ($wantsDownload && !$scope['download']) {
            return new DataResponse(
                ['error' => 'Das Herunterladen ist deaktiviert.'],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            $handle = $node->fopen('r');
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Datei nicht lesbar.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($handle === false) {
            return new DataResponse(['error' => 'Datei nicht lesbar.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        /*
         * Sitzung schliessen, bevor die Ausgabe beginnt.
         *
         * PHP haelt die Sitzung waehrend eines Aufrufs gesperrt. Eine
         * Aufnahme laeuft aber minutenlang - so lange wuerde jede weitere
         * Anfrage derselben Person warten (Ordner oeffnen, Titelwechsel).
         * Ab hier wird nichts mehr in die Sitzung geschrieben, das Schliessen
         * ist also gefahrlos.
         */
        $this->session->close();

        $size = (int)$node->getSize();
        $range = $this->parseRange($this->request->getHeader('Range'), $size);

        $disposition = ($wantsDownload ? 'attachment' : 'inline')
            . '; filename="' . rawurlencode($node->getName()) . '"';

        /*
         * Auch ohne Range-Kopf wird bewusst die eigene Antwortklasse
         * verwendet statt Nextclouds StreamResponse: Nur sie leert die
         * PHP-Ausgabepuffer und sendet fortlaufend. Sonst wartet der Hoerer,
         * bis die komplette Aufnahme im Speicher liegt.
         */
        if ($range === null) {
            $response = new RangeStreamResponse($handle, $size);
            $response->addHeader('Content-Type', AudioFolder::mimeFor($node->getName()));
            $response->addHeader('Content-Length', (string)$size);
            $response->addHeader('Accept-Ranges', 'bytes');
            $response->addHeader('Content-Disposition', $disposition);
            return $response;
        }

        [$start, $end] = $range;

        if ($start > $end || $start >= $size) {
            fclose($handle);
            $response = new DataResponse(null, Http::STATUS_REQUESTED_RANGE_NOT_SATISFIABLE);
            $response->addHeader('Content-Range', 'bytes */' . $size);
            return $response;
        }

        fseek($handle, $start);

        $response = new RangeStreamResponse($handle, $end - $start + 1);
        $response->setStatus(Http::STATUS_PARTIAL_CONTENT);
        $response->addHeader('Content-Type', AudioFolder::mimeFor($node->getName()));
        $response->addHeader('Content-Length', (string)($end - $start + 1));
        $response->addHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size);
        $response->addHeader('Accept-Ranges', 'bytes');
        $response->addHeader('Content-Disposition', $disposition);

        return $response;
    }

    /** Hoechstens so viele Dateien je ZIP (jede ist beim Packen geoeffnet). */
    private const ZIP_MAX_FILES = 800;

    /**
     * Ordner als ZIP herunterladen (ab 0.25.0, Vikunja #29).
     *
     * Nur Unterordner - die oberste Ebene einer Quelle bzw. Freigabe nie
     * (Wunsch: "das Hauptverzeichnis nicht erlauben"). Enthalten sind alle
     * Aufnahmen samt Unterordnern sowie Ordnerbilder; versteckte Dateien
     * nicht. Erlaubt je nach Verwaltung bzw. Freigabe ("folderDownload").
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function zip(string $path = '', string $source = AudioFolder::SOURCE_SHARED, string $s = ''): Response {
        $scope = $this->scope->resolve($source, $s);
        if (is_int($scope)) {
            return new DataResponse(['error' => 'Nicht gefunden.'], $scope);
        }
        if (!($scope['folderDownload'] ?? false)) {
            return new DataResponse(['error' => 'Das Herunterladen von Ordnern ist nicht erlaubt.'], Http::STATUS_FORBIDDEN);
        }
        $node = $this->audioFolder->resolveIn($scope['root'], $path);
        if (!$node instanceof Folder || trim($path, '/') === '' || $node->getPath() === $scope['root']->getPath()) {
            return new DataResponse(['error' => 'Nur Unterordner lassen sich herunterladen.'], Http::STATUS_FORBIDDEN);
        }

        $entries = [];
        $tooMany = false;
        $this->collect($node, $node->getName(), $entries, $tooMany, 0);
        if ($tooMany) {
            return new DataResponse(
                ['error' => 'Der Ordner enthält zu viele Dateien – bitte einen Unterordner herunterladen.'],
                Http::STATUS_REQUEST_ENTITY_TOO_LARGE
            );
        }
        if ($entries === []) {
            return new DataResponse(['error' => 'Der Ordner enthält keine Aufnahmen.'], Http::STATUS_NOT_FOUND);
        }

        $this->session->close();
        $response = new ZipResponse($this->request, $node->getName());
        foreach ($entries as [$file, $name]) {
            try {
                $handle = $file->fopen('r');
            } catch (\Throwable $e) {
                continue;
            }
            if ($handle === false) {
                continue;
            }
            $response->addResource($handle, $name, (int)$file->getSize(), (int)$file->getMTime());
        }
        return $response;
    }

    /** @param list<array{0: File, 1: string}> $entries */
    private function collect(Folder $folder, string $prefix, array &$entries, bool &$tooMany, int $depth): void {
        if ($depth > 12 || $tooMany) {
            return;
        }
        try {
            $listing = $folder->getDirectoryListing();
        } catch (\Throwable $e) {
            return;
        }
        foreach ($listing as $child) {
            $name = $child->getName();
            if (str_starts_with($name, '.')) {
                continue;
            }
            if ($child instanceof Folder) {
                $this->collect($child, $prefix . '/' . $name, $entries, $tooMany, $depth + 1);
                continue;
            }
            if (!$child instanceof File) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'avif'], true);
            if (!$isImage && !$this->audioFolder->isAllowedFile($child)) {
                continue;
            }
            if (count($entries) >= self::ZIP_MAX_FILES) {
                $tooMany = true;
                return;
            }
            $entries[] = [$child, $prefix . '/' . $name];
        }
    }

    /**
     * Wertet den Range-Kopf aus.
     *
     * @return array{0: int, 1: int}|null Start und Ende, oder null fuer die ganze Datei
     */
    private function parseRange(?string $header, int $size): ?array {
        if ($header === null || $header === '' || $size <= 0) {
            return null;
        }

        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
            return null;
        }

        $startRaw = $m[1];
        $endRaw = $m[2];

        if ($startRaw === '' && $endRaw === '') {
            return null;
        }

        if ($startRaw === '') {
            // Form "bytes=-500": die letzten N Bytes
            $length = (int)$endRaw;
            $start = max(0, $size - $length);
            $end = $size - 1;
        } else {
            $start = (int)$startRaw;
            $end = ($endRaw === '') ? $size - 1 : (int)$endRaw;
            if ($end >= $size) {
                $end = $size - 1;
            }
        }

        return [$start, $end];
    }
}

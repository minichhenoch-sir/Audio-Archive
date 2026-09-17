<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\AccessGuard;
use OCA\AudioArchive\Response\RangeStreamResponse;
use OCA\AudioArchive\Service\AudioFolder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\IRequest;

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
    ) {
        parent::__construct($appName, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function index(string $path, string $download = ''): Response {
        if (!$this->guard->hasAccess()) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $node = $this->audioFolder->resolve($path);
        if (!$node instanceof File || !$this->audioFolder->isAllowedFile($node)) {
            return new DataResponse(['error' => 'Aufnahme nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }

        $wantsDownload = ($download === '1');

        /*
         * Der Schalter wird auch hier geprueft, nicht nur beim Anzeigen des
         * Knopfes: Sonst liesse sich das Herunterladen durch Anhaengen von
         * "&download=1" an die Adresse umgehen.
         */
        if ($wantsDownload) {
            $allowed = $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_DOWNLOAD, false
            );
            if (!$allowed) {
                return new DataResponse(
                    ['error' => 'Das Herunterladen ist deaktiviert.'],
                    Http::STATUS_FORBIDDEN
                );
            }
        }

        try {
            $handle = $node->fopen('r');
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Datei nicht lesbar.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($handle === false) {
            return new DataResponse(['error' => 'Datei nicht lesbar.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $size = (int)$node->getSize();
        $range = $this->parseRange($this->request->getHeader('Range'), $size);

        $disposition = ($wantsDownload ? 'attachment' : 'inline')
            . '; filename="' . rawurlencode($node->getName()) . '"';

        if ($range === null) {
            $response = new StreamResponse($handle);
            $response->addHeader('Content-Type', 'audio/mpeg');
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
        $response->addHeader('Content-Type', 'audio/mpeg');
        $response->addHeader('Content-Length', (string)($end - $start + 1));
        $response->addHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size);
        $response->addHeader('Accept-Ranges', 'bytes');
        $response->addHeader('Content-Disposition', $disposition);

        return $response;
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

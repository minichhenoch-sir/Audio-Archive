<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\Service\AudioFolder;
use OCA\AudioArchive\Service\ContentScope;
use OCA\AudioArchive\Service\CoverFinder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\IRequest;

/**
 * Cover einer Aufnahme (ab 0.14): eingebettetes Bild, sonst ein Bild im
 * Ordner (cover.jpg usw.). Gleicher Zugang wie fuer die Aufnahme selbst -
 * wer sie hoeren darf, darf auch ihr Cover sehen, sonst niemand.
 */
class CoverController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private ContentScope $scope,
        private AudioFolder $audioFolder,
        private CoverFinder $covers,
    ) {
        parent::__construct($appName, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function index(string $path = '', string $source = AudioFolder::SOURCE_SHARED, string $s = ''): Response {
        $scope = $this->scope->resolve($source, $s);
        if (is_int($scope)) {
            return new DataDisplayResponse('', $scope);
        }

        $node = $this->audioFolder->resolveIn($scope['root'], $path);
        if (!$node instanceof File || !$this->audioFolder->isAllowedFile($node)) {
            return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
        }

        $cover = $this->covers->coverFor($node, $scope['root']);
        if ($cover === null) {
            return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
        }

        $response = new DataDisplayResponse($cover['data'], Http::STATUS_OK, ['Content-Type' => $cover['mime']]);
        // Die Adresse traegt eine Versionskennung (v=...), aendert sich also
        // mit dem Bild. Nur im Browser speichern, nicht in Zwischenspeichern
        // unterwegs - die Aufnahmen sind nicht oeffentlich.
        $response->cacheFor(60 * 60 * 24 * 7, false);
        return $response;
    }
}

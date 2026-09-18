<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\Service\PlayerPage;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Player-Oberflaeche fuer angemeldete Nextcloud-Nutzer.
 *
 * Zwei Darstellungen desselben Players:
 *  - index():      innerhalb von Nextcloud, mit Kopfleiste (Menue-Eintrag)
 *  - standalone(): ohne Nextcloud-Rahmen, als installierte App
 *
 * Getrennt, weil Nextcloud auf seinen eigenen Seiten ein eigenes Manifest
 * einbindet. Von der eingebetteten Seite aus wuerde der Browser deshalb
 * Nextcloud installieren statt des Players.
 */
class PageController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private PlayerPage $playerPage,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        return $this->playerPage->build('', true);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function standalone(): TemplateResponse {
        return $this->playerPage->build('', false);
    }
}

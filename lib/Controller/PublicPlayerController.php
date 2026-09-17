<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\PlayerPage;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Oeffentlicher Zugang ohne Nextcloud-Konto.
 *
 * Bewusst ein einfacher PublicPage-Controller statt AuthPublicShareController:
 * Die App bringt einen eigenen Anmelde-Bildschirm mit, an dem auch die
 * Offline-Anmeldung haengt (der Passwort-Pruefwert wird im Browser
 * hinterlegt). Nextclouds Standard-Anmeldeseite wuerde diesen Ablauf
 * unterbrechen. Die eigentliche Passwortpruefung uebernimmt der
 * Login-Endpunkt der App, abgesichert ueber Nextclouds Brute-Force-Schutz.
 */
class PublicPlayerController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IAppConfig $appConfig,
        private PlayerPage $playerPage,
    ) {
        parent::__construct($appName, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function index(string $token): Response {
        $enabled = $this->appConfig->getValueBool(
            Application::APP_ID,
            Application::SETTING_PUBLIC_ENABLED,
            false
        );

        $expected = $this->appConfig->getValueString(
            Application::APP_ID,
            Application::SETTING_PUBLIC_TOKEN,
            ''
        );

        // Nicht freigegeben oder falscher Token: bewusst dieselbe Antwort,
        // damit sich gueltige Token nicht erraten lassen.
        if (!$enabled || $expected === '' || !hash_equals($expected, $token)) {
            return new NotFoundResponse();
        }

        return $this->playerPage->build($token);
    }
}

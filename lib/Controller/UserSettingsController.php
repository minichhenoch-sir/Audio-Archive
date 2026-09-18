<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\Appearance;
use OCA\AudioArchive\Service\BackgroundImage;
use OCA\AudioArchive\Service\ShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Persoenliche Einstellungen eines angemeldeten Nutzers. Seit 0.13 alle
 * Oberflaechen-Einstellungen: Gestaltung, Titel, Zusatzzeile, die drei
 * Farben und das Hintergrundbild. Sie gelten nur fuer seine eigene Ansicht.
 * Leer bedeutet jeweils: Vorgabe des Administrators.
 *
 * Nicht dabei: der Beta-Hinweis. Den schaltet nur der Administrator.
 *
 * Alle Methoden verlangen einen angemeldeten Nutzer und - weil ohne
 * NoCSRFRequired - das Anfrage-Token von Nextcloud. Der Administrator kann
 * das Ganze in den Einstellungen abschalten.
 */
class UserSettingsController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private Appearance $appearance,
        private BackgroundImage $backgroundImage,
    ) {
        parent::__construct($appName, $request);
    }

    /** Nur lesend - deshalb ohne Anfrage-Token abrufbar. */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function get(): DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new DataResponse([
            'allowed' => $this->appearance->userCustomizationAllowed(),
            // Eigene Werte ('' = Vorgabe) und die Vorgaben des Administrators
            'values' => $this->appearance->userValues($uid),
            'admin' => $this->appearance->adminValues(),
            'design' => $this->appearance->userDesignPreference($uid),
            'adminDesign' => $this->appearance->adminDesign(),
            'hasBackground' => $this->backgroundImage->exists(BackgroundImage::userKey($uid)),
        ]);
    }

    /**
     * @param string $design '' (Vorgabe), 'custom' oder 'nextcloud'
     * @param string|null $title, $subtitle, $themeAccent, $themeBar, $themeBase
     *        null = unveraendert, '' = Vorgabe des Administrators
     */
    #[NoAdminRequired]
    public function set(string $design = '', ?string $title = null, ?string $subtitle = null,
        ?string $themeAccent = null, ?string $themeBar = null, ?string $themeBase = null): DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->appearance->userCustomizationAllowed()) {
            return new DataResponse(['error' => 'Vom Administrator abgeschaltet.'], Http::STATUS_FORBIDDEN);
        }
        if (!in_array($design, ['', Application::DESIGN_CUSTOM, Application::DESIGN_NEXTCLOUD], true)) {
            return new DataResponse(['error' => 'Unbekannte Gestaltung.'], Http::STATUS_BAD_REQUEST);
        }

        $values = [];
        if ($title !== null) {
            $values['title'] = mb_substr(trim($title), 0, 200);
        }
        if ($subtitle !== null) {
            $values['subtitle'] = mb_substr(trim($subtitle), 0, 500);
        }
        foreach (['themeAccent' => $themeAccent, 'themeBar' => $themeBar, 'themeBase' => $themeBase] as $key => $value) {
            if ($value === null) {
                continue;
            }
            $value = trim($value);
            if ($value !== '' && ShareService::normalizeColor($value) === '') {
                return new DataResponse(['error' => 'Ungültiger Farbwert: ' . $value], Http::STATUS_BAD_REQUEST);
            }
            $values[$key] = ShareService::normalizeColor($value);
        }

        $this->appearance->setUserDesignPreference($uid, $design);
        $this->appearance->setUserValues($uid, $values);
        return new DataResponse(['design' => $design, 'values' => $this->appearance->userValues($uid)]);
    }

    #[NoAdminRequired]
    public function uploadBackground(): DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->appearance->userCustomizationAllowed()) {
            return new DataResponse(['error' => 'Vom Administrator abgeschaltet.'], Http::STATUS_FORBIDDEN);
        }

        $file = $this->request->getUploadedFile('file');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new DataResponse(['error' => 'Es wurde keine Datei empfangen.'], Http::STATUS_BAD_REQUEST);
        }

        $error = $this->backgroundImage->store(
            (string)$file['tmp_name'],
            (int)$file['size'],
            BackgroundImage::userKey($uid)
        );
        if ($error !== '') {
            return new DataResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
        }

        return new DataResponse(['hasBackground' => true]);
    }

    #[NoAdminRequired]
    public function removeBackground(): DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $this->backgroundImage->remove(BackgroundImage::userKey($uid));
        return new DataResponse(['hasBackground' => false]);
    }

    private function uid(): ?string {
        return $this->userSession->getUser()?->getUID();
    }
}

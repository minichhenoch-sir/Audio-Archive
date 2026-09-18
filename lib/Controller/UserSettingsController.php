<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\Appearance;
use OCA\AudioArchive\Service\BackgroundImage;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Persoenliche Einstellungen eines angemeldeten Nutzers: Gestaltung und
 * eigenes Hintergrundbild. Sie gelten nur fuer seine eigene Ansicht.
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
            'design' => $this->appearance->userDesignPreference($uid),
            'adminDesign' => $this->appearance->adminDesign(),
            'hasBackground' => $this->backgroundImage->exists(BackgroundImage::userKey($uid)),
        ]);
    }

    /** @param string $design '' (Vorgabe), 'custom' oder 'nextcloud' */
    #[NoAdminRequired]
    public function set(string $design = ''): DataResponse {
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

        $this->appearance->setUserDesignPreference($uid, $design);
        return new DataResponse(['design' => $design]);
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

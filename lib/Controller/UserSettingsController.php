<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\Appearance;
use OCA\AudioArchive\Service\BackgroundImage;
use OCA\AudioArchive\Service\ShareService;
use OCA\AudioArchive\Service\StyleTokens;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Persoenliche Einstellungen eines angemeldeten Nutzers. Seit 0.13 alle
 * Oberflaechen-Einstellungen: Gestaltung, Titel, Zusatzzeile, die drei
 * Farben und das Hintergrundbild. Sie gelten nur fuer seine eigene Ansicht.
 * Leer bedeutet jeweils: Vorgabe des Administrators.
 *
 * Dazu (ab 0.31.0) ein eigener Text ueber den Aufnahmen. Das BETA-Schild
 * schaltet nur der Administrator.
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
        private IConfig $config,
        private \OCP\IAppConfig $appConfig,
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
            // Ab 0.17: eigene Werte fuer "Benutzerdefiniert" (null = noch
            // keine) und die Gestaltung des Administrators, sofern angeboten
            'style' => $this->appearance->userStyle($uid),
            'adminStyleOffered' => $this->appearance->adminStyleOffered(),
            'adminStyle' => $this->appearance->adminStyleOffered() || $this->appearance->adminDesign() === Application::DESIGN_ADMIN
                ? $this->appearance->adminStyle() : null,
            'hasBackground' => $this->backgroundImage->exists(BackgroundImage::userKey($uid)),
            // Favoriten (ab 0.24.0): persoenlich ein/aus, sofern der Administrator sie anbietet
            'favorites' => $this->config->getUserValue($uid, Application::APP_ID, Application::USER_FAVORITES, '1') !== '0',
            'favoritesOffered' => $this->appConfig->getValueBool(Application::APP_ID, Application::SETTING_FEATURE_FAVORITES, true),
            // Wiederholen-Vorgabe (ab 0.28.0): '' = Vorgabe der Verwaltung
            'repeatDefault' => $this->config->getUserValue($uid, Application::APP_ID, Application::USER_REPEAT_DEFAULT, ''),
            // Kommentare/Bewertung (ab 0.29.0): persoenlich ein/aus, sofern angeboten
            'comments' => $this->config->getUserValue($uid, Application::APP_ID, Application::USER_COMMENTS, '1') !== '0',
            'rating' => $this->config->getUserValue($uid, Application::APP_ID, Application::USER_RATING, '1') !== '0',
            'commentsOffered' => $this->appConfig->getValueBool(Application::APP_ID, Application::SETTING_FEATURE_COMMENTS, false),
            'ratingOffered' => $this->appConfig->getValueBool(Application::APP_ID, Application::SETTING_FEATURE_RATING, true),
            // Text ueber den Aufnahmen (ab 0.31.0): eigener und der der Verwaltung (als Platzhalter)
            'notice' => $this->config->getUserValue($uid, Application::APP_ID, Application::USER_NOTICE, ''),
            'adminNotice' => Application::noticeEnabled($this->appConfig)
                ? $this->appConfig->getValueString(Application::APP_ID, Application::SETTING_BETA_TEXT, '') : '',
        ]);
    }

    /**
     * @param string $design '' (Vorgabe), 'nextcloud', 'custom', 'admin' oder 'defined'
     * @param array|null $style Werte fuer "Benutzerdefiniert" (null = unveraendert)
     * @param string|null $title, $subtitle, $themeAccent, $themeBar, $themeBase
     *        null = unveraendert, '' = Vorgabe des Administrators
     */
    #[NoAdminRequired]
    public function set(string $design = '', ?string $title = null, ?string $subtitle = null,
        ?string $themeAccent = null, ?string $themeBar = null, ?string $themeBase = null,
        ?array $style = null, ?bool $favorites = null, ?string $repeatDefault = null,
        ?bool $comments = null, ?bool $rating = null, ?string $notice = null): DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if ($favorites !== null) {
            $this->config->setUserValue($uid, Application::APP_ID, Application::USER_FAVORITES, $favorites ? '1' : '0');
        }
        if ($notice !== null) {
            // Formatierter Text (ab 0.37.0, Vikunja #49) - bereinigt
            $notice = \OCA\AudioArchive\Service\RichText::forStorage($notice);
            if ($notice !== '') {
                $this->config->setUserValue($uid, Application::APP_ID, Application::USER_NOTICE, $notice);
            } else {
                $this->config->deleteUserValue($uid, Application::APP_ID, Application::USER_NOTICE);
            }
        }
        if ($comments !== null) {
            $this->config->setUserValue($uid, Application::APP_ID, Application::USER_COMMENTS, $comments ? '1' : '0');
        }
        if ($rating !== null) {
            $this->config->setUserValue($uid, Application::APP_ID, Application::USER_RATING, $rating ? '1' : '0');
        }
        if ($repeatDefault !== null) {
            if (in_array($repeatDefault, Application::REPEAT_MODES, true)) {
                $this->config->setUserValue($uid, Application::APP_ID, Application::USER_REPEAT_DEFAULT, $repeatDefault);
            } else {
                $this->config->deleteUserValue($uid, Application::APP_ID, Application::USER_REPEAT_DEFAULT);
            }
        }
        if (!$this->appearance->userCustomizationAllowed()) {
            return new DataResponse(['error' => 'Vom Administrator abgeschaltet.'], Http::STATUS_FORBIDDEN);
        }
        if ($design !== '' && !in_array($design, Application::DESIGNS, true)) {
            return new DataResponse(['error' => 'Unbekannte Gestaltung.'], Http::STATUS_BAD_REQUEST);
        }
        if ($design === Application::DESIGN_ADMIN && !$this->appearance->adminStyleOffered()) {
            return new DataResponse(['error' => 'Diese Gestaltung bietet der Administrator derzeit nicht an.'], Http::STATUS_BAD_REQUEST);
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
        if ($style !== null) {
            $this->appearance->setUserStyle($uid, StyleTokens::normalize($style));
        }
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

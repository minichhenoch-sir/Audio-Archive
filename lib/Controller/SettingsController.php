<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\BackgroundImage;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;

/**
 * Speichert die Verwaltungs-Einstellungen.
 *
 * Bewusst OHNE die Attribute NoAdminRequired/PublicPage: In Nextcloud setzt
 * eine Controller-Methode ohne solche Angaben von sich aus
 * Administratorrechte und ein gueltiges Anfrage-Token voraus. Genau das ist
 * hier gewuenscht.
 */
class SettingsController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IAppConfig $appConfig,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private IHasher $hasher,
        private ISecureRandom $secureRandom,
        private IURLGenerator $urlGenerator,
        private BackgroundImage $backgroundImage,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string|null $sourceFolder Pfad innerhalb der Dateien des Administrators
     * @param bool|null $publicEnabled Oeffentlichen Zugang ein-/ausschalten
     * @param string|null $publicPassword Neues Passwort (leer = unveraendert)
     * @param string|null $headerTitle
     * @param string|null $headerSubtitle
     * @param string|null $themeAccent
     * @param string|null $themeBar
     * @param string|null $themeBase
     * @param bool|null $featureOffline
     * @param bool|null $featureDownload
     */
    public function setAdmin(
        ?string $sourceFolder = null,
        ?bool $publicEnabled = null,
        ?string $publicPassword = null,
        ?string $headerTitle = null,
        ?string $headerSubtitle = null,
        ?string $themeAccent = null,
        ?string $themeBar = null,
        ?string $themeBase = null,
        ?bool $featureOffline = null,
        ?bool $featureDownload = null,
        ?bool $betaEnabled = null,
        ?string $betaText = null,
        ?string $betaLinkUrl = null,
        ?string $betaLinkLabel = null,
    ): DataResponse {

        // ---------- Quellordner ----------
        if ($sourceFolder !== null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return new DataResponse(['error' => 'Nicht angemeldet.'], Http::STATUS_UNAUTHORIZED);
            }

            $path = '/' . trim($sourceFolder, '/');

            if ($path !== '/') {
                try {
                    $node = $this->rootFolder->getUserFolder($user->getUID())->get($path);
                } catch (NotFoundException $e) {
                    return new DataResponse(
                        ['error' => 'Der Ordner wurde nicht gefunden.'],
                        Http::STATUS_BAD_REQUEST
                    );
                }

                if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER) {
                    return new DataResponse(
                        ['error' => 'Der angegebene Pfad ist kein Ordner.'],
                        Http::STATUS_BAD_REQUEST
                    );
                }
            }

            $this->appConfig->setValueString(Application::APP_ID, Application::SETTING_FOLDER, $path);
            // Wem die Dateien gehoeren, muss mitgespeichert werden: Beim
            // oeffentlichen Zugang gibt es keinen angemeldeten Nutzer, ueber
            // dessen Dateien sich der Ordner sonst aufloesen liesse.
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_FOLDER_OWNER, $user->getUID()
            );
        }

        // ---------- Oeffentlicher Zugang ----------
        if ($publicEnabled !== null) {
            $this->appConfig->setValueBool(
                Application::APP_ID, Application::SETTING_PUBLIC_ENABLED, $publicEnabled
            );

            // Beim ersten Einschalten einen Token erzeugen. Er bleibt danach
            // bestehen, damit bereits verteilte Links gueltig bleiben.
            if ($publicEnabled) {
                $token = $this->appConfig->getValueString(
                    Application::APP_ID, Application::SETTING_PUBLIC_TOKEN, ''
                );
                if ($token === '') {
                    $token = $this->secureRandom->generate(
                        24,
                        ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_DIGITS
                    );
                    $this->appConfig->setValueString(
                        Application::APP_ID, Application::SETTING_PUBLIC_TOKEN, $token
                    );
                }
            }
        }

        if ($publicPassword !== null && $publicPassword !== '') {
            $this->appConfig->setValueString(
                Application::APP_ID,
                Application::SETTING_PUBLIC_PASSWORD,
                $this->hasher->hash($publicPassword)
            );
        }

        // ---------- Texte ----------
        if ($headerTitle !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_HEADER_TITLE, trim($headerTitle)
            );
        }
        if ($headerSubtitle !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_HEADER_SUBTITLE, trim($headerSubtitle)
            );
        }

        // ---------- Farben ----------
        $colors = [
            Application::SETTING_THEME_ACCENT => $themeAccent,
            Application::SETTING_THEME_BAR => $themeBar,
            Application::SETTING_THEME_BASE => $themeBase,
        ];
        foreach ($colors as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
                return new DataResponse(
                    ['error' => 'Ungültiger Farbwert: ' . $value],
                    Http::STATUS_BAD_REQUEST
                );
            }
            $this->appConfig->setValueString(Application::APP_ID, $key, strtolower($value));
        }

        // ---------- Funktionen ----------
        if ($featureOffline !== null) {
            $this->appConfig->setValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_OFFLINE, $featureOffline
            );
        }
        if ($featureDownload !== null) {
            $this->appConfig->setValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_DOWNLOAD, $featureDownload
            );
        }

        // ---------- Beta-Hinweis ----------
        if ($betaEnabled !== null) {
            $this->appConfig->setValueBool(
                Application::APP_ID, Application::SETTING_BETA_ENABLED, $betaEnabled
            );
        }

        if ($betaText !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_BETA_TEXT, trim($betaText)
            );
        }

        if ($betaLinkUrl !== null) {
            $url = trim($betaLinkUrl);

            /*
             * Nur http und https zulassen. Ohne diese Pruefung liesse sich
             * hier "javascript:..." hinterlegen - und der Hinweis wird allen
             * Nutzern angezeigt, auch denen der oeffentlichen Seite.
             */
            if ($url !== '') {
                $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
                if (!in_array($scheme, ['http', 'https'], true)) {
                    return new DataResponse(
                        ['error' => 'Die Adresse muss mit http:// oder https:// beginnen.'],
                        Http::STATUS_BAD_REQUEST
                    );
                }
            }

            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_BETA_LINK_URL, $url
            );
        }

        if ($betaLinkLabel !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_BETA_LINK_LABEL, trim($betaLinkLabel)
            );
        }

        return new DataResponse(['publicUrl' => $this->publicUrl()]);
    }

    /**
     * Nimmt ein Hintergrundbild entgegen.
     *
     * Gesendet wird als klassischer Datei-Upload, nicht als JSON - deshalb
     * kommt der Inhalt ueber $_FILES und nicht ueber die Parameter.
     */
    public function uploadBackground(): DataResponse {
        $file = $_FILES['file'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new DataResponse(['error' => 'Es wurde keine Datei empfangen.'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $error = $this->backgroundImage->store((string)$file['tmp_name'], (int)$file['size']);
        } catch (\Throwable $e) {
            /*
             * Die Meldung wird mitgegeben, weil diesen Endpunkt nur
             * Administratoren erreichen. Ohne sie erscheint in der
             * Oberflaeche nur ein nichtssagendes "Hochladen fehlgeschlagen",
             * und die Ursache bleibt im Verborgenen.
             */
            return new DataResponse(
                ['error' => 'Unerwarteter Fehler: ' . $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        if ($error !== '') {
            return new DataResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
        }

        return new DataResponse(['hasBackground' => true]);
    }

    public function removeBackground(): DataResponse {
        $this->backgroundImage->remove();
        return new DataResponse(['hasBackground' => false]);
    }

    /** Vollstaendiger Link der oeffentlichen Seite, leer wenn nicht freigegeben. */
    private function publicUrl(): string {
        $enabled = $this->appConfig->getValueBool(
            Application::APP_ID, Application::SETTING_PUBLIC_ENABLED, false
        );
        $token = $this->appConfig->getValueString(
            Application::APP_ID, Application::SETTING_PUBLIC_TOKEN, ''
        );

        if (!$enabled || $token === '') {
            return '';
        }

        return $this->urlGenerator->linkToRouteAbsolute(
            Application::APP_ID . '.publicPlayer.index',
            ['token' => $token]
        );
    }
}

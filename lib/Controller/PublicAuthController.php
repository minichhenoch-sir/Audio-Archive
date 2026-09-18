<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\Service\AccessGuard;
use OCA\AudioArchive\Service\ShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Anmeldung an der oeffentlichen Seite (gemeinsames Passwort).
 *
 * Der Brute-Force-Schutz von Nextcloud wird hier bewusst genutzt: Ein
 * einzelnes, geteiltes Passwort ist ein lohnendes Ziel, und ohne Drosselung
 * liesse es sich in Ruhe durchprobieren.
 */
class PublicAuthController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private AccessGuard $guard,
        private ShareService $shares,
    ) {
        parent::__construct($appName, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'audioarchivePublicLogin')]
    public function login(string $token = '', string $password = ''): DataResponse {
        if ($this->guard->tryPublicLogin($token, $password)) {
            return new DataResponse(['success' => true]);
        }

        // Kein Administrator-Link? Dann eine Freigabe eines Nutzers.
        $share = $this->shares->findActive($token);
        if ($share !== null && $this->shares->tryLogin($share, $password)) {
            return new DataResponse(['success' => true]);
        }

        $response = new DataResponse(
            ['success' => false, 'error' => 'Passwort falsch.'],
            Http::STATUS_UNAUTHORIZED
        );

        // Zaehlt den Fehlversuch fuer die Drosselung
        $response->throttle(['action' => 'audioarchivePublicLogin']);

        return $response;
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function logout(string $s = ''): DataResponse {
        if ($s !== '') {
            $share = $this->shares->findByToken($s);
            if ($share !== null) {
                $this->shares->logout($share);
            }
            return new DataResponse(['success' => true]);
        }
        $this->guard->publicLogout();
        return new DataResponse(['success' => true]);
    }

    /** Erlaubt der Oberflaeche zu erkennen, ob bereits Zugang besteht. */
    #[PublicPage]
    #[NoCSRFRequired]
    public function status(string $s = ''): DataResponse {
        if ($s !== '') {
            $share = $this->shares->findActive($s);
            return new DataResponse([
                'authenticated' => $share !== null && $this->shares->hasAccess($share),
                'loggedInUser' => $this->guard->isLoggedInUser(),
                'publicEnabled' => $share !== null,
                'hasPassword' => $share !== null && $share['hasPassword'],
            ]);
        }

        return new DataResponse([
            'authenticated' => $this->guard->hasAccess(),
            'loggedInUser' => $this->guard->isLoggedInUser(),
            'publicEnabled' => $this->guard->isPublicEnabled(),
            'hasPassword' => $this->guard->hasPublicPassword(),
        ]);
    }
}

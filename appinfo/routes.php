<?php
declare(strict_types=1);

return [
    'routes' => [
        // Oberflaeche fuer angemeldete Nextcloud-Nutzer
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Oeffentlicher Zugang ohne Konto. Der Token stammt aus den
        // Admin-Einstellungen und wirkt wie der Link einer Dateifreigabe.
        ['name' => 'publicPlayer#index', 'url' => '/s/{token}', 'verb' => 'GET'],

        // Verwaltungs-Einstellungen speichern (nur fuer Administratoren,
        // siehe Hinweis im SettingsController)
        ['name' => 'settings#setAdmin', 'url' => '/settings/admin', 'verb' => 'POST'],

        // PWA-Bausteine. Beide MUESSEN unterhalb von /apps/audioarchive/
        // ausgeliefert werden, damit der Service Worker genau diesen Bereich
        // abdecken darf - ein Worker kann nie oberhalb seines eigenen Pfades
        // gelten.
        ['name' => 'asset#serviceWorker', 'url' => '/service-worker.js', 'verb' => 'GET'],
        ['name' => 'asset#manifest', 'url' => '/manifest.webmanifest', 'verb' => 'GET'],
    ],
];

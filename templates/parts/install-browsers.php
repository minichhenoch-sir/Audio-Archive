<?php
/**
 * Wie man die App in welchem Browser installiert (ab 1.0.3 an einer Stelle).
 *
 * Wird an zwei Stellen gezeigt: im Fenster „App installieren“ des Players
 * (templates/parts/player-body.php) und in der Anleitung
 * (templates/manual/user.php). Aendert sich hier etwas, stimmt beides.
 *
 * Werte sind fertiges HTML (eigene, feste Texte).
 *
 * @return list<array{0: string, 1: string}> [Geraet, Anleitung]
 */
return [
    ['📱 iPhone und iPad',
        '<strong>Safari:</strong> Teilen <span aria-hidden="true">⬆︎</span> → „Zum Home-Bildschirm“.<br>'
        . '<strong>Chrome, Edge, Firefox</strong> (ab iOS 16.4): Teilen-Symbol in der Adressleiste → „Zum Home-Bildschirm“.'],
    ['🤖 Android',
        '<strong>Chrome, Edge, Samsung Internet, Firefox, Opera:</strong> Menü ⋮ → „App installieren“ bzw. „Zum Startbildschirm hinzufügen“.'],
    ['💻 Windows, Mac, Linux',
        '<strong>Chrome und Edge:</strong> Installieren-Symbol rechts in der Adressleiste (oder Menü → „App installieren“).<br>'
        . '<strong>Safari am Mac</strong> (ab macOS 14 Sonoma): Ablage → „Zum Dock hinzufügen“.<br>'
        . '<strong>Firefox unter Windows</strong> (neuere Versionen): Symbol „Zur Taskleiste hinzufügen“ in der Adressleiste.<br>'
        . '<strong>Firefox am Mac und unter Linux:</strong> geht nicht – bitte Chrome oder Edge verwenden oder ein Lesezeichen setzen.'],
];

# Audio Archive – Nextcloud-App (Schritt 1: Grundgerüst und Prototyp)

Dieser Stand ist **noch kein fertiger Player**. Er beantwortet zuerst die eine
Frage, von der die weitere Struktur abhängt:

> Lässt sich die App innerhalb von Nextcloud als eigene App auf dem
> Homescreen installieren – mit eigenem Service Worker, also mit der
> Grundlage für den Offline-Betrieb?

Erst wenn das geklärt ist, lohnt es sich, Player, Offline-Speicherung und
Einstellungen zu portieren.

## Was enthalten ist

| Bereich | Datei | Zweck |
|---|---|---|
| App-Beschreibung | `appinfo/info.xml` | Kennung, Version, Lizenz, Einstellungs-Abschnitt |
| Routen | `appinfo/routes.php` | beide Eingänge plus Manifest und Service Worker |
| Angemeldet | `lib/Controller/PageController.php` | Seite für Nextcloud-Nutzer |
| Öffentlich | `lib/Controller/PublicPlayerController.php` | Zugang ohne Konto, über Token |
| PWA | `lib/Controller/AssetController.php` | erzeugt Manifest, liefert Service Worker |
| Seitenaufbau | `lib/Service/PlayerPage.php` | gemeinsam für beide Eingänge |
| Verwaltung | `lib/Settings/` | Abschnitt unter Einstellungen → Verwaltung |

## Installieren

1. Ordner `audioarchive` nach `custom_apps/` (oder `apps/`) der
   Nextcloud-Installation kopieren.
   Im TrueNAS-Docker-Setup muss `custom_apps` als Volume gemountet sein.
2. Rechte setzen, damit der Webserver lesen darf:
   `chown -R www-data:www-data custom_apps/audioarchive`
3. In Nextcloud unter **Apps → Deaktivierte Apps** die App „Audio Archive"
   aktivieren.

## Prüfen

**a) Angemeldeter Zugang**
`https://<deine-cloud>/apps/audioarchive/` aufrufen. Die Seite zeigt drei
Zeilen mit dem Ergebnis der Selbstprüfung an:

- *Service Worker: registriert für …* → der Geltungsbereich muss auf
  `/apps/audioarchive/` enden
- *Manifest: geladen (start_url …, scope …)*
- *Installierbar: ja* → erscheint nur, wenn der Browser die Installation
  tatsächlich anbietet

Zusätzlich in Chrome prüfen: DevTools → Anwendung → Manifest. Dort darf
**kein** Hinweis auf ein fremdes Manifest stehen, und unter
„Installierbarkeit" keine Fehlermeldung.

**b) Öffentlicher Zugang**
Noch nicht nutzbar – der Token wird erst im nächsten Schritt in den
Einstellungen erzeugt. Der Aufruf von `/apps/audioarchive/s/beliebig`
muss aktuell 404 liefern (so ist es beabsichtigt).

**c) Verwaltung**
Einstellungen → Verwaltung → „Audio Archive". Der Abschnitt muss
erscheinen; Inhalt folgt.

## Das entscheidende Ergebnis

Bitte den genauen Text der drei Zeilen zurückmelden, besonders ob
„Installierbar: ja" erscheint. Davon hängt ab, ob der Player unverändert
übernommen werden kann oder ob die Seitenstruktur anders gelöst werden muss.

# Audio Archive – Nextcloud-App (Schritt 3b: vollständige Oberfläche)

**Schritt 1 ist abgeschlossen:** Die App lässt sich innerhalb von Nextcloud
als eigene PWA installieren – eigener Service Worker mit Geltungsbereich
`/apps/audioarchive/`, eigenes Manifest, und der Browser bietet die
Installation an. Damit ist bestätigt, dass Nextcloud-Integration, öffentlicher
Zugang und Offline-Betrieb gleichzeitig möglich sind.

**Dieser Stand (Schritt 2)** ergänzt die Verwaltungs-Einstellungen. Der Player
selbst folgt in Schritt 3.

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

---

## Schritt 2: Einstellungen prüfen

Einstellungen → Verwaltung → **Audio Archive**. Zu prüfen:

1. **Quellordner** – „Auswählen …" öffnet Nextclouds Dateidialog. Einen Ordner
   mit mp3-Dateien wählen und speichern. Darunter erscheint danach ein Hinweis,
   aus wessen Dateien gelesen wird.
2. **Öffentlicher Zugang** – Häkchen setzen, ein Passwort eintragen, speichern.
   Es erscheint ein Link mit Token. Dieser Link muss sich öffnen lassen
   (zeigt aktuell noch die Prüfseite, nicht den Player).
   Ohne Häkchen muss derselbe Link 404 liefern.
3. **Darstellung und Funktionen** – Werte speichern, Seite neu laden: Die Werte
   müssen erhalten bleiben.

### Wichtig zum Quellordner
Gespeichert wird nicht nur der Pfad, sondern auch, **wem** die Dateien gehören.
Beim öffentlichen Zugang gibt es keinen angemeldeten Nutzer, über den sich der
Ordner sonst auflösen ließe. Wer die Einstellungen speichert, legt damit fest,
aus wessen Dateien gelesen wird.

### Was noch nicht geht
Der Player selbst: Ordnerliste, Wiedergabe, Offline-Speicherung. Beides folgt
in Schritt 3, wenn die Controller portiert sind.

---

## Schritt 3a: Aufnahmen lesen und ausliefern

Neu sind die Endpunkte unter `/apps/audioarchive/api/`:

| Endpunkt | Zweck |
|---|---|
| `GET api/list?path=` | Inhalt eines Ordners als JSON, inkl. Dauer und ID3-Tags |
| `GET api/stream?path=` | Ausgabe einer Aufnahme, mit Range-Unterstützung |
| `POST api/public/login` | Passwort der öffentlichen Seite prüfen |
| `POST api/public/logout` | öffentliche Sitzung beenden |
| `GET api/public/status` | Zugangszustand abfragen |

### Wichtig: Die Sicherheitslücke ist geschlossen
Die öffentliche Seite fragt jetzt das Passwort ab. Zwei Punkte dabei:
- **Ohne gesetztes Passwort gibt es keinen Zugang** – sonst wäre ein
  aktivierter öffentlicher Zugang ohne Passwort für jeden offen, der den Link
  kennt.
- In der Sitzung wird der **Token** hinterlegt, nicht nur ein Ja/Nein. Wechselt
  der Token oder wird der Zugang abgeschaltet, verlieren bestehende Sitzungen
  sofort ihre Gültigkeit.

### Prüfen

**a) Als angemeldeter Nutzer:** `/apps/audioarchive/` öffnen. Unter „Aufnahmen"
muss der Inhalt des eingestellten Quellordners erscheinen. Ordner sind
anklickbar, Dateien haben einen Abspieler.

Dabei zu beachten:
- Stimmen die angezeigten Längen (z. B. `[11:19]`)?
- Erscheinen Künstler und Album bei Dateien mit ID3-Tags?
- Lässt sich **im Abspieler spulen**? Das prüft die Range-Unterstützung –
  ohne sie springt die Wiedergabe zurück an den Anfang oder startet nicht.

**b) Öffentlicher Zugang:** In den Einstellungen aktivieren, Passwort setzen,
Link in einem privaten Fenster öffnen. Es muss nach dem Passwort gefragt
werden. Mit falschem Passwort: Fehlermeldung. Mit richtigem: dieselbe Liste.

**c) Download-Schalter:** Bei ausgeschaltetem Schalter muss
`api/stream?path=...&download=1` mit 403 antworten.

### Nach dem Einspielen nicht vergessen
```bash
occ app:disable audioarchive && occ app:enable audioarchive
```
Sonst liefern die neuen Routen 404 (Nextcloud hält die Routen im
Zwischenspeicher).

---

## Schritt 3b: Oberfläche

Aus der Prüfseite ist die richtige App geworden. Übernommen aus der
eigenständigen Fassung:

- Explorer mit Breadcrumb über die echte Ordnerstruktur
- Schwebende Player-Leiste mit 15-Sekunden-Sprüngen, vor/zurück, Fortschritt
- Media Session: Titel und Steuerung auf dem Sperrbildschirm
- Laufender Titel in der Liste hervorgehoben, Equalizer-Symbol hält bei Pause an
- Farbsystem aus den Einstellungen (Akzent, Leisten, Grundton)
- Download-Knopf je Aufnahme, sofern freigegeben

### Was sich gegenüber der eigenständigen Fassung geändert hat
- Titel, Untertitel und Farben stehen bereits im Dokument; kein eigener Abruf
  mehr nötig, dadurch kein Umspringen beim Laden.
- Alle Adressen laufen über `js/config.js`, damit die übrigen Dateien nicht
  wissen müssen, unter welchem Pfad die App liegt.
- Der Abmelde-Knopf erscheint nur auf der öffentlichen Seite. Angemeldete
  Nextcloud-Nutzer melden sich über Nextcloud ab.

### Noch nicht enthalten
- **Offline-Wiedergabe.** Der Knopf „Offline verfügbar machen" speichert die
  Dateien zwar, aber der Service Worker liefert sie noch nicht aus – er ist
  seit dem CSP-Zwischenfall bewusst passiv. Kommt in Schritt 4. Bis dahin
  kann der Schalter in den Einstellungen aus bleiben.
- **Eigenes Hintergrundbild.** Der Upload fehlt in den Einstellungen; bis
  dahin wird der Verlauf aus dem Grundton gezeigt.

### Prüfen
Ordner öffnen, Aufnahme starten, spulen, nächster/vorheriger Titel, Bildschirm
sperren (läuft die Wiedergabe weiter, erscheint die Steuerung?), Zurück-Geste
(geht sie eine Ordnerebene zurück statt die App zu schließen?).

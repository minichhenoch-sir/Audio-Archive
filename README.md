# Audio Archive – Nextcloud-App (0.27.0)

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

---

## Schritt 4: Offline-Betrieb

Der Service Worker ist wieder aktiv – aber gezielt:

| Anfrage | Verhalten |
|---|---|
| Seitenaufruf | Netz, bei Ausfall die zuletzt geladene Seite aus dem Speicher |
| `api/stream` | zuerst Offline-Speicher, sonst Netz |
| übrige `api/…` | immer Netz (zu veränderlich zum Speichern) |
| CSS, JS, Bilder | Netz, bei Ausfall aus dem Speicher |

### Byte-Bereiche
Audio wird vom Browser abschnittsweise angefordert, im Speicher liegt aber die
ganze Datei. Der Service Worker schneidet den angeforderten Bereich selbst
heraus. Alle Sonderformen sind geprüft: offener Anfang (`bytes=0-`), fester
Bereich, offenes Ende, Suffix (`bytes=-50`), Anforderung über das Dateiende
hinaus sowie ungültige Bereiche (Antwort 416).

### Offline-PIN
Angemeldete Nextcloud-Nutzer haben kein App-Passwort, und ihre Anmeldung lässt
sich ohne Verbindung nicht prüfen – das Kontopasswort darf dafür keinesfalls
lokal liegen. Deshalb vergeben sie beim **ersten** Speichern eines Ordners eine
eigene PIN. Gespeichert wird nur ein gesalzener Prüfwert. Sie schützt
ausschließlich den Zugriff auf die bereits heruntergeladenen Aufnahmen.

Auf der öffentlichen Seite bleibt es beim gemeinsamen Passwort.

### Vorausladen
Wieder eingeschaltet, aber nur wenn ein Service Worker die Seite tatsächlich
steuert – sonst würde der Hintergrund-Download nur Bandbreite kosten und die
laufende Wiedergabe ausbremsen.

### Prüfen
1. Ordner öffnen, „Offline verfügbar machen" – PIN vergeben, Fortschritt abwarten
2. Flugmodus einschalten, App neu starten
3. PIN eingeben → nur die gespeicherten Ordner erscheinen
4. Aufnahme abspielen **und spulen** – das prüft die Bereichs-Logik

### Nachtrag zu Schritt 4

**Offline-Ansicht als echter Ordnerbaum.** Gespeichert wird je Ordner unter
seinem vollen Pfad; die Zwischenebenen werden daraus abgeleitet. Liegt etwa
`2026_08/Teil 1` vor, zeigt die oberste Ebene `2026_08` und erst darin
`Teil 1` – statt alle gespeicherten Ordner flach nebeneinander.

**Künstler und Album offline.** Diese Angaben stehen nur im Verzeichnis, nicht
im Audio-Speicher. Musste das Verzeichnis aus dem Speicher rekonstruiert
werden, fehlten sie deshalb. Zwei Vorkehrungen:
- Übernahme aus den Schlüsseln der älteren Fassung (`gp_…`), damit ein
  bestehendes Verzeichnis nicht verloren geht
- Wird ein gespeicherter Ordner online geöffnet, frischt die App das
  hinterlegte Verzeichnis auf. Unvollständige Einträge heilen damit von selbst.

**Zur PIN:** Sie wird nur abgefragt, wenn noch gar keine Offline-Anmeldung
eingerichtet ist. Wer sich zuvor über die öffentliche Seite angemeldet hat,
dessen Prüfwert liegt bereits vor – dann erscheint keine Abfrage.

---

## Schritt 5a: Hintergrundbild

Der Administrator kann unter Einstellungen → Verwaltung → Audio Archive ein
Hintergrundbild hochladen (PNG, JPEG oder WebP, höchstens 8 MB). Ohne Bild
zeigt die App den Verlauf aus dem Grundton.

**Ablage im AppData-Bereich, nicht im App-Ordner.** Bei der Code-Signierung
für den App Store werden Prüfsummen aller Dateien im App-Ordner hinterlegt –
ein Upload dorthin würde die Integritätsprüfung bei jedem Speichern anschlagen
lassen.

**Typprüfung am Inhalt**, nicht am Dateinamen: Ein passender Name sagt nichts
darüber aus, was tatsächlich in der Datei steht.

Die Ausgabe unter `/apps/audioarchive/background` ist bewusst öffentlich
erreichbar – das Bild erscheint auch auf dem Anmelde-Bildschirm der
Freigabe-Seite, also bevor jemand angemeldet ist.

### Noch offen für den Store
- Mehrsprachigkeit (Englisch ist Pflicht, Oberfläche ist derzeit deutsch)
- Bildschirmfotos und Beschreibung
- Öffentliches Repository, Zertifikat, signierte Veröffentlichung

## Beta-Hinweis

In den Einstellungen unter „Beta-Hinweis" einschaltbar. Zeigt dann:
- ein „Beta"-Zeichen neben dem Titel in der Kopfzeile
- einen Hinweisstreifen über dem Pfad mit frei formuliertem Text

Der Link wird nur mit seiner **Beschriftung** angezeigt, nicht mit der vollen
Adresse – so bleibt der Hinweis auch auf dem Telefon kurz. Erlaubt sind nur
`http://` und `https://`; ohne diese Prüfung ließe sich dort `javascript:`
hinterlegen, und der Hinweis erscheint allen Nutzern, auch denen der
öffentlichen Seite. Text und Beschriftung werden als reiner Text eingesetzt,
nie als Markup.

Der Streifen erscheint auf beiden Zugangswegen, aber nicht auf dem
Anmelde-Bildschirm.

---

## 0.8.0: Innerhalb von Nextcloud mit Kopfleiste

Wer die App über das Nextcloud-Menü öffnet, bleibt jetzt in Nextcloud: Die
Kopfleiste (Apps, Suche, Benachrichtigungen, Konto) bleibt stehen, der Player
füllt den Inhaltsbereich darunter.

| Adresse | Darstellung | Wofür |
|---|---|---|
| `/apps/audioarchive/` | mit Nextcloud-Leiste | Menü-Eintrag, angemeldete Nutzer |
| `/apps/audioarchive/app` | ohne Leiste | installierte App (angemeldet) |
| `/apps/audioarchive/s/<token>` | ohne Leiste | geteilter Link, unverändert |

### Warum zwei Fassungen für angemeldete Nutzer?
Auf Seiten mit Leiste bindet Nextcloud sein eigenes Manifest ein. Von dort aus
würde der Browser Nextcloud installieren, nicht den Player. Deshalb zeigt die
eingebettete Seite oben rechts **„App installieren"**. Der Knopf führt zur
Fassung ohne Leiste, und dort bietet der Browser die Installation an. Auf
dieser Seite erscheint derselbe Knopf, sobald der Browser die Installation
direkt anbietet (Chrome, Edge, Android). Auf dem iPhone: Teilen → „Zum
Home-Bildschirm".

Bereits installierte Apps starten noch unter der alten Adresse. Sie werden
automatisch auf die Fassung ohne Leiste umgeleitet. Die Kennung der App
(`id` im Manifest) bleibt gleich, also entsteht keine zweite Kachel.

### Abschirmung gegen Nextclouds Stile
Alle Regeln aus `css/style.css` gelten nur innerhalb von `#audioarchive`.
Nextclouds Vorgaben für Knöpfe, Eingabefelder und Überschriften werden dort
zurückgesetzt (`all: revert`). Schriftgrößen beziehen sich auf `--aa-rem`,
weil Nextcloud die Grundschrift auf 15px setzt. So sieht der Player
eingebettet genauso aus wie eigenständig.

### Nebenbei behoben
- Das `<audio>`-Element stand doppelt im Dokument (gleiche ID).
- Der Abmelde-Knopf blieb bei angemeldeten Nutzern unter Umständen sichtbar,
  weil `.icon-btn` das `hidden`-Attribut überstimmte.

### Prüfen
1. Über das Nextcloud-Menü öffnen: Die Leiste bleibt, der Player darunter
   scrollt, die Player-Leiste steht unten im Inhaltsbereich.
2. Menüs der Nextcloud-Leiste (Konto, Benachrichtigungen) öffnen: Sie müssen
   **über** dem Player liegen.
3. „App installieren": Die Seite ohne Leiste öffnet sich, und der Browser
   bietet die Installation an.
4. Eine bereits installierte App starten: Sie muss ohne Leiste erscheinen.
5. Geteilten Link im privaten Fenster öffnen: keine Leiste, wie bisher.
6. Offline: Die installierte App startet weiterhin ohne Verbindung.

Nach dem Einspielen den Container neu starten, weil sich PHP-Dateien geändert
haben. Weil `routes.php` und `info.xml` geändert sind, die App außerdem aus-
und wieder einschalten.

---

## 0.9.0: Gestaltung „Eigene" oder „Nextcloud"

Einstellungen → Verwaltung → Audio Archive → Darstellung → **Gestaltung**.

| Gestaltung | Wirkung |
|---|---|
| Eigene (Vorgabe) | wie bisher: eigene Farben, Hintergrundbild, Glas-Design |
| Nextcloud | Farben, Hintergrund und Schrift von Nextcloud, Hell/Dunkel automatisch |

Die Einstellung gilt überall: innerhalb von Nextcloud, für die installierte
App und für den geteilten Link. Der geteilte Link bleibt dabei ohne
Nextcloud-Kopfleiste. Bei „Nextcloud" werden die eigenen Farben und das
Hintergrundbild abgeblendet angezeigt, bleiben aber gespeichert, sodass ein
Zurückwechseln nichts verliert.

### So sieht die Nextcloud-Gestaltung aus
- Ruhige Hauptfläche statt Glas. Einträge sehen aus wie in Nextclouds
  Navigation, der laufende Titel ist in der hellen Hauptfarbe hinterlegt.
- Die Knöpfe folgen Nextclouds Stil, der Abspielknopf ist in der Hauptfarbe.
- Die Player-Leiste schwebt wie Nextclouds Menüs: halbdurchsichtig, mit
  Unschärfe.
- Der Anmelde-Bildschirm des geteilten Links zeigt Nextclouds
  Hintergrundbild, wie Nextclouds eigene Anmeldeseite.
- Ohne Leiste auf breiten Bildschirmen: Das Hintergrundbild rahmt die
  Hauptfläche ein, wie innerhalb von Nextcloud.

### Woher die Farben kommen
Innerhalb von Nextcloud stehen Nextclouds CSS-Variablen ohnehin auf der
Seite, und zwar passend zum Design, das der jeweilige Nutzer gewählt hat. Die
Seiten ohne Leiste binden sie selbst ein, über denselben öffentlichen
Endpunkt der Theming-App, den Nextcloud auf seinen Anmeldeseiten nutzt:
`theme/default.css` immer, `theme/dark.css` bei dunklem Gerät. Der Service
Worker speichert diese Dateien mit, damit die App auch offline gestaltet
bleibt. Ist die Theming-App abgeschaltet, gelten Ersatzwerte (Nextclouds
helles Standard-Design).

Statusleiste und Manifest der installierten App nehmen bei
Nextcloud-Gestaltung Nextclouds Hauptfarbe.

### Prüfen
1. Gestaltung auf „Nextcloud" stellen und speichern.
2. Die App in Nextcloud öffnen: Farben wie Nextcloud, im persönlichen Design
   auf „Dunkel" umstellen, dann muss der Player mitwechseln.
3. Den geteilten Link im privaten Fenster öffnen: Der Anmelde-Bildschirm
   zeigt das Nextcloud-Hintergrundbild, danach die helle oder dunkle Liste
   passend zur Geräte-Einstellung.
4. Zurück auf „Eigene": wieder das bisherige Design mit den alten Farben.

Da sich PHP-Dateien geändert haben, den Container neu starten.

---

## 0.10.0: Persönliche Darstellung, flexibles Hintergrundbild

### Nutzer wählen selbst
Angemeldete Nutzer öffnen über das **Zahnrad** oben rechts ihre persönliche
Darstellung:
- **Gestaltung:** Vorgabe des Administrators, Eigene oder Nextcloud
  (Hell/Dunkel automatisch)
- **Hintergrundbild:** eigenes Bild hochladen oder entfernen

Das gilt nur für die eigene Ansicht, andere Nutzer und der öffentliche Link
bleiben unberührt. Der Administrator kann es unter „Nutzer dürfen
Gestaltung und Hintergrundbild selbst wählen" abschalten.

### Welches Hintergrundbild gilt
| Ebene | gilt |
|---|---|
| Nutzer-Bild | für diesen Nutzer, in **beiden** Gestaltungen |
| Administrator-Bild | bei eigener Gestaltung immer, bei Nextcloud-Gestaltung nur mit Häkchen „auch bei Nextcloud-Gestaltung" |
| kein Bild | Verlauf aus dem Grundton bzw. Nextclouds Hintergrund |

Bei Nextcloud-Gestaltung mit Bild liegt das Bild hinter allem, und die Liste
steht auf einer ruhigen Fläche.

### Wichtige Fehlerbehebung: Service Worker unter Nextcloud 34
Seit Nextcloud 34 enthält die Sicherheitsrichtlinie für Skripte nur noch das
Nonce, kein `'self'` mehr. Ohne eigene `worker-src`-Angabe verweigerte der
Browser deshalb die Registrierung des Service Workers, und zwar ohne
sichtbare Fehlermeldung. Folgen: keine Offline-Wiedergabe, kein Vorausladen,
keine Installation als App. Behoben durch `worker-src 'self'`. Gefunden an
einer echten Nextcloud 34.0.3 in der Testumgebung.

### Weitere Änderungen
- Autor: Henoch Minich
- Hintergrundbilder werden mit dem richtigen Typ ausgeliefert (bisher
  `application/octet-stream`)
- Überbleibsel der eigenständigen Fassung entfernt: Die Eingabe „admin" im
  Passwortfeld führte auf eine nicht mehr existierende Seite.

Nach dem Einspielen: Container neu starten. Nextcloud meldet wegen der neuen
Version ein Update. Bestätigen (oder `occ upgrade`).

---

## 0.11.0: Ordnerbaum in der Seitenleiste

Angemeldete Nutzer sehen links einen aufklappbaren Ordnerbaum mit zwei
Bereichen:

| Bereich | Inhalt |
|---|---|
| **Gemeinsame Aufnahmen** | der vom Administrator eingestellte Quellordner (nur wenn eingerichtet) |
| **Meine Dateien** | die eigenen Nextcloud-Dateien mit **allen** Ordnern, auch den mit einem geteilten |

- Unterordner werden erst beim Aufklappen geladen, auch sehr große
  Dateibestände bremsen deshalb nicht.
- Der geöffnete Ordner ist im Baum markiert. Wer über die Liste tiefer geht,
  sieht den Baum automatisch mitlaufen.
- Auf schmalen Bildschirmen (unter 1024 px) klappt der Baum über das
  Menü-Symbol oben links als Seitenmenü auf und schließt sich nach der Wahl
  eines Ordners.
- Ohne Verbindung zeigt der Baum nur die offline gespeicherten Ordner.
- Der öffentliche Link hat keinen Baum und bleibt wie bisher.

### Eigene Dateien
- In „Meine Dateien" werden Ordner nicht gezählt (keine „N Aufnahmen"): Das
  hieße, den kompletten Dateibestand bei jedem Öffnen zu durchsuchen.
- Eigene Aufnahmen darf man immer herunterladen, unabhängig vom Schalter des
  Administrators.
- Wie bisher werden mp3-Dateien angezeigt.

### Technik
- Neuer Parameter `source=home` an `api/list` und `api/stream`, neuer
  Endpunkt `api/tree` (nur Unterordner).
- Für den gemeinsamen Ordner bleiben alle Adressen **unverändert**. Bereits
  offline gespeicherte Aufnahmen bleiben dadurch gültig.
- Das Offline-Verzeichnis trennt die Quellen (`@@home:`-Vorsilbe für eigene
  Dateien).
- Mit Baum scrollen Baum und Liste jeweils für sich, die Kopfzeile bleibt
  stehen.

---

## 0.12.0: Ordner teilen – Freigaben durch Nutzer

Jeder angemeldete Nutzer kann einen Ordner **samt Unterordnern** über einen
eigenen Link teilen. Das geht mit Ordnern aus „Meine Dateien" und aus
„Gemeinsame Aufnahmen".

**So geht's:** Ordner öffnen → „Diesen Ordner teilen" → „Neue Freigabe". Alle
eigenen Freigaben stehen im Ordnerbaum unter **„Meine Freigaben"**. Ein Klick
öffnet den Ordner mit der Freigabe zum Bearbeiten.

### Einstellungen je Freigabe
| Bereich | Einstellungen |
|---|---|
| Zugang | Passwort (**optional**), Ablaufdatum (optional, gilt bis einschließlich dieses Tages) |
| Funktionen | Offline speichern, Herunterladen als Datei |
| Aussehen | Titel (leer = Ordnername), Zusatzzeile, Gestaltung (Vorgabe/Eigene/Nextcloud), eigene Farben, eigenes Hintergrundbild |
| Beta-Hinweis | Zeichen, Text, Link, Beschriftung |

Ohne Passwort öffnet der Link direkt die Aufnahmen, auch offline. Mit
Passwort wird es wie beim bisherigen Link abgefragt, mit Brute-Force-Schutz.

### Verwaltung
Einstellungen → Verwaltung → Audio Archive → **Freigaben durch Nutzer**:
- Schalter, ob Nutzer Freigaben anlegen dürfen (Vorgabe: ja). Abschalten
  sperrt nur neue Freigaben, bestehende bleiben gültig.
- Übersicht aller Freigaben (Ordner, Ersteller, Passwort, Ablauf) mit
  **Löschen**

Bearbeiten und löschen darf nur, wer die Freigabe angelegt hat, dazu
Administratoren. Fremde Freigaben sind für andere Nutzer unsichtbar.

### Technik
- **Neue Datenbanktabelle** `audioarchive_shares`. Nextcloud meldet nach dem
  Einspielen ein Update, das die Tabelle anlegt: bestätigen, oder
  `occ upgrade`.
- Der Ordner wird über seine **Datei-ID** gespeichert, ein Umbenennen oder
  Verschieben bricht den Link also nicht.
- Seite einer Freigabe: `/apps/audioarchive/s/<token>`, wie der
  Administrator-Link. Alle Abrufe tragen `s=<token>`. Der Administrator-Link
  bleibt unverändert, auch seine offline gespeicherten Aufnahmen.
- Offline getrennt je Freigabe: eigener Bereich im Verzeichnis, eigener
  Passwort-Prüfwert.
- Geprüft: kein Ausbrechen aus dem freigegebenen Ordner (`..` → 404),
  abgelaufene oder gelöschte Freigaben → 404, Download nur wenn erlaubt,
  schreibende Aufrufe nur mit Anfrage-Token.

Nach dem Einspielen den Container neu starten und das Update bestätigen.

---

## 0.12.1: Neues App-Symbol

Weißes Mikrofon auf blauem Grund (vom Nutzer gewählt).

| Datei | Verwendung |
|---|---|
| `img/icon-192.png`, `img/icon-512.png` | installierte App, Favicon, Sperrbildschirm (abgerundetes Quadrat) |
| `img/icon-maskable-512.png` | Android-Kachel. Vollflächig, das System schneidet selbst zu. Das Mikrofon liegt in der sicheren Zone. |
| `img/apple-touch-icon.png` | iPhone/iPad-Homebildschirm (vollflächig, iOS rundet selbst) |
| `img/app.svg` | Nextcloud-Kopfleiste und App-Menü (weiße Linien-Grafik) |
| `img/app-dark.svg` | Einstellungen → Verwaltung (dunkle Variante) |

Bereits installierte Apps übernehmen das neue Symbol, sobald der Browser das
Manifest neu einliest. Das kann bis zu einem Tag dauern, auf dem iPhone ist
eine Neuinstallation nötig.

---

## 0.13.0: Wunschnamen, persönliche Oberfläche, Teilen mit Nextcloud-Nutzern

### Links mit Wunschnamen
Statt einer Zufallsadresse lässt sich ein eigener Name vergeben:
`/apps/audioarchive/s/konzert-abend`.

- Bei jedem Link eines Nutzers („Neuer Link" bzw. „Bearbeiten") und beim
  öffentlichen Link des Administrators (Einstellungen → Verwaltung).
- Erlaubt: a–z, Ziffern, Bindestrich, 3 bis 64 Zeichen. Eingaben werden
  umgewandelt: „Konzert Abend Über" → `konzert-abend-ueber`.
- Groß-/Kleinschreibung in der Adresse spielt keine Rolle.
- Jeder Name nur einmal, auch nicht gleich dem Administrator-Link.
- Feld leeren = wieder eine zufällige Adresse.
- **Ändern macht den alten Link ungültig** (auch in installierten Apps
  und für offline Gespeichertes dieses Links).
- Wunschnamen sind leichter zu erraten. Aufrufe unbekannter Adressen werden
  deshalb über Nextclouds Brute-Force-Schutz gedrosselt. Für private
  Inhalte ein Passwort setzen.

### Alle Oberflächen-Einstellungen persönlich
Das Zahnrad bietet jetzt alles, was der Administrator für die Oberfläche
einstellt: Gestaltung, **Titel, Zusatzzeile, die drei Farben** und
Hintergrundbild. Leere Felder = Vorgabe des Administrators. Gilt nur für die
eigene Ansicht (auch Titel und Leistenfarbe der installierten App). Der
Schalter des Administrators „Nutzer dürfen … selbst wählen" sperrt weiterhin
alles.

### Beta-Hinweis nur durch den Administrator
Aus den Freigaben entfernt. Ist er in der Verwaltung eingeschaltet, erscheint
er **überall**: in der App, auf dem Administrator-Link und auf allen Links der
Nutzer. Alte Beta-Werte in bestehenden Freigaben werden ignoriert.

### Ordner mit Nextcloud-Nutzern und -Gruppen teilen
„Diesen Ordner teilen" hat zwei Bereiche:

| Bereich | Wirkung |
|---|---|
| **Mit Personen und Gruppen** | Suche über Nextcloud (beachtet dessen Einstellungen zum Teilen). Die Personen sehen den Ordner im Ordnerbaum unter **„Mit mir geteilt"** – nur in dieser App, nicht in der Dateien-App. Weiterteilen können sie nicht. |
| **Öffentliche Links** | wie bisher, jetzt mit Wunschnamen |

Beide Arten haben Ablaufdatum, Funktionen (Offline/Herunterladen) und ein
eigenes Aussehen (Titel, Zusatzzeile, Gestaltung, Farben, Hintergrundbild).

### Aussehen beim Empfänger: er entscheidet
Öffnet der Empfänger einen mit ihm geteilten Ordner, erscheint darüber
„Geteilt von …" und das Häkchen **„Aussehen der Freigabe verwenden"**
(Vorgabe: an). Gespeichert je Freigabe und Nutzer. Felder, die der Teilende
leer lässt, übernehmen die eigene Darstellung des Empfängers. Der Wechsel
geschieht ohne Neuladen; Wiedergabe und Ordnerbaum bleiben erhalten. Beim
Verlassen des Ordners gilt wieder die eigene Ansicht.

Offline: Mit mir geteilte Ordner lassen sich wie andere speichern (eigener
Bereich im Verzeichnis, `@@in:<id>:`).

### Technik
- Datenbank: Spalte `kind` in `audioarchive_shares` (`link`/`internal`,
  bestehende Zeilen = `link`) und neue Tabelle `audioarchive_share_members`.
  **Nextcloud meldet ein Update – bestätigen oder `occ upgrade`.**
- Neue Quelle `in:<id>` für `api/list`, `api/tree`, `api/stream`; Zugriff
  nur für Empfänger (direkt oder über eine Gruppe) und den Ersteller.
- Interne Freigaben sind über keinen öffentlichen Weg erreichbar (Link-Seite,
  Anmeldung, Bild, Manifest suchen ausschließlich `kind = link`).
- Neue Endpunkte: `GET api/incoming`, `POST api/incoming/{id}/design`,
  `GET api/members/search`, `GET background/incoming/{id}`.

### Prüfen
1. Update bestätigen, Container neu starten.
2. Ordner teilen → „Neuer Link" mit Wunschnamen → Link im privaten Fenster
   öffnen, auch mit Großbuchstaben in der Adresse.
3. Ordner mit einem zweiten Nutzer teilen, Gestaltung „Nextcloud" wählen. Als
   zweiter Nutzer: „Mit mir geteilt" → Ordner öffnen → Aussehen wechselt;
   Häkchen abwählen → eigenes Aussehen; zurück zu „Meine Dateien" → eigenes.
4. Empfänger aus der Freigabe entfernen → beim Empfänger verschwindet der
   Ordner (nach Neuladen), direkter Aufruf liefert „nicht mehr geteilt".
5. Zahnrad → Titel und Farben ändern → Übernehmen.
6. Beta in der Verwaltung einschalten → erscheint auch auf Links der Nutzer.

---

## 0.14.0: Cover und Vollbild-Player

### Cover
Die Player-Leiste zeigt links das Cover des laufenden Titels. Woher es kommt:

1. das in der mp3 **eingebettete Bild** (ID3v2.2/2.3/2.4; bei mehreren
   gewinnt die Vorderseite)
2. sonst ein **Bild im Ordner**: `cover`, `folder`, `front`, `album` oder
   `albumart` mit Endung `.jpg`, `.jpeg`, `.png` oder `.webp`
   (Groß-/Kleinschreibung egal)
3. sonst dasselbe in den **übergeordneten Ordnern** – ein Bild reicht also
   für eine ganze Reihe mit Unterordnern. Gesucht wird nie oberhalb der
   Wurzel: Bei einer Freigabe erscheint kein Bild aus nicht geteilten Ordnern.
4. ohne Cover: das **App-Symbol**

Das Cover erscheint auch auf dem Sperrbildschirm (Media Session). Beim
Offline-Speichern eines Ordners werden die Cover mitgespeichert.

### Vollbild-Player
Das Symbol rechts in der Leiste (oder ein Tipp aufs Cover) öffnet den
Player im Vollbild: großes Cover oben, darunter Titel, Fortschritt und
Steuerung, dahinter das Cover unscharf. Schließen über den Pfeil oben links,
die Zurück-Geste bzw. Zurück-Taste oder Esc. Die Wiedergabe läuft dabei
ununterbrochen weiter. Im Querformat auf dem Telefon steht das Cover links.

### Technik
- Neuer Endpunkt `GET api/cover?path=…` (gleiche Zugangsprüfung wie
  `api/stream`, also auch für Links und mit mir geteilte Ordner).
- Die Ordnerliste meldet je Aufnahme `cover` (Versionskennung, `null` = kein
  Cover). Das Bild selbst wird nur beim Abruf gelesen; beim Auflisten liest
  der Server nur die Frame-Köpfe des ID3-Tags.
- Der Zwischenspeicher der Metadaten hat einen neuen Schlüssel (`v2-`) –
  beim ersten Öffnen eines Ordners werden die Angaben einmal neu gelesen.

### Prüfen
Nach dem Einspielen Container neu starten und App aus-/einschalten (neue
Route). Einen Ordner mit `cover.jpg` und eine mp3 mit eingebettetem Bild
abspielen, Vollbild öffnen und per Zurück-Geste schließen, Sperrbildschirm
ansehen.

---

## 0.15.0: Wiederholen, nächster Ordner, Angaben zur Aufnahme

### Wiederholen – ein Knopf, vier Stufen
In der Player-Leiste (und beschriftet im Vollbild). Jeder Tipp schaltet weiter:

| Stufe | Am Ende des Ordners |
|---|---|
| Wiederholen aus (Vorgabe) | Wiedergabe endet |
| **Danach nächster Ordner** | weiter mit dem nächsten Ordner, der Aufnahmen enthält |
| **Ordner wiederholen** | der Ordner beginnt von vorn |
| **Titel wiederholen** | der Titel läuft endlos (ohne Lücke) |

Die Wahl merkt sich jedes Gerät. „Nächster" am letzten Titel verhält sich
passend zur Stufe.

### Nächster Ordner = Baum-Reihenfolge
Wie ein Inhaltsverzeichnis: zuerst die Unterordner, dann der Ordner daneben,
am Ende einer Ebene eine Ebene höher. Beispiel:
`2026_08` → `2026_08/Teil 1` → `2026_09` → `2026_10/Teil`. Ordner ohne
Aufnahmen werden übersprungen, ihre Unterordner aber durchsucht. Nie
außerhalb der Quelle (bei Links und geteilten Ordnern nur innerhalb der
Freigabe). Zeigt die Liste gerade den fertigen Ordner, wandert sie mit.
Ohne Verbindung geht es durch die offline gespeicherten Ordner.

### Angaben zur Aufnahme
Im Vollbild-Player der Knopf **„Angaben"** – klappt unter der Steuerung auf:
- Aufnahme: Titel, Künstler, Album, Albumkünstler, Jahr, Genre, Titelnummer,
  CD, Komponist, Kommentar
- Wiedergabe: Dauer, Bitrate (bei variabler der Mittelwert), Abtastrate,
  Mono/Stereo, Format
- Datei: Name, Ordner, Größe, Änderungsdatum

Nur vorhandene Angaben erscheinen. Ohne Verbindung bleibt es bei den
Grundangaben aus der Ordnerliste.

### Technik
- Neue Endpunkte `GET api/info` und `GET api/next` (gleiche Zugangsprüfung
  wie die Ordnerliste).
- Tag-Leser erweitert: TPE2, TYER/TDRC, TCON (auch „(17)"-Verweise auf die
  ID3v1-Genres), TRCK, TPOS, TCOM, COMM und TXXX „comment" (so schreibt ffmpeg
  Kommentare), Rückfall auf ID3v1.1.
- Die Suche nach dem nächsten Ordner sieht höchstens 3000 Ordner an.

Nach dem Einspielen: Container neu starten, App aus-/einschalten (neue Routen).

---

## 0.15.1: Prüfung in fünf Durchgängen – Korrekturen

Geprüft an echter Nextcloud 34.0.3 mit Chromium: Syntax und Linter, alle
Symbole und Bilder, Ein-/Ausblenden aller Elemente je Zugang, Layout auf
sieben Bildschirmgrößen in beiden Gestaltungen, alle Funktionen samt
Sperrbildschirm (Media Session nachgebildet) und Offline-Betrieb.

### Behoben
- **Spulen in gespeicherten/vorgeladenen Aufnahmen brach ab.** Beim Spulen
  (v. a. ans Ende) meldete Chromium „data source error", die Wiedergabe blieb
  stehen – etwa jedes zweite Mal reproduzierbar. Der Service Worker lud dazu
  bei jedem Sprung die GANZE Datei in den Arbeitsspeicher; jetzt schneidet er
  per Blob aus, ohne Kopie. Zusätzlich setzt der Player nach einem Abriss
  (auch Funkloch, Netzwechsel) die Wiedergabe an derselben Stelle fort,
  höchstens dreimal je Titel.
- **Vollbild-Player auf kleinen Telefonen** (z. B. 320×568): Das Cover
  schob sich unter die Kopfzeile, die Steuerung rutschte aus dem Bild. Die
  Cover-Größe richtet sich jetzt nach der verfügbaren Höhe.
- **Vollbild im Querformat**: Die Steuerung landete unter dem Cover statt
  rechts daneben.
- **Abmelde-Knopf bei Links ohne Passwort** ausgeblendet (dort gibt es
  nichts abzumelden).
- **Seitenmenü auf dem Telefon**: geschlossen nicht mehr per Tab-Taste bzw.
  Bildschirmleser erreichbar.

### Sperrbildschirm und Benachrichtigung
Registriert werden: Wiedergabe, Pause, Stopp, Titel vor/zurück,
**15 s zurück/vor** und **Spulen über den Fortschrittsbalken** (seekto). Die
Position wird bei jedem Sprung sofort gemeldet, damit der Balken stimmt.
- Jede Aktion einzeln abgesichert: Ältere Browser, die eine Aktion nicht
  kennen, werfen beim Registrieren – vorher brach das alle folgenden
  Aktionen und sogar den Titelwechsel ab.
- Positionsangaben werden auf 0…Dauer begrenzt (sonst verwirft der Browser
  sie, und der Balken fehlt).
- „Danach nächster Ordner": Der nächste Ordner wird schon während des
  letzten Titels gesucht, damit es auf dem Sperrbildschirm ohne Pause
  weitergeht.

Hinweis zu iPhone/iPad: iOS zeigt nur EIN Knopfpaar und nimmt dabei
Titel vor/zurück. Gespult wird dort über den Balken; Android zeigt in der
aufgeklappten Benachrichtigung zusätzlich die 15-Sekunden-Knöpfe.

---

## 0.15.2: Versionsanzeige

Die installierte Fassung steht jetzt an drei Stellen:
- **Einstellungen → Verwaltung → Audio Archive:** neben der Überschrift
- **In der App, am Ende jeder Ordnerliste:** dezent „Audio Archive ·
  Version …“ – für alle, auch Gäste über einen Link
- **Zahnrad (Darstellung):** unten im Bereich

Die Nummer kommt aus der `info.xml` der installierten App (über Nextclouds
`IAppManager`). Offline gestartet zeigt die App die Fassung, die gerade
tatsächlich läuft – bei Rückfragen („welche Version hast du?“) genau die
richtige Angabe.

---

## 0.15.3: Kleine Bildschirme

Auf kleinen Telefonen (etwa 340 × 600 Punkte) blieben neben Kopfzeile und
Player-Leiste nur ein bis zwei Einträge der Liste sichtbar.

- **Player-Leiste kompakter:** Die Zeile unter dem Titel (Interpret ·
  Album) ist einzeilig und wird abgekürzt, statt auf bis zu sechs Zeilen
  umzubrechen. Kleinere Knöpfe und Abstände. Die Leiste ist dadurch etwa
  ein Drittel niedriger. Vollständig steht alles weiter im Vollbild-Player.
- **Kopfzeile scrollt mit:** Auf Telefonen bleibt der Titel samt Untertitel nicht
  mehr fest oben stehen, sondern scrollt mit der Liste weg. Der Untertitel bleibt
  vollständig sichtbar, sobald man nach oben scrollt. Mit Ordnerbaum
  scrollt dafür die ganze Seite; das Menü (☰) ist oben erreichbar.
- **Telefon quer:** Steuerung neben dem Titel, Fortschritt darunter – die
  Leiste ist dort nur noch gut halb so hoch.

Tablets und Desktop bleiben unverändert.

---

## 0.15.4: Laufschrift, Kopfzeile bleibt, „App installieren“ wieder da

- **Laufschrift:** Passen Titel oder die Zeile darunter (Interpret · Album)
  nicht in die Leiste, wandert der Text langsam hin und her – mit kurzer
  Pause an Anfang und Ende. Ist auf dem Gerät „Bewegung reduzieren“
  eingeschaltet, wird stattdessen mit „…“ abgekürzt.
- **Kopfzeile bleibt oben stehen** (das Wegscrollen aus 0.15.3 ist wieder
  entfernt). Die kompakte Player-Leiste bleibt.
- **Knopf „App installieren“** erscheint im Browser jetzt immer (außer in
  der installierten App selbst). Bietet der Browser die Installation nicht
  von sich aus an – etwa weil sie schon einmal abgelehnt wurde oder die App
  schon installiert ist, in Firefox und Safari grundsätzlich –, erklärt der
  Knopf, wo die Installation im Browsermenü zu finden ist.

---

## 0.15.5: Knopf „App installieren" wieder wie in 0.15.3

Der immer sichtbare Knopf aus 0.15.4 ist wieder entfernt. Er erscheint wie
zuvor nur, wenn der Browser die Installation selbst anbietet. Laufschrift
und feststehende Kopfzeile aus 0.15.4 bleiben.

---

## 0.15.6: Benachrichtigung auf älteren Android-Geräten

- **Blinkendes Symbol behoben:** Die App meldete dem System alle 5 Sekunden
  die Wiedergabeposition. Android baut die Benachrichtigung dabei jedes Mal
  samt Bild neu auf – auf älteren Geräten blinkte das Symbol. Jetzt wird
  nur noch gemeldet, wenn die Position wirklich abweicht (Sprung, Pause,
  Nachladen).
- **Pause über Kopfhörer:** Reißt die Verbindung während einer Pause ab,
  lädt die App die Aufnahme erst beim nächsten Abspielen neu. Vorher wurde
  sofort neu geladen – dabei verschwand die Benachrichtigung, und die
  Wiedergabe startete ungewollt von selbst weiter.

---

## 0.16.0: App-Symbol in der Leistenfarbe

Das Mikrofon-Symbol hat jetzt überall den Hintergrund in der Farbe der
Player-Leiste – blaue Leiste, blaues Symbol; gelbe Leiste, gelbes Symbol.
Das Mikrofon ist weiß, auf hellen Farben (etwa Gelb oder Weiß) schwarz.

- **Wo:** Symbol der installierten App (Startbildschirm), Browser-Tab,
  Player-Leiste und Vollbild-Player bei Aufnahmen ohne eigenes Cover,
  Benachrichtigung und Sperrbildschirm.
- **Welche Farbe:** die der jeweiligen Ansicht – Freigabe-Link mit seinen
  Farben, Administrator-Link mit den Farben aus der Verwaltung, angemeldete
  Nutzer mit ihrer persönlichen Darstellung. Bei Nextcloud-Gestaltung
  Nextclouds Hauptfarbe.
- **Bereits installierte Apps:** Android übernimmt das neue Symbol erst,
  wenn Chrome das Manifest wieder prüft (meist innerhalb eines Tages,
  manchmal mit Rückfrage). Auf iPhone/iPad bleibt das alte Symbol, bis die
  App neu zum Home-Bildschirm hinzugefügt wird.
- Die Symbole erzeugt der Server aus dem mitgelieferten Bild und hält sie
  im Zwischenspeicher. Dafür wird PHP-GD genutzt (bei Nextcloud ohnehin
  Pflicht); fehlt es, bleibt das blaue Symbol.

---

## 0.16.1: Keine Obergrenze für die Nextcloud-Version

Bisher war in der App Nextcloud 34 als höchste Version eingetragen. Nach
dem Update auf Nextcloud 35 hat Nextcloud die App deshalb abgeschaltet.
Jetzt steht dort 99 – die App lässt sich damit auch nach künftigen
Nextcloud-Updates weiter einschalten. (Ob sie mit einer neuen Version
tatsächlich fehlerfrei läuft, ist damit nicht gesagt; bei Problemen nach
einem Update bitte melden.)

---

## 0.17.0: Vier Gestaltungen

Jeder angemeldete Nutzer wählt über das Zahnrad eine von vier Gestaltungen
(oder „Vorgabe“ = wie vom Administrator eingestellt). Die Auswahl zeigt
kleine Vorschaubilder, und die Seite übernimmt jede Änderung sofort als
Vorschau; gespeichert wird mit „Übernehmen“, „Schließen“ nimmt die
Vorschau zurück.

- **Klassisch** – Nextcloud-Design (bisher „Nextcloud“): Farben,
  Hintergrund und Schrift von Nextcloud, Hell/Dunkel automatisch.
- **Modern** – der runde Glas-Look (bisher „Eigene Gestaltung“). Farben
  frei wählbar, dazu zehn fertige Farbvorlagen.
- **Vom Administrator** – eine vom Administrator frei gestaltete Fassung.
  Nur wählbar, wenn er sie unter Einstellungen → Verwaltung → Audio Archive
  anbietet. Ändert er sie, sehen alle, die sie gewählt haben, die Änderung
  automatisch. Nimmt er das Angebot zurück, gilt dort wieder die Vorgabe.
- **Benutzerdefiniert** – alles selbst einstellen:
  - Farben: Akzent, Leisten, Listen & Karten, Hintergrund; Schriftfarben
    automatisch (nach Kontrast) oder selbst gewählt
  - Form & Glas: Grundstil (Modern mit Glas oder flach wie Nextcloud),
    Ecken von eckig bis sehr rund, Unschärfe (Blur), Deckkraft von Leisten
    und Listen, Schatten
  - Schrift & Abstände: Schrift für Überschriften und Text (5 Arten),
    Schriftgröße, Abstände (kompakt/normal/großzügig)
  - Hintergrund: Farbverlauf oder einfarbig, Abdunkeln eines Bildes
  - Sieben Vorlagen als Ausgangspunkt (Modern, Flach & klar, Eckig,
    Weich & rund, Dunkel, Papier, Kompakt) und die Farbvorlagen

**Freigaben** (Links und interne) haben dieselben vier Gestaltungen samt
Editor, „Vorgabe“ bedeutet dort wie bisher: Administrator bzw. beim
Empfänger dessen eigene Ansicht.

**Verwaltung:** Die Vorgabe für alle ist jetzt Klassisch, Modern oder
„Vom Administrator“ (auch für den Administrator-Link). Darunter die
Farbvorlagen für Modern und der Editor für die eigene Gestaltung mit
Schalter „Anbieten“.

**Bestehende Einstellungen bleiben gültig:** „Eigene Gestaltung“ ist jetzt
„Modern“, „Nextcloud“ ist „Klassisch“ – mit denselben Farben und Bildern.

**Einspielen:** PHP-Dateien geändert → Container neu starten. Keine
Datenbankänderung.

---

## 0.17.1: Lesbare laufende Zeile

Die Zeile des gerade laufenden Titels war zu 55 % durchsichtig. Über
einem dunklen Hintergrundbild wurde sie grau, und der Titel in der
Akzentfarbe war kaum zu lesen. Jetzt:

- Die Zeile ist fast deckend und nur leicht in der Akzentfarbe getönt.
- Der Titel steht fett in der normalen Schriftfarbe.
- Wo die Akzentfarbe als Schrift erscheint („Läuft gerade“, Pfad,
  Knöpfe), wird sie so weit abgedunkelt (auf dunklen Flächen aufgehellt),
  dass sie mindestens 4,5:1 Kontrast hat. Helle Akzente wie Rosé oder Sonne
  bleiben dadurch lesbar.

Nur CSS und JavaScript geändert – kein Neustart des Containers nötig.

---

## 0.17.2: Cover aus dem Ordner – jeder Dateiname

- Hat eine MP3 kein eingebettetes Bild, nimmt die App **jedes Bild im
  Ordner der Aufnahme** (JPG, PNG, WebP, GIF) – der Dateiname ist egal.
- Gesucht wird **nur in diesem Ordner**, nicht mehr in übergeordneten.
  Ohne Bild erscheint das App-Symbol.
- Mehrere Bilder im Ordner: Ein Name wie `cover`, `folder`, `front`,
  `album` oder `albumart` hat Vorrang, sonst das alphabetisch erste
  (`Bild2` vor `Bild10`). Versteckte Dateien (Punkt am Anfang, etwa die
  `._…`-Dateien von macOS) zählen nicht.

PHP geändert → nach dem Einspielen den Container neu starten.

---

## 0.17.3: Vollbild im Querformat nicht mehr gequetscht

Auf Telefonen im Querformat nahm das Cover 40 % der Breite, daneben stand
eine feste 460 Punkte breite Spalte. Auf schmalen Geräten blieb dafür zu
wenig Platz: Knöpfe und Fortschritt klebten am Rand.

- Das Cover richtet sich jetzt nach der Höhe und hört bei 32 % der Breite
  (höchstens 320 Punkte) auf.
- Der Abstand zwischen Cover und Bedienung wächst mit der Breite mit,
  links und rechts bleibt immer Rand.
- Die fünf Steuerknöpfe sind im Querformat etwas kleiner, ihre Abstände
  wachsen mit der Breite.
- „Angaben“ und „Wiederholen“ dürfen umbrechen; unter 720 Punkten Breite
  bleiben nur ihre Symbole.
- Titel und Zusatzzeile etwas kleiner, damit alles ohne Scrollen passt.

Nur CSS geändert – kein Neustart des Containers nötig.

---

## 0.17.4: Vollbild im Hochformat – runde Knöpfe, größeres Cover

Zwei Fehler auf schmalen Telefonen im Hochformat:

- **Aus den runden Knöpfen wurden Ovale.** Die fünf Steuerknöpfe brauchen
  mit festen Maßen rund 390 Punkte Breite. Auf einem 360er Telefon wurden
  sie als Flex-Kinder in der Breite gestaucht, die Höhe blieb. Jetzt
  schrumpfen sie nicht mehr (`flex: none`); Größe und Abstände wachsen
  stattdessen mit der Bildschirmbreite mit.
- **Das Cover war zu klein.** Seine Größe wurde aus „Höhe minus 420
  Punkte“ geschätzt. Unter der Steuerung blieb dadurch Platz frei,
  während das Bild klein blieb (220 statt 276 Punkte auf einem 360×640er
  Telefon). Jetzt ist es so groß, wie die Breite erlaubt, und schrumpft
  nur so weit, wie es die Höhe verlangt.

Geprüft bei 320×568, 360×640, 360×740, 390×844, 412×915 und im Querformat
bei 667×375, 740×420, 780×360, 844×390: alle Knöpfe rund, nichts läuft
über den Rand, nichts wird abgeschnitten.

Nur CSS geändert – kein Neustart des Containers nötig.

---

## 0.18.0: Vollbild nach unten wegwischen

Der Vollbild-Player lässt sich jetzt schließen, indem man ihn nach unten
wischt – wie in üblichen Musik-Apps. Die Leiste folgt dabei dem Finger und
rutscht unten aus dem Bild.

- Ein kurzes Stück reicht bei einem schnellen Schubs; langsam gezogen
  schließt es ab etwa 120 Punkten, sonst federt es zurück.
- Sind die Angaben aufgeklappt, scrollt zuerst der Inhalt. Die Geste
  beginnt erst, wenn oben nichts mehr zu scrollen ist.
- Auf Schiebereglern und Knöpfen startet die Geste nicht – der
  Fortschritt lässt sich also weiterhin ziehen.
- Am Rechner schließt auch das Mausrad nach unten (nur ohne aufgeklappte
  Angaben).
- Die bisherigen Wege bleiben: Pfeil oben links, Zurück-Geste, Escape.

Nur CSS und JavaScript geändert – kein Neustart des Containers nötig.

---

## 0.18.1: Vollbild liegt sichtbar über der Liste

Der Vollbild-Player sieht jetzt aus wie eine Karte, die über die Liste
gelegt ist – nicht mehr wie eine neue Seite.

- Er fährt von unten herein und lässt oben einen Streifen frei. Dort
  bleibt die Liste sichtbar, abgedunkelt und leicht unscharf.
- Oben runde Ecken, Schatten und ein Griff mit Pfeil zum Schließen. In der
  flachen Gestaltung („Klassisch“) ein doppelter Pfeil nach unten.
- Beim Wegwischen rutscht die Karte nach unten, und der abgedunkelte
  Hintergrund wird dabei wieder hell – die Liste kommt sichtbar zurück.
- Der Griff bleibt beim Lesen der Angaben oben stehen.

Nebenbei behoben: Bei aufgeklappten Angaben rutschten „Angaben“ und
„Wiederholen“ über den Fortschritt. Und das Cover richtet sich jetzt auch
nach dem Streifen oben, damit im Vollbild nichts mehr gescrollt werden
muss.

Nur CSS, JavaScript und eine Vorlage geändert – kein Neustart des
Containers nötig.

---

## 0.18.2: Wegwischen fühlt sich richtig an

Die Wisch-Geste zum Schließen des Vollbild-Players ist überarbeitet.

- **Kein Aufblitzen mehr beim Schließen.** Die Karte sprang kurz zurück
  nach oben, bevor sie verschwand. Jetzt gleitet sie in einem Zug nach
  unten hinaus, und die kleine Leiste blendet danach sanft ein.
- **Es zählt die Richtung beim Loslassen.** Wer runterzieht, es sich
  anders überlegt und wieder ein Stück hochzieht, behält den Player offen.
  Ein schneller Schubs nach unten schließt dagegen schon auf kurzer
  Strecke; langsames Ziehen braucht etwa ein Fünftel der Kartenhöhe.
- **Zurückfedern ist weich** statt eines harten Sprungs.
- **Mehr Stellen zum Anfassen:** Die Geste startet jetzt auch auf dem
  Cover, auf dem Griff und im abgedunkelten Streifen oben. Tippen auf den
  Streifen schließt ebenfalls. Nur auf dem Fortschrittsregler startet sie
  nicht.
- Nach unten ziehen löst im Browser kein Neuladen der Seite (Android) und
  kein Gummiband-Scrollen (iPhone) mehr aus.
- Schließen über Griff, Zurück-Geste oder Escape fährt die Karte ebenfalls
  nach unten hinaus. Doppeltes Tippen auf den Griff springt nicht mehr
  zwei Schritte im Verlauf zurück.

Nur CSS und JavaScript geändert – kein Neustart des Containers nötig.

---

## 0.18.3: Wiedergabe hält Aussetzer aus, Offline mit Lücken

**Nachladen und Aussetzer**

- Reißt die Verbindung ab oder schafft das Gerät das Nachladen nicht,
  versucht der Player jetzt bis zu sechsmal mit wachsendem Abstand (zusammen
  etwa eine halbe Minute), an derselben Stelle weiterzuspielen. Ohne
  Verbindung wartet er, bis sie zurückkommt.
- Der Zähler gilt nicht mehr für den ganzen Titel: Nach zehn Sekunden
  sauberer Wiedergabe beginnt er von vorn.
- Hängt die Verbindung still (kein Fehler, aber keine Daten), lädt der
  Player nach 20 Sekunden selbst neu. Vorher blieb er für immer stehen.
- Scheitert es endgültig, steht „Wiedergabe fehlgeschlagen – Zum erneuten
  Versuch auf Play tippen“. Play lädt dann neu, an derselben Stelle.
  Vorher half Play nicht.
- Wer schnell zwischen Titeln wechselt, bekommt keinen alten Titel mehr
  zurück: Geplante Wiederholungen des vorherigen Titels werden verworfen.

**Offline mit teilweise gespeicherten Ordnern**

- Ohne Verbindung sind nicht gespeicherte Titel abgeblendet und mit „Nicht
  offline gespeichert“ beschriftet. Der Player überspringt sie.
- Wichtigster Fund: Aufnahmen mit Leerzeichen im Namen kamen nie aus dem
  Offline-Speicher – die Adresse wurde beim Nachschlagen umgeschrieben
  (%20 → +). Behoben; das betraf auch das Vorausladen.
- Das Verzeichnis der gespeicherten Aufnahmen wird jetzt nach jeder Datei
  fortgeschrieben, die Ordnerliste zuerst abgelegt. Bricht das Speichern
  ab, sind die fertigen Aufnahmen trotzdem offline da.

Nur JavaScript und CSS geändert – kein Neustart des Containers nötig.

---

## 0.19.0: Ordneranzeige leichter verständlich

- Links neben dem Pfad steht jetzt ein runder Pfeil „Zurück“. Er führt eine
  Ebene nach oben und nennt beim Darauf-Zeigen, wohin („Zurück zu 2026“).
- Der Pfad selbst bleibt klein, ist aber besser lesbar: etwas größere Schrift,
  „›“ statt „/“, und der aktuelle Ordner steht kräftig statt blass.
- Ordnernamen werden lesbarer angezeigt (nur die Anzeige, die Ordner bleiben
  unverändert): Unterstriche werden Leerzeichen, „2026_08“ wird „August 2026“,
  „2026-09-21 Konzert Abend“ wird „Konzert Abend,
  21. September 2026“. Das gilt für Liste, Pfad, Ordnerbaum und die Angabe im
  Player.

Nur JavaScript und CSS geändert – kein Neustart des Containers nötig.

---

## 0.19.1: Vollbild-Karte erscheint sanfter

- Die Karte fährt langsamer und weicher herein (0,5 statt 0,26 Sekunden,
  schnell los und lang auslaufend – wie bei den Karten auf dem iPhone).
- Der Inhalt folgt leicht versetzt: erst wächst das Cover heraus, dann
  blenden Titel, Knöpfe, Fortschritt und Steuerung nacheinander von unten ein.
- Die abgedunkelte Fläche dahinter blendet gemächlicher ein.
- Schließen über Griff oder Zurück-Geste läuft ebenfalls etwas ruhiger;
  nach dem Wegwischen bleibt der Schwung des Fingers erhalten.
- Mit „Bewegung reduzieren“ im System erscheint alles ohne Animation.

Nur JavaScript und CSS geändert – kein Neustart des Containers nötig.

---

## 0.19.2: Version auf dem Anmelde-Bildschirm

Die Versionsangabe („Audio Archive · Version …“) steht jetzt auch auf dem
Anmelde-Bildschirm, dezent unter dem Anmeldefeld.

Nur JavaScript und CSS geändert – kein Neustart des Containers nötig.

---

## 0.19.3: Autoradio – Spulen hört wieder auf, Länge sofort da

Gefunden an einem Werksradio im VW T5 (Android 9, installierte App).

- **Spulen am Radio:** Ein kurzer Druck auf „Spulen“ ließ die Wiedergabe
  bisher immer weiter um 15 Sekunden springen, bis zum nächsten Titel. Das
  Radio wiederholt den Befehl von sich aus. Jetzt zählen schnell
  aufeinanderfolgende Spulbefehle als ein Druck: höchstens zwei Sprünge
  (30 Sekunden), danach wird ignoriert, bis das Radio aufhört. Play, Pause,
  Titelwechsel oder der Fortschrittsbalken beenden das sofort. Die Knöpfe in
  der App selbst sind davon nicht betroffen.
- **Gesamtlänge im Radio:** Die Länge wird jetzt schon beim Titelwechsel
  zusammen mit dem Titel gemeldet, nicht erst nach dem Laden der Datei.
  Vorher zeigte das Radio sie deshalb nur manchmal an.
- **Fehlersuche:** Unter „Angaben“ (i) im großen Player steht jetzt die
  Gruppe „Befehle von außen“ – die letzten Befehle von Radio,
  Sperrbildschirm oder Kopfhörer mit Uhrzeit, Abstand und ob sie gebremst
  wurden. Sie erscheint nur, wenn in den letzten zwei Stunden solche
  Befehle kamen.

Nur JavaScript geändert – kein Neustart des Containers nötig.

---

## 0.20.0: Neues Logo, Cover-Ersatz je Freigabe wählbar

- **Neues App-Symbol:** Archiv-Liste mit Lautsprecher statt Mikrofon – auf
  dem Startbildschirm, im Browser-Tab, in der Benachrichtigung und in der
  Nextcloud-Kopfleiste. Weiterhin in der Farbe der Player-Leiste.
- **Vollbild ohne Cover:** Statt des abgerundeten App-Symbols (dessen
  Schatten an den Ecken abgeschnitten wurde) füllt jetzt eine Fläche in der
  Leistenfarbe das Cover-Feld randlos, das Zeichen steht kleiner in der
  Mitte. Gilt auch für die kleine Player-Leiste und den Sperrbildschirm.
- **Je Freigabe wählbar** („Aussehen“ → „Bild bei Aufnahmen ohne Cover“):
  Standard, Lautsprecher rund, Box, Kopfhörer, Abspielen, Mikrofon oder ein
  eigenes Bild (PNG/JPEG/WebP, am besten quadratisch). Ohne Wahl gilt das
  Standard-Zeichen in der Farbe der Freigabe. Eigene Ansicht und
  Administrator-Link zeigen immer das Standard-Zeichen.

PHP, Routen und `info.xml` geändert – nach dem Einspielen Container neu
starten; keine Datenbankänderung. Bereits installierte Apps übernehmen das
neue Symbol auf Android beim nächsten Manifest-Abgleich, auf dem iPhone erst
nach erneutem Hinzufügen zum Home-Bildschirm.

---

## 0.20.1: Installierbar auf Nextcloud 31 und 32

Seit 0.13.0 brach die Installation auf Nextcloud bis einschließlich 32 mit
„Primary index name on "oc_audioarchive_share_members" is too long“ ab.
Diese Versionen verlangen bei Tabellen mit Standard-Primärschlüssel einen
Namen unter 23 Zeichen. Der Primärschlüssel der Tabelle hat jetzt einen
eigenen, kurzen Namen.

Bestehende Installationen (ab Nextcloud 33) betrifft das nicht, dort ist
dieser Schritt bereits gelaufen. Geprüft: Neuinstallation auf Nextcloud
31.0.14 und 32.0.15 mit allen Funktionen von 0.20.0, Update 0.20.0 → 0.20.1
auf Nextcloud 35.0.1.

Nur eine PHP-Datei (Migration) geändert – nach dem Einspielen Container neu
starten.

---

## 0.21.0: Weitere Audioformate

Bisher zeigte und spielte die App nur MP3. Jetzt erscheinen alle Formate,
die verbreitete Browser selbst abspielen – **ohne Umwandlung** auf dem
Server:

| Endung | Format | Hinweis |
|---|---|---|
| mp3 | MP3 | überall |
| m4a, m4b, aac | AAC, Apple Lossless (ALAC) | AAC überall; ALAC je nach Browser |
| ogg, oga, opus | Ogg Vorbis, Opus | ältere Safari-Versionen nicht |
| flac | FLAC | überall |
| wav | WAV | überall |
| webm, weba | WebM | ältere Safari-Versionen nicht |
| aif, aiff, aifc, caf | AIFF, Core Audio | nur Safari (iPhone, iPad, Mac) |

Ob ein Gerät ein Format kann, entscheidet dessen Browser; die App fragt ihn
(siehe unten) und rät nicht.

- **Angaben wie bei MP3:** Länge, Titel/Künstler/Album, Info-Ansicht
  (Jahr, Genre, Titelnummer, Kommentar, Qualität, Format) und eingebettetes
  Cover werden auch aus FLAC, Ogg/Opus, M4A, WAV und AIFF gelesen – ohne
  zusätzliche Programme auf dem Server (neu: `lib/Service/AudioProbe.php`).
  Bei WebM, AAC-Rohstrom und CAF ermittelt der Browser die Länge erst beim
  Abspielen; die Liste zeigt dort die Dateigröße.
- **Formatkürzel in der Liste:** Bei allen Dateien außer MP3 steht rechts
  klein das Format („0:42 · FLAC“). So sind gleichnamige Aufnahmen in
  verschiedenen Formaten unterscheidbar.
- **Was der Browser nicht kann**, steht blass in der Liste („nur Safari“
  bzw. „hier nicht abspielbar“). Antippen zeigt einen Hinweis statt endloser
  Ladeversuche; eine laufende Wiedergabe wird dabei nicht unterbrochen. Beim
  Weiterspielen (Titelende, Weiter-Taste, nächster Ordner) werden solche
  Titel übersprungen.
- **Erst beim Abspielen erkannt:** Sagt der Browser „vielleicht“, kann die
  Datei aber nicht dekodieren (z. B. Apple Lossless in Firefox), prüft die
  App kurz, ob der Server die Datei liefert. Wenn ja, liegt es am Format:
  Hinweis bzw. Sprung zum nächsten Titel statt 30 Sekunden Nachladen.
- Der Server liefert jede Datei mit dem passenden Medientyp aus
  (`audio/flac`, `audio/ogg` …), Spulen per Byte-Bereich wie bei MP3.
- Vorausladen plant mit der echten Länge statt mit einer MP3-Schätzung –
  große WAV/FLAC-Dateien sprengen den Puffer nicht mehr.

PHP, JavaScript, CSS und `info.xml` geändert – nach dem Einspielen
Container neu starten; keine Datenbankänderung.

---

## 0.21.1: Kleine Verbesserungen aus den Rückmeldungen

- **Künstler und Album je in einer Zeile** im Vollbild-Player über der
  Steuerung; leere Angaben entfallen. Die kleine Leiste bleibt einzeilig.
- **„Zu Nextcloud“** unten in der Seitenleiste (angemeldet): führt zur
  Startseite der Nextcloud – wichtig vor allem in der installierten App.
- **„App installieren“ auf geteilten Links immer sichtbar.** Bietet der
  Browser die Installation selbst an, startet sie direkt; sonst erscheint
  eine kurze Anleitung passend zum Gerät (iPhone/iPad, Android, Mac-Safari,
  Rechner). Innerhalb von Nextcloud unverändert.
- **Bild bei Aufnahmen ohne Cover für den Administrator-Link:** Verwaltung
  → Öffentlicher Zugang. Gleiche Auswahl wie bei Freigaben (sechs Zeichen
  oder eigenes Bild, PNG/JPEG/WebP). Eigene Ansichten zeigen weiterhin das
  Standard-Zeichen.
- **Mehr Bildformate als Ordner-Cover:** zusätzlich BMP, AVIF, TIFF, HEIC/
  HEIF, JPEG XL, JPEG 2000, PSD und TGA. Sie werden für den Player in WebP
  umgewandelt (höchstens 1200 px, abgelegt im AppData-Bereich, die Datei des
  Nutzers bleibt unverändert). BMP und AVIF gehen mit GD, die übrigen
  brauchen **Imagick** auf dem Server (im offiziellen Nextcloud-Abbild
  enthalten). Ohne passendes Werkzeug wird das Bild übergangen. Liegen
  `cover.tif` und `cover.jpg` nebeneinander, gewinnt das JPEG.
- **Tasten an Auto, Kopfhörer und Sperrbildschirm:** Im Vollbild unter
  „Angaben“ wählbar, was Weiter/Zurück tun – „Titel wechseln“ (Vorgabe,
  wie bisher) oder „15 s spulen“ (mit derselben Bremse gegen Dauerspulen).
  Gilt je Gerät.

PHP, Routen, JavaScript, CSS und `info.xml` geändert – nach dem Einspielen
Container neu starten; keine Datenbankänderung.

---

## 0.22.0: Sortieren, Datum und Suche

- **Datum in der Liste:** Aufnahmen zeigen klein, wann sie zuletzt geändert
  wurden („0:42 · 21.09.2026“), Ordner, wann sie hinzugekommen sind – neben
  der Anzahl („21.09.2026 · 46 Aufnahmen“). „Hinzugekommen“ ist die
  Erstellzeit, sonst der Zeitpunkt des Hochladens, sonst die Änderungszeit
  des Ordners (sie steigt, sobald darin etwas hinzukommt).
- **Sortieren:** Knopf rechts neben dem Pfad schaltet zwischen „Name“ und
  „Neueste“ um; Ordner stehen immer vor den Aufnahmen, die Abspielreihenfolge
  folgt der Anzeige. Die Vorgabe stellt der Administrator ein (Verwaltung →
  Funktionen → „Sortierung der Liste“), jedes Gerät merkt sich die Wahl
  seines Hörers.
- **Suche:** Feld über der Liste. Gesucht wird in der ganzen geöffneten
  Quelle (gemeinsame Aufnahmen, eigene Dateien oder Freigabe) – in Ordner-
  und Dateinamen, im Ordnerpfad sowie in Titel, Künstler und Album. Mehrere
  Wörter müssen alle vorkommen („vortrag 2024“), Groß-/Kleinschreibung und
  Akzente egal. Treffer zeigen darunter ihren Ordner; Antippen spielt die
  Trefferliste ab bzw. öffnet den Ordner. Ohne Verbindung wird in den offline
  gespeicherten Ordnern gesucht.
- Grenzen der Suche (Schutz des Servers): höchstens 150 Treffer je Art,
  4000 Ordner und 40 000 Dateien; Angaben aus noch nie gelesenen Dateien
  werden höchstens 4 Sekunden lang nachgelesen. Wird eine Grenze erreicht,
  sagt die Anzeige „nicht alles durchsucht“.

PHP, Routen, JavaScript, CSS und `info.xml` geändert – nach dem Einspielen
Container neu starten; keine Datenbankänderung.

---

## 0.23.0: Weiterhören, angemeldet bleiben, Name des gemeinsamen Ordners

- **Weiterhören:** Jedes Gerät merkt sich je Aufnahme die Stelle, an der
  zuletzt gehört wurde (ab 15 Sekunden; die letzten 20 Sekunden gelten als
  zu Ende gehört). Beim erneuten Abspielen geht es dort weiter – ein kurzer
  Hinweis sagt, ab wo. Beim Öffnen der App steht die zuletzt gehörte
  Aufnahme oben als Karte „Weiterhören“ (mit Stelle, Länge und Ordner);
  Antippen öffnet den Ordner und spielt ab der Stelle, das × blendet die
  Karte aus. Getrennt je Link, gespeichert nur im Browser.
- **Angemeldet bleiben:** Verwaltung → Öffentlicher Zugang → „Angemeldet
  bleiben“ (aus, 7, 15, 30 oder 90 Tage). Gilt für den Link der Verwaltung
  und alle Freigaben mit Passwort. Nach richtiger Passworteingabe merkt sich
  der Browser den Zugang in einem signierten Cookie (`aa_remember`, nur für
  den Server lesbar). Ändert sich das Passwort oder der Link, ist der
  gemerkte Zugang sofort ungültig; „Abmelden“ löscht ihn. Ohne Verbindung
  überspringt die App die Passwortabfrage bis zum selben Datum.
- **Name des gemeinsamen Ordners** (Verwaltung → Darstellung): ersetzt
  „Gemeinsame Aufnahmen“ bzw. auf dem Link „Aufnahmen“ im Pfad und in der
  Seitenleiste. Leer = wie bisher.

PHP, JavaScript, CSS und `info.xml` geändert – nach dem Einspielen
Container neu starten; keine Datenbankänderung.

---

## 0.24.0: Favoriten

- **Stern in jeder Zeile** (Ordner und Aufnahmen) markiert Favoriten.
  Angemeldete Nutzer setzen damit echte **Nextcloud-Favoriten** – der Stern
  erscheint auch in „Dateien“, soweit die Datei in ihren eigenen Dateien
  liegt. Hörer über einen Link (ohne Konto) speichern ihre Favoriten auf dem
  Gerät, getrennt je Link.
- **Ansicht „Favoriten“** ganz unten: als letzter Eintrag der obersten Ebene
  und – angemeldet – unten im Ordnerbaum. Sie zeigt Ordner und Aufnahmen aus
  allen Quellen mit ihrem Ordner darunter; Antippen spielt bzw. öffnet,
  der Stern entfernt. Ordner ohne Aufnahmen (andere Nextcloud-Favoriten wie
  „Dokumente“) werden nicht gezeigt; dieselbe Datei aus zwei Quellen nur
  einmal. Ohne Verbindung zeigt sie den Stand der letzten Anzeige
  (angemeldet) bzw. die Gerätefavoriten.
- **Abschaltbar:** Verwaltung → Funktionen → „Favoriten (Stern) anbieten“
  (Vorgabe: an); jeder angemeldete Nutzer zusätzlich für sich unter
  „Darstellung“ → Funktionen.
- Neue Routen `api/favorites` (lesen) und `api/favorite` (setzen).

PHP, Routen, JavaScript, CSS und `info.xml` geändert – nach dem Einspielen
Container neu starten; keine Datenbankänderung (Favoriten liegen in
Nextclouds eigener Tabelle).

---

## 0.25.0: Ordner als ZIP herunterladen

- In jedem **Unterordner** erscheint „Ordner herunterladen (ZIP)“ – mit
  allen Aufnahmen, Unterordnern und Ordnerbildern (keine versteckten
  Dateien). Die **oberste Ebene** einer Quelle bzw. Freigabe lässt sich nie
  als Ganzes herunterladen.
- **Schalter:** Verwaltung → Funktionen → „Unterordner als ZIP herunterladen
  erlauben“ (Vorgabe: aus; gilt für den Link der Verwaltung und die
  gemeinsamen Aufnahmen angemeldeter Nutzer). Jede Freigabe hat dafür einen
  eigenen Schalter (Funktionen). Die eigenen Dateien eines Nutzers gehen
  immer.
- Gepackt wird beim Herunterladen ohne Zwischendatei (Nextclouds
  `ZipResponse`); höchstens 800 Dateien je ZIP – bei mehr erscheint der
  Hinweis, einen Unterordner zu wählen. Ohne Verbindung kein Knopf.
- Neue Route `api/zip`.

PHP, Routen, JavaScript, CSS und `info.xml` geändert – nach dem Einspielen
Container neu starten; keine Datenbankänderung.


---

## 0.25.1: Suche zuverlässiger, deutsche Texte durchgesehen

- **Suche (Vikunja #32):** Gelesene Angaben der Aufnahmen (Dauer, Titel,
  Künstler, Album) liegen jetzt **dauerhaft** in einer eigenen Tabelle
  `audioarchive_meta` – nicht mehr nur im Zwischenspeicher (Redis/APCu), der
  nach einem Neustart leer ist oder ganz fehlen kann. Dann fand die Suche
  innerhalb ihrer Lesezeit nicht alles und meldete fälschlich „Nichts
  gefunden“.
- Liest der Server noch, fragt die Suche selbst nach und zeigt die Treffer
  schon an („… Treffer bisher – die Angaben der Aufnahmen werden noch
  gelesen“). Ist nichts gefunden, aber nicht alles durchsucht, steht das da.
- Schlägt die Suche fehl (z. B. Fehler 404, wenn nach dem Einspielen der
  Container nicht neu gestartet wurde), erscheint eine Fehlermeldung statt
  „Nichts gefunden“.
- Im Zwischenspeicher bleiben die Angaben nur noch einen Tag (vorher 30) –
  dauerhaft liegen sie in der Tabelle.
- **Texte (Vikunja #37):** Anführungszeichen vereinheitlicht („…“),
  „Lade …“/„Speichere …“ ausformuliert, gemeindespezifische Beispiele
  (Platzhalter, Vorschau im Gestaltungs-Editor) durch allgemeine ersetzt.

PHP, JavaScript, Vorlagen und `info.xml` geändert; **neue Tabelle**
`audioarchive_meta` (wird beim Aktualisieren automatisch angelegt, bestehende
Daten bleiben unverändert). Nach dem Einspielen Container neu starten.

---

## 0.26.0: Weiterteilen, Empfänger erkennbar, persönliche Einstellungen

Erster Teil von Vikunja #8 (mehrere Quellordner und Links).

- **Schalter „Empfänger dürfen weiterteilen“** bei Freigaben an Personen und
  Gruppen (Vorgabe: aus). Ist er an, sehen die Empfänger in „Mit mir
  geteilt“ den Teilen-Knopf und können den Ordner (oder Unterordner) an
  Personen, Gruppen oder per Link weitergeben. Wer über einen Link zuhört,
  hat kein Konto und kann **nie** weiterteilen.
- Weitergeteilte Freigaben merken sich ihren Ursprung (`viaShare` in den
  Einstellungen der Freigabe). Sie gelten nur, solange die Ursprungsfreigabe
  besteht, gültig ist, Weiterteilen erlaubt und der Weitergebende dort noch
  Empfänger ist – sonst sind sie sofort ungültig (Link: „nicht gefunden“).
  Höchstens fünf Stufen.
- **Empfänger erkennbar:** in der App, in der Verwaltung und in den
  persönlichen Einstellungen steht „👤 Name“ bzw. „👥 Gruppe Name“ bzw.
  „🔗 Link“, dazu „dürfen weiterteilen“ und ggf. „weitergeteilt aus einer
  Freigabe von …“.
- **Einstellungen → Persönlich → Audio Archive:** Liste der eigenen
  Freigaben mit Empfängern und Ablauf; Link kopieren, löschen, „In der App
  bearbeiten“ (öffnet die App direkt im Formular dieser Freigabe,
  `#share=<id>`), „+ Neue Freigabe in der App“.

PHP, JavaScript, CSS, Vorlagen und `info.xml` geändert (neuer Abschnitt in
den persönlichen Einstellungen). Keine Datenbankänderung – die neuen Angaben
liegen in den vorhandenen Einstellungen der Freigabe. Nach dem Einspielen
Container neu starten.

---

## 0.27.0: Nicht abspielbare Formate in MP3 umwandeln

Vikunja #34 („beim Abspielen umwandeln, wenn das geht“).

- Neuer Schalter in der Verwaltung → Funktionen: **„Nicht abspielbare
  Formate beim Abspielen in MP3 umwandeln“** (Vorgabe: aus). Nur wählbar,
  wenn auf dem Server **ffmpeg** gefunden wird (Nextclouds
  `preview_ffmpeg_path`, sonst `/usr/bin`, `/usr/local/bin`, …); darunter
  steht, ob ffmpeg vorhanden ist.
- Kann ein Gerät ein Format nicht (z. B. AIFF außerhalb von Safari, ALAC in
  Chrome), steht der Titel nicht mehr blass in der Liste. Beim Abspielen
  startet der Server ffmpeg im Hintergrund, der Player zeigt „… wird für
  dieses Gerät in MP3 umgewandelt“ und spielt die fertige MP3 ab – mit
  Spulen. Die Originaldatei bleibt unverändert.
- Die umgewandelten Fassungen liegen im Temp-Verzeichnis von Nextcloud
  (`audioarchive-mp3-<instanz>`), gültig solange die Datei unverändert ist;
  höchstens 2 GB, die am längsten nicht gehörten werden entfernt.
- Neue Route `api/transcode`; `api/stream` nimmt `mp3=1` (nur Abspielen,
  kein Herunterladen).

PHP, Routen, JavaScript, Vorlage und `info.xml` geändert; keine
Datenbankänderung. Nach dem Einspielen Container neu starten.

---

## 0.28.0: Suche im geöffneten Ordner, Wiederholen-Vorgabe, Ordnernamen am Telefon

Vikunja #32, #2, #41.

- **Suche (#32):** Gesucht wird jetzt im **geöffneten Ordner samt
  Unterordnern**; ganz oben weiterhin überall. Das Suchfeld nennt den
  Ordner („In „Vorträge 2024“ suchen …“), die Trefferzeile ebenso.
  Verglichen wird nur der Pfad unterhalb des geöffneten Ordners – sonst
  passte dessen Name auf jede Aufnahme darin. Neu in der Verwaltung →
  Funktionen: **„Suchbereich“** (Geöffneter Ordner mit Unterordnern /
  Alles). Ohne Verbindung gilt derselbe Bereich für die offline
  gespeicherten Ordner. `api/search` nimmt dafür `path`.
- **Wiederholen-Vorgabe (#2):** Neu in der Verwaltung → Funktionen:
  **„Wiederholen (Vorgabe)“**, Vorgabe „Danach nächster Ordner“. Für einen
  Link lässt sich das im Link-Formular anders einstellen, angemeldete Nutzer
  können es unter „Darstellung“ für sich ändern (jeweils „Vorgabe der
  Verwaltung“ = übernehmen). Tippt jemand selbst auf den Knopf, merkt sich
  das Gerät die Wahl – zusammen mit der Vorgabe, die dabei galt. Ändert sich
  die Vorgabe, gilt wieder die neue. Gespeicherte Werte aus 0.27.0 und älter
  werden deshalb einmalig durch die Vorgabe ersetzt.
- **Schmale Bildschirme (#41):** Unter 640 px steht „Datum · Anzahl“ in einer
  zweiten Zeile unter dem Namen, der Name hat die volle Breite. Breite
  Bildschirme unverändert.

PHP, JavaScript, CSS, Vorlagen und `info.xml` geändert; keine
Datenbankänderung (die Link-Einstellung steckt in den vorhandenen
Einstellungen der Freigabe). Nach dem Einspielen Container neu starten.

---

## 0.29.0: Kommentare zu Aufnahmen

Vikunja #5.

- Neuer Knopf **„Kommentare“** im großen Player (neben „Angaben“). Dort
  schreibt man Anmerkungen, Änderungswünsche oder Fehler zur laufenden
  Aufnahme, optional mit **1–5 Sternen**. Man sieht nur seine eigenen
  Kommentare (mit Datum) und kann sie wieder löschen.
- Es sind **echte Nextcloud-Dateikommentare** (`ICommentsManager`,
  objectType `files`): Wer die Datei in „Dateien“ sieht, liest sie in der
  Seitenleiste unter „Kommentare“ und kann dort antworten. Sterne und – bei
  Gästen – der Name stehen vorn im Text, zusätzlich in den Metadaten.
- **Gäste über einen Link** geben ihren Namen an (merkt sich das Gerät).
  Erkannt werden sie über eine zufällige Kennung je Gerät (gespeichert nur
  als Hash, actorType `audioarchive_guest`).
- **Schalter:** Verwaltung → Funktionen „Kommentare zu Aufnahmen erlauben“
  (Vorgabe: aus), „Bewertung mit 1–5 Sternen“, „Auch Hörer über den
  öffentlichen Link“; **je Link** „Kommentare zu Aufnahmen erlauben“;
  **persönlich** unter „Darstellung“ Kommentare bzw. Sterne für die eigene
  Ansicht ausschalten.
- **Benachrichtigung:** In der Verwaltung lässt sich eine Nextcloud-Gruppe
  wählen (z. B. Tontechnik); ihre Mitglieder bekommen eine Nextcloud-
  Benachrichtigung mit Text und Sternen, Link auf die Datei.
- Schutz: Schreiben nur mit Kopfzeile `X-AudioArchive: 1`, höchstens 20
  Kommentare je 10 Minuten für Gäste (60 angemeldet).
- Im Vollbild dürfen die Knöpfe unter dem Titel jetzt in eine zweite Zeile
  umbrechen.
- Neue Routen `api/comments` (GET/POST) und `api/comments/{id}/delete`.

PHP (neu: `CommentController`, `CommentService`, `Notification\Notifier`),
JavaScript, CSS, Vorlagen und `info.xml` geändert; keine Datenbankänderung
(Nextclouds eigene Kommentar-Tabelle). Nach dem Einspielen Container neu
starten.

## 0.30.0: Anzahl der Aufnahmen nur auf Wunsch

Vikunja #42.

- Neben den Ordnern steht die **Anzahl der Aufnahmen** („12 Aufnahmen“)
  nicht mehr von selbst. Der Administrator kann sie unter Verwaltung →
  Funktionen mit „Anzahl der Aufnahmen bei Ordnern anzeigen“ wieder
  einschalten (Vorgabe: aus). Das Datum neben dem Ordner bleibt.
- Die Einstellung gilt überall: angemeldete Nutzer, öffentlicher Link und
  Freigaben der Nutzer.

PHP, JavaScript, Vorlagen und `info.xml` geändert; keine Datenbankänderung.
Nach dem Einspielen Container neu starten (PHP-Dateien geändert).

## 0.31.0: BETA-Schild und Text über den Aufnahmen getrennt

Vikunja #39.

- Das **„BETA“-Schild** neben dem Titel hat in der Verwaltung einen eigenen
  Schalter („„BETA“-Schild neben dem Titel zeigen“). Es gehört nicht mehr
  mit dem Textstreifen zusammen und lässt sich ausschalten, sobald die App
  öffentlich ist.
- Der bisherige Beta-Hinweis heißt jetzt **„Text über den Aufnahmen“**:
  frei formulierbar, ohne Überschrift. In der Verwaltung ist er die Vorgabe
  („Diesen Text anzeigen“, Text, optional Link). Ist der neue Schalter noch
  nie gespeichert worden, gilt der alte Zustand des Beta-Hinweises weiter.
- **Angemeldete Nutzer** setzen unter Zahnrad → Texte einen eigenen Text
  für ihre Ansicht, **beim Teilen** lässt sich je Link ein eigener Text
  setzen. Leer = Text der Verwaltung (falls eingeschaltet).
- Neu: Einstellungen `notice_enabled` (Verwaltung), Nutzerwert `notice`,
  Freigabe-Einstellung `notice`.

PHP, JavaScript, Vorlagen und `info.xml` geändert; keine Datenbankänderung.
Nach dem Einspielen Container neu starten.

## 0.32.0: Mehrere Quellordner, Gruppen, die teilen dürfen

Vikunja #8 (Teil 2).

- **Weitere Quellen:** Verwaltung → Quellen → „+ Weitere Quelle“ mit Name
  und Ordner. Jede erscheint in der App für alle angemeldeten Nutzer als
  eigener Eintrag oben in der Seitenleiste (Quelle `src:<n>`, gespeichert in
  `extra_sources` als JSON). Funktionen (Offline, Herunterladen, ZIP,
  Kommentare) wie beim gemeinsamen Ordner. Ohne Anmeldung nur über einen
  Link der Quelle.
- **Links einer Quelle** sind gewöhnliche Freigaben (Quelle `src:<n>` in der
  Spalte `source`): eigenes Passwort, Wunschname, „Angemeldet bleiben“,
  eigene Gestaltung und Text, beliebig viele. Angelegt in der App über
  „Diesen Ordner teilen“; „In der App öffnen“ in der Verwaltung springt
  direkt zur Quelle (`#source=src:<n>`). Übersichten zeigen den Namen der
  Quelle (`sourceName`).
- **Wer darf teilen:** Verwaltung → Freigaben durch Nutzer → Gruppen
  ankreuzen (`share_groups`). Keine Gruppe = alle angemeldeten Nutzer. Gilt
  für Links, Freigaben an Personen und das Weiterteilen.
- Entfernte Quelle: verschwindet aus der Seitenleiste, bestehende Links
  bleiben gültig, bis sie gelöscht werden.

PHP, JavaScript, CSS, Vorlagen und `info.xml` geändert; keine
Datenbankänderung. Nach dem Einspielen Container neu starten.

## 0.33.0: Originale Namen, Anzeige in der Liste einstellbar, allgemeine Texte

Vikunja #44, #50, #45, #3.

- **Originale Namen (#44):** Ordner und Aufnahmen heißen in der App genau so
  wie im Ordner. Das lesbare Umschreiben aus 0.19.0 („2026_08“ → „August
  2026“) gibt es nur noch auf Wunsch: Verwaltung → Funktionen → Namen →
  „Ordnernamen lesbar umschreiben“ (`pretty_folder_names`, Vorgabe aus).
  Der Player (auch Sperrbildschirm/Auto) zeigt den Dateinamen; den Titel aus
  den Tags nur mit „Im Player den Titel aus der Datei zeigen“
  (`title_from_tags`, Vorgabe aus) – **ab 0.35.1 umgekehrt, siehe dort**.
- **Anzeige in der Liste (#50):** Einzeln abschaltbar – bei Ordnern Datum
  (`show_folder_date`) und Anzahl (`show_folder_count`, wie bisher aus), bei
  Aufnahmen Länge (`show_track_duration`) und Datum (`show_track_date`).
  Vorgabe wie bisher. Gilt für alle, auch für Links.
- **Sternfarbe (#3):** Der Favoriten-Stern hat jetzt die Akzentfarbe der
  Gestaltung (bei „Klassisch“ die Nextcloud-Farbe). Wählbar: Gestaltung,
  Gelb, Schriftfarbe (`star_color`). Gilt auch für die Sterne der Bewertung.
- **Allgemeine Texte (#45):** Gemeindespezifische Beispiele aus Oberfläche,
  Vorschau des Gestaltungs-Editors, Kommentaren im Code und README entfernt
  (z. B. „Bibelvers“, „Kinderstunden“, „Vortrag am Sonntag“). Nur der alte
  Name des Offline-Speichers der eigenständigen Fassung bleibt, damit dort
  gespeicherte Aufnahmen lesbar bleiben.

PHP, JavaScript, CSS, Vorlagen und `info.xml` geändert; keine
Datenbankänderung. Nach dem Einspielen Container neu starten.

## 0.34.0: Schneller – Angaben im Hintergrund, kleinere Cover

Vikunja #43.

- **Hintergrundaufgabe `ReadMetadata`** (Nextcloud-Cron, alle 15 Minuten,
  je Lauf höchstens 40 s): liest Länge, Titel, Künstler, Album und Cover-
  Kennung aller Aufnahmen im gemeinsamen Ordner und den weiteren Quellen
  vorab in `audioarchive_meta`. Bekannte Dateien kosten eine Abfrage je
  Ordner. Im Test: 1 800 Dateien in 19 s. Voraussetzung: Nextclouds
  Hintergrundaufgaben laufen (am besten „Cron“).
- **Ordner öffnen:** bekannte Angaben aller Aufnahmen mit einer Abfrage
  (`peekMany`) statt je Datei; noch nie gelesene Dateien nur 1,5 s lang,
  danach kommt die Liste sofort mit `pending: true`, und die App holt die
  restlichen Angaben im Hintergrund nach (ohne die Liste neu aufzubauen).
- **Anzahl je Unterordner** wird nur noch gezählt, wenn die Verwaltung sie
  anzeigen lässt (das Zählen ging bei jedem Öffnen durch alle Unterordner).
- **Suche:** schon gelesene Angaben werden für die Treffer weiterverwendet
  statt erneut nachgeschlagen.
- **Cover für Sperrbildschirm, Benachrichtigung und Bluetooth/Auto:**
  `api/cover?…&size=96|256|512` liefert ein quadratisches JPEG
  (`CoverThumbnail`, GD, abgelegt in den App-Daten `cover-thumbs/`). Die
  Media Session meldet diese drei Größen mit Typ statt des Originalbilds
  (oft mehrere MB). Ohne Verbindung weiter das Original aus dem
  Offline-Speicher.

PHP, JavaScript und `info.xml` (Hintergrundaufgabe) geändert; keine
Datenbankänderung. Nach dem Einspielen Container neu starten.

## 0.35.0: Kommentare einsehen und exportieren

Vikunja #5 (Erweiterung).

- **Persönliche Einstellungen → Audio Archive → Kommentare:** Tabelle der
  Kommentare und Bewertungen zu Aufnahmen in den Ordnern, die der Nutzer
  selbst geteilt hat (Links und Freigaben an Personen/Gruppen, nur gültige
  Freigaben mit erreichbarem Ordner) – auch Kommentare über seinen Link und
  Antworten aus „Dateien“. Mitglieder der Benachrichtigungs-Gruppe können
  auf „Alle Kommentare“ umschalten.
- **Verwaltung → Audio Archive – Kommentare:** alle Kommentare (gemeinsamer
  Ordner, weitere Quellen, alle Freigaben).
- **Export:** „Für Excel herunterladen (CSV)“ – UTF-8 mit BOM, Semikolon,
  Spalten Datum, Aufnahme, Ordner, Bereich, Von, Bewertung, Kommentar; im
  Browser erzeugt. „Drucken / als PDF“ – Druckansicht nur der Tabelle; im
  Druckfenster „Als PDF sichern“. Suchfeld filtert Tabelle und Export.
- Server: `GET api/comments/overview?scope=mine|all` (`CommentOverview`):
  Nextcloud-Dateikommentare (neueste 3 000), nur erlaubte Audioformate
  innerhalb der jeweiligen Ordner. `all` nur für Administrator und
  Benachrichtigungs-Gruppe (sonst 403).

PHP, JavaScript, CSS, Vorlagen, Routen und `info.xml` geändert; keine
Datenbankänderung. Nach dem Einspielen Container neu starten.

## 0.35.1: Player zeigt wieder den Titel aus der Datei

Klarstellung zu Vikunja #44: „Unverändert“ gilt für Liste und Ordnerbaum. Der
Player (auch Sperrbildschirm/Auto) zeigt wieder den Titel aus der Datei, falls
vorhanden, sonst den Dateinamen. Abschaltbar unter Verwaltung → Funktionen →
Namen. Neuer Schlüssel `player_title_from_tags` (Vorgabe ja); der Schlüssel
`title_from_tags` aus 0.33.0–0.35.0 (Vorgabe nein) wird nicht mehr gelesen.

PHP, JavaScript, Vorlage und `info.xml` geändert; keine Datenbankänderung.
Nach dem Einspielen Container neu starten.

## 0.36.0: Kommentare – Rechte, Excel-Datei, Office, Sprung, Druckansicht

Vikunja #5 (Rückmeldungen von Hans, 2026-10-02 abends).

- **Wer sieht was:** Nutzer sehen ihre **eigenen** Kommentare und die, die
  **über ihre Freigaben** geschrieben wurden; **alle** nur der
  Administrator (die Benachrichtigungs-Gruppe sieht nicht mehr alles). Über
  welche Freigabe ein Kommentar kam, steht ab jetzt in seinen Metadaten
  (`share`); ältere Kommentare anderer sieht nur der Administrator.
- **Excel-Datei:** `POST api/comments/export` (`scope`, `q` = Filter,
  `save`) erzeugt eine .xlsx (`XlsxWriter`, ZipArchive, ohne Bibliothek:
  fette fixierte Kopfzeile, Filter, Spaltenbreiten, Umbruch). Mit
  `save=true` landet sie in den eigenen Dateien unter `Audio Archive/`, die
  Antwort enthält `/f/<id>?openfile=true` – öffnet „Dateien“ mit der Datei
  und damit das Office-Programm der Nextcloud (z. B. Euro-Office). CSV und
  Drucken bleiben.
- **Druckansicht:** eigenes Fenster, A4 quer, Seitenränder, Kopfzeile auf
  jeder Seite, feste Spaltenbreiten mit Umbruch, Zebrastreifen, Titel mit
  Stand und Filter.
- **Sprung zur Aufnahme:** ▶ links in jeder Zeile oder Klick auf die Zeile
  öffnet die App bei `#open=<quelle>|<pfad>`: Ordner der Aufnahme, Zeile
  hervorgehoben. 📁 zeigt die Datei in „Dateien“ (wenn sie in den eigenen
  Dateien liegt).
- **Kommentar senden:** Hinweisfenster „Bitte warten! – Der Kommentar wird
  versendet …“, danach „Kommentar erfolgreich übermittelt!“.

PHP, JavaScript, CSS, Vorlagen, Routen und `info.xml` geändert; keine
Datenbankänderung. Nach dem Einspielen Container neu starten.

## 0.37.0: Text mit Formatierung, Hilfe und Kontakt, ohne BETA-Schild

Vikunja #49, #27, #39.

- **Text über den Aufnahmen mit Formatierung (#49):** In der Verwaltung, im
  Zahnrad (eigene Ansicht) und beim Teilen je Link gibt es statt des
  einfachen Felds einen Editor mit Knopfleiste (`js/rich-text.js`,
  `css/rich-text.css`): Schriftart, Größe, fett, kursiv, unterstrichen,
  durchgestrichen, Farbe, Ausrichtung, Listen, Link, Formatierung entfernen.
  Gespeichert wird HTML. `RichText.php` lässt beim Speichern **und** beim
  Ausliefern nur eine feste Auswahl an Elementen, Attributen und
  CSS-Eigenschaften durch (keine Skripte, Ereignis-Attribute, Bilder,
  `url()`; Links nur http/https/mailto). Alter reiner Text wird mit
  Zeilenumbrüchen übernommen. Einfügen aus der Zwischenablage nur als reiner
  Text.
- **Hilfe und Kontakt (#27):** Neuer Abschnitt in der Verwaltung: Gruppe für
  Nachrichten und optional eine E-Mail-Adresse. Ist eins davon gesetzt,
  erscheint oben ein Knopf (i) – in der App und auf allen Links – und in der
  Anleitung „App installieren“ der Verweis „Hilfe und Kontakt“. Das Fenster
  bietet „Per E-Mail schreiben“ (`mailto:` mit Betreff und Angaben, wo man
  gerade ist) und/oder ein Textfenster: `POST api/help` schickt jedem
  Mitglied der Gruppe eine Nextcloud-Benachrichtigung (Name, Antwort-Adresse,
  Seite/Ordner/Aufnahme/Gerät). Zugang wie bei Kommentaren, begrenzt auf 5
  Nachrichten je 10 Minuten ohne Anmeldung.
- **App installieren (#27):** Ausklappbare Übersicht „Welche Browser können
  das?“ für iPhone/iPad, Android und Rechner; eigene Schritte für Chrome,
  Edge und Firefox auf dem iPhone sowie für Firefox unter Windows.
- **BETA-Schild entfernt (#39):** Haken in der Verwaltung und Schild in der
  Kopfzeile gibt es nicht mehr. Der gespeicherte Wert `beta_enabled` wird nur
  noch gelesen, damit „Diesen Text anzeigen“ wie bisher vorbelegt bleibt.

PHP, JavaScript, CSS, Vorlagen, Routen und `info.xml` geändert; keine
Datenbankänderung. Nach dem Einspielen Container neu starten.

## 0.38.0: Sortieren, Neu laden, Suche und Wiedergabe verbessert

Vikunja #30, #49, #50, #51, #52, #54.

- **Sortieren (#30):** Statt des Umschalters Name/Neueste gibt es eine Auswahl
  **Name / Neueste / Zufällig** (echtes `<select>` über der Pille, auf dem
  Telefon mit der gewohnten Systemauswahl) und daneben einen **Pfeil für die
  Richtung** (A–Z ↔ Z–A, neueste ↔ älteste zuerst). Bei „Zufällig“ wird der
  Pfeil zum Knopf „Neu mischen“: Gemischt werden die Aufnahmen (und so auch
  abgespielt), Ordner bleiben nach Name. Die Mischung bleibt gleich, bis neu
  gemischt wird (fester Startwert im Gerät). Beim Wechsel der Art beginnt die
  Richtung wieder in der gewohnten Reihenfolge. Die Vorgabe in der Verwaltung
  kennt jetzt auch „Zufällig“.
- **Seite neu laden (#50):** Neuer Knopf in der Kopfzeile (Pfeil im Kreis),
  vor allem für die installierte App ohne Browserleiste. Fragt vorher bis zu
  3 s nach einer neuen Fassung des Service Workers und lädt dann neu.
- **Textfeld in voller Breite (#49):** Nextcloud gibt jedem
  `div[contenteditable]` 130 px Breite und einen eigenen Rahmen – der Editor
  für „Text über den Aufnahmen“ war dadurch ein schmaler Streifen.
  `css/rich-text.css` setzt Breite, Rahmen und Abstand zurück.
- **Nächster Titel beginnt vorne (#51):** Beim automatischen Weiterspielen,
  beim Überspringen und bei Vor/Zurück startet der Titel am Anfang, eine
  früher gemerkte Stelle dieses Titels wird verworfen. Weiterhören an der
  gemerkten Stelle gilt nur noch, wenn man den Titel selbst antippt (und für
  die Karte „Weiterhören“).
- **Suche (#52):** Die Ansicht blinkte, weil die Liste bei jedem Nachfragen
  (solange der Server Titel/Künstler liest) neu aufgebaut wurde, und nach
  12 Runden kam „nicht alles durchsucht, bitte genauer suchen“ – auch bei
  wenigen Treffern. Jetzt: `api/search` meldet `limited` (zu viele Treffer)
  und `unread` (Aufnahmen, deren Angaben noch fehlen). Die Oberfläche fragt
  nach, solange `unread` sinkt (höchstens 40 Runden), zeigt „noch N
  Aufnahmen“ und baut die Liste nur neu auf, wenn sich die Treffer ändern.
  Die Schlussmeldung unterscheidet „zu viele Treffer – genauer suchen“ von
  „Datei- und Ordnernamen ganz durchsucht, bei N Aufnahmen fehlen noch Titel
  und Künstler“. Nicht lesbare Dateien werden eine Stunde lang nicht erneut
  versucht (nur Zwischenspeicher, nicht dauerhaft).
- **Abmelden stoppt die Wiedergabe (#54):** `Player.stop()` merkt die Stelle,
  hält an, entfernt die Quelle, blendet die Leiste aus und meldet der
  Sperrbildschirm-Steuerung „none“.

Geprüft in Nextcloud 35.0.1 (Sandbox, Playwright). Einspielen wie gewohnt mit
Container-Neustart (PHP-Dateien geändert); keine Datenbank-Änderung, keine
neuen Routen.

## 0.39.0: Offline ohne Passwort, Installieren direkt

Vikunja #53, #27.

- **Offline ohne Passwort (#53):** Neue Einstellung in der Verwaltung
  „Offline ohne Passwort öffnen“ (`offline_open`, Vorgabe **an**). Dann
  öffnen sich offline gespeicherte Aufnahmen ohne Verbindung direkt – auf
  geteilten Links ohne Passwortabfrage, angemeldete Nutzer vergeben beim
  Offline-Speichern keine PIN mehr. Wer sich auf einem Link ausdrücklich
  abmeldet, sperrt den Offline-Zugang auf diesem Gerät
  (`audioarchive_offline_locked[:token]`), bis er sich online wieder
  anmeldet. Ausgeschaltet verhält sich alles wie bisher (Link-Passwort bzw.
  PIN). Hinweis in der Verwaltung: Wer das Gerät hat, kann das Gespeicherte
  dann ohne Passwort hören.
- **App installieren (#27):** In Browsern mit eigenem Installieren-Dialog
  (Chrome, Edge, Samsung Internet, Opera) erscheint der Knopf erst, wenn der
  Browser `beforeinstallprompt` meldet – also nur, wenn die App installierbar
  und noch nicht installiert ist; ein Tipp öffnet sofort den Dialog statt der
  Anleitung. In Safari/Firefox bleibt die Anleitung (jetzt auch angemeldet
  ohne Nextcloud-Leiste). Innerhalb von Nextcloud führt der Knopf zu
  `…/app#install`; dort öffnet sich gleich das Fenster mit „📲 Jetzt
  installieren“ (ein Tipp ist nötig, Browser erlauben keine Installation ohne
  Nutzeraktion) bzw. nach 4 s ohne Meldung die Anleitung.

Geprüft in Nextcloud 35.0.1 (Sandbox, Playwright). Einspielen mit
Container-Neustart; keine Datenbank-Änderung, keine neuen Routen.

# Audio Archive – Nextcloud-App (0.18.1)

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
`2026_08/Sonntag` vor, zeigt die oberste Ebene `2026_08` und erst darin
`Sonntag` – statt alle gespeicherten Ordner flach nebeneinander.

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
`/apps/audioarchive/s/gottesdienst-sonntag`.

- Bei jedem Link eines Nutzers („Neuer Link" bzw. „Bearbeiten") und beim
  öffentlichen Link des Administrators (Einstellungen → Verwaltung).
- Erlaubt: a–z, Ziffern, Bindestrich, 3 bis 64 Zeichen. Eingaben werden
  umgewandelt: „Gottesdienst Sonntag Über" → `gottesdienst-sonntag-ueber`.
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
`2026_08` → `2026_08/Sonntag` → `2026_09` → `2026_10/Teil`. Ordner ohne
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
- **Kopfzeile scrollt mit:** Auf Telefonen bleibt der Titel samt Vers nicht
  mehr fest oben stehen, sondern scrollt mit der Liste weg. Der Vers bleibt
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

# Audio Archive – Nextcloud-App (Schritt 4: Offline-Betrieb)

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

<?php
$ncRange = ($_['ncMin'] !== '' && $_['ncMax'] !== '') ? $_['ncMin'] . ' bis ' . $_['ncMax'] : '';
?>
<?php
/**
 * Anleitung fuer Administratoren (ab 1.0.2). Eingebunden von manual.php,
 * dort stehen $escape, $figure und $_ bereit.
 *
 * Die Sprungmarken (id) sind fest: Die Verwaltungsseite verlinkt mit „?“
 * neben jeder Ueberschrift direkt dorthin (templates/settings-admin.php).
 * Danach die PDF neu erzeugen (docs/ENTWICKLUNG.md, Abschnitt 1.0.2).
 */
?>
<nav class="m-toc" aria-label="Inhalt">
  <h2>Inhalt</h2>
  <ol>
    <li><a href="#ueberblick">Was Audio Archive ist</a></li>
    <li><a href="#voraussetzungen">Voraussetzungen</a></li>
    <li><a href="#installation">Installation</a></li>
    <li><a href="#schnellstart">Ersteinrichtung in fünf Schritten</a></li>
    <li><a href="#einstellungen">Die Einstellungen im Einzelnen</a>
      <ol>
        <li><a href="#quellen">Quellen</a></li>
        <li><a href="#oeffentlich">Öffentlicher Zugang</a></li>
        <li><a href="#freigaben">Freigaben durch Nutzer</a></li>
        <li><a href="#darstellung">Darstellung</a></li>
        <li><a href="#funktionen">Funktionen</a></li>
        <li><a href="#hilfe-kontakt">Hilfe und Kontakt</a></li>
        <li><a href="#text">Text über den Aufnahmen</a></li>
      </ol>
    </li>
    <li><a href="#kommentare">Kommentare einsehen und exportieren</a></li>
    <li><a href="#hintergrund">Hintergrundaufgabe und MP3-Umwandlung</a></li>
    <li><a href="#updates">Updates und Wartung</a></li>
    <li><a href="#entfernen">App abschalten oder vollständig entfernen</a></li>
    <li><a href="#fragen">Häufige Fragen</a></li>
  </ol>
</nav>

<section id="ueberblick" class="m-section">
  <h2><span class="m-num">1</span>Was Audio Archive ist</h2>
  <p>Audio Archive macht aus einem Ordner mit Aufnahmen in Nextcloud – Predigten, Vorträge,
  Konzerte, Podcasts, Proben – einen einfachen Audio-Player. Er funktioniert für angemeldete
  Nextcloud-Nutzer und auf Wunsch auch für Hörer <strong>ohne Nextcloud-Konto</strong> über einen
  Link mit Passwort.</p>
  <ul>
    <li>Echte Ordnerstruktur, beliebig tief, mit Suche, Sortierung und Favoriten</li>
    <li>Wiedergabe im Hintergrund mit Steuerung auf dem Sperrbildschirm; merkt sich die Stelle</li>
    <li>MP3, M4A/AAC, Ogg Vorbis, Opus, FLAC, WAV, WebM, AIFF – optional Umwandlung in MP3</li>
    <li>Als App (PWA) installierbar, gespeicherte Ordner auch offline hörbar</li>
    <li>Öffentlicher Link sowie Freigaben, die Nutzer selbst für einzelne Ordner anlegen</li>
    <li>Kommentare und Bewertungen je Aufnahme mit Übersicht und Excel-Export</li>
    <li>Eigene Gestaltung (Farben, Hintergrund, Cover) oder Nextcloud-Optik</li>
    <li>Hilfe-Knopf mit dieser Anleitung und Kontakt zu einer Nextcloud-Gruppe</li>
  </ul>
  <p class="m-note">Die Oberfläche ist derzeit nur auf Deutsch verfügbar. Für Hörer und Nutzer gibt es eine
  eigene <a href="<?php echo $escape($_['userUrl']); ?>">Anleitung</a>; sie ist in der App über den
  Knopf (i) „Hilfe“ erreichbar – auch ohne Nextcloud-Konto.</p>
</section>

<section id="voraussetzungen" class="m-section">
  <h2><span class="m-num">2</span>Voraussetzungen</h2>
  <table class="m-table">
    <thead><tr><th>Komponente</th><th>Anforderung</th><th>Bemerkung</th></tr></thead>
    <tbody>
      <tr><td>Nextcloud</td><td><?php echo $escape($ncRange !== '' ? $ncRange : 'siehe App Store'); ?></td><td>Steht auch im App Store bei der App</td></tr>
      <tr><td>PHP</td><td>8.1 oder neuer</td><td></td></tr>
      <tr><td>Hintergrundaufgaben</td><td>empfohlen: <strong>Cron</strong></td><td>Liest Titel, Länge und Cover großer Archive vorab ein</td></tr>
      <tr><td>ffmpeg</td><td>optional</td><td>Nur für die Umwandlung nicht abspielbarer Formate in MP3</td></tr>
    </tbody>
  </table>
  <p>Die Art der Hintergrundaufgaben stellt man in Nextcloud unter <em>Verwaltung → Grundeinstellungen →
  Hintergrund-Aufgaben</em> ein. Ohne funktionierende Hintergrundaufgaben arbeitet die App trotzdem;
  große Ordner öffnen beim ersten Mal aber langsamer.</p>
</section>

<section id="installation" class="m-section">
  <h2><span class="m-num">3</span>Installation</h2>
  <h3>Aus dem Nextcloud App Store</h3>
  <ol>
    <li>Als Administrator <em>Apps</em> öffnen.</li>
    <li>Kategorie <em>Multimedia</em> wählen und <strong>Audio Archive</strong> suchen.</li>
    <li><em>Herunterladen und aktivieren</em> klicken.</li>
  </ol>
  <h3>Manuell (ohne App Store)</h3>
  <ol>
    <li>Das Release-Archiv in den Ordner <code>custom_apps/</code> der Nextcloud entpacken – der Ordner
    muss <code>audioarchive</code> heißen.</li>
    <li>Unter <em>Apps</em> die App aktivieren oder per Befehlszeile:</li>
  </ol>
  <pre class="m-code">occ app:enable audioarchive</pre>
  <p>Danach erscheint Audio Archive im App-Menü sowie in den Einstellungen unter <em>Verwaltung</em> und
  <em>Persönlich</em>.</p>
</section>

<section id="schnellstart" class="m-section">
  <h2><span class="m-num">4</span>Ersteinrichtung in fünf Schritten</h2>
  <ol>
    <li><em>Einstellungen → Verwaltung → Audio Archive</em> öffnen.</li>
    <li>Unter <strong>Quellen</strong> mit „Auswählen …“ den Ordner mit den Aufnahmen festlegen.</li>
    <li>Sollen Hörer ohne Konto zuhören: <strong>Öffentlichen Zugang aktivieren</strong> und ein Passwort setzen.</li>
    <li>Unter <strong>Darstellung</strong> Titel und Gestaltung wählen.</li>
    <li>Ganz unten auf <strong>Speichern</strong> klicken. Bei aktivem öffentlichem Zugang erscheint danach der
    Link mit „Link kopieren“.</li>
  </ol>
  <p class="m-warn"><strong>Wichtig:</strong> Gespeichert wird nicht nur der Pfad des Quellordners, sondern auch,
  <strong>wem</strong> die Dateien gehören – dem Administrator, der speichert. Aus dessen Dateien liest die App,
  auch beim öffentlichen Zugang. Unter dem Feld steht, aus wessen Dateien gelesen wird.</p>
  <?php echo $figure('admin.jpg', 'Die Verwaltungsseite mit Quellen und öffentlichem Zugang'); ?>
</section>

<section id="einstellungen" class="m-section">
  <h2><span class="m-num">5</span>Die Einstellungen im Einzelnen</h2>
  <p>Alle Einstellungen liegen auf einer Seite unter <em>Einstellungen → Verwaltung → Audio Archive</em>.
  Neben jeder Überschrift führt ein <strong>?</strong> direkt zum passenden Abschnitt hier.
  Änderungen gelten erst nach <strong>Speichern</strong> am Seitenende (Bilder werden sofort hochgeladen).</p>

  <h3 id="quellen">5.1 Quellen</h3>
  <ul>
    <li><strong>Erster Ordner</strong> – der gemeinsame Ordner. Unterordner erscheinen so, wie sie angelegt sind.
    Nur dieser Ordner ist über den öffentlichen Zugang (5.2) erreichbar.</li>
    <li><strong>+ Weitere Quelle</strong> – zusätzliche Ordner mit eigenem Namen (z.&nbsp;B. „Hörbücher“). Jede erscheint
    für alle angemeldeten Nutzer als eigener Eintrag oben in der Seitenleiste. Ohne Anmeldung ist eine weitere
    Quelle nur über einen eigenen Link erreichbar: in der App die Quelle öffnen → „Diesen Ordner teilen“ → „Neuer Link“.</li>
    <li>Entfernen nimmt eine Quelle aus der Seitenleiste. Angelegte Links bleiben gültig, bis sie unter
    „Freigaben durch Nutzer“ gelöscht werden.</li>
  </ul>

  <h3 id="oeffentlich">5.2 Öffentlicher Zugang</h3>
  <table class="m-table">
    <thead><tr><th>Einstellung</th><th>Wirkung</th></tr></thead>
    <tbody>
      <tr><td>Öffentlichen Zugang aktivieren</td><td>Zuhören ohne Nextcloud-Konto über einen Link mit gemeinsamem Passwort.</td></tr>
      <tr><td>Passwort</td><td>Setzen oder ändern; leer lassen = unverändert. Nach einer Änderung muss es auf allen Geräten neu eingegeben werden.</td></tr>
      <tr><td>Wunschname im Link</td><td>Lesbarer Name statt zufälliger Adresse. <strong>Achtung:</strong> Wird er geändert, funktioniert der
        bisherige Link nicht mehr – auch nicht in bereits installierten Apps.</td></tr>
      <tr><td>Angemeldet bleiben</td><td>Aus, 7, 15, 30 oder 90 Tage. Gilt für diesen Link und alle Freigaben mit Passwort.</td></tr>
      <tr><td>Bild bei Aufnahmen ohne Cover</td><td>Ersatzbild für diesen Link (Auswahl oder eigenes PNG/JPEG/WebP). Freigaben wählen ihr Bild selbst.</td></tr>
    </tbody>
  </table>

  <h3 id="freigaben">5.3 Freigaben durch Nutzer</h3>
  <p>Mit „Angemeldete Nutzer dürfen Ordner über die App teilen“ können Nutzer selbst teilen – als öffentlichen Link
  (auch mit Wunschnamen) oder an Nextcloud-Nutzer und -Gruppen; solche Freigaben erscheinen nur in der App unter
  „Mit mir geteilt“. Jede Freigabe hat eigene Einstellungen (Ablaufdatum, Aussehen, Offline/Herunterladen).</p>
  <ul>
    <li><strong>Wer darf teilen?</strong> – Gruppen ankreuzen. Keine Gruppe angehakt = alle angemeldeten Nutzer.
    Hörer über einen Link können nie teilen.</li>
    <li>Die Tabelle zeigt alle bestehenden Freigaben (Ordner, angelegt von, geteilt mit, Ablauf); dort lassen sie sich löschen.</li>
    <li>Abschalten sperrt nur das <em>Anlegen</em> neuer Freigaben; bestehende bleiben gültig, bis sie gelöscht werden.</li>
    <li>Nutzer sehen ihre eigenen Freigaben unter <em>Einstellungen → Persönlich → Audio Archive</em>.</li>
  </ul>
  <?php echo $figure('share.jpg', 'Ein Nutzer teilt einen Ordner aus der App heraus'); ?>

  <h3 id="darstellung">5.4 Darstellung</h3>
  <table class="m-table">
    <thead><tr><th>Einstellung</th><th>Wirkung</th></tr></thead>
    <tbody>
      <tr><td>Titel / Zusatzzeile</td><td>Name der App oben in der Oberfläche, optional mit zweiter Zeile.</td></tr>
      <tr><td>Name des gemeinsamen Ordners</td><td>Bezeichnung im Pfad und in der Seitenleiste. Leer = „Gemeinsame Aufnahmen“ (auf dem Link: „Aufnahmen“).</td></tr>
      <tr><td>Gestaltung (Vorgabe für alle)</td><td><strong>Klassisch</strong> übernimmt Farben, Hintergrund und Schrift von Nextcloud und folgt
        dem Hell-/Dunkelmodus. <strong>Modern</strong>: rund mit Glaseffekt in wählbaren Farben. <strong>Vom Administrator</strong>:
        frei einstellbare Gestaltung (siehe unten).</td></tr>
      <tr><td>Farben für „Modern“</td><td>Fertige Paletten oder eigene Akzentfarbe, Leistenfarbe und Grundton. Die Akzent- und Leistenfarbe
        färben auch diese Anleitung.</td></tr>
      <tr><td>Vom Administrator bereitgestellte Gestaltung</td><td>Farben, Ecken, Glaseffekt, Schrift und Hintergrund frei festlegen und als Vorgabe bzw. zur Auswahl anbieten.</td></tr>
      <tr><td>Hintergrundbild</td><td>PNG, JPEG oder WebP, höchstens 8&nbsp;MB. Ohne Bild erscheint ein Verlauf aus dem Grundton.
        Optional auch bei Nextcloud-Gestaltung verwenden.</td></tr>
      <tr><td>Nutzer dürfen Gestaltung selbst wählen</td><td>Angemeldete Nutzer ändern über das Zahnrad ihre eigene Ansicht (nicht den öffentlichen Link).</td></tr>
    </tbody>
  </table>

  <h3 id="funktionen">5.5 Funktionen</h3>
  <table class="m-table">
    <thead><tr><th>Einstellung</th><th>Wirkung</th></tr></thead>
    <tbody>
      <tr><td>Favoriten (Stern)</td><td>Angemeldete Nutzer verwenden echte Nextcloud-Favoriten; Hörer über Links speichern Favoriten auf ihrem Gerät. Sternfarbe wählbar.</td></tr>
      <tr><td>Sortierung (Vorgabe)</td><td>Name, Neueste zuerst oder Zufällig. Jeder Hörer kann umschalten.</td></tr>
      <tr><td>Anzeige in der Liste</td><td>Bei Ordnern Datum und Anzahl der Aufnahmen, bei Aufnahmen Länge und Datum – einzeln zuschaltbar.</td></tr>
      <tr><td>Namen</td><td>Ordnernamen lesbar umschreiben („2026_08“ → „August 2026“) und im Player den Titel aus der Datei statt des
        Dateinamens zeigen. Die Dateien selbst bleiben unverändert.</td></tr>
      <tr><td>Kommentare</td><td>Knopf „Kommentare“ im Player; optional Bewertung mit 1–5 Sternen, Kommentare auch über den öffentlichen
        Link und Benachrichtigung einer Gruppe (z.&nbsp;B. Tontechnik).</td></tr>
      <tr><td>Suchbereich</td><td>Nur geöffneter Ordner mit Unterordnern, oder alles.</td></tr>
      <tr><td>Wiederholen (Vorgabe)</td><td>Danach nächster Ordner, Aus, Ordner wiederholen oder Titel wiederholen.</td></tr>
      <tr><td>Offline verfügbar machen</td><td>Aufnahmen werden in der App gespeichert (nicht im Download-Ordner).</td></tr>
      <tr><td>Offline ohne Passwort öffnen</td><td>Gespeicherte Aufnahmen öffnen offline ohne Passwort bzw. PIN. Wer das Gerät in der Hand hat,
        kann sie dann hören. Abmelden sperrt wieder.</td></tr>
      <tr><td>Herunterladen als Datei</td><td>Knopf je Aufnahme zum Speichern. Die Datei lässt sich danach frei weitergeben.</td></tr>
      <tr><td>Unterordner als ZIP</td><td>„Ordner herunterladen (ZIP)“ in jedem Unterordner; die oberste Ebene nie als Ganzes.</td></tr>
      <tr><td>In MP3 umwandeln</td><td>Nur wählbar, wenn ffmpeg gefunden wird – siehe <a href="#hintergrund">Abschnitt 7</a>.</td></tr>
    </tbody>
  </table>

  <h3 id="hilfe-kontakt">5.6 Hilfe und Kontakt</h3>
  <p>Oben in der App und auf allen Links steht der Knopf <strong>(i)</strong> „Hilfe“. Er führt immer zur Anleitung für
  Hörer und Nutzer; die folgenden Kontaktwege kommen hinzu, wenn sie eingerichtet sind:</p>
  <ul>
    <li><strong>Nachrichten an Gruppe</strong> – alle Mitglieder erhalten eine Nextcloud-Benachrichtigung mit Name, Antwort-Adresse
    und wo der Absender gerade war (Seite, Ordner, Aufnahme, Gerät). Per E-Mail kommt sie, wenn das in den persönlichen
    Benachrichtigungs-Einstellungen eingeschaltet ist.</li>
    <li><strong>E-Mail-Adresse</strong> (optional) – öffnet beim Hörer das Mailprogramm. Die Adresse ist damit für alle sichtbar,
    die die App oder einen Link öffnen.</li>
  </ul>

  <h3 id="text">5.7 Text über den Aufnahmen</h3>
  <p>Ein frei formulierbarer Text über der Liste (Gruß, Zitat, Hinweis) mit Formatierungsleiste sowie optionalem Link mit eigener
  Beschriftung. Das ist die Vorgabe: Nutzer können für ihre eigene Ansicht und für jeden ihrer Links einen eigenen Text setzen.</p>
</section>

<section id="kommentare" class="m-section">
  <h2><span class="m-num">6</span>Kommentare einsehen und exportieren</h2>
  <p>Unter den Einstellungen steht der Bereich <strong>Audio Archive – Kommentare</strong>. Administratoren sehen dort alle Kommentare
  und Bewertungen – aus dem gemeinsamen Ordner, den weiteren Quellen und allen Freigaben. Nutzer sehen unter <em>Persönlich</em>
  nur ihre eigenen und die über ihre Freigaben.</p>
  <ul>
    <li>▶ oder ein Klick auf die Zeile öffnet die Aufnahme in der App.</li>
    <li>Export als Excel-Datei (herunterladen oder in den eigenen Dateien unter „Audio Archive/“ ablegen und mit dem
    Office-Programm der Nextcloud öffnen), als CSV oder als Druckansicht.</li>
    <li>Es sind echte Nextcloud-Dateikommentare: Wer die Datei in „Dateien“ sieht, findet sie dort in der Seitenleiste
    und kann antworten.</li>
  </ul>
</section>

<section id="hintergrund" class="m-section">
  <h2><span class="m-num">7</span>Hintergrundaufgabe und MP3-Umwandlung</h2>
  <h3>Hintergrundaufgabe „ReadMetadata“</h3>
  <p>Läuft über Nextclouds Cron etwa alle 15 Minuten (je Lauf höchstens 40 Sekunden) und liest Länge, Titel, Künstler, Album
  und Cover aller Aufnahmen im gemeinsamen Ordner und den weiteren Quellen vorab ein. Dadurch öffnen sich auch große Ordner
  schnell. Voraussetzung: Nextclouds Hintergrundaufgaben laufen – am besten im Modus „Cron“.</p>
  <h3>MP3-Umwandlung mit ffmpeg</h3>
  <p>Manche Formate kann nicht jedes Gerät abspielen – AIFF zum Beispiel nur Safari. Ist „Nicht abspielbare Formate beim
  Abspielen in MP3 umwandeln“ eingeschaltet, wandelt der Server solche Aufnahmen beim ersten Abspielen um.</p>
  <ul>
    <li>ffmpeg wird über Nextclouds Einstellung <code>preview_ffmpeg_path</code> gesucht, sonst in <code>/usr/bin</code>,
    <code>/usr/local/bin</code> usw. Unter dem Schalter steht, ob ffmpeg gefunden wurde.</li>
    <li>Läuft Nextcloud in einem Container, muss ffmpeg <strong>im Nextcloud-Container</strong> installiert sein.</li>
    <li>Die Originaldatei bleibt unverändert. Umgewandelte Fassungen liegen im Temp-Verzeichnis von Nextcloud
    (<code>audioarchive-mp3-…</code>), höchstens 2&nbsp;GB; die am längsten nicht gehörten werden entfernt.</li>
  </ul>
</section>

<section id="updates" class="m-section">
  <h2><span class="m-num">8</span>Updates und Wartung</h2>
  <ul>
    <li>Updates aus dem App Store werden unter <em>Apps</em> angeboten. Meldet Nextcloud danach ein Update der Datenbank,
    bestätigen oder <code>occ upgrade</code> ausführen.</li>
    <li>Bei manueller Installation den Ordner <code>custom_apps/audioarchive</code> durch die neue Version ersetzen; läuft
    Nextcloud in einem Container, diesen danach neu starten (PHP behält sonst den alten Code im Zwischenspeicher).</li>
    <li>Diese Version unterstützt Nextcloud <?php echo $escape($ncRange !== '' ? $ncRange : 'gemäß App Store'); ?>. Nach einem Upgrade auf eine neuere, noch nicht freigegebene Version
    schaltet Nextcloud die App ab, bis eine passende Version erscheint. Freigaben und Einstellungen bleiben dabei erhalten.</li>
    <li>Die installierte Version steht auf der Verwaltungsseite neben der Überschrift „Audio Archive“.</li>
  </ul>
</section>

<section id="entfernen" class="m-section">
  <h2><span class="m-num">9</span>App abschalten oder vollständig entfernen</h2>
  <p><strong>Abschalten</strong> behält alle Daten – nach dem erneuten Aktivieren ist alles wie vorher. Zum
  <strong>vollständigen Entfernen</strong> aller gespeicherten Daten (Freigaben, Einstellungen, zwischengespeicherte Angaben):</p>
  <pre class="m-code">occ audioarchive:remove-data --force
occ app:disable audioarchive
occ app:remove audioarchive</pre>
  <p class="m-note">Ohne <code>--force</code> ändert der erste Befehl nichts und erklärt nur, was gelöscht würde. Die Aufnahmen
  selbst und die Kommentare (normale Nextcloud-Dateikommentare) werden nie angetastet.</p>
</section>

<section id="fragen" class="m-section">
  <h2><span class="m-num">10</span>Häufige Fragen</h2>
  <dl class="m-faq">
    <dt>Ein Titel lässt sich auf einem Gerät nicht abspielen (z.&nbsp;B. AIFF auf Android).</dt>
    <dd>ffmpeg installieren und unter <em>Funktionen</em> die MP3-Umwandlung einschalten (Abschnitt 7).</dd>
    <dt>Große Ordner öffnen langsam, Titel und Längen erscheinen verzögert.</dt>
    <dd>Prüfen, ob Nextclouds Hintergrundaufgaben laufen (empfohlen: Cron).</dd>
    <dt>Installierte Apps der Hörer finden den Link nicht mehr.</dt>
    <dd>Der Wunschname im Link wurde geändert. Hörer müssen den neuen Link öffnen und die App neu installieren.</dd>
    <dt>Hörer werden nach dem Passwort gefragt, obwohl „Angemeldet bleiben“ eingeschaltet ist.</dt>
    <dd>Nach einer Passwortänderung muss es überall neu eingegeben werden.</dd>
    <dt>Nutzer sehen den Knopf „Diesen Ordner teilen“ nicht.</dt>
    <dd>„Freigaben durch Nutzer“ einschalten und prüfen, ob der Nutzer in einer der angehakten Gruppen ist.</dd>
    <dt>Im Hilfe-Fenster fehlen E-Mail und Nachrichtenfeld.</dt>
    <dd>Unter <em>Hilfe und Kontakt</em> eine Gruppe oder E-Mail-Adresse eintragen. Die Anleitung ist immer erreichbar.</dd>
    <dt>Auf dem öffentlichen Link fehlen Aufnahmen.</dt>
    <dd>Der Link zeigt nur den ersten (gemeinsamen) Ordner. Für weitere Quellen eigene Links in der App anlegen.</dd>
  </dl>
  <p>Fehler und Wünsche: <a href="https://github.com/minichhenoch-sir/Audio-Archive/issues" rel="noopener noreferrer">GitHub-Issues</a>
  · Lizenz: AGPL-3.0-or-later</p>
</section>

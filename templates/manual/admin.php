<?php
$ncRange = ($_['ncMin'] !== '' && $_['ncMax'] !== '') ? $_['ncMin'] . ' bis ' . $_['ncMax'] : '';
?>
<?php
/**
 * Anleitung fuer Administratoren (ab 1.0.2). Eingebunden von manual.php,
 * dort stehen $escape, $figure und $_ bereit.
 *
 * Abschnitt 5 (Einstellungen) entsteht automatisch aus
 * templates/settings-admin.php (ManualContent::settingsReference): neue
 * Schalter, Felder und Hinweistexte erscheinen hier ohne Aenderung an
 * dieser Datei. Die Sprungmarken kommen aus den $help('…')-Aufrufen dort.
 * Die PDF wird bei jeder Version neu erzeugt (docs/ENTWICKLUNG.md, 1.0.3);
 * passt sie nicht mehr, bietet die App automatisch die Druckfassung an.
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
        <?php foreach ($_['settingsRef'] as $n => $sec) { ?>
        <li><a href="#<?php echo $escape($sec['anchor'] !== '' ? $sec['anchor'] : 'einstellung-' . ($n + 1)); ?>"><?php echo $escape($sec['title']); ?></a></li>
        <?php } ?>
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
      <tr><td>PHP</td><td><?php echo $escape($_['phpMin'] !== '' ? $_['phpMin'] . ' oder neuer' : 'siehe App Store'); ?></td><td></td></tr>
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
  <p class="m-note m-auto">Diese Übersicht entsteht automatisch aus der Verwaltungsseite selbst – Schalter, Auswahlfelder
  und Erklärungen stehen hier immer so, wie sie in dieser Version der App angezeigt werden.</p>
<?php
/*
 * Automatisch aus templates/settings-admin.php (ManualContent::settingsReference).
 * Von Hand ergaenzt wird nur, was die Verwaltungsseite selbst nicht sagt ($extra).
 */
$extra = [
    'quellen' => '<p class="m-warn"><strong>Wichtig:</strong> Gespeichert wird auch, <strong>wem</strong> die Dateien gehören – '
        . 'dem Administrator, der speichert. Aus dessen Dateien liest die App, auch beim öffentlichen Zugang.</p>',
    'freigaben' => $figure('share.jpg', 'Ein Nutzer teilt einen Ordner aus der App heraus')
        . '<p>Nutzer sehen ihre eigenen Freigaben unter <em>Einstellungen → Persönlich → Audio Archive</em>.</p>',
    'darstellung' => '<p>Akzent- und Leistenfarbe der Gestaltung „Modern“ färben auch diese Anleitung.</p>',
    'funktionen' => '<p>Mehr zur Umwandlung in MP3 und zur Hintergrundaufgabe in <a href="#hintergrund">Abschnitt 7</a>.</p>',
];
$kindMark = ['switch' => '☐', 'choice' => '▾', 'field' => '✎', 'color' => '●', 'button' => '▭', 'range' => '⇔'];
$kindName = ['switch' => 'Schalter', 'choice' => 'Auswahl', 'field' => 'Feld', 'color' => 'Farbe', 'button' => 'Knopf', 'range' => 'Regler'];
/** Einträge (Schalter, Felder, Hinweise) als Liste ausgeben */
$renderItems = static function (array $items) use ($escape, $kindMark, $kindName): void {
    $open = false;
    foreach ($items as $item) {
        if (in_array($item['kind'], ['group', 'subgroup', 'note'], true)) {
            if ($open) { echo "</dl>\n"; $open = false; }
            if ($item['kind'] === 'note') {
                echo '<p>' . $escape($item['label']) . "</p>\n";
                continue;
            }
            echo ($item['kind'] === 'group' ? '<h4>' : '<p class="m-ref-sub">') . $escape($item['label'])
                . ($item['kind'] === 'group' ? "</h4>\n" : "</p>\n");
            foreach ($item['hints'] as $line) {
                echo '<p>' . $escape($line) . "</p>\n";
            }
            continue;
        }
        if (!$open) { echo '<dl class="m-ref">' . "\n"; $open = true; }
        echo '<dt><span class="m-ref-kind" title="' . $escape($kindName[$item['kind']] ?? '') . '">'
            . $escape($kindMark[$item['kind']] ?? '•') . '</span>' . $escape($item['label']) . "</dt>\n<dd>";
        if ($item['options'] !== []) {
            echo '<span class="m-ref-options">' . implode('', array_map(
                static fn (string $o): string => '<span class="m-chip">' . $escape($o) . '</span>', $item['options']
            )) . '</span>';
        }
        echo implode('', array_map(static fn (string $h): string => '<span class="m-ref-hint">' . $escape($h) . '</span>', $item['hints']));
        echo "</dd>\n";
    }
    if ($open) { echo "</dl>\n"; }
};
foreach ($_['settingsRef'] as $n => $sec) {
    $anchor = $sec['anchor'] !== '' ? $sec['anchor'] : 'einstellung-' . ($n + 1);
    echo '<h3 id="' . $escape($anchor) . '">5.' . ($n + 1) . ' ' . $escape($sec['title']) . "</h3>\n";
    foreach ($sec['intro'] as $line) {
        echo '<p>' . $escape($line) . "</p>\n";
    }
    $renderItems($sec['items']);
    // Freie Gestaltung: im Browser aufgebaut, deshalb aus js/style-tokens.js gelesen
    if ($sec['anchor'] === 'darstellung' && $_['styleRef'] !== []) {
        echo '<h4 id="freie-gestaltung">Freie Gestaltung im Einzelnen</h4>' . "\n"
            . '<p>So lässt sich die Gestaltung „Vom Administrator“ einstellen – genauso „Benutzerdefiniert“ im Zahnrad der Nutzer. '
            . 'Die Seite zeigt Änderungen sofort.</p>' . "\n";
        $renderItems($_['styleRef']);
    }
    echo $extra[$sec['anchor']] ?? '';
}
?>
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

<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Inhalte der Anleitung, die sich selbst aktuell halten (ab 1.0.3).
 *
 * 1. settingsReference(): Die Einstellungen der Verwaltung werden direkt
 *    aus templates/settings-admin.php gelesen - Ueberschriften, Schalter,
 *    Auswahlfelder mit ihren Moeglichkeiten und alle Hinweistexte. Kommt
 *    dort etwas dazu oder aendert sich ein Text, steht es ohne weiteres
 *    Zutun auch in der Anleitung.
 *
 * 2. features(): Was der Administrator gerade eingeschaltet hat. Die
 *    Anleitung fuer Hoerer und Nutzer schreibt damit "bei euch
 *    eingeschaltet" bzw. "ausgeschaltet" statt "je nach Einrichtung".
 *
 * 3. sourcesHash(): Pruefsumme ueber alles, woraus die Anleitung entsteht.
 *    Damit erkennt die App, ob die mitgelieferten PDFs noch zum Stand der
 *    Seiten passen (siehe ManualController::pdf()).
 *
 * Bewusst ohne Abhaengigkeit zu Nextcloud in den statischen Teilen:
 * release.sh ruft sourcesHash() ueber die Befehlszeile auf.
 */
class ManualContent {

    /** Datei mit der Pruefsumme, zu der die PDFs erzeugt wurden. */
    public const PDF_STAMP = 'manual/pdf-quellen.txt';

    public function __construct(
        private IAppConfig $appConfig,
        private IGroupManager $groupManager,
    ) {
    }

    // ------------------------------------------------------------------
    // Pruefsumme der Quellen
    // ------------------------------------------------------------------

    /**
     * Alles, was in den PDFs steckt: Vorlagen und Stylesheet der
     * Anleitung, die Verwaltungsseite (daraus entsteht die
     * Einstellungs-Uebersicht), die Browser-Anleitung zum Installieren,
     * die Bilder und die Versionsangaben aus info.xml.
     *
     * @return list<string> Pfade relativ zum App-Ordner, sortiert
     */
    public static function sourceFiles(string $appDir): array {
        $files = [
            'templates/manual.php',
            'templates/settings-admin.php',
            'templates/parts/install-browsers.php',
            'css/manual.css',
            'lib/Service/ManualContent.php',
            'js/style-tokens.js',
        ];
        foreach (['templates/manual/*.php', 'img/manual/*'] as $pattern) {
            foreach (glob($appDir . '/' . $pattern) ?: [] as $path) {
                $files[] = substr($path, strlen($appDir) + 1);
            }
        }
        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    }

    public static function sourcesHash(string $appDir): string {
        $ctx = hash_init('sha256');
        foreach (self::sourceFiles($appDir) as $file) {
            $data = @file_get_contents($appDir . '/' . $file);
            hash_update($ctx, $file . "\0" . ($data === false ? '' : $data) . "\0");
        }
        // Aus info.xml nur, was in der Anleitung steht (Version, Nextcloud, PHP)
        $info = (string)@file_get_contents($appDir . '/appinfo/info.xml');
        preg_match_all('#<version>[^<]*</version>|<(?:nextcloud|php)\b[^>]*>#', $info, $m);
        hash_update($ctx, implode("\n", $m[0]));
        return hash_final($ctx);
    }

    /** Pruefsumme, zu der die PDFs erzeugt wurden ('' = unbekannt). */
    public static function pdfStamp(string $appDir): string {
        $stamp = @file_get_contents($appDir . '/' . self::PDF_STAMP);
        return $stamp === false ? '' : trim($stamp);
    }

    /**
     * Ziel fuer "Als PDF": die mitgelieferte Datei, solange sie zum Stand
     * der Seiten passt - sonst die Seite mit Druckfenster ("Als PDF
     * sichern"), damit nie eine veraltete Anleitung heruntergeladen wird.
     *
     * @return array{url: string, file: bool}
     */
    public static function pdfLink(\OCP\IURLGenerator $urls, string $kind): array {
        $admin = $kind === 'admin';
        if (self::pdfsCurrent(dirname(__DIR__, 2))) {
            return ['url' => $urls->linkToRoute(Application::APP_ID . '.manual.' . ($admin ? 'adminPdf' : 'userPdf')), 'file' => true];
        }
        return ['url' => $urls->linkToRoute(Application::APP_ID . '.manual.' . ($admin ? 'admin' : 'user'), ['drucken' => '1']), 'file' => false];
    }

    public static function pdfsCurrent(string $appDir): bool {
        $stamp = self::pdfStamp($appDir);
        return $stamp !== '' && hash_equals($stamp, self::sourcesHash($appDir));
    }

    // ------------------------------------------------------------------
    // Was ist bei dieser Nextcloud eingeschaltet?
    // ------------------------------------------------------------------

    /**
     * @return array<string, bool|string> Schalter der Verwaltung, so wie die
     *         App sie auswertet (gleiche Vorgaben wie im uebrigen Code)
     */
    public function features(): array {
        $bool = fn (string $key, bool $default): bool => $this->appConfig->getValueBool(Application::APP_ID, $key, $default);
        $str = fn (string $key, string $default): string => $this->appConfig->getValueString(Application::APP_ID, $key, $default);
        $helpGroup = $str(Application::SETTING_HELP_GROUP, '');
        $comments = $bool(Application::SETTING_FEATURE_COMMENTS, false);
        return [
            'favoriten' => $bool(Application::SETTING_FEATURE_FAVORITES, true),
            'offline' => $bool(Application::SETTING_FEATURE_OFFLINE, true),
            'offlineOhnePasswort' => $bool(Application::SETTING_OFFLINE_OPEN, true),
            'herunterladen' => $bool(Application::SETTING_FEATURE_DOWNLOAD, false),
            'zip' => $bool(Application::SETTING_FEATURE_FOLDER_DOWNLOAD, false),
            'kommentare' => $comments,
            'bewertung' => $comments && $bool(Application::SETTING_FEATURE_RATING, true),
            'teilen' => $bool(Application::SETTING_USER_SHARES, true),
            'darstellung' => $bool(Application::SETTING_USER_CUSTOMIZATION, true),
            'umwandeln' => $bool(Application::SETTING_TRANSCODE, false),
            'kontaktMail' => (bool)preg_match('/^[^\s@]+@[^\s@]+$/', $str(Application::SETTING_HELP_EMAIL, '')),
            'kontaktNachricht' => $helpGroup !== '' && $this->groupManager->groupExists($helpGroup),
            'suche' => $str(Application::SETTING_SEARCH_SCOPE, 'folder') === 'all' ? 'all' : 'folder',
        ];
    }

    // ------------------------------------------------------------------
    // Freie Gestaltung ("Vom Administrator" / "Benutzerdefiniert"), gelesen
    // aus dem Editor in js/style-tokens.js
    // ------------------------------------------------------------------

    /**
     * Der Editor wird im Browser aufgebaut und steht deshalb nicht in der
     * Vorlage der Verwaltung. Gelesen werden die Aufrufe im Editor-Aufbau
     * (section, colorControl, rangeControl, selectControl, segmentedControl,
     * Hinweise) - neue Regler oder Auswahlen erscheinen so von selbst.
     *
     * @return list<array{kind: string, label: string, options: list<string>, hints: list<string>}>
     */
    public static function styleEditorReference(string $appDir): array {
        $js = @file_get_contents($appDir . '/js/style-tokens.js');
        if ($js === false) {
            return [];
        }
        $from = strpos($js, '// ---------- Vorlagen ----------');
        $to = strpos($js, 'Alles zurücksetzen');
        if ($from === false || $to === false || $to < $from) {
            return [];
        }
        $body = substr($js, $from, $to - $from);
        $str = '\'((?:[^\'\\\\]|\\\\.)*)\'';
        $unq = static fn (string $v): string => stripcslashes($v);

        $fonts = [];
        if (preg_match('/const FONT_LABELS = \{(.*?)\};/s', $js, $m)) {
            preg_match_all('/:\s*' . $str . '/', $m[1], $mm);
            $fonts = array_map($unq, $mm[1]);
        }
        $presets = [];
        if (preg_match('/const PRESETS = \[(.*?)\n  \];/s', $js, $m)) {
            preg_match_all('/name:\s*' . $str . '/', $m[1], $mm);
            $presets = array_map($unq, $mm[1]);
        }

        $items = [];
        $last = null;
        $patterns = [
            'section' => '/section\(' . $str . '/',
            'color' => '/colorControl\(' . $str . ',\s*' . $str . '/',
            'range' => '/rangeControl\(' . $str . ',\s*' . $str . ',\s*' . $str . '(?:,\s*' . $str . ')?/',
            'select' => '/selectControl\(' . $str . ',\s*' . $str . '/',
            'seg' => '/segmentedControl\(' . $str . ',\s*' . $str . ',\s*\[(.*?)\]\)\);/',
            'hint' => '/h\(\'span\', \'aa-se-hint\', ' . $str . '\)/',
            'check' => '/h\(\'span\', \'\', ' . $str . '\)\);/',
            'presets' => '/PRESETS\.forEach/',
        ];
        // Alle Treffer in der Reihenfolge des Quelltexts
        $hits = [];
        foreach ($patterns as $type => $re) {
            preg_match_all($re, $body, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($mm as $hit) {
                $hits[] = [$hit[0][1], $type, $hit];
            }
        }
        usort($hits, static fn ($x, $y) => $x[0] <=> $y[0]);
        foreach ($hits as [, $type, $hit]) {
            switch ($type) {
                case 'section':
                    $items[] = ['kind' => 'subgroup', 'label' => $unq($hit[1][0]), 'options' => [], 'hints' => []];
                    $last = null;
                    break;
                case 'presets':
                    $items[] = ['kind' => 'choice', 'label' => 'Vorlagen', 'options' => $presets, 'hints' => []];
                    $last = array_key_last($items);
                    break;
                case 'color':
                    $items[] = ['kind' => 'color', 'label' => $unq($hit[2][0]), 'options' => [], 'hints' => []];
                    $last = array_key_last($items);
                    break;
                case 'range':
                    $hints = isset($hit[4]) && $hit[4][1] >= 0 ? [$unq($hit[4][0])] : [];
                    $items[] = ['kind' => 'range', 'label' => $unq($hit[2][0]), 'options' => [], 'hints' => $hints];
                    $last = array_key_last($items);
                    break;
                case 'select':
                    $items[] = ['kind' => 'choice', 'label' => $unq($hit[2][0]), 'options' => $fonts, 'hints' => []];
                    $last = array_key_last($items);
                    break;
                case 'seg':
                    preg_match_all('/\[' . $str . ',\s*' . $str . '\]/', $hit[3][0], $om);
                    $items[] = ['kind' => 'choice', 'label' => $unq($hit[2][0]), 'options' => array_map($unq, $om[2]), 'hints' => []];
                    $last = array_key_last($items);
                    break;
                case 'check':
                    $items[] = ['kind' => 'switch', 'label' => $unq($hit[1][0]), 'options' => [], 'hints' => []];
                    $last = array_key_last($items);
                    break;
                case 'hint':
                    $raw = $unq($hit[1][0]);
                    $text = rtrim($raw, ':');
                    // "…:" leitet etwas ein (z. B. die Farbvorlagen) - eigene Zeile
                    if ($last !== null && !str_ends_with($raw, ':')) {
                        $items[$last]['hints'][] = $text;
                    } else {
                        $items[] = ['kind' => 'note', 'label' => $text, 'options' => [], 'hints' => []];
                    }
                    break;
            }
        }
        return $items;
    }

    // ------------------------------------------------------------------
    // Einstellungen der Verwaltung, gelesen aus der Vorlage
    // ------------------------------------------------------------------

    /**
     * Liest templates/settings-admin.php und liefert die Abschnitte in der
     * Reihenfolge der Seite.
     *
     * @return list<array{title: string, anchor: string, intro: list<string>, items: list<array{kind: string, label: string, options: list<string>, hints: list<string>}>}>
     */
    public static function settingsReference(string $appDir): array {
        $raw = @file_get_contents($appDir . '/templates/settings-admin.php');
        if ($raw === false) {
            return [];
        }
        // "?"-Verweis neben der Ueberschrift -> Sprungmarke merken, dann PHP entfernen
        $raw = preg_replace('#<\?php\s+\$help\(\'([a-z0-9-]+)\'\);\s*\?>#', '<span data-anchor="$1"></span>', $raw);
        $raw = preg_replace('#<\?php.*?\?>#s', '', (string)$raw);

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $raw . '</div>', LIBXML_NONET | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('audioarchive-settings');
        if ($root === null) {
            return [];
        }

        $sections = [];
        $current = null;
        $lastItem = null;   // Index des letzten Eintrags im aktuellen Abschnitt
        $text = static fn (\DOMNode $n): string => trim((string)preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $n->textContent)));

        $walk = static function (\DOMNode $node) use (&$walk, &$sections, &$current, &$lastItem, $text, $doc): void {
            foreach ($node->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                $tag = strtolower($child->tagName);
                $class = ' ' . $child->getAttribute('class') . ' ';
                $id = $child->getAttribute('id');

                if ($tag === 'h3') {
                    if ($current !== null) {
                        $sections[] = $current;
                    }
                    $anchor = '';
                    foreach ($child->getElementsByTagName('span') as $span) {
                        if ($span->hasAttribute('data-anchor')) {
                            $anchor = $span->getAttribute('data-anchor');
                        }
                    }
                    $current = ['title' => $text($child), 'anchor' => $anchor, 'intro' => [], 'items' => []];
                    $lastItem = null;
                    continue;
                }
                if ($current === null) {
                    continue; // Kopfbereich vor der ersten Ueberschrift
                }
                if ($child->hasAttribute('hidden') || $tag === 'table' || $tag === 'script') {
                    continue;
                }
                if ($tag === 'h4') {
                    $current['items'][] = ['kind' => 'group', 'label' => $text($child), 'options' => [], 'hints' => []];
                    $lastItem = null;
                    continue;
                }
                if ($tag === 'p' && str_contains($class, ' settings-hint ')) {
                    $hint = $text($child);
                    if ($hint === '' || preg_match('/-(state|owner)$/', $id)) {
                        continue;
                    }
                    if ($lastItem !== null) {
                        $current['items'][$lastItem]['hints'][] = $hint;
                    } elseif ($current['items'] !== [] && end($current['items'])['kind'] === 'group') {
                        $current['items'][array_key_last($current['items'])]['hints'][] = $hint;
                    } else {
                        $current['intro'][] = $hint;
                    }
                    continue;
                }
                // <p><strong>Bei Ordnern:</strong></p> - Zwischenzeile
                if ($tag === 'p' && $child->getElementsByTagName('input')->length === 0
                    && $child->getElementsByTagName('button')->length === 0
                    && $child->getElementsByTagName('strong')->length === 1 && $text($child) !== '') {
                    $current['items'][] = ['kind' => 'subgroup', 'label' => rtrim($text($child), ':'), 'options' => [], 'hints' => []];
                    $lastItem = null;
                    continue;
                }
                if ($tag === 'label' || ($tag === 'span' && str_contains($class, ' aa-field-label '))) {
                    $kind = 'field';
                    $options = [];
                    $for = $child->getAttribute('for');
                    $target = $for !== '' ? $doc->getElementById($for) : null;
                    if ($target instanceof \DOMElement) {
                        $ttag = strtolower($target->tagName);
                        $type = strtolower($target->getAttribute('type'));
                        if ($ttag === 'input' && $type === 'checkbox') {
                            $kind = 'switch';
                        } elseif ($ttag === 'select') {
                            $kind = 'choice';
                            foreach ($target->getElementsByTagName('option') as $option) {
                                $options[] = $text($option);
                            }
                        } elseif ($ttag === 'input' && $type === 'color') {
                            $kind = 'color';
                        }
                    }
                    $current['items'][] = ['kind' => $kind, 'label' => $text($child), 'options' => $options, 'hints' => []];
                    $lastItem = array_key_last($current['items']);
                    continue;
                }
                // Passwortfeld ohne eigene Beschriftung: Platzhalter als Name
                if ($tag === 'input' && strtolower($child->getAttribute('type')) === 'password' && $child->getAttribute('placeholder') !== '') {
                    $current['items'][] = ['kind' => 'field', 'label' => trim($child->getAttribute('placeholder')), 'options' => [], 'hints' => []];
                    $lastItem = array_key_last($current['items']);
                    continue;
                }
                if ($tag === 'button' || ($tag === 'a' && str_contains($class, ' button '))) {
                    $label = $text($child);
                    if ($label !== '' && $label !== 'Speichern') {
                        $current['items'][] = ['kind' => 'button', 'label' => $label, 'options' => [], 'hints' => []];
                        $lastItem = array_key_last($current['items']);
                    }
                    continue;
                }
                $walk($child);
            }
        };
        $walk($root);
        if ($current !== null) {
            $sections[] = $current;
        }
        return $sections;
    }
}

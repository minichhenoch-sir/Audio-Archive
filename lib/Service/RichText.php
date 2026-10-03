<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

/**
 * Formatierter "Text ueber den Aufnahmen" (ab 0.37.0, Vikunja #49).
 *
 * Der Text wird im Editor mit Knopfleiste (js/rich-text.js) als HTML
 * geschrieben und allen Hoerern angezeigt - auch auf der oeffentlichen
 * Seite. Deshalb laesst diese Klasse nur eine feste Auswahl an Elementen,
 * Attributen und CSS-Eigenschaften durch und verwirft alles andere
 * (Skripte, Ereignis-Attribute, fremde Bilder, url() in Stilen ...).
 *
 * Bereinigt wird beim Speichern UND beim Ausliefern - so ist auch Text sicher,
 * der vor 0.37.0 oder an der App vorbei in die Datenbank kam.
 *
 * Alter, reiner Text (bis 0.36.0) enthaelt kein "<" und wird unveraendert
 * mit Zeilenumbruechen als HTML ausgegeben.
 */
class RichText {

    /** Hoechstlaenge des gespeicherten HTML (Zeichen) */
    public const MAX_LENGTH = 8000;

    /** Erlaubte Elemente => erlaubte Attribute (ausser style) */
    private const TAGS = [
        'p' => [], 'div' => [], 'br' => [], 'span' => [],
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [],
        's' => [], 'strike' => [], 'del' => [], 'sub' => [], 'sup' => [],
        'h2' => [], 'h3' => [], 'h4' => [], 'blockquote' => [], 'hr' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'a' => ['href'],
        // execCommand erzeugt in manchen Browsern noch <font>
        'font' => ['face', 'size', 'color'],
    ];

    /** Elemente, die samt Inhalt verschwinden */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'noscript',
        'template', 'svg', 'math', 'form', 'input', 'button', 'textarea', 'select',
        'img', 'video', 'audio', 'picture', 'source', 'link', 'meta', 'head', 'title'];

    /** Erlaubte CSS-Eigenschaften */
    private const STYLES = ['font-family', 'font-size', 'font-weight', 'font-style',
        'text-decoration', 'text-decoration-line', 'color', 'background-color',
        'text-align', 'line-height'];

    /** Reiner Text oder HTML -> sicheres HTML fuer die Anzeige. */
    public static function toHtml(string $stored): string {
        $stored = trim($stored);
        if ($stored === '') {
            return '';
        }
        if (!str_contains($stored, '<')) {
            return nl2br(htmlspecialchars($stored, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false);
        }
        return self::sanitize($stored);
    }

    /**
     * Fuer die Ablage: bereinigtes HTML, gekuerzt; leer, wenn kein sichtbarer
     * Inhalt bleibt (z. B. nur "<p><br></p>" aus einem geleerten Editor).
     */
    public static function forStorage(string $input): string {
        $input = trim($input);
        if ($input === '') {
            return '';
        }
        $html = str_contains($input, '<') ? self::sanitize($input) : $input;
        if (str_contains($html, '<')) {
            $visible = trim(html_entity_decode(strip_tags(str_replace('<hr>', 'x', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{00A0}");
            if ($visible === '') {
                return '';
            }
        }
        if (mb_strlen($html) > self::MAX_LENGTH) {
            // Lieber reinen Text kuerzen als HTML mitten im Element abschneiden
            $plain = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</div>', '</li>'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            return mb_substr($plain, 0, 2000);
        }
        return $html;
    }

    /** HTML auf die erlaubte Auswahl zuruechtstutzen. */
    public static function sanitize(string $html): string {
        if (trim($html) === '') {
            return '';
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // Der XML-Vorspann sorgt dafuer, dass Umlaute als UTF-8 gelesen werden
        $doc->loadHTML('<?xml encoding="UTF-8"?><!DOCTYPE html><html><body><div id="aa-root">'
            . $html . '</div></body></html>', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('aa-root');
        if ($root === null) {
            return '';
        }
        self::cleanChildren($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    private static function cleanChildren(\DOMNode $node): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                continue;
            }
            if (!$child instanceof \DOMElement) {
                // Kommentare, Verarbeitungsanweisungen ...
                $node->removeChild($child);
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            self::cleanChildren($child);
            if (!array_key_exists($tag, self::TAGS)) {
                // Unbekanntes Element: Inhalt behalten, Element entfernen
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            self::cleanAttributes($child, $tag);
        }
    }

    private static function cleanAttributes(\DOMElement $el, string $tag): void {
        $keep = [];
        foreach (self::TAGS[$tag] as $name) {
            if ($el->hasAttribute($name)) {
                $keep[$name] = $el->getAttribute($name);
            }
        }
        $style = $el->hasAttribute('style') ? self::cleanStyle($el->getAttribute('style')) : '';
        foreach (iterator_to_array($el->attributes) as $attr) {
            $el->removeAttribute($attr->name);
        }

        // Geprueft wieder setzen
        if ($style !== '') {
            $el->setAttribute('style', $style);
        }
        if ($tag === 'a') {
            $href = self::cleanUrl($keep['href'] ?? '');
            if ($href !== '') {
                $el->setAttribute('href', $href);
                $el->setAttribute('target', '_blank');
                $el->setAttribute('rel', 'noopener noreferrer');
            }
        } elseif ($tag === 'font') {
            if (isset($keep['size']) && preg_match('/^[1-7]$/', $keep['size'])) {
                $el->setAttribute('size', $keep['size']);
            }
            if (isset($keep['color']) && preg_match('/^#[0-9a-f]{3,8}$|^[a-z]{3,20}$/i', $keep['color'])) {
                $el->setAttribute('color', $keep['color']);
            }
            if (isset($keep['face']) && preg_match('/^[a-z0-9 ,\'"\-]{1,100}$/i', $keep['face'])) {
                $el->setAttribute('face', $keep['face']);
            }
        }
    }

    /** Stil-Angaben filtern: nur erlaubte Eigenschaften, keine url()/expression() */
    private static function cleanStyle(string $style): string {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            $parts = explode(':', $decl, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $prop = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if (!in_array($prop, self::STYLES, true) || $value === '') {
                continue;
            }
            $lower = strtolower($value);
            if (preg_match('/url\s*\(|expression|javascript:|@import|\\\\|[<>{}]/', $lower)) {
                continue;
            }
            if (!preg_match('/^[a-z0-9#%.,\s\'"()\-]+$/i', $value) || strlen($value) > 120) {
                continue;
            }
            $out[] = $prop . ': ' . $value;
        }
        return implode('; ', $out);
    }

    /** Nur http(s) und mailto - sonst leer */
    public static function cleanUrl(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto'], true) ? $url : '';
    }
}

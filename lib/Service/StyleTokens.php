<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

/**
 * Werte einer frei einstellbaren Gestaltung (ab 0.17).
 *
 * Dieselbe Wertemenge nutzen drei Stellen:
 *   - "Vom Administrator bereitgestellt" (Verwaltung, IAppConfig)
 *   - "Benutzerdefiniert" eines Nutzers (IConfig-Nutzerwert)
 *   - "Benutzerdefiniert" einer Freigabe (settings-JSON der Freigabe)
 *
 * Gespeichert wird immer die bereinigte, VOLLSTAENDIGE Menge. Unbekannte
 * Schluessel fallen weg, Zahlen werden auf ihren Bereich begrenzt, Farben
 * muessen Hex-Werte sein, Auswahlen muessen in ihrer Liste stehen. So
 * kann aus dem Browser nichts in die Seite gelangen, was dort als CSS
 * etwas anderes bewirkt als vorgesehen.
 *
 * Umgesetzt in CSS-Variablen wird das in js/style-tokens.js. Die
 * Vorgabewerte hier und dort muessen uebereinstimmen; sie ergeben genau das
 * Aussehen von "Modern" mit den Standardfarben.
 */
class StyleTokens {

    public const DEFAULTS = [
        'base' => 'modern',          // Grundstil: modern (Glas, schwebend) | classic (flach, wie Nextcloud)
        'accent' => '#b9793f',       // Knoepfe, Fortschritt, Hervorhebung
        'bar' => '#291c12',          // Kopfzeile und Player-Leiste
        'surface' => '#fffaf2',      // Listen, Karten, Felder
        'background' => '#a86a3d',   // Grundton des Hintergrunds
        'barText' => '',             // '' = automatisch nach Kontrast
        'surfaceText' => '',
        'bgStyle' => 'gradient',     // gradient | solid
        'radius' => 100,             // Rundung in % der Vorgabe
        'blur' => 20,                // Unschaerfe in px
        'barOpacity' => 55,          // Deckkraft der Leisten in %
        'surfaceOpacity' => 82,      // Deckkraft der Listen in %
        'shadow' => 100,             // Schattenstaerke in %
        'font' => 'system',          // Schrift fuer Text
        'titleFont' => 'serif',      // Schrift fuer Ueberschriften
        'fontScale' => 100,          // Schriftgroesse in %
        'density' => 'normal',       // compact | normal | comfortable
        'imageDim' => 55,            // Abdunkeln eines Hintergrundbildes in %
    ];

    private const COLORS = ['accent', 'bar', 'surface', 'background', 'barText', 'surfaceText'];
    /** Farben, die leer sein duerfen ('' = automatisch) */
    private const OPTIONAL_COLORS = ['barText', 'surfaceText'];

    private const CHOICES = [
        'base' => ['modern', 'classic'],
        'bgStyle' => ['gradient', 'solid'],
        'font' => ['system', 'serif', 'rounded', 'humanist', 'mono'],
        'titleFont' => ['system', 'serif', 'rounded', 'humanist', 'mono'],
        'density' => ['compact', 'normal', 'comfortable'],
    ];

    private const RANGES = [
        'radius' => [0, 200],
        'blur' => [0, 60],
        'barOpacity' => [10, 100],
        'surfaceOpacity' => [10, 100],
        'shadow' => [0, 200],
        'fontScale' => [85, 130],
        'imageDim' => [0, 90],
    ];

    /**
     * Bereinigt eine Wertemenge. Fehlende oder ungueltige Werte bekommen
     * die Vorgabe.
     *
     * @param mixed $input
     */
    public static function normalize($input): array {
        $out = self::DEFAULTS;
        if (!is_array($input)) {
            return $out;
        }

        foreach (self::COLORS as $key) {
            if (!array_key_exists($key, $input) || !is_string($input[$key])) {
                continue;
            }
            $color = ShareService::normalizeColor($input[$key]);
            if ($color !== '') {
                $out[$key] = self::expandColor($color);
            } elseif (in_array($key, self::OPTIONAL_COLORS, true) && trim($input[$key]) === '') {
                $out[$key] = '';
            }
        }

        foreach (self::CHOICES as $key => $allowed) {
            if (isset($input[$key]) && is_string($input[$key]) && in_array($input[$key], $allowed, true)) {
                $out[$key] = $input[$key];
            }
        }

        foreach (self::RANGES as $key => [$min, $max]) {
            if (isset($input[$key]) && is_numeric($input[$key])) {
                $out[$key] = max($min, min($max, (int)round((float)$input[$key])));
            }
        }

        return $out;
    }

    /** Gespeicherten JSON-Text lesen; null, wenn nichts (Gueltiges) da ist. */
    public static function fromJson(string $json): ?array {
        if ($json === '') {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? self::normalize($data) : null;
    }

    public static function toJson(array $style): string {
        return (string)json_encode(self::normalize($style), JSON_UNESCAPED_SLASHES);
    }

    /** Flacher Grundstil wie Nextcloud? */
    public static function isClassic(?array $style): bool {
        return $style !== null && ($style['base'] ?? '') === 'classic';
    }

    /**
     * Farbe fuer Statusleiste, Manifest und App-Symbol: beim flachen
     * Grundstil die Akzentfarbe (wie Nextclouds Hauptfarbe bei
     * "Klassisch"), sonst die Leistenfarbe.
     */
    public static function barColor(array $style): string {
        return self::isClassic($style) ? $style['accent'] : $style['bar'];
    }

    /** #abc -> #aabbcc (normalizeColor liefert bereits Kleinschreibung) */
    private static function expandColor(string $hex): string {
        if (strlen($hex) === 4) {
            return '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
        }
        return $hex;
    }
}

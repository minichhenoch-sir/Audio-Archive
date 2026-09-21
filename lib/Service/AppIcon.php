<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IURLGenerator;

/**
 * App-Symbol in der Farbe der Player-Leiste (ab 0.16).
 *
 * Das Mikrofon bleibt gleich, nur der Hintergrund nimmt die Leistenfarbe
 * der jeweiligen Ansicht an (Freigabe, Administrator-Link, persoenliche
 * Darstellung; bei Nextcloud-Gestaltung Nextclouds Hauptfarbe). Das
 * Mikrofon wird weiss oder schwarz - je nachdem, was auf dem Hintergrund
 * besser lesbar ist.
 *
 * Grundlage sind die mitgelieferten PNGs (weisses Mikrofon auf Blau): Pro
 * Bildpunkt gibt der Rotanteil an, wie viel "Mikrofon" er enthaelt (Blau
 * hat fast kein Rot, Weiss volles Rot). Damit bleiben die weichen Kanten
 * erhalten, und die Form muss nicht ein zweites Mal gepflegt werden.
 *
 * Erzeugte Bilder werden im AppData-Bereich abgelegt (Ordner "icons") -
 * einmal je Farbe und Variante, auch ohne Memcache/Redis dauerhaft. Nicht
 * im App-Ordner, das wuerde die Code-Signierung verletzen. Der Browser
 * haelt sie ein Jahr (die Farbe steht in der Adresse).
 */
class AppIcon {
    /** Name => [Vorlage, Kantenlaenge] */
    public const VARIANTS = [
        'any-64' => ['icon-512.png', 64],
        'any-192' => ['icon-512.png', 192],
        'any-512' => ['icon-512.png', 512],
        'maskable-512' => ['icon-maskable-512.png', 512],
        'apple-180' => ['icon-maskable-512.png', 180],
    ];

    /** Rotanteil des Blaus und des Weiss in den Vorlagen */
    private const RED_BACKGROUND = 1;
    private const RED_FOREGROUND = 254;

    private const FOLDER = 'icons';

    private IAppData $appData;

    public function __construct(
        IAppDataFactory $appDataFactory,
        private IURLGenerator $urlGenerator,
    ) {
        $this->appData = $appDataFactory->get(Application::APP_ID);
    }

    private function folder(): ISimpleFolder {
        try {
            return $this->appData->getFolder(self::FOLDER);
        } catch (NotFoundException $e) {
            return $this->appData->newFolder(self::FOLDER);
        }
    }

    private function stored(string $hex, string $variant): ?string {
        try {
            return $this->appData->getFolder(self::FOLDER)->getFile($this->key($hex, $variant))->getContent();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** '#AABBCC', '#abc' oder 'aabbcc' => 'aabbcc'; sonst null */
    public static function normalizeColor(string $color): ?string {
        $hex = strtolower(ltrim(trim($color), '#'));
        if (preg_match('/^[0-9a-f]{3}$/', $hex)) {
            return $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (preg_match('/^([0-9a-f]{6})([0-9a-f]{2})?$/', $hex, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Adresse eines Symbols; ohne gueltige Farbe das mitgelieferte PNG. */
    public function url(string $color, string $variant, bool $absolute = false, string $version = ''): string {
        $hex = self::normalizeColor($color);
        if ($hex === null || !isset(self::VARIANTS[$variant])) {
            $path = $this->urlGenerator->imagePath(Application::APP_ID, self::VARIANTS[$variant][0] ?? 'icon-512.png');
            return $absolute ? $this->urlGenerator->getAbsoluteURL($path) : $path;
        }
        $params = ['color' => $hex, 'name' => $variant];
        $url = $absolute
            ? $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.asset.icon', $params)
            : $this->urlGenerator->linkToRoute(Application::APP_ID . '.asset.icon', $params);
        return $version !== '' ? $url . '?v=' . rawurlencode($version) : $url;
    }

    /** Liegt das Symbol schon im Zwischenspeicher? */
    public function isCached(string $color, string $variant): bool {
        $hex = self::normalizeColor($color);
        if ($hex === null || !isset(self::VARIANTS[$variant])) {
            return false;
        }
        try {
            return $this->appData->getFolder(self::FOLDER)->fileExists($this->key($hex, $variant));
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function key(string $hex, string $variant): string {
        return 'v1-' . $hex . '-' . $variant . '.png';
    }

    /** PNG-Daten oder null bei unbekannter Variante/Farbe. */
    public function render(string $color, string $variant): ?string {
        $hex = self::normalizeColor($color);
        if ($hex === null || !isset(self::VARIANTS[$variant])) {
            return null;
        }
        [$template, $size] = self::VARIANTS[$variant];

        $stored = $this->stored($hex, $variant);
        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        $path = __DIR__ . '/../../img/' . $template;
        // Ohne GD (bei Nextcloud eigentlich Pflicht): unveraendert blau
        if (!function_exists('imagecreatefrompng')) {
            return is_file($path) ? (string)file_get_contents($path) : null;
        }

        $png = $this->paint($path, $hex, $size);
        if ($png === null) {
            return null;
        }
        try {
            $this->folder()->newFile($this->key($hex, $variant), $png);
        } catch (\Throwable $e) {
            // Ablegen ist nur eine Beschleunigung - das Bild gibt es trotzdem
        }
        return $png;
    }

    private function paint(string $path, string $hex, int $size): ?string {
        $src = @imagecreatefrompng($path);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);

        $bg = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $fg = self::foregroundFor($bg);

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);

        $span = self::RED_FOREGROUND - self::RED_BACKGROUND;
        $palette = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($src, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                $red = ($rgba >> 16) & 0xFF;
                $t = ($red - self::RED_BACKGROUND) / $span;
                $t = $t < 0 ? 0.0 : ($t > 1 ? 1.0 : $t);
                // Mischung auf 64 Stufen genuegt fuer glatte Kanten und haelt
                // die Zahl der Farbzuweisungen klein
                $step = (int)round($t * 63);
                $ck = $step . ':' . $alpha;
                if (!isset($palette[$ck])) {
                    $m = $step / 63;
                    $palette[$ck] = imagecolorallocatealpha(
                        $out,
                        (int)round($bg[0] + ($fg[0] - $bg[0]) * $m),
                        (int)round($bg[1] + ($fg[1] - $bg[1]) * $m),
                        (int)round($bg[2] + ($fg[2] - $bg[2]) * $m),
                        $alpha
                    );
                }
                imagesetpixel($out, $x, $y, $palette[$ck]);
            }
        }
        imagedestroy($src);

        if ($size !== $w) {
            $scaled = imagecreatetruecolor($size, $size);
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
            imagecopyresampled($scaled, $out, 0, 0, 0, 0, $size, $size, $w, $h);
            imagedestroy($out);
            $out = $scaled;
        }

        ob_start();
        imagepng($out, null, 9);
        $png = (string)ob_get_clean();
        imagedestroy($out);
        return $png !== '' ? $png : null;
    }

    /**
     * Weiss oder Schwarz fuer das Mikrofon (Kontrastformel der WCAG).
     *
     * @param int[] $rgb
     * @return int[]
     */
    public static function foregroundFor(array $rgb): array {
        $lin = static function (int $c): float {
            $s = $c / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin($rgb[0]) + 0.7152 * $lin($rgb[1]) + 0.0722 * $lin($rgb[2]);
        // Weiss bevorzugt (wie das urspruengliche Symbol), solange es fuer
        // eine grosse Grafik ausreichend absticht (WCAG: mindestens 3:1).
        // Erst auf hellen Farben wie Gelb oder Weiss wird es schwarz.
        $contrastWhite = 1.05 / ($l + 0.05);
        return $contrastWhite >= 3.0 ? [255, 255, 255] : [17, 17, 17];
    }
}

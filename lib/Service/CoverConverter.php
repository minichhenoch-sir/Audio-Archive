<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;

/**
 * Wandelt Ordnerbilder, die Browser nicht anzeigen (TIFF, HEIC ...), in
 * WebP bzw. JPEG um (ab 0.21.1, Vikunja #1: "wenn nicht geht, dann
 * umwandeln").
 *
 * Umgewandelt wird nur das Vorschaubild fuer den Player, nie die Datei des
 * Nutzers. Das Ergebnis liegt im AppData-Bereich (nicht im App-Ordner -
 * Code-Signierung) unter Dateikennung + Aenderungszeit; aendert sich das
 * Bild, entsteht automatisch ein neues.
 *
 * Werkzeug: Imagick, falls auf dem Server vorhanden (das offizielle
 * Nextcloud-Abbild bringt es mit), sonst GD fuer das, was GD lesen kann.
 * Fehlt beides fuer ein Format, wird das Bild schlicht nicht als Cover
 * verwendet.
 */
class CoverConverter {

    /** Groesste Kantenlaenge des umgewandelten Bildes - fuer den Player reichlich. */
    private const MAX_EDGE = 1200;
    private const FOLDER = 'converted-covers';

    /** Endung -> Imagick-Formatname. */
    private const IMAGICK_FORMATS = [
        'tif' => 'TIFF', 'tiff' => 'TIFF', 'heic' => 'HEIC', 'heif' => 'HEIF',
        'jxl' => 'JXL', 'jp2' => 'JP2', 'psd' => 'PSD', 'tga' => 'TGA',
        'avif' => 'AVIF', 'bmp' => 'BMP',
    ];

    private IAppData $appData;

    public function __construct(IAppDataFactory $appDataFactory) {
        $this->appData = $appDataFactory->get(Application::APP_ID);
    }

    /** Laesst sich diese Endung umwandeln? */
    public function canConvert(string $ext): bool {
        $ext = strtolower($ext);
        if (class_exists(\Imagick::class) && isset(self::IMAGICK_FORMATS[$ext])) {
            try {
                return \Imagick::queryFormats(self::IMAGICK_FORMATS[$ext]) !== [];
            } catch (\Throwable $e) {
                // weiter mit GD
            }
        }
        return ($ext === 'bmp' && function_exists('imagecreatefrombmp'))
            || ($ext === 'avif' && function_exists('imagecreatefromavif'));
    }

    /**
     * @return array{mime: string, data: string}|null
     */
    public function convert(File $image): ?array {
        $name = $image->getId() . '-' . $image->getMTime();
        try {
            $folder = $this->folder();
            foreach (['webp' => 'image/webp', 'jpg' => 'image/jpeg'] as $ext => $mime) {
                if ($folder->fileExists($name . '.' . $ext)) {
                    return ['mime' => $mime, 'data' => $folder->getFile($name . '.' . $ext)->getContent()];
                }
            }
        } catch (\Throwable $e) {
            $folder = null;
        }

        try {
            $source = $image->getContent();
        } catch (\Throwable $e) {
            return null;
        }
        $result = $this->withImagick($source) ?? $this->withGd($source);
        if ($result === null) {
            return null;
        }

        if ($folder !== null) {
            try {
                $folder->newFile($name . '.' . ($result['mime'] === 'image/webp' ? 'webp' : 'jpg'), $result['data']);
            } catch (\Throwable $e) {
                // Ablage misslungen: dann eben beim naechsten Mal neu umwandeln
            }
        }
        return $result;
    }

    /** @return array{mime: string, data: string}|null */
    private function withImagick(string $source): ?array {
        if (!class_exists(\Imagick::class)) {
            return null;
        }
        try {
            $im = new \Imagick();
            $im->readImageBlob($source);
            $im->setIteratorIndex(0); // mehrseitiges TIFF: erste Seite
            $im = $im->getImage();
            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            }
            $im->setImageColorspace(\Imagick::COLORSPACE_SRGB);
            if ($im->getImageWidth() > self::MAX_EDGE || $im->getImageHeight() > self::MAX_EDGE) {
                $im->thumbnailImage(self::MAX_EDGE, self::MAX_EDGE, true);
            }
            $format = \Imagick::queryFormats('WEBP') !== [] ? 'webp' : 'jpeg';
            $im->setImageFormat($format);
            $im->setImageCompressionQuality(85);
            $im->stripImage();
            $data = $im->getImagesBlob();
            $im->clear();
            return $data !== '' ? ['mime' => 'image/' . $format, 'data' => $data] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array{mime: string, data: string}|null */
    private function withGd(string $source): ?array {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring($source);
        if ($img === false) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, self::MAX_EDGE / max($w, $h, 1));
        if ($scale < 1) {
            $scaled = imagescale($img, max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)));
            if ($scaled !== false) {
                imagedestroy($img);
                $img = $scaled;
            }
        }
        ob_start();
        $ok = function_exists('imagewebp') ? imagewebp($img, null, 85) : imagejpeg($img, null, 85);
        $data = (string)ob_get_clean();
        imagedestroy($img);
        if (!$ok || $data === '') {
            return null;
        }
        return ['mime' => function_exists('imagewebp') ? 'image/webp' : 'image/jpeg', 'data' => $data];
    }

    private function folder(): \OCP\Files\SimpleFS\ISimpleFolder {
        try {
            return $this->appData->getFolder(self::FOLDER);
        } catch (NotFoundException $e) {
            return $this->appData->newFolder(self::FOLDER);
        }
    }
}

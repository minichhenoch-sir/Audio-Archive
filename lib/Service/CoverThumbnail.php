<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;

/**
 * Verkleinertes Cover (ab 0.34.0, Vikunja #43) fuer Sperrbildschirm,
 * Benachrichtigung und Bluetooth/Autoradio.
 *
 * Die Bilder in den Aufnahmen sind oft sehr gross (3000 x 3000 Punkte,
 * mehrere MB). Telefone geben das Cover an das Auto weiter und brechen bei
 * grossen Bildern oder langsamer Verbindung gern ab - dann fehlt das Cover
 * oder es bleibt das vorige stehen. Hier entsteht ein kleines JPEG, einmal
 * je Bild und Groesse, abgelegt in den App-Daten (nicht im App-Ordner -
 * das wuerde die Code-Signierung verletzen).
 */
class CoverThumbnail {

    public const SIZES = [96, 256, 512];
    private const FOLDER = 'cover-thumbs';

    private IAppData $appData;

    public function __construct(IAppDataFactory $appDataFactory) {
        $this->appData = $appDataFactory->get(Application::APP_ID);
    }

    /**
     * @param array{data: string, mime: string} $cover
     * @return array{data: string, mime: string} verkleinert, sonst unveraendert
     */
    public function scaled(array $cover, int $size): array {
        if (!in_array($size, self::SIZES, true) || !function_exists('imagecreatefromstring')) {
            return $cover;
        }
        $name = sha1($cover['data']) . '-' . $size . '.jpg';
        try {
            $folder = $this->folder();
            try {
                return ['data' => $folder->getFile($name)->getContent(), 'mime' => 'image/jpeg'];
            } catch (NotFoundException $e) {
                // noch nicht erzeugt
            }
        } catch (\Throwable $e) {
            $folder = null;
        }

        $jpeg = $this->resize($cover['data'], $size);
        if ($jpeg === null) {
            return $cover;
        }
        if ($folder !== null) {
            try {
                $folder->newFile($name, $jpeg);
            } catch (\Throwable $e) {
                // Ablegen ist nur eine Ersparnis fuer das naechste Mal
            }
        }
        return ['data' => $jpeg, 'mime' => 'image/jpeg'];
    }

    private function folder(): \OCP\Files\SimpleFS\ISimpleFolder {
        try {
            return $this->appData->getFolder(self::FOLDER);
        } catch (NotFoundException $e) {
            return $this->appData->newFolder(self::FOLDER);
        }
    }

    /** Quadratisch zugeschnitten (Mitte) und auf $size verkleinert, oder null. */
    private function resize(string $data, int $size): ?string {
        $src = @imagecreatefromstring($data);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $edge = min($w, $h);
        $target = min($size, $edge);
        $dst = imagecreatetruecolor($target, $target);
        if ($dst === false) {
            imagedestroy($src);
            return null;
        }
        // Weisser Grund fuer durchsichtige PNGs (JPEG kennt keine Transparenz)
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, (int)(($w - $edge) / 2), (int)(($h - $edge) / 2), $target, $target, $edge, $edge);
        ob_start();
        imagejpeg($dst, null, 85);
        $out = (string)ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return $out !== '' ? $out : null;
    }
}

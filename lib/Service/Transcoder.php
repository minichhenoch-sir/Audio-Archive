<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;

/**
 * Umwandlung in MP3 beim Abspielen (ab 0.27.0, Vikunja #34).
 *
 * Nur fuer Formate, die das Geraet des Hoerers nicht abspielen kann (etwa
 * AIFF ausserhalb von Safari) - und nur, wenn auf dem Server das Programm
 * ffmpeg vorhanden ist und der Administrator die Umwandlung eingeschaltet
 * hat. Die Originaldatei bleibt unveraendert.
 *
 * Ablauf: Die erste Anfrage startet ffmpeg im Hintergrund (eine Aufnahme
 * kann eine Stunde lang sein; das dauert laenger, als eine Webanfrage
 * offen bleiben sollte). Das Ergebnis landet in einem eigenen Ordner im
 * Temp-Verzeichnis von Nextcloud und wird von dort wie jede Aufnahme
 * ausgeliefert - mit Spulen. Gilt, solange sich die Datei nicht aendert
 * (Dateikennung + Aenderungszeit). Ist der Ordner groesser als
 * MAX_CACHE_BYTES, werden die am laengsten nicht gehoerten entfernt.
 */
class Transcoder {

    private const MAX_CACHE_BYTES = 2 * 1024 * 1024 * 1024;
    /** Laeuft eine Umwandlung so lange ohne Fortschritt, gilt sie als gescheitert. */
    private const STALE_SECONDS = 120;
    private const CANDIDATES = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg', '/bin/ffmpeg'];

    private ?string $ffmpeg = null;
    private bool $searched = false;

    public function __construct(
        private IAppConfig $appConfig,
        private IConfig $config,
        private ITempManager $tempManager,
    ) {
    }

    /** Pfad zu ffmpeg, oder null wenn nicht vorhanden bzw. nicht ausfuehrbar. */
    public function ffmpegPath(): ?string {
        if ($this->searched) {
            return $this->ffmpeg;
        }
        $this->searched = true;
        if (!self::canRun()) {
            return null;
        }
        // Nextclouds eigene Einstellung fuer Vorschaubilder zuerst
        $configured = (string)$this->config->getSystemValue('preview_ffmpeg_path', '');
        foreach (array_merge($configured !== '' ? [$configured] : [], self::CANDIDATES) as $candidate) {
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $this->ffmpeg = $candidate;
            }
        }
        return null;
    }

    /** Darf ueberhaupt umgewandelt werden? (Schalter + ffmpeg vorhanden) */
    public function enabled(): bool {
        return $this->appConfig->getValueBool(Application::APP_ID, Application::SETTING_TRANSCODE, false)
            && $this->ffmpegPath() !== null;
    }

    private static function canRun(): bool {
        if (!function_exists('exec') || !function_exists('escapeshellarg')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array('exec', $disabled, true);
    }

    /**
     * Stand der Umwandlung; startet sie bei Bedarf.
     *
     * @return array{state: string, path?: string}
     *         state: 'ready' (path = fertige MP3), 'working', 'failed'
     */
    public function prepare(File $file): array {
        $ffmpeg = $this->ffmpegPath();
        $dir = $this->cacheDir();
        if ($ffmpeg === null || $dir === null) {
            return ['state' => 'failed'];
        }
        $base = $dir . '/' . self::key($file);
        if (is_file($base . '.mp3')) {
            @touch($base . '.mp3'); // zuletzt gehoert - fuer das Aufraeumen
            return ['state' => 'ready', 'path' => $base . '.mp3'];
        }
        if (is_file($base . '.fail') && time() - (int)filemtime($base . '.fail') < 600) {
            return ['state' => 'failed'];
        }
        // Laeuft schon? Fortschritt = die Teildatei waechst
        foreach (['.part.mp3', '.src'] as $suffix) {
            if (is_file($base . $suffix) && time() - (int)filemtime($base . $suffix) < self::STALE_SECONDS) {
                return ['state' => 'working'];
            }
        }
        @unlink($base . '.part.mp3');
        @unlink($base . '.fail');

        $this->prune($dir);

        // Eingabe: bei lokalem Speicher direkt die Datei, sonst eine Kopie
        $input = null;
        $copy = false;
        try {
            $storage = $file->getStorage();
            if ($storage->isLocal()) {
                $local = $storage->getLocalFile($file->getInternalPath());
                if (is_string($local) && is_file($local)) {
                    $input = $local;
                }
            }
        } catch (\Throwable $e) {
            $input = null;
        }
        if ($input === null) {
            try {
                $in = $file->fopen('r');
                $out = @fopen($base . '.src', 'wb');
                if ($in === false || $out === false) {
                    throw new \RuntimeException('copy');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                $input = $base . '.src';
                $copy = true;
            } catch (\Throwable $e) {
                @unlink($base . '.src');
                @touch($base . '.fail');
                return ['state' => 'failed'];
            }
        }

        $cmd = escapeshellarg($ffmpeg) . ' -nostdin -hide_banner -loglevel error -y -i ' . escapeshellarg($input)
            . ' -vn -map_metadata 0 -c:a libmp3lame -q:a 4 -id3v2_version 3 -f mp3 ' . escapeshellarg($base . '.part.mp3')
            . ' && mv ' . escapeshellarg($base . '.part.mp3') . ' ' . escapeshellarg($base . '.mp3')
            . ' || { rm -f ' . escapeshellarg($base . '.part.mp3') . '; touch ' . escapeshellarg($base . '.fail') . '; }';
        if ($copy) {
            $cmd = '{ ' . $cmd . '; }; rm -f ' . escapeshellarg($base . '.src');
        }
        // Im Hintergrund, unabhaengig von dieser Anfrage
        @touch($base . '.part.mp3');
        @exec('nohup sh -c ' . escapeshellarg($cmd) . ' > /dev/null 2>&1 &');
        return ['state' => 'working'];
    }

    private static function key(File $file): string {
        return $file->getId() . '-' . $file->getMTime();
    }

    private function cacheDir(): ?string {
        $base = rtrim($this->tempManager->getTempBaseDir(), '/');
        $dir = $base . '/audioarchive-mp3-' . preg_replace('/[^a-z0-9]/i', '', (string)$this->config->getSystemValue('instanceid', 'x'));
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return null;
        }
        return is_writable($dir) ? $dir : null;
    }

    /** Aelteste fertige Umwandlungen entfernen, bis der Ordner klein genug ist. */
    private function prune(string $dir): void {
        $files = [];
        $total = 0;
        foreach ((array)glob($dir . '/*.mp3') as $path) {
            if (str_ends_with($path, '.part.mp3')) {
                continue;
            }
            $size = (int)@filesize($path);
            $files[$path] = [(int)@filemtime($path), $size];
            $total += $size;
        }
        if ($total <= self::MAX_CACHE_BYTES) {
            return;
        }
        uasort($files, static fn ($a, $b) => $a[0] <=> $b[0]);
        foreach ($files as $path => [, $size]) {
            @unlink($path);
            $total -= $size;
            if ($total <= self::MAX_CACHE_BYTES * 0.8) {
                break;
            }
        }
    }
}

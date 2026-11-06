<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Response;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * Gibt einen Datenstrom fortlaufend aus - wahlweise vollstaendig oder nur
 * einen angeforderten Abschnitt (HTTP-Range, Antwort 206).
 *
 * Warum nicht Nextclouds StreamResponse?
 * Erstens gibt sie immer den GANZEN Strom aus und taugt damit nicht fuer
 * Teilbereiche. Zweitens - und das ist der wichtigere Grund - schreibt
 * Nextclouds Ausgabe mit einem einfachen print(), und PHP puffert das.
 * Ohne das Leeren der Puffer unten verlaesst kein einziges Byte den Server,
 * bevor das Skript fertig ist. Weil ein <audio>-Element beim ersten Abruf in
 * der Regel die vollstaendige Datei anfordert, staut sich dabei die gesamte
 * Aufnahme im Arbeitsspeicher: Die Wiedergabe beginnt spaet, stockt und muss
 * staendig nachladen.
 */
class RangeStreamResponse extends Response implements ICallbackResponse {

    private const CHUNK_SIZE = 256 * 1024;

    /**
     * @param resource $handle Strom, bereits auf den Startpunkt gesetzt
     * @param int $length Anzahl auszugebender Bytes
     */
    public function __construct(
        private $handle,
        private int $length,
    ) {
        parent::__construct();
    }

    public function callback(IOutput $output): void {
        try {
            if ($output->getHttpResponseCode() === Http::STATUS_NOT_MODIFIED) {
                return;
            }

            // Lange Aufnahmen duerfen nicht am Zeitlimit scheitern
            @set_time_limit(0);

            /*
             * Bricht der Hoerer ab (Titelwechsel, Spulen), endet die Schleife
             * unten SELBST ueber connection_aborted() - PHP darf das Skript
             * nicht mitten im Lesen abwuergen (ab 1.0.6).
             *
             * Grund: Wird das Skript abgewuergt, bleibt der Datenstrom offen
             * und wird erst beim Abbau des Prozesses geschlossen. Liegt die
             * Aufnahme auf einem externen SMB-Speicher, ist die SMB-Verbindung
             * dann oft schon abgebaut - das ergab "TypeError: smbclient_..."
             * und anschliessend Abstuerze des PHP-Prozesses (SIGSEGV), einer
             * je Titelwechsel.
             */
            @ignore_user_abort(true);

            // Vorhandene Ausgabepuffer leeren, damit die folgenden Bloecke
            // wirklich sofort hinausgehen.
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }

            $remaining = $this->length;

            while ($remaining > 0 && !connection_aborted()) {
                if (!is_resource($this->handle) || feof($this->handle)) {
                    break;
                }
                $chunk = fread($this->handle, (int)min(self::CHUNK_SIZE, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $output->setOutput($chunk);
                @flush();

                $remaining -= strlen($chunk);
            }
        } catch (\Throwable $e) {
            // Lesefehler des Speichers: Ausgabe endet, Datei wird unten
            // trotzdem geschlossen.
        } finally {
            $this->closeHandle();
        }
    }

    /** Datenstrom genau einmal und ohne Folgefehler schliessen. */
    private function closeHandle(): void {
        if (is_resource($this->handle)) {
            try {
                @fclose($this->handle);
            } catch (\Throwable $e) {
                // bereits vom Speicher geschlossen
            }
        }
        $this->handle = null;
    }
}

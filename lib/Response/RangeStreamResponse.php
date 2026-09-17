<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Response;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * Gibt einen BEGRENZTEN Abschnitt eines Datenstroms aus.
 *
 * Nextclouds StreamResponse liefert immer den gesamten Strom - fuer
 * HTTP-Range-Antworten (206) ist das unbrauchbar. Diese Klasse schreibt
 * genau so viele Bytes, wie angefordert wurden, ab der Stelle, an der der
 * Strom gerade steht.
 *
 * Ausgegeben wird abschnittsweise statt am Stueck, damit auch grosse
 * Aufnahmen nicht vollstaendig in den Arbeitsspeicher geladen werden.
 */
class RangeStreamResponse extends Response implements ICallbackResponse {

    private const CHUNK_SIZE = 256 * 1024;

    /**
     * @param resource $handle Bereits auf den Startpunkt gesetzter Strom
     * @param int $length Anzahl auszugebender Bytes
     */
    public function __construct(
        private $handle,
        private int $length,
    ) {
        parent::__construct();
    }

    public function callback(IOutput $output): void {
        if ($output->getHttpResponseCode() === Http::STATUS_NOT_MODIFIED) {
            return;
        }

        $remaining = $this->length;

        while ($remaining > 0 && !feof($this->handle)) {
            $chunk = fread($this->handle, (int)min(self::CHUNK_SIZE, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }

            $output->setOutput($chunk);
            $remaining -= strlen($chunk);
        }

        fclose($this->handle);
    }
}

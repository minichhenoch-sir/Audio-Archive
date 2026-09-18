<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCP\Files\File;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Liest Spieldauer und ID3-Tags (Kuenstler, Album, Titel) aus mp3-Dateien.
 *
 * Uebernommen aus der eigenstaendigen Fassung, aber auf Datei-STROEME
 * umgestellt: In Nextcloud liegen Dateien nicht zwingend als Pfad im
 * Dateisystem vor (externer Speicher, Objektspeicher), deshalb wird hier mit
 * dem von der Datei-API gelieferten Strom gearbeitet.
 *
 * Zur Dauer: Bei variabler Bitrate wird die exakte Frame-Anzahl aus dem
 * Xing-/Info-Tag genommen; nur bei konstanter Bitrate wird aus der
 * Dateigroesse gerechnet.
 *
 * Zur Textkodierung: iconv statt mbstring, denn mbstring ist in den
 * ueblichen PHP-Abbildern NICHT aktiviert, iconv dagegen fest einkompiliert.
 * Als letzte Ebene gibt es eine Umrechnung in reinem PHP.
 */
class MetadataReader {

    private ICache $cache;

    public function __construct(ICacheFactory $cacheFactory) {
        $this->cache = $cacheFactory->createDistributed('audioarchive_meta_');
    }

    /**
     * Liefert Dauer und Tags einer Datei.
     *
     * Zwischengespeichert wird ueber Dateikennung und Aenderungszeitpunkt -
     * aendert sich die Datei, wird automatisch neu gelesen. Der Cache liegt
     * bewusst NICHT im App-Ordner: Schreibzugriffe dort wuerden die
     * Code-Signierung der Store-Fassung verletzen.
     *
     * @return array{duration: ?float, artist: ?string, album: ?string, title: ?string, cover: bool}
     */
    public function read(File $file): array {
        // 'v2': ab 0.14 gehoert die Angabe 'cover' dazu - aeltere Eintraege
        // ohne sie werden so nicht mehr verwendet, sondern neu gelesen
        $key = 'v2-' . $file->getId() . '-' . $file->getMTime();

        $cached = $this->cache->get($key);
        if (is_string($cached)) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $result = ['duration' => null, 'artist' => null, 'album' => null, 'title' => null, 'cover' => false];

        try {
            $fh = $file->fopen('r');
        } catch (\Throwable $e) {
            return $result;
        }

        if ($fh === false) {
            return $result;
        }

        try {
            $size = (int)$file->getSize();
            $result['duration'] = $this->readDuration($fh, $size);

            $tags = $this->readTags($fh, $size);
            $result['artist'] = $tags['artist'];
            $result['album'] = $tags['album'];
            $result['title'] = $tags['title'];
            $result['cover'] = $this->findEmbeddedCover($fh) !== null;
        } catch (\Throwable $e) {
            // Beschaedigte Datei: Dann bleibt es bei den Standardwerten.
        } finally {
            fclose($fh);
        }

        $this->cache->set($key, (string)json_encode($result), 60 * 60 * 24 * 30);

        return $result;
    }

    /**
     * Liest die Spieldauer einer mp3-Datei in Sekunden aus den Datei-Headern.
     *
     * Bewusst ohne externe Bibliothek (ffmpeg/getID3 sind im Container nicht
     * vorhanden). Gelesen werden nur die ersten Kilobytes:
     *   1. Ein evtl. vorhandener ID3v2-Tag wird uebersprungen.
     *   2. Der erste MPEG-Audio-Frame-Header wird ausgewertet.
     *   3. Enthaelt dieser Frame einen Xing-/Info-Tag (variable Bitrate), wird
     *      die exakte Frame-Anzahl daraus genommen - das ist die genaue Dauer.
     *   4. Sonst wird mit konstanter Bitrate gerechnet (Dateigroesse / Bitrate).
     *
     * Rueckgabe: Dauer in Sekunden (float) oder null, wenn nicht ermittelbar.
     */
    private function readDuration($fh, int $size): ?float
    {
        if ($size <= 0) {
            return null;
        }

        // Der Strom wird von aussen gereicht und mehrfach gelesen, deshalb
        // jedes Mal an den Anfang zuruecksetzen.
        rewind($fh);

        $offset = 0;

        // --- 1) ID3v2-Tag ueberspringen (falls vorhanden) ---
        $head = fread($fh, 10);
        if ($head !== false && strlen($head) === 10 && substr($head, 0, 3) === 'ID3') {
            $b = array_values(unpack('C*', substr($head, 6, 4)));
            // Syntasafe Integer: je Byte nur 7 nutzbare Bits
            $tagSize = ($b[0] << 21) | ($b[1] << 14) | ($b[2] << 7) | $b[3];
            $offset = 10 + $tagSize;
        }

        // --- 2) Ersten gueltigen Frame-Header suchen ---
        fseek($fh, $offset);
        $buffer = fread($fh, 8192);
        if ($buffer === false || strlen($buffer) < 4) {
            return null;
        }

        $bitrates = [
            1 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320], // MPEG1 Layer III
            2 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],     // MPEG2/2.5 Layer III
        ];
        $sampleRates = [
            3 => [44100, 48000, 32000], // MPEG1
            2 => [22050, 24000, 16000], // MPEG2
            0 => [11025, 12000, 8000],  // MPEG2.5
        ];

        $len = strlen($buffer);
        for ($i = 0; $i < $len - 4; $i++) {
            if (ord($buffer[$i]) !== 0xFF || (ord($buffer[$i + 1]) & 0xE0) !== 0xE0) {
                continue;
            }

            $b1 = ord($buffer[$i + 1]);
            $b2 = ord($buffer[$i + 2]);
            $b3 = ord($buffer[$i + 3]);

            $versionId = ($b1 >> 3) & 0x03; // 3=MPEG1, 2=MPEG2, 0=MPEG2.5
            $layer     = ($b1 >> 1) & 0x03; // 1 = Layer III
            if ($versionId === 1 || $layer !== 1) {
                continue; // reserviert bzw. kein Layer III
            }

            $bitrateIndex    = ($b2 >> 4) & 0x0F;
            $sampleRateIndex = ($b2 >> 2) & 0x03;
            $padding         = ($b2 >> 1) & 0x01;
            $channelMode     = ($b3 >> 6) & 0x03; // 3 = mono

            if ($bitrateIndex === 0 || $bitrateIndex === 0x0F || $sampleRateIndex === 3) {
                continue; // "frei"/ungueltig
            }
            if (!isset($sampleRates[$versionId])) {
                continue;
            }

            $isMpeg1    = ($versionId === 3);
            $bitrate    = $bitrates[$isMpeg1 ? 1 : 2][$bitrateIndex] * 1000;
            $sampleRate = $sampleRates[$versionId][$sampleRateIndex];
            if ($bitrate <= 0 || $sampleRate <= 0) {
                continue;
            }

            $samplesPerFrame = $isMpeg1 ? 1152 : 576;
            $frameLength = (int) floor(($isMpeg1 ? 144 : 72) * $bitrate / $sampleRate) + $padding;

            // --- 3) Xing-/Info-Tag im ersten Frame (variable Bitrate)? ---
            $sideInfo = $isMpeg1
                ? ($channelMode === 3 ? 17 : 32)
                : ($channelMode === 3 ? 9 : 17);
            $xingPos = $i + 4 + $sideInfo;

            if ($xingPos + 12 <= $len) {
                $marker = substr($buffer, $xingPos, 4);
                if ($marker === 'Xing' || $marker === 'Info') {
                    $flags = unpack('N', substr($buffer, $xingPos + 4, 4))[1];
                    if ($flags & 0x01) { // Frames-Feld vorhanden
                        $frames = unpack('N', substr($buffer, $xingPos + 8, 4))[1];
                        if ($frames > 0) {
                            return $frames * $samplesPerFrame / $sampleRate;
                        }
                    }
                }
            }

            // --- 4) Konstante Bitrate: Dauer aus der Dateigroesse ---
            $audioBytes = $size - ($offset + $i);
            return $audioBytes > 0 ? ($audioBytes * 8) / $bitrate : null;
        }

        return null;
    }


    /**
     * Liest Künstler, Album und Titel aus den ID3-Tags einer mp3-Datei.
     *
     * Unterstützt ID3v2.2/2.3/2.4 (inkl. der vier Text-Kodierungen) und faellt
     * auf den alten ID3v1-Tag am Dateiende zurueck. Rueckgabe ist immer ein
     * Array mit den Schluesseln artist/album/title; nicht vorhandene Angaben
     * sind null.
     */
    private function readTags($fh, int $size): array
    {
        $result = ['artist' => null, 'album' => null, 'title' => null];

        rewind($fh);

        $header = fread($fh, 10);
        if ($header !== false && strlen($header) === 10 && substr($header, 0, 3) === 'ID3') {
            $major = ord($header[3]);
            $flags = ord($header[5]);
            $b = array_values(unpack('C*', substr($header, 6, 4)));
            $tagSize = ($b[0] << 21) | ($b[1] << 14) | ($b[2] << 7) | $b[3];

            $body = $tagSize > 0 ? fread($fh, min($tagSize, 1024 * 512)) : '';
            if ($body !== false && $body !== '') {
                $pos = 0;

                // Erweiterten Header ueberspringen, falls gesetzt
                if ($flags & 0x40 && strlen($body) >= 4) {
                    $extSize = unpack('N', substr($body, 0, 4))[1];
                    $pos += ($major >= 4) ? $extSize : $extSize + 4;
                }

                // ID3v2.2 nutzt 3-stellige Frame-IDs und 3-Byte-Laengen
                $isV2 = ($major === 2);
                $idLen = $isV2 ? 3 : 4;
                $headLen = $isV2 ? 6 : 10;

                $wanted = $isV2
                    ? ['TP1' => 'artist', 'TAL' => 'album', 'TT2' => 'title']
                    : ['TPE1' => 'artist', 'TALB' => 'album', 'TIT2' => 'title'];

                $bodyLen = strlen($body);
                while ($pos + $headLen <= $bodyLen) {
                    $frameId = substr($body, $pos, $idLen);
                    if (!preg_match('/^[A-Z0-9]+$/', $frameId)) {
                        break; // Padding bzw. Ende der Frames erreicht
                    }

                    if ($isV2) {
                        $s = array_values(unpack('C*', substr($body, $pos + 3, 3)));
                        $frameSize = ($s[0] << 16) | ($s[1] << 8) | $s[2];
                    } elseif ($major >= 4) {
                        // v2.4: syncsafe Laenge
                        $s = array_values(unpack('C*', substr($body, $pos + 4, 4)));
                        $frameSize = ($s[0] << 21) | ($s[1] << 14) | ($s[2] << 7) | $s[3];
                    } else {
                        $frameSize = unpack('N', substr($body, $pos + 4, 4))[1];
                    }

                    if ($frameSize <= 0 || $pos + $headLen + $frameSize > $bodyLen) {
                        break;
                    }

                    if (isset($wanted[$frameId])) {
                        $raw = substr($body, $pos + $headLen, $frameSize);
                        $value = $this->decodeId3Text($raw);
                        if ($value !== '') {
                            $result[$wanted[$frameId]] = $value;
                        }
                    }

                    $pos += $headLen + $frameSize;
                }
            }
        }

        // --- Rueckfall auf ID3v1 (letzte 128 Byte), falls noch etwas fehlt ---
        if ($result['artist'] === null || $result['album'] === null || $result['title'] === null) {
            if (fseek($fh, -128, SEEK_END) === 0) {
                $v1 = fread($fh, 128);
                if ($v1 !== false && strlen($v1) === 128 && substr($v1, 0, 3) === 'TAG') {
                    $clean = function ($s) {
                        $s = trim(str_replace("\0", '', $s));
                        return $s === '' ? null : $this->toUtf8($s);
                    };
                    $result['title']  = $result['title']  ?? $clean(substr($v1, 3, 30));
                    $result['artist'] = $result['artist'] ?? $clean(substr($v1, 33, 30));
                    $result['album']  = $result['album']  ?? $clean(substr($v1, 63, 30));
                }
            }
        }

        return $result;
    }

    /**
     * Ausfuehrliche Angaben fuer die Info-Ansicht (ab 0.15): weitere Tags
     * und technische Daten. Nur fuer EINE Datei auf Abruf - die Ordnerliste
     * bleibt bewusst schlank.
     *
     * @return array<string, mixed>
     */
    public function readDetails(File $file): array {
        $key = 'd2-' . $file->getId() . '-' . $file->getMTime();
        $cached = $this->cache->get($key);
        if (is_string($cached)) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $result = [
            'title' => null, 'artist' => null, 'album' => null, 'albumArtist' => null,
            'year' => null, 'genre' => null, 'track' => null, 'disc' => null,
            'composer' => null, 'comment' => null,
            'duration' => null, 'bitrate' => null, 'vbr' => null, 'sampleRate' => null,
            'channels' => null, 'format' => null, 'id3' => null,
        ];

        try {
            $fh = $file->fopen('r');
        } catch (\Throwable $e) {
            return $result;
        }
        if ($fh === false) {
            return $result;
        }

        try {
            $size = (int)$file->getSize();
            $result = array_merge($result, $this->readExtendedTags($fh, $size));
            $audio = $this->readAudioInfo($fh, $size);
            foreach ($audio as $k => $v) {
                $result[$k] = $v;
            }
        } catch (\Throwable $e) {
            // Beschaedigte Datei: was bis dahin gelesen wurde, bleibt
        } finally {
            fclose($fh);
        }

        $this->cache->set($key, (string)json_encode($result), 60 * 60 * 24 * 30);
        return $result;
    }

    /**
     * Technische Angaben aus dem ersten MPEG-Frame. Bei variabler Bitrate
     * (Xing/VBRI... hier Xing/Info) wird die mittlere Bitrate aus Groesse und
     * Dauer berechnet.
     *
     * @param resource $fh
     */
    private function readAudioInfo($fh, int $size): array {
        $out = [];
        rewind($fh);
        $offset = 0;
        $head = fread($fh, 10);
        if ($head !== false && strlen($head) === 10 && substr($head, 0, 3) === 'ID3') {
            $b = array_values(unpack('C*', substr($head, 6, 4)));
            $offset = 10 + (($b[0] << 21) | ($b[1] << 14) | ($b[2] << 7) | $b[3]);
            $out['id3'] = 'ID3v2.' . ord($head[3]);
        }
        fseek($fh, $offset);
        $buffer = fread($fh, 8192);
        if ($buffer === false || strlen($buffer) < 4) {
            return $out;
        }

        $bitrates = [
            1 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
            2 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
        ];
        $sampleRates = [3 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 0 => [11025, 12000, 8000]];
        $versionNames = [3 => 'MPEG-1', 2 => 'MPEG-2', 0 => 'MPEG-2.5'];

        $len = strlen($buffer);
        for ($i = 0; $i < $len - 4; $i++) {
            if (ord($buffer[$i]) !== 0xFF || (ord($buffer[$i + 1]) & 0xE0) !== 0xE0) {
                continue;
            }
            $b1 = ord($buffer[$i + 1]);
            $b2 = ord($buffer[$i + 2]);
            $b3 = ord($buffer[$i + 3]);
            $versionId = ($b1 >> 3) & 0x03;
            $layer = ($b1 >> 1) & 0x03;
            if ($versionId === 1 || $layer !== 1) {
                continue;
            }
            $bitrateIndex = ($b2 >> 4) & 0x0F;
            $sampleRateIndex = ($b2 >> 2) & 0x03;
            $channelMode = ($b3 >> 6) & 0x03;
            if ($bitrateIndex === 0 || $bitrateIndex === 0x0F || $sampleRateIndex === 3 || !isset($sampleRates[$versionId])) {
                continue;
            }
            $isMpeg1 = $versionId === 3;
            $out['bitrate'] = $bitrates[$isMpeg1 ? 1 : 2][$bitrateIndex];
            $out['sampleRate'] = $sampleRates[$versionId][$sampleRateIndex];
            $out['channels'] = $channelMode === 3 ? 1 : 2;
            $out['format'] = $versionNames[$versionId] . ' Layer III';
            $out['vbr'] = false;

            $sideInfo = $isMpeg1 ? ($channelMode === 3 ? 17 : 32) : ($channelMode === 3 ? 9 : 17);
            $xingPos = $i + 4 + $sideInfo;
            if ($xingPos + 4 <= $len && substr($buffer, $xingPos, 4) === 'Xing') {
                $out['vbr'] = true;
            }
            $duration = $this->readDuration($fh, $size);
            if ($duration !== null && $duration > 0) {
                $out['duration'] = $duration;
                if ($out['vbr']) {
                    $out['bitrate'] = (int)round(($size - $offset) * 8 / $duration / 1000);
                }
            }
            return $out;
        }
        return $out;
    }

    /** Die 80 Standard-Genres aus ID3v1 - v2-Tags verweisen oft darauf ("(17)"). */
    private const GENRES = [
        'Blues', 'Classic Rock', 'Country', 'Dance', 'Disco', 'Funk', 'Grunge', 'Hip-Hop', 'Jazz', 'Metal',
        'New Age', 'Oldies', 'Other', 'Pop', 'R&B', 'Rap', 'Reggae', 'Rock', 'Techno', 'Industrial',
        'Alternative', 'Ska', 'Death Metal', 'Pranks', 'Soundtrack', 'Euro-Techno', 'Ambient', 'Trip-Hop', 'Vocal', 'Jazz+Funk',
        'Fusion', 'Trance', 'Classical', 'Instrumental', 'Acid', 'House', 'Game', 'Sound Clip', 'Gospel', 'Noise',
        'AlternRock', 'Bass', 'Soul', 'Punk', 'Space', 'Meditative', 'Instrumental Pop', 'Instrumental Rock', 'Ethnic', 'Gothic',
        'Darkwave', 'Techno-Industrial', 'Electronic', 'Pop-Folk', 'Eurodance', 'Dream', 'Southern Rock', 'Comedy', 'Cult', 'Gangsta',
        'Top 40', 'Christian Rap', 'Pop/Funk', 'Jungle', 'Native American', 'Cabaret', 'New Wave', 'Psychadelic', 'Rave', 'Showtunes',
        'Trailer', 'Lo-Fi', 'Tribal', 'Acid Punk', 'Acid Jazz', 'Polka', 'Retro', 'Musical', 'Rock & Roll', 'Hard Rock',
    ];

    private function genreName(string $raw): string {
        $raw = trim($raw);
        if (preg_match('/^\(?(\d{1,3})\)?(.*)$/', $raw, $m)) {
            $rest = trim($m[2]);
            if ($rest !== '') {
                return $rest; // "(17)Rock" -> "Rock"
            }
            $n = (int)$m[1];
            return self::GENRES[$n] ?? $raw;
        }
        return $raw;
    }

    /**
     * Alle Text-Tags fuer die Info-Ansicht. Wie readTags(), aber mit mehr
     * Feldern und einem Kommentar-Frame (COMM).
     *
     * @param resource $fh
     */
    private function readExtendedTags($fh, int $size): array {
        $basic = $this->readTags($fh, $size);
        $out = $basic;

        rewind($fh);
        $header = fread($fh, 10);
        if ($header === false || strlen($header) !== 10 || substr($header, 0, 3) !== 'ID3') {
            return $out + $this->readId3v1Extras($fh);
        }
        $major = ord($header[3]);
        $flags = ord($header[5]);
        $b = array_values(unpack('C*', substr($header, 6, 4)));
        $tagSize = ($b[0] << 21) | ($b[1] << 14) | ($b[2] << 7) | $b[3];
        $body = $tagSize > 0 ? (string)fread($fh, min($tagSize, 1024 * 512)) : '';

        $pos = 0;
        if ($flags & 0x40 && strlen($body) >= 4) {
            $extSize = unpack('N', substr($body, 0, 4))[1];
            $pos += ($major >= 4) ? $extSize : $extSize + 4;
        }
        $isV2 = $major === 2;
        $idLen = $isV2 ? 3 : 4;
        $headLen = $isV2 ? 6 : 10;
        $wanted = $isV2
            ? ['TP2' => 'albumArtist', 'TYE' => 'year', 'TCO' => 'genre', 'TRK' => 'track', 'TPA' => 'disc', 'TCM' => 'composer', 'COM' => 'comment']
            : ['TPE2' => 'albumArtist', 'TYER' => 'year', 'TDRC' => 'year', 'TCON' => 'genre', 'TRCK' => 'track', 'TPOS' => 'disc', 'TCOM' => 'composer', 'COMM' => 'comment'];

        $bodyLen = strlen($body);
        while ($pos + $headLen <= $bodyLen) {
            $frameId = substr($body, $pos, $idLen);
            if (!preg_match('/^[A-Z0-9]+$/', $frameId)) {
                break;
            }
            if ($isV2) {
                $s = array_values(unpack('C*', substr($body, $pos + 3, 3)));
                $frameSize = ($s[0] << 16) | ($s[1] << 8) | $s[2];
            } elseif ($major >= 4) {
                $s = array_values(unpack('C*', substr($body, $pos + 4, 4)));
                $frameSize = ($s[0] << 21) | ($s[1] << 14) | ($s[2] << 7) | $s[3];
            } else {
                $frameSize = unpack('N', substr($body, $pos + 4, 4))[1];
            }
            if ($frameSize <= 0 || $pos + $headLen + $frameSize > $bodyLen) {
                break;
            }
            // Manche Programme (z. B. ffmpeg) legen den Kommentar als
            // benutzerdefiniertes Textfeld TXXX "comment" ab statt als COMM
            if ($frameId === 'TXXX' || $frameId === 'TXX') {
                $txx = $this->decodeUserText(substr($body, $pos + $headLen, $frameSize));
                if ($txx !== null && in_array(strtolower($txx[0]), ['comment', 'description', 'kommentar'], true)
                    && $txx[1] !== '' && ($out['comment'] ?? null) === null) {
                    $out['comment'] = $txx[1];
                }
            }
            if (isset($wanted[$frameId])) {
                $field = $wanted[$frameId];
                $raw = substr($body, $pos + $headLen, $frameSize);
                $value = $field === 'comment' ? $this->decodeComment($raw) : $this->decodeId3Text($raw);
                if ($value !== '' && ($out[$field] ?? null) === null) {
                    if ($field === 'genre') {
                        $value = $this->genreName($value);
                    } elseif ($field === 'year') {
                        $value = substr($value, 0, 4); // TDRC: "2024-05-01" -> "2024"
                    }
                    $out[$field] = $value;
                }
            }
            $pos += $headLen + $frameSize;
        }

        return $out + $this->readId3v1Extras($fh);
    }

    /** Jahr, Genre, Titelnummer und Kommentar aus ID3v1 (Rueckfall). */
    private function readId3v1Extras($fh): array {
        if (fseek($fh, -128, SEEK_END) !== 0) {
            return [];
        }
        $v1 = fread($fh, 128);
        if ($v1 === false || strlen($v1) !== 128 || substr($v1, 0, 3) !== 'TAG') {
            return [];
        }
        $clean = function (string $s): ?string {
            $s = trim(str_replace("\0", '', $s));
            return $s === '' ? null : $this->toUtf8($s);
        };
        $out = ['year' => $clean(substr($v1, 93, 4))];
        // ID3v1.1: Byte 125 = 0 und Byte 126 = Titelnummer
        if ($v1[125] === "\0" && ord($v1[126]) > 0) {
            $out['track'] = (string)ord($v1[126]);
            $out['comment'] = $clean(substr($v1, 97, 28));
        } else {
            $out['comment'] = $clean(substr($v1, 97, 30));
        }
        $genre = ord($v1[127]);
        $out['genre'] = self::GENRES[$genre] ?? null;
        return array_filter($out, static fn ($v) => $v !== null);
    }

    /**
     * TXXX: Kodierung, Beschreibung, Wert.
     *
     * @return array{0: string, 1: string}|null [Beschreibung, Wert]
     */
    private function decodeUserText(string $raw): ?array {
        if (strlen($raw) < 2) {
            return null;
        }
        $encoding = ord($raw[0]);
        $rest = substr($raw, 1);
        if ($encoding === 1 || $encoding === 2) {
            for ($i = 0; $i + 1 < strlen($rest); $i += 2) {
                if ($rest[$i] === "\0" && $rest[$i + 1] === "\0") {
                    $desc = $this->decodeId3Text(chr($encoding) . substr($rest, 0, $i));
                    $value = substr($rest, $i + 2);
                    if ($encoding === 1 && !in_array(substr($value, 0, 2), ["\xFF\xFE", "\xFE\xFF"], true)) {
                        $bom = substr($rest, 0, 2);
                        if (in_array($bom, ["\xFF\xFE", "\xFE\xFF"], true)) {
                            $value = $bom . $value;
                        }
                    }
                    return [$desc, $this->decodeId3Text(chr($encoding) . $value)];
                }
            }
            return null;
        }
        $nul = strpos($rest, "\0");
        if ($nul === false) {
            return null;
        }
        return [
            $this->decodeId3Text(chr($encoding) . substr($rest, 0, $nul)),
            $this->decodeId3Text(chr($encoding) . substr($rest, $nul + 1)),
        ];
    }

    /** COMM: Kodierung, Sprache (3), Kurzbeschreibung, Text. */
    private function decodeComment(string $raw): string {
        if (strlen($raw) < 5) {
            return '';
        }
        $encoding = ord($raw[0]);
        $rest = substr($raw, 4);
        if ($encoding === 1 || $encoding === 2) {
            for ($i = 0; $i + 1 < strlen($rest); $i += 2) {
                if ($rest[$i] === "\0" && $rest[$i + 1] === "\0") {
                    $text = substr($rest, $i + 2);
                    if ($encoding === 1 && strlen($text) >= 2 && !in_array(substr($text, 0, 2), ["\xFF\xFE", "\xFE\xFF"], true)) {
                        // Manche Programme lassen die Byte-Order-Mark im Text weg
                        $bom = substr($rest, 0, 2);
                        if (in_array($bom, ["\xFF\xFE", "\xFE\xFF"], true)) {
                            $text = $bom . $text;
                        }
                    }
                    return $this->decodeId3Text(chr($encoding) . $text);
                }
            }
            return '';
        }
        $nul = strpos($rest, "\0");
        return $nul === false ? '' : $this->decodeId3Text(chr($encoding) . substr($rest, $nul + 1));
    }

    /**
     * Liefert das eingebettete Cover einer Datei (ab 0.14).
     *
     * @return array{mime: string, data: string}|null
     */
    public function readEmbeddedCover(File $file): ?array {
        try {
            $fh = $file->fopen('r');
        } catch (\Throwable $e) {
            return null;
        }
        if ($fh === false) {
            return null;
        }
        try {
            $info = $this->findEmbeddedCover($fh);
            if ($info === null) {
                return null;
            }
            fseek($fh, $info['offset']);
            $data = '';
            $remaining = $info['length'];
            while ($remaining > 0 && !feof($fh)) {
                $chunk = fread($fh, min(65536, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $data .= $chunk;
                $remaining -= strlen($chunk);
            }
            return strlen($data) === $info['length'] ? ['mime' => $info['mime'], 'data' => $data] : null;
        } catch (\Throwable $e) {
            return null;
        } finally {
            fclose($fh);
        }
    }

    /** Groesstes Cover, das ausgeliefert wird (Schutz vor defekten Angaben). */
    private const MAX_COVER_BYTES = 16 * 1024 * 1024;

    /**
     * Sucht im ID3v2-Tag ein Bild (APIC, bei ID3v2.2 PIC).
     *
     * Gelesen werden nur die Frame-Koepfe: Der Strom springt von Frame zu
     * Frame, das Bild selbst wird erst beim Ausliefern gelesen. Cover sind
     * oft mehrere hundert Kilobyte gross - beim Auflisten eines Ordners soll
     * davon nichts durch den Speicher.
     *
     * Gibt es mehrere Bilder, gewinnt die Vorderseite (Bildtyp 3), sonst das
     * erste.
     *
     * @param resource $fh
     * @return array{offset: int, length: int, mime: string}|null
     */
    private function findEmbeddedCover($fh): ?array {
        rewind($fh);
        $header = fread($fh, 10);
        if ($header === false || strlen($header) !== 10 || substr($header, 0, 3) !== 'ID3') {
            return null;
        }
        $major = ord($header[3]);
        $flags = ord($header[5]);
        if ($major < 2 || $major > 4) {
            return null;
        }
        // Unsynchronisation (sehr selten) wuerde die Bilddaten verfaelschen
        if ($flags & 0x80) {
            return null;
        }
        $b = array_values(unpack('C*', substr($header, 6, 4)));
        $tagEnd = 10 + (($b[0] << 21) | ($b[1] << 14) | ($b[2] << 7) | $b[3]);

        $pos = 10;
        if ($flags & 0x40 && $major >= 3) {
            $ext = fread($fh, 4);
            if ($ext === false || strlen($ext) !== 4) {
                return null;
            }
            if ($major >= 4) {
                $e = array_values(unpack('C*', $ext));
                $pos += ($e[0] << 21) | ($e[1] << 14) | ($e[2] << 7) | $e[3];
            } else {
                $pos += unpack('N', $ext)[1] + 4;
            }
        }

        $isV2 = $major === 2;
        $headLen = $isV2 ? 6 : 10;
        $found = null;

        while ($pos + $headLen <= $tagEnd) {
            fseek($fh, $pos);
            $head = fread($fh, $headLen);
            if ($head === false || strlen($head) !== $headLen) {
                break;
            }
            $frameId = substr($head, 0, $isV2 ? 3 : 4);
            if (!preg_match('/^[A-Z0-9]+$/', $frameId)) {
                break; // Padding
            }
            if ($isV2) {
                $s = array_values(unpack('C*', substr($head, 3, 3)));
                $size = ($s[0] << 16) | ($s[1] << 8) | $s[2];
            } elseif ($major >= 4) {
                $s = array_values(unpack('C*', substr($head, 4, 4)));
                $size = ($s[0] << 21) | ($s[1] << 14) | ($s[2] << 7) | $s[3];
            } else {
                $size = unpack('N', substr($head, 4, 4))[1];
            }
            if ($size <= 0 || $pos + $headLen + $size > $tagEnd) {
                break;
            }

            if ($frameId === 'APIC' || $frameId === 'PIC') {
                $dataStart = $pos + $headLen;
                $dataSize = $size;
                $formatFlags = $isV2 ? 0 : ord($head[9]);
                // Komprimiert oder verschluesselt: nicht verwendbar
                $usable = !($major === 3 && ($formatFlags & 0xC0)) && !($major === 4 && ($formatFlags & 0x0C));
                if ($major === 4 && ($formatFlags & 0x01)) {
                    // Angabe der Datenlaenge vorangestellt
                    $dataStart += 4;
                    $dataSize -= 4;
                }
                if ($usable && $dataSize > 0) {
                    $picture = $this->parsePictureFrame($fh, $dataStart, $dataSize, $isV2);
                    if ($picture !== null) {
                        if ($picture['type'] === 3) {
                            return $picture;
                        }
                        $found ??= $picture;
                    }
                }
            }
            $pos += $headLen + $size;
        }

        return $found;
    }

    /**
     * Zerlegt den Kopf eines Bild-Frames: Kodierung, Bildformat, Bildtyp,
     * Beschreibung - danach folgen die eigentlichen Bilddaten.
     *
     * @return array{offset: int, length: int, mime: string, type: int}|null
     */
    private function parsePictureFrame($fh, int $start, int $size, bool $isV2): ?array {
        fseek($fh, $start);
        // Kopf samt Beschreibung passt praktisch immer in 1 KB
        $head = fread($fh, min($size, 1024));
        if ($head === false || strlen($head) < 4) {
            return null;
        }
        $encoding = ord($head[0]);
        $p = 1;
        if ($isV2) {
            $format = strtoupper(substr($head, 1, 3));
            $mime = $format === 'PNG' ? 'image/png' : 'image/jpeg';
            $p = 4;
        } else {
            $end = strpos($head, "\0", 1);
            if ($end === false) {
                return null;
            }
            $mime = strtolower(trim(substr($head, 1, $end - 1)));
            $p = $end + 1;
        }
        if ($p >= strlen($head)) {
            return null;
        }
        $type = ord($head[$p]);
        $p++;

        // Beschreibung ueberspringen: bei UTF-16 endet sie mit zwei Nullbytes
        if ($encoding === 1 || $encoding === 2) {
            $end = null;
            for ($i = $p; $i + 1 < strlen($head); $i += 2) {
                if ($head[$i] === "\0" && $head[$i + 1] === "\0") {
                    $end = $i + 2;
                    break;
                }
            }
        } else {
            $nul = strpos($head, "\0", $p);
            $end = $nul === false ? null : $nul + 1;
        }
        if ($end === null) {
            return null;
        }

        $length = $size - $end;
        if ($length <= 0 || $length > self::MAX_COVER_BYTES) {
            return null;
        }

        // Typ am Inhalt bestimmen - die Angabe im Tag ist oft ungenau ("jpg",
        // "image/jpg") oder fehlt
        fseek($fh, $start + $end);
        $magic = (string)fread($fh, 12);
        if (str_starts_with($magic, "\xFF\xD8\xFF")) {
            $mime = 'image/jpeg';
        } elseif (str_starts_with($magic, "\x89PNG")) {
            $mime = 'image/png';
        } elseif (str_starts_with($magic, 'RIFF') && substr($magic, 8, 4) === 'WEBP') {
            $mime = 'image/webp';
        } elseif (str_starts_with($magic, 'GIF8')) {
            $mime = 'image/gif';
        } else {
            return null; // Kein bekanntes Bildformat - lieber kein Cover
        }

        return ['offset' => $start + $end, 'length' => $length, 'mime' => $mime, 'type' => $type];
    }

    /** Prueft, ob ein String bereits gueltiges UTF-8 ist (ohne mbstring). */
    private function isUtf8(string $s): bool
    {
        return (bool) preg_match('//u', $s);
    }

    /**
     * Wandelt Text nach UTF-8 um.
     *
     * Reihenfolge bewusst: iconv zuerst, denn mbstring ist im offiziellen
     * php:8.2-apache-Image NICHT aktiviert, iconv dagegen fest einkompiliert.
     * Als letzte Ebene eine reine PHP-Umrechnung, damit Umlaute auch dann
     * korrekt ankommen, wenn beide Erweiterungen fehlen.
     */
    private function convertEncoding(string $s, string $from): string
    {
        if ($s === '') {
            return '';
        }

        if (function_exists('iconv')) {
            // //IGNORE: einzelne kaputte Bytes verwerfen statt alles abzubrechen
            $out = @iconv($from, 'UTF-8//IGNORE', $s);
            if ($out !== false) {
                return $out;
            }
        }

        if (function_exists('mb_convert_encoding')) {
            $out = @mb_convert_encoding($s, 'UTF-8', $from);
            if ($out !== false && $out !== '') {
                return $out;
            }
        }

        // --- Rueckfall ohne Erweiterungen ---
        $upper = strtoupper($from);

        if ($upper === 'ISO-8859-1') {
            $out = '';
            $len = strlen($s);
            for ($i = 0; $i < $len; $i++) {
                $c = ord($s[$i]);
                $out .= ($c < 0x80)
                    ? chr($c)
                    : chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F));
            }
            return $out;
        }

        if ($upper === 'UTF-16BE' || $upper === 'UTF-16LE') {
            $little = ($upper === 'UTF-16LE');
            $out = '';
            $len = strlen($s) - (strlen($s) % 2);

            for ($i = 0; $i < $len; $i += 2) {
                $a = ord($s[$i]);
                $b = ord($s[$i + 1]);
                $code = $little ? ($b << 8) | $a : ($a << 8) | $b;

                // Surrogat-Paare (Zeichen ausserhalb der Basisebene) zusammensetzen
                if ($code >= 0xD800 && $code <= 0xDBFF && $i + 3 < $len) {
                    $c = ord($s[$i + 2]);
                    $d = ord($s[$i + 3]);
                    $low = $little ? ($d << 8) | $c : ($c << 8) | $d;
                    if ($low >= 0xDC00 && $low <= 0xDFFF) {
                        $code = 0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00);
                        $i += 2;
                    }
                }

                if ($code < 0x80) {
                    $out .= chr($code);
                } elseif ($code < 0x800) {
                    $out .= chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
                } elseif ($code < 0x10000) {
                    $out .= chr(0xE0 | ($code >> 12))
                          . chr(0x80 | (($code >> 6) & 0x3F))
                          . chr(0x80 | ($code & 0x3F));
                } else {
                    $out .= chr(0xF0 | ($code >> 18))
                          . chr(0x80 | (($code >> 12) & 0x3F))
                          . chr(0x80 | (($code >> 6) & 0x3F))
                          . chr(0x80 | ($code & 0x3F));
                }
            }
            return $out;
        }

        return $s;
    }

    /** ID3v1-Texte sind ISO-8859-1 kodiert. */
    private function toUtf8(string $s): string
    {
        return $this->isUtf8($s) ? $s : $this->convertEncoding($s, 'ISO-8859-1');
    }

    /**
     * Dekodiert ein ID3v2-Textfeld. Das erste Byte gibt die Kodierung an:
     * 0 = ISO-8859-1, 1 = UTF-16 mit BOM, 2 = UTF-16BE ohne BOM, 3 = UTF-8.
     */
    private function decodeId3Text(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $encoding = ord($raw[0]);
        $text = substr($raw, 1);

        switch ($encoding) {
            case 1: // UTF-16 mit Byte Order Mark
                if (strlen($text) >= 2) {
                    $bom = substr($text, 0, 2);
                    if ($bom === "\xFF\xFE") {
                        $text = $this->convertEncoding(substr($text, 2), 'UTF-16LE');
                    } elseif ($bom === "\xFE\xFF") {
                        $text = $this->convertEncoding(substr($text, 2), 'UTF-16BE');
                    } else {
                        $text = $this->convertEncoding($text, 'UTF-16LE');
                    }
                }
                break;

            case 2: // UTF-16 Big Endian ohne BOM
                $text = $this->convertEncoding($text, 'UTF-16BE');
                break;

            case 3: // bereits UTF-8
                if (!$this->isUtf8($text)) {
                    $text = $this->convertEncoding($text, 'ISO-8859-1');
                }
                break;

            default: // ISO-8859-1
                $text = $this->toUtf8($text);
        }

        return trim(str_replace("\0", '', $text));
    }


}

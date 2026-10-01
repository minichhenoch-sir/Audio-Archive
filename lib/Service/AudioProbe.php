<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

/**
 * Liest Dauer, technische Angaben, Tags und Cover aus den Audioformaten
 * ausser MP3 (ab 0.21.0). MP3 bleibt bei den bewaehrten Routinen im
 * MetadataReader.
 *
 * Wie dort ohne externe Bibliothek (ffmpeg/getID3 fehlen auf vielen
 * Servern) und nur aus den Datei-Koepfen: Gelesen werden die Kopfbereiche,
 * nie die Tondaten selbst. Bilder werden nur gefunden (Lage in der Datei),
 * nicht gelesen.
 *
 * Erkannt wird am Dateiinhalt, nicht an der Endung:
 *   - FLAC          Bloecke STREAMINFO, VORBIS_COMMENT, PICTURE
 *   - Ogg           Vorbis, Opus und Ogg-FLAC; Dauer aus der letzten Seite
 *   - MP4/M4A       AAC, ALAC, Opus ...; Tags aus moov/udta/meta/ilst
 *   - WAV (RIFF)    fmt/data, Tags aus LIST/INFO und einem ID3-Block
 *   - AIFF/AIFC     COMM, NAME/AUTH/ANNO und ein ID3-Block
 *   - WebM, AAC-Rohstrom, CAF: nur erkannt (Formatname); die Dauer
 *     liefert dann der Browser beim Abspielen.
 *
 * Rueckgabe von probe():
 *   container  null = kein bekanntes Format (dann MP3-Weg im MetadataReader)
 *   duration, sampleRate, channels, bitrate (kbit/s), format (Anzeigename)
 *   tags       title, artist, album, albumArtist, year, genre, track, disc,
 *              composer, comment (nur vorhandene)
 *   cover      ['offset','length','mime'] oder ['data','mime'] oder null
 *   id3        ['offset','length'] eines eingebetteten ID3v2-Blocks oder null
 */
class AudioProbe {

    /** Groesster Tag-Bereich, der gelesen wird (Schutz vor defekten Angaben). */
    private const MAX_TAG_BYTES = 4 * 1024 * 1024;

    /** Obergrenze fuer Schleifen ueber Bloecke/Kaesten. */
    private const MAX_ITEMS = 4096;

    /** Vorbis-Kommentar-Felder -> eigene Schluessel. */
    private const VORBIS_FIELDS = [
        'TITLE' => 'title',
        'ARTIST' => 'artist',
        'ALBUM' => 'album',
        'ALBUMARTIST' => 'albumArtist',
        'ALBUM ARTIST' => 'albumArtist',
        'DATE' => 'year',
        'YEAR' => 'year',
        'GENRE' => 'genre',
        'TRACKNUMBER' => 'track',
        'DISCNUMBER' => 'disc',
        'COMPOSER' => 'composer',
        'COMMENT' => 'comment',
        'DESCRIPTION' => 'comment',
    ];

    /** iTunes-Felder (ilst) -> eigene Schluessel. "\xA9" ist das (c)-Zeichen. */
    private const MP4_FIELDS = [
        "\xA9nam" => 'title',
        "\xA9ART" => 'artist',
        "\xA9alb" => 'album',
        'aART' => 'albumArtist',
        "\xA9day" => 'year',
        "\xA9gen" => 'genre',
        "\xA9wrt" => 'composer',
        "\xA9cmt" => 'comment',
        'desc' => 'comment',
    ];

    /** RIFF-INFO-Felder (WAV) -> eigene Schluessel. */
    private const RIFF_FIELDS = [
        'INAM' => 'title',
        'IART' => 'artist',
        'IPRD' => 'album',
        'ICRD' => 'year',
        'IGNR' => 'genre',
        'ITRK' => 'track',
        'IPRT' => 'track',
        'IMUS' => 'composer',
        'ICMT' => 'comment',
    ];

    /** AIFF-Textbloecke -> eigene Schluessel. */
    private const AIFF_FIELDS = [
        'NAME' => 'title',
        'AUTH' => 'artist',
        'ANNO' => 'comment',
    ];

    /** Codec-Kennungen in MP4 -> Anzeigename. */
    private const MP4_CODECS = [
        'mp4a' => 'AAC',
        'alac' => 'Apple Lossless (ALAC)',
        'Opus' => 'Opus',
        'fLaC' => 'FLAC',
        'ac-3' => 'AC-3',
        'ec-3' => 'E-AC-3',
        '.mp3' => 'MP3',
    ];

    /** WAV-Formatkennungen -> Anzeigename. */
    private const WAV_CODECS = [
        1 => 'PCM',
        2 => 'MS ADPCM',
        3 => 'PCM (Gleitkomma)',
        6 => 'A-law',
        7 => 'µ-law',
        0x11 => 'IMA ADPCM',
        0x55 => 'MP3',
    ];

    /**
     * @param resource $fh
     * @return array<string, mixed>
     */
    public function probe($fh, int $size): array {
        $result = $this->detect($fh, $size);
        unset($result['_coverFront']);
        return $result;
    }

    /**
     * @param resource $fh
     * @return array<string, mixed>
     */
    private function detect($fh, int $size): array {
        $result = self::empty();
        if ($size < 12) {
            return $result;
        }

        try {
            // Manche Programme setzen auch vor FLAC einen ID3-Block
            $base = 0;
            $head = $this->readAt($fh, 0, 12);
            if (strlen($head) >= 10 && substr($head, 0, 3) === 'ID3') {
                $b = array_values(unpack('C*', substr($head, 6, 4)));
                $base = 10 + (($b[0] << 21) | ($b[1] << 14) | ($b[2] << 7) | $b[3]);
                if (ord($head[5]) & 0x10) {
                    $base += 10; // Fusszeile (ID3v2.4)
                }
                $head = $this->readAt($fh, $base, 12);
            }
            if (strlen($head) < 12) {
                return $result;
            }

            if (substr($head, 0, 4) === 'fLaC') {
                return $this->probeFlac($fh, $base, $size);
            }
            if ($base !== 0) {
                return $result; // ID3 + etwas anderes: das ist MP3
            }
            if (substr($head, 0, 4) === 'OggS') {
                return $this->probeOgg($fh, $size);
            }
            if (substr($head, 4, 4) === 'ftyp') {
                return $this->probeMp4($fh, $size);
            }
            if ((substr($head, 0, 4) === 'RIFF' || substr($head, 0, 4) === 'RF64') && substr($head, 8, 4) === 'WAVE') {
                return $this->probeWav($fh, $size);
            }
            if (substr($head, 0, 4) === 'FORM' && (substr($head, 8, 4) === 'AIFF' || substr($head, 8, 4) === 'AIFC')) {
                return $this->probeAiff($fh, $size, substr($head, 8, 4) === 'AIFC');
            }
            if (substr($head, 0, 4) === "\x1A\x45\xDF\xA3") {
                return ['container' => 'webm', 'format' => 'WebM/Matroska'] + $result;
            }
            if (substr($head, 0, 4) === 'caff') {
                return ['container' => 'caf', 'format' => 'Core Audio (CAF)'] + $result;
            }
            if (ord($head[0]) === 0xFF && (ord($head[1]) & 0xF6) === 0xF0) {
                // ADTS-Kopf: Layer-Bits 00 - das ist AAC, nicht MP3
                return ['container' => 'aac', 'format' => 'AAC'] + $result;
            }
        } catch (\Throwable $e) {
            // Beschaedigte Datei: kein Ergebnis
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public static function empty(): array {
        return [
            'container' => null,
            'duration' => null,
            'sampleRate' => null,
            'channels' => null,
            'bitrate' => null,
            'format' => null,
            'tags' => [],
            'cover' => null,
            'id3' => null,
        ];
    }

    // ------------------------------------------------------------------
    // FLAC
    // ------------------------------------------------------------------

    /**
     * @param resource $fh
     * @return array<string, mixed>
     */
    private function probeFlac($fh, int $base, int $size): array {
        $out = ['container' => 'flac', 'format' => 'FLAC'] + self::empty();
        $pos = $base + 4;

        for ($i = 0; $i < self::MAX_ITEMS && $pos + 4 <= $size; $i++) {
            $h = $this->readAt($fh, $pos, 4);
            if (strlen($h) !== 4) {
                break;
            }
            $last = (ord($h[0]) & 0x80) !== 0;
            $type = ord($h[0]) & 0x7F;
            $len = (ord($h[1]) << 16) | (ord($h[2]) << 8) | ord($h[3]);
            $data = $pos + 4;

            if ($type === 0 && $len >= 34) {
                $this->applyStreamInfo($out, $this->readAt($fh, $data, 34));
            } elseif ($type === 4 && $len <= self::MAX_TAG_BYTES) {
                $this->applyVorbisComment($out, $this->readAt($fh, $data, $len));
            } elseif ($type === 6 && $len > 32) {
                $picture = $this->flacPicture($this->readAt($fh, $data, min($len, 65536)), $data, $len);
                if ($picture !== null) {
                    $this->offerCover($out, ['offset' => $picture['offset'], 'length' => $picture['length'], 'mime' => $picture['mime']], $picture['front']);
                }
            }

            $pos = $data + $len;
            if ($last) {
                break;
            }
        }

        $this->finishBitrate($out, $size - $pos);
        return $out;
    }

    /**
     * Merkt ein gefundenes Bild vor. Gibt es mehrere, gewinnt die
     * Vorderseite (Bildtyp 3), sonst das erste - wie bei MP3.
     */
    private function offerCover(array &$out, array $cover, bool $front): void {
        if ($out['cover'] === null || ($front && empty($out['_coverFront']))) {
            $out['cover'] = $cover;
            $out['_coverFront'] = $front;
        }
    }

    /** Wertet einen 34 Byte langen STREAMINFO-Block aus. */
    private function applyStreamInfo(array &$out, string $d): void {
        if (strlen($d) < 18) {
            return;
        }
        $rate = (ord($d[10]) << 12) | (ord($d[11]) << 4) | (ord($d[12]) >> 4);
        $channels = ((ord($d[12]) >> 1) & 0x07) + 1;
        $bits = (((ord($d[12]) & 0x01) << 4) | (ord($d[13]) >> 4)) + 1;
        $samples = ((ord($d[13]) & 0x0F) << 32)
            | (ord($d[14]) << 24) | (ord($d[15]) << 16) | (ord($d[16]) << 8) | ord($d[17]);
        if ($rate > 0) {
            $out['sampleRate'] = $rate;
            if ($samples > 0) {
                $out['duration'] = $samples / $rate;
            }
        }
        $out['channels'] = $channels;
        if ($out['format'] === 'FLAC' || $out['format'] === 'Ogg FLAC') {
            $out['format'] .= ' · ' . $bits . ' bit';
        }
    }

    /**
     * FLAC-PICTURE-Block (auch base64 in Vorbis-Kommentaren).
     *
     * @return array{offset: int, length: int, mime: string, type: int, front: bool}|null
     */
    private function flacPicture(string $d, int $fileOffset, int $blockLength): ?array {
        if (strlen($d) < 32) {
            return null;
        }
        $type = unpack('N', substr($d, 0, 4))[1];
        $mimeLen = unpack('N', substr($d, 4, 4))[1];
        if ($mimeLen > 256 || 8 + $mimeLen + 4 > strlen($d)) {
            return null;
        }
        $mime = substr($d, 8, $mimeLen);
        $p = 8 + $mimeLen;
        $descLen = unpack('N', substr($d, $p, 4))[1];
        $p += 4 + $descLen + 16;
        if ($p + 4 > strlen($d)) {
            return null;
        }
        $length = unpack('N', substr($d, $p, 4))[1];
        $p += 4;
        if ($length <= 0 || $p + $length > $blockLength) {
            return null;
        }
        return [
            'offset' => $fileOffset + $p,
            'length' => $length,
            'mime' => $this->imageMime($mime),
            'type' => $type,
            'front' => $type === 3,
        ];
    }

    /**
     * Vorbis-Kommentar (FLAC, Ogg Vorbis, Opus): Laenge+Text, little endian.
     */
    private function applyVorbisComment(array &$out, string $d): void {
        $len = strlen($d);
        if ($len < 8) {
            return;
        }
        $vendorLen = unpack('V', substr($d, 0, 4))[1];
        $p = 4 + $vendorLen;
        if ($p + 4 > $len) {
            return;
        }
        $count = unpack('V', substr($d, $p, 4))[1];
        $p += 4;
        $totals = [];

        for ($i = 0; $i < $count && $i < self::MAX_ITEMS && $p + 4 <= $len; $i++) {
            $entryLen = unpack('V', substr($d, $p, 4))[1];
            $p += 4;
            if ($entryLen < 0 || $p + $entryLen > $len) {
                break;
            }
            $entry = substr($d, $p, $entryLen);
            $p += $entryLen;

            $eq = strpos($entry, '=');
            if ($eq === false) {
                continue;
            }
            $key = strtoupper(substr($entry, 0, $eq));
            $value = substr($entry, $eq + 1);

            if ($key === 'METADATA_BLOCK_PICTURE') {
                // base64-kodierter FLAC-Bildblock; nur dekodieren, solange
                // noch keine Vorderseite gefunden ist
                if ($out['cover'] === null || empty($out['_coverFront'])) {
                    $raw = base64_decode($value, true);
                    $pic = $raw === false ? null : $this->flacPicture($raw, 0, strlen($raw));
                    if ($pic !== null) {
                        $this->offerCover($out, ['data' => substr($raw, $pic['offset'], $pic['length']), 'mime' => $pic['mime']], $pic['front']);
                    }
                }
                continue;
            }
            if (in_array($key, ['TRACKTOTAL', 'TOTALTRACKS', 'DISCTOTAL', 'TOTALDISCS'], true)) {
                $totals[str_contains($key, 'TRACK') ? 'track' : 'disc'] = trim($value);
                continue;
            }
            $field = self::VORBIS_FIELDS[$key] ?? null;
            if ($field !== null) {
                $this->setTag($out, $field, $value);
            }
        }

        foreach ($totals as $field => $total) {
            if (isset($out['tags'][$field]) && $total !== '' && !str_contains($out['tags'][$field], '/')) {
                $out['tags'][$field] .= '/' . $total;
            }
        }
    }

    // ------------------------------------------------------------------
    // Ogg (Vorbis, Opus, FLAC)
    // ------------------------------------------------------------------

    /**
     * @param resource $fh
     * @return array<string, mixed>
     */
    private function probeOgg($fh, int $size): array {
        $out = ['container' => 'ogg', 'format' => 'Ogg'] + self::empty();

        // Die ersten beiden Pakete des ersten Datenstroms zusammensetzen:
        // Kennungs-Kopf und Kommentar-Kopf (der kann ueber Seiten reichen)
        $packets = [];
        $current = '';
        $serial = null;
        $pos = 0;
        for ($page = 0; $page < 2000 && count($packets) < 2 && $pos + 27 <= $size; $page++) {
            $h = $this->readAt($fh, $pos, 27);
            if (strlen($h) !== 27 || substr($h, 0, 4) !== 'OggS') {
                break;
            }
            $segments = ord($h[26]);
            $table = $this->readAt($fh, $pos + 27, $segments);
            $bodyLen = 0;
            for ($i = 0; $i < strlen($table); $i++) {
                $bodyLen += ord($table[$i]);
            }
            $pageSerial = substr($h, 14, 4);
            $serial ??= $pageSerial;
            if ($pageSerial === $serial) {
                $body = $this->readAt($fh, $pos + 27 + $segments, $bodyLen);
                $offset = 0;
                for ($i = 0; $i < strlen($table) && count($packets) < 2; $i++) {
                    $lace = ord($table[$i]);
                    $current .= substr($body, $offset, $lace);
                    $offset += $lace;
                    if ($lace < 255) {
                        $packets[] = $current;
                        $current = '';
                    }
                }
                if (strlen($current) > self::MAX_TAG_BYTES) {
                    break; // riesiger Kommentar (Bild): Tags ohne ihn
                }
            }
            $pos += 27 + $segments + $bodyLen;
        }

        if ($packets === [] || $serial === null) {
            return $out;
        }

        $id = $packets[0];
        $granuleRate = 0;
        $preSkip = 0;
        $comment = null;

        if (substr($id, 0, 7) === "\x01vorbis" && strlen($id) >= 28) {
            $out['format'] = 'Ogg Vorbis';
            $out['channels'] = ord($id[11]);
            $out['sampleRate'] = unpack('V', substr($id, 12, 4))[1];
            $granuleRate = $out['sampleRate'];
            if (isset($packets[1]) && substr($packets[1], 0, 7) === "\x03vorbis") {
                $comment = substr($packets[1], 7);
            }
        } elseif (substr($id, 0, 8) === 'OpusHead' && strlen($id) >= 19) {
            $out['format'] = 'Opus';
            $out['channels'] = ord($id[9]);
            $preSkip = unpack('v', substr($id, 10, 2))[1];
            $inputRate = unpack('V', substr($id, 12, 4))[1];
            $out['sampleRate'] = $inputRate > 0 ? $inputRate : 48000;
            $granuleRate = 48000; // Opus zaehlt immer in 48 kHz
            if (isset($packets[1]) && substr($packets[1], 0, 8) === 'OpusTags') {
                $comment = substr($packets[1], 8);
            }
        } elseif (substr($id, 0, 5) === "\x7FFLAC" && strlen($id) >= 51) {
            $out['format'] = 'Ogg FLAC';
            $this->applyStreamInfo($out, substr($id, 17, 34));
            $granuleRate = (int)$out['sampleRate'];
            $out['duration'] = null; // kommt unten aus der letzten Seite
            if (isset($packets[1]) && strlen($packets[1]) > 4 && (ord($packets[1][0]) & 0x7F) === 4) {
                $comment = substr($packets[1], 4);
            }
        }

        if ($comment !== null) {
            $this->applyVorbisComment($out, $comment);
        }

        if ($granuleRate > 0) {
            $granule = $this->lastGranule($fh, $size, $serial);
            if ($granule !== null && $granule > $preSkip) {
                $out['duration'] = ($granule - $preSkip) / $granuleRate;
            }
        }

        $this->finishBitrate($out, $size - strlen($packets[0]) - strlen($packets[1] ?? ''));
        return $out;
    }

    /**
     * Position (in Samples) der letzten Seite des Datenstroms - daraus
     * ergibt sich die Dauer.
     *
     * @param resource $fh
     */
    private function lastGranule($fh, int $size, string $serial): ?int {
        foreach ([65536, 1024 * 1024] as $window) {
            $start = max(0, $size - $window);
            $tail = $this->readAt($fh, $start, $size - $start);
            $p = strlen($tail);
            while ($p > 0 && ($p = strrpos(substr($tail, 0, $p), 'OggS')) !== false) {
                if ($p + 27 <= strlen($tail) && substr($tail, $p + 14, 4) === $serial) {
                    $granule = unpack('P', substr($tail, $p + 6, 8))[1];
                    if ($granule > 0) {
                        return $granule;
                    }
                }
            }
            if ($start === 0) {
                break;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // MP4 / M4A
    // ------------------------------------------------------------------

    /**
     * @param resource $fh
     * @return array<string, mixed>
     */
    private function probeMp4($fh, int $size): array {
        $out = ['container' => 'mp4', 'format' => 'MP4'] + self::empty();
        $state = ['timescale' => 0, 'length' => 0, 'codec' => null, 'trackDuration' => null];

        $audioBytes = 0;
        foreach ($this->boxes($fh, 0, $size) as [$type, $start, $end]) {
            if ($type === 'moov') {
                $this->walkMp4($fh, $start, $end, $out, $state, 0);
            } elseif ($type === 'mdat') {
                $audioBytes += $end - $start;
            }
        }

        if ($state['timescale'] > 0 && $state['length'] > 0) {
            $out['duration'] = $state['length'] / $state['timescale'];
        } elseif ($state['trackDuration'] !== null) {
            $out['duration'] = $state['trackDuration'];
        }
        if ($state['codec'] !== null) {
            $out['format'] = (self::MP4_CODECS[$state['codec']] ?? trim($state['codec'])) . ' (MP4)';
        }

        $this->finishBitrate($out, $audioBytes);
        return $out;
    }

    /**
     * Steigt rekursiv durch die Kaesten, die fuer Dauer, Codec und Tags
     * gebraucht werden. Grosse Kaesten (Tondaten, Sample-Tabellen) werden
     * nur uebersprungen, nie gelesen.
     *
     * @param resource $fh
     */
    private function walkMp4($fh, int $from, int $to, array &$out, array &$state, int $depth): void {
        if ($depth > 8) {
            return;
        }
        foreach ($this->boxes($fh, $from, $to) as [$type, $start, $end]) {
            switch ($type) {
                case 'trak':
                case 'mdia':
                case 'minf':
                case 'stbl':
                case 'udta':
                    $this->walkMp4($fh, $start, $end, $out, $state, $depth + 1);
                    break;
                case 'meta':
                    // ISO: "voller" Kasten mit 4 Byte Version/Flags vorneweg;
                    // QuickTime: Kinder beginnen sofort
                    $skip = $this->readAt($fh, $start + 4, 4) === 'hdlr' ? 0 : 4;
                    $this->walkMp4($fh, $start + $skip, $end, $out, $state, $depth + 1);
                    break;
                case 'ilst':
                    $this->readIlst($fh, $start, $end, $out);
                    break;
                case 'mvhd':
                    $d = $this->readAt($fh, $start, 32);
                    if (strlen($d) >= 20 && ord($d[0]) === 1 && strlen($d) >= 32) {
                        $state['timescale'] = unpack('N', substr($d, 20, 4))[1];
                        $state['length'] = unpack('J', substr($d, 24, 8))[1];
                    } elseif (strlen($d) >= 20) {
                        $state['timescale'] = unpack('N', substr($d, 12, 4))[1];
                        $state['length'] = unpack('N', substr($d, 16, 4))[1];
                    }
                    break;
                case 'mdhd':
                    if ($state['trackDuration'] === null) {
                        $d = $this->readAt($fh, $start, 32);
                        if (strlen($d) >= 32 && ord($d[0]) === 1) {
                            $scale = unpack('N', substr($d, 20, 4))[1];
                            $len = unpack('J', substr($d, 24, 8))[1];
                        } elseif (strlen($d) >= 20) {
                            $scale = unpack('N', substr($d, 12, 4))[1];
                            $len = unpack('N', substr($d, 16, 4))[1];
                        } else {
                            break;
                        }
                        if ($scale > 0 && $len > 0) {
                            $state['trackDuration'] = $len / $scale;
                        }
                    }
                    break;
                case 'stsd':
                    if ($state['codec'] === null) {
                        // 4 Version/Flags + 4 Anzahl, dann der erste Eintrag
                        $d = $this->readAt($fh, $start + 8, 36);
                        if (strlen($d) >= 36) {
                            $codec = substr($d, 4, 4);
                            $state['codec'] = $codec;
                            $out['channels'] = unpack('n', substr($d, 24, 2))[1] ?: null;
                            $rate = unpack('N', substr($d, 32, 4))[1] >> 16;
                            $out['sampleRate'] = $rate > 0 ? $rate : null;
                        }
                    }
                    break;
            }
        }
    }

    /**
     * iTunes-Tags: Jedes Feld ist ein Kasten mit einem "data"-Kasten
     * (Typ, Sprache, Wert) darin.
     *
     * @param resource $fh
     */
    private function readIlst($fh, int $from, int $to, array &$out): void {
        foreach ($this->boxes($fh, $from, $to) as [$type, $start, $end]) {
            foreach ($this->boxes($fh, $start, $end) as [$inner, $dStart, $dEnd]) {
                if ($inner !== 'data' || $dEnd - $dStart < 8) {
                    continue;
                }
                $kind = unpack('N', $this->readAt($fh, $dStart, 4))[1] & 0x00FFFFFF;
                $valueStart = $dStart + 8;
                $valueLength = $dEnd - $valueStart;

                if ($type === 'covr') {
                    if ($out['cover'] === null && $valueLength > 0) {
                        $mime = $kind === 14 ? 'image/png' : ($kind === 27 ? 'image/bmp' : 'image/jpeg');
                        if ($kind !== 13 && $kind !== 14 && $kind !== 27) {
                            $mime = $this->sniffImage($this->readAt($fh, $valueStart, 8)) ?? 'image/jpeg';
                        }
                        $out['cover'] = ['offset' => $valueStart, 'length' => $valueLength, 'mime' => $mime];
                    }
                    break;
                }
                if ($valueLength > 65536) {
                    break;
                }
                $value = $this->readAt($fh, $valueStart, $valueLength);

                if ($type === 'trkn' || $type === 'disk') {
                    if (strlen($value) >= 6) {
                        $n = unpack('n', substr($value, 2, 2))[1];
                        $total = unpack('n', substr($value, 4, 2))[1];
                        if ($n > 0) {
                            $this->setTag($out, $type === 'trkn' ? 'track' : 'disc', $total > 0 ? $n . '/' . $total : (string)$n);
                        }
                    }
                } elseif ($type === 'gnre') {
                    // Nummer des ID3v1-Genres + 1; der MetadataReader loest "(n)" auf
                    if (strlen($value) >= 2) {
                        $n = unpack('n', substr($value, 0, 2))[1];
                        if ($n > 0) {
                            $this->setTag($out, 'genre', '(' . ($n - 1) . ')');
                        }
                    }
                } elseif (isset(self::MP4_FIELDS[$type])) {
                    if ($kind === 2) {
                        $value = $this->fromUtf16($value, false);
                    }
                    $this->setTag($out, self::MP4_FIELDS[$type], $value);
                }
                break;
            }
        }
    }

    // ------------------------------------------------------------------
    // WAV
    // ------------------------------------------------------------------

    /**
     * @param resource $fh
     * @return array<string, mixed>
     */
    private function probeWav($fh, int $size): array {
        $out = ['container' => 'wav', 'format' => 'WAV'] + self::empty();
        $byteRate = 0;
        $bits = 0;
        $codec = null;
        $dataSize = null;
        $pos = 12;

        for ($i = 0; $i < self::MAX_ITEMS && $pos + 8 <= $size; $i++) {
            $h = $this->readAt($fh, $pos, 8);
            if (strlen($h) !== 8) {
                break;
            }
            $id = substr($h, 0, 4);
            $len = unpack('V', substr($h, 4, 4))[1];
            $data = $pos + 8;

            if ($id === 'fmt ' && $len >= 16) {
                $f = $this->readAt($fh, $data, min($len, 40));
                $codec = unpack('v', substr($f, 0, 2))[1];
                $out['channels'] = unpack('v', substr($f, 2, 2))[1];
                $out['sampleRate'] = unpack('V', substr($f, 4, 4))[1];
                $byteRate = unpack('V', substr($f, 8, 4))[1];
                $bits = unpack('v', substr($f, 14, 2))[1];
                if ($codec === 0xFFFE && strlen($f) >= 26) {
                    $codec = unpack('v', substr($f, 24, 2))[1]; // WAVE_FORMAT_EXTENSIBLE
                }
            } elseif ($id === 'data') {
                // RF64 bzw. abgebrochene Aufnahmen: Rest der Datei
                $dataSize = ($len === 0xFFFFFFFF || $data + $len > $size) ? $size - $data : $len;
                if ($len === 0xFFFFFFFF) {
                    break; // dahinter laesst sich nicht weiterspringen
                }
            } elseif ($id === 'LIST' && $len >= 4 && $len <= self::MAX_TAG_BYTES) {
                $list = $this->readAt($fh, $data, $len);
                if (substr($list, 0, 4) === 'INFO') {
                    $this->readRiffInfo(substr($list, 4), $out);
                }
            } elseif (($id === 'id3 ' || $id === 'ID3 ') && $len > 10) {
                $out['id3'] = ['offset' => $data, 'length' => min($len, $size - $data)];
            }

            $pos = $data + $len + ($len & 1);
        }

        if ($codec !== null) {
            $name = self::WAV_CODECS[$codec] ?? 'Codec ' . $codec;
            $out['format'] = 'WAV · ' . $name . ($bits > 0 && in_array($codec, [1, 3], true) ? ' · ' . $bits . ' bit' : '');
        }
        if ($byteRate > 0) {
            $out['bitrate'] = (int)round($byteRate * 8 / 1000);
            if ($dataSize !== null) {
                $out['duration'] = $dataSize / $byteRate;
            }
        }
        return $out;
    }

    private function readRiffInfo(string $list, array &$out): void {
        $p = 0;
        $len = strlen($list);
        for ($i = 0; $i < self::MAX_ITEMS && $p + 8 <= $len; $i++) {
            $id = substr($list, $p, 4);
            $size = unpack('V', substr($list, $p + 4, 4))[1];
            $value = substr($list, $p + 8, $size);
            if (isset(self::RIFF_FIELDS[$id])) {
                $this->setTag($out, self::RIFF_FIELDS[$id], $value);
            }
            $p += 8 + $size + ($size & 1);
        }
    }

    // ------------------------------------------------------------------
    // AIFF / AIFC
    // ------------------------------------------------------------------

    /**
     * @param resource $fh
     * @return array<string, mixed>
     */
    private function probeAiff($fh, int $size, bool $isAifc): array {
        $out = ['container' => 'aiff', 'format' => $isAifc ? 'AIFF-C' : 'AIFF'] + self::empty();
        $pos = 12;

        for ($i = 0; $i < self::MAX_ITEMS && $pos + 8 <= $size; $i++) {
            $h = $this->readAt($fh, $pos, 8);
            if (strlen($h) !== 8) {
                break;
            }
            $id = substr($h, 0, 4);
            $len = unpack('N', substr($h, 4, 4))[1];
            $data = $pos + 8;

            if ($id === 'COMM' && $len >= 18) {
                $c = $this->readAt($fh, $data, min($len, 64));
                $channels = unpack('n', substr($c, 0, 2))[1];
                $frames = unpack('N', substr($c, 2, 4))[1];
                $bits = unpack('n', substr($c, 6, 2))[1];
                $rate = $this->extended80(substr($c, 8, 10));
                $out['channels'] = $channels ?: null;
                if ($rate > 0) {
                    $out['sampleRate'] = (int)round($rate);
                    $out['duration'] = $frames / $rate;
                }
                $compression = ($isAifc && strlen($c) >= 22) ? substr($c, 18, 4) : 'NONE';
                $uncompressed = in_array($compression, ['NONE', 'sowt', 'twos', 'raw '], true);
                if ($uncompressed && $bits > 0) {
                    $out['format'] .= ' · PCM · ' . $bits . ' bit';
                    if ($rate > 0) {
                        $out['bitrate'] = (int)round($rate * $channels * $bits / 1000);
                    }
                } elseif (in_array($compression, ['fl32', 'FL32', 'fl64', 'FL64'], true)) {
                    $out['format'] .= ' · PCM (Gleitkomma)';
                } elseif (!$uncompressed) {
                    $out['format'] .= ' · ' . trim($compression);
                }
            } elseif (isset(self::AIFF_FIELDS[$id]) && $len <= 65536) {
                $this->setTag($out, self::AIFF_FIELDS[$id], $this->readAt($fh, $data, $len));
            } elseif (($id === 'ID3 ' || $id === 'id3 ') && $len > 10) {
                $out['id3'] = ['offset' => $data, 'length' => min($len, $size - $data)];
            }

            $pos = $data + $len + ($len & 1);
        }

        if ($out['bitrate'] === null) {
            $this->finishBitrate($out, $size);
        }
        return $out;
    }

    /** IEEE-754-Zahl mit 80 Bit (Abtastrate im AIFF-Kopf). */
    private function extended80(string $b): float {
        if (strlen($b) !== 10) {
            return 0.0;
        }
        $exponent = ((ord($b[0]) & 0x7F) << 8) | ord($b[1]);
        $hi = unpack('N', substr($b, 2, 4))[1];
        $lo = unpack('N', substr($b, 6, 4))[1];
        if ($exponent === 0 && $hi === 0 && $lo === 0) {
            return 0.0;
        }
        $mantissa = $hi * 4294967296.0 + $lo;
        return $mantissa * (2 ** ($exponent - 16383 - 63));
    }

    // ------------------------------------------------------------------
    // Hilfen
    // ------------------------------------------------------------------

    /**
     * Kaesten (MP4) zwischen from und to: [Typ, Inhaltsanfang, Ende].
     *
     * @param resource $fh
     * @return \Generator<array{0: string, 1: int, 2: int}>
     */
    private function boxes($fh, int $from, int $to): \Generator {
        $pos = $from;
        for ($i = 0; $i < self::MAX_ITEMS && $pos + 8 <= $to; $i++) {
            $h = $this->readAt($fh, $pos, 8);
            if (strlen($h) !== 8) {
                return;
            }
            $boxSize = unpack('N', substr($h, 0, 4))[1];
            $type = substr($h, 4, 4);
            $header = 8;
            if ($boxSize === 1) {
                $large = $this->readAt($fh, $pos + 8, 8);
                if (strlen($large) !== 8) {
                    return;
                }
                $boxSize = unpack('J', $large)[1];
                $header = 16;
            } elseif ($boxSize === 0) {
                $boxSize = $to - $pos;
            }
            if ($boxSize < $header || $pos + $boxSize > $to) {
                return;
            }
            yield [$type, $pos + $header, $pos + $boxSize];
            $pos += $boxSize;
        }
    }

    /** Setzt ein Tag, sofern noch keines gesetzt ist; Text wird bereinigt. */
    private function setTag(array &$out, string $field, string $value): void {
        $value = trim(str_replace("\0", '', $this->toUtf8($value)));
        if ($value === '' || isset($out['tags'][$field])) {
            return;
        }
        if ($field === 'year') {
            $value = substr($value, 0, 4);
        }
        $out['tags'][$field] = $value;
    }

    /**
     * Mittlere Bitrate aus der Menge der Tondaten und der Dauer. Kopf und
     * eingebettete Bilder zaehlen nicht mit, sonst wirkt eine Datei mit
     * grossem Cover viel hochwertiger, als sie ist.
     */
    private function finishBitrate(array &$out, int $audioBytes): void {
        if ($out['bitrate'] === null && $audioBytes > 0 && $out['duration'] !== null && $out['duration'] > 0) {
            $out['bitrate'] = (int)round($audioBytes * 8 / $out['duration'] / 1000);
        }
    }

    private function toUtf8(string $s): string {
        if ($s === '' || preg_match('//u', $s) === 1) {
            return $s;
        }
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
        // Rueckfall: Latin-1 Zeichen fuer Zeichen
        $out = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = ord($s[$i]);
            $out .= $c < 0x80 ? $s[$i] : chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F));
        }
        return $out;
    }

    private function fromUtf16(string $s, bool $littleEndian): string {
        $converted = @iconv($littleEndian ? 'UTF-16LE' : 'UTF-16BE', 'UTF-8//IGNORE', $s);
        return is_string($converted) ? $converted : '';
    }

    private function imageMime(string $mime): string {
        $mime = strtolower(trim($mime));
        if ($mime === 'image/jpg') {
            return 'image/jpeg';
        }
        return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'], true) ? $mime : 'image/jpeg';
    }

    private function sniffImage(string $head): ?string {
        if (str_starts_with($head, "\xFF\xD8")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, "\x89PNG")) {
            return 'image/png';
        }
        if (str_starts_with($head, 'GIF8')) {
            return 'image/gif';
        }
        if (str_starts_with($head, 'BM')) {
            return 'image/bmp';
        }
        return null;
    }

    /**
     * Liest genau length Bytes ab offset (Datei-Stroeme liefern je fread
     * oft nur 8 KB).
     *
     * @param resource $fh
     */
    public function readAt($fh, int $offset, int $length): string {
        if ($length <= 0 || fseek($fh, $offset) !== 0) {
            return '';
        }
        $data = '';
        while (strlen($data) < $length && !feof($fh)) {
            $chunk = fread($fh, min(1024 * 1024, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }
}

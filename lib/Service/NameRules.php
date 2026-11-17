<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

/**
 * Namensmuster auf dem Server (ab 1.2.1, Vikunja #67).
 *
 * Gleiche Regeln wie js/name-rules.js (dort ausfuehrlich beschrieben). Der
 * Server braucht sie fuer die Suche - sie findet auf Seiten mit Mustern die
 * ANGEZEIGTEN Namen, nicht die Dateinamen - und fuer Namen, die er selbst
 * ausgibt (Titel eines Links ohne eigenen Titel, Name einer mit mir
 * geteilten Freigabe). Aenderungen immer in beiden Dateien gleich machen.
 */
class NameRules {

    private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli',
        'August', 'September', 'Oktober', 'November', 'Dezember'];
    private const TOKEN = '/\{([^{}:]+?)(?::([^{}]*))?\}/u';
    private const LETTERS = 'A-Za-zÀ-ÖØ-öø-ÿĀ-žẞ';
    private const DATE_RE = '\d{4}[-_.]\d{1,2}[-_.]\d{1,2}|\d{8}|\d{1,2}\.\d{1,2}\.\d{4}|\d{4}[-_.]\d{1,2}';

    /** @var array<string, array{re: string, slots: list<array{name: string, type: string}>}|null> */
    private static array $compiled = [];

    /**
     * Anzeigename nach den Regeln, oder null wenn keine passt.
     *
     * @param list<array> $rules
     * @param string $kind 'file' oder 'dir'
     */
    public static function apply(array $rules, string $name, string $kind): ?string {
        if ($rules === [] || $name === '') {
            return null;
        }
        foreach ($rules as $rule) {
            $pattern = (string)($rule['pattern'] ?? '');
            if ($pattern === '') {
                continue;
            }
            $applies = (string)($rule['applies'] ?? 'both');
            if (($kind === 'dir' && $applies === 'files') || ($kind === 'file' && $applies === 'folders')) {
                continue;
            }
            $c = self::compile($pattern);
            if ($c === null || !preg_match($c['re'], $name, $m)) {
                continue;
            }
            $values = [];
            foreach ($c['slots'] as $i => $slot) {
                $values[self::lower($slot['name'])] = $m[$i + 1] ?? '';
            }
            $shown = self::render($rule, $c['slots'], $values, $name);
            return $shown !== '' ? $shown : null;
        }
        return null;
    }

    /** Name nach den Regeln, sonst unveraendert. */
    public static function show(array $rules, string $name, string $kind): string {
        return self::apply($rules, $name, $kind) ?? $name;
    }

    /** Pfad (a/b/c) Abschnitt fuer Abschnitt; der letzte ist $lastKind. */
    public static function showPath(array $rules, string $path, string $lastKind): string {
        if ($rules === [] || $path === '') {
            return $path;
        }
        $parts = explode('/', $path);
        $last = count($parts) - 1;
        foreach ($parts as $i => $part) {
            $kind = $i === $last ? $lastKind : 'dir';
            $base = $kind === 'file' ? self::withoutExtension($part) : $part;
            $shown = self::apply($rules, $base, $kind);
            $parts[$i] = $shown ?? $part;
        }
        return implode('/', $parts);
    }

    public static function withoutExtension(string $file): string {
        $dot = strrpos($file, '.');
        return $dot === false || $dot === 0 ? $file : substr($file, 0, $dot);
    }

    /** @return array{re: string, slots: list<array{name: string, type: string}>}|null */
    private static function compile(string $pattern): ?array {
        if (array_key_exists($pattern, self::$compiled)) {
            return self::$compiled[$pattern];
        }
        $result = null;
        if (trim($pattern) !== '' && preg_match_all(self::TOKEN, $pattern, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $source = '^';
            $last = 0;
            $slots = [];
            $ok = true;
            foreach ($all as $match) {
                [$whole, $offset] = $match[0];
                $source .= self::literal(substr($pattern, $last, $offset - $last));
                $last = $offset + strlen($whole);
                $name = trim($match[1][0]);
                $type = self::lower(trim($match[2][0] ?? ''));
                if ($type === '' && self::lower($name) === 'datum') {
                    $type = 'datum';
                }
                if (preg_match('/^\d{1,3}$/', $type)) {
                    $group = '(.{' . max(1, (int)$type) . '})';
                } elseif ($type === 'zahl') {
                    $group = '(\d+)';
                } elseif ($type === 'buchstaben') {
                    $group = '([' . self::LETTERS . ']+)';
                } elseif ($type === 'wort') {
                    $group = '([^\s_\-]+)';
                } elseif ($type === 'datum') {
                    $group = '(' . self::DATE_RE . ')';
                } elseif ($type === '') {
                    $group = '(.+?)';
                } else {
                    $ok = false;
                    break;
                }
                $slots[] = ['name' => $name, 'type' => $type];
                $source .= $group;
            }
            if ($ok && $slots !== []) {
                $source .= self::literal(substr($pattern, $last)) . '$';
                $re = '/' . str_replace('/', '\/', $source) . '/iu';
                if (@preg_match($re, '') !== false) {
                    $result = ['re' => $re, 'slots' => $slots];
                }
            }
        }
        self::$compiled[$pattern] = $result;
        return $result;
    }

    /** Woertlicher Teil: Leerzeichen und Unterstrich gleichwertig. */
    private static function literal(string $text): string {
        $parts = preg_split('/[\s_]+/u', $text) ?: [$text];
        return implode('[\s_]+', array_map(static fn ($p) => preg_quote($p, '/'), $parts));
    }

    private static function render(array $rule, array $slots, array $values, string $original): string {
        $map = self::parseMap((string)($rule['map'] ?? ''));
        $types = [];
        foreach ($slots as $slot) {
            $types[self::lower($slot['name'])] = $slot['type'];
        }
        $spaces = ($rule['spaces'] ?? true) !== false;
        $out = preg_replace_callback(self::TOKEN, static function (array $m) use ($values, $types, $map, $spaces, $original): string {
            $lower = self::lower(trim($m[1]));
            $opt = self::lower(trim($m[2] ?? ''));
            if (array_key_exists($lower, $values)) {
                $value = (string)$values[$lower];
            } elseif ($lower === 'name') {
                $value = $original;
            } else {
                return '';
            }
            $key = self::lower($value);
            if (isset($map['byName'][$lower][$key])) {
                return self::applyCase($map['byName'][$lower][$key], $opt);
            }
            if (isset($map['all'][$key])) {
                return self::applyCase($map['all'][$key], $opt);
            }
            if (($types[$lower] ?? '') === 'datum' || in_array($opt, ['kurz', 'lang', 'jahr', 'monat', 'tag', 'iso'], true)) {
                $date = self::parseDate($value);
                if ($date !== null) {
                    return self::formatDate($date, $opt);
                }
            }
            if ($spaces) {
                $value = (string)preg_replace('/_+/u', ' ', $value);
            }
            return self::applyCase($value, $opt);
        }, (string)($rule['display'] ?? ''));
        return trim((string)preg_replace('/\s+/u', ' ', (string)$out));
    }

    /** @return array{all: array<string, string>, byName: array<string, array<string, string>>} */
    private static function parseMap(string $text): array {
        $all = [];
        $byName = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (!preg_match('/^\s*(.+?)\s*(?:=>|→|=)\s*(.*?)\s*$/u', $line, $m)) {
                continue;
            }
            $from = $m[1];
            $scope = '';
            if (preg_match('/^([^.]+)\.(.+)$/u', $from, $d)) {
                $scope = self::lower(trim($d[1]));
                $from = $d[2];
            }
            $key = self::lower(trim($from));
            if ($scope !== '') {
                $byName[$scope][$key] = $m[2];
            } else {
                $all[$key] = $m[2];
            }
        }
        return ['all' => $all, 'byName' => $byName];
    }

    /** @return array{y: int, m: int, d: int}|null */
    private static function parseDate(string $text): ?array {
        if (preg_match('/^(\d{4})[-_.]?(\d{1,2})[-_.]?(\d{1,2})$/', $text, $m)) {
            return self::valid((int)$m[1], (int)$m[2], (int)$m[3]);
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $text, $m)) {
            return self::valid((int)$m[3], (int)$m[2], (int)$m[1]);
        }
        if (preg_match('/^(\d{4})[-_.](\d{1,2})$/', $text, $m)) {
            return self::valid((int)$m[1], (int)$m[2], 0);
        }
        return null;
    }

    private static function valid(int $y, int $m, int $d): ?array {
        return ($m < 1 || $m > 12 || $d < 0 || $d > 31) ? null : ['y' => $y, 'm' => $m, 'd' => $d];
    }

    private static function formatDate(array $date, string $how): string {
        $two = static fn (int $n): string => str_pad((string)$n, 2, '0', STR_PAD_LEFT);
        return match ($how) {
            'kurz' => $date['d'] ? $two($date['d']) . '.' . $two($date['m']) . '.' . $date['y'] : $two($date['m']) . '.' . $date['y'],
            'jahr' => (string)$date['y'],
            'monat' => self::MONTHS[$date['m'] - 1],
            'tag' => $date['d'] ? (string)$date['d'] : '',
            'iso' => $date['y'] . '-' . $two($date['m']) . ($date['d'] ? '-' . $two($date['d']) : ''),
            default => ($date['d'] ? $date['d'] . '. ' : '') . self::MONTHS[$date['m'] - 1] . ' ' . $date['y'],
        };
    }

    private static function applyCase(string $value, string $opt): string {
        return match ($opt) {
            'groß', 'gross' => mb_strtoupper($value, 'UTF-8'),
            'klein' => mb_strtolower($value, 'UTF-8'),
            'anfang' => mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8'),
            default => $value,
        };
    }

    private static function lower(string $s): string {
        return mb_strtolower($s, 'UTF-8');
    }
}

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
     * Anzeigename nach den Regeln, oder null wenn keine passt. Ohne Blick
     * auf die Nachbarn - {x:mehrere} bleibt sichtbar; dafuer applyAll().
     *
     * @param list<array> $rules
     * @param string $kind 'file' oder 'dir'
     */
    public static function apply(array $rules, string $name, string $kind): ?string {
        if ($rules === [] || $name === '') {
            return null;
        }
        // Fertige Namen merken (ab 1.2.2)
        $key = md5(serialize($rules)) . '|' . $kind . '|' . $name;
        if (array_key_exists($key, self::$results)) {
            return self::$results[$key];
        }
        if (count(self::$results) > 20000) {
            self::$results = [];
        }
        $m = self::match($rules, $name, $kind);
        $shown = $m === null ? null : self::render($m['rule'], $m['slots'], $m['values'], $name, []);
        return self::$results[$key] = ($shown === '' ? null : $shown);
    }

    /** @var array<string, string|null> */
    private static array $results = [];

    /**
     * Alle Namen eines Ordners auf einmal (ab 1.2.3): wie apply(), dazu
     * {x:mehrere} - x entfaellt, wenn kein Nachbar sich nur in x unterscheidet.
     *
     * @param list<string> $names
     * @return array<string, string|null>
     */
    public static function applyAll(array $rules, array $names, string $kind): array {
        $out = [];
        if ($rules === []) {
            return $out;
        }
        $found = [];
        foreach ($names as $name) {
            $name = (string)$name;
            if ($name === '') {
                continue;
            }
            $m = self::match($rules, $name, $kind);
            if ($m === null) {
                $out[$name] = null;
            } else {
                $found[] = ['name' => $name, 'm' => $m];
            }
        }
        $groupKey = static function (array $f, string $x): string {
            $parts = [(string)$f['m']['index'], $x];
            foreach ($f['m']['slots'] as $slot) {
                $n = self::lower($slot['name']);
                if ($n !== $x) {
                    $parts[] = self::lower((string)$f['m']['values'][$n]);
                }
            }
            return implode("\x01", $parts);
        };
        $groups = [];
        foreach ($found as $f) {
            foreach (self::multiOf((string)($f['m']['rule']['display'] ?? '')) as $x) {
                if (array_key_exists($x, $f['m']['values'])) {
                    $groups[$groupKey($f, $x)][self::lower((string)$f['m']['values'][$x])] = true;
                }
            }
        }
        foreach ($found as $f) {
            $hidden = [];
            foreach (self::multiOf((string)($f['m']['rule']['display'] ?? '')) as $x) {
                if (array_key_exists($x, $f['m']['values']) && count($groups[$groupKey($f, $x)] ?? []) < 2) {
                    $hidden[$x] = true;
                }
            }
            $shown = self::render($f['m']['rule'], $f['m']['slots'], $f['m']['values'], $f['name'], $hidden);
            $out[$f['name']] = $shown === '' ? null : $shown;
        }
        return $out;
    }

    /** @return array{rule: array, index: int, slots: list<array>, values: array<string, string>}|null */
    private static function match(array $rules, string $name, string $kind): ?array {
        foreach ($rules as $index => $rule) {
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
            return ['rule' => $rule, 'index' => (int)$index, 'slots' => $c['slots'], 'values' => $values];
        }
        return null;
    }

    /** @var array<string, list<string>> */
    private static array $multi = [];

    /** @return list<string> Platzhalter mit {x:mehrere} (klein) */
    private static function multiOf(string $display): array {
        if (!array_key_exists($display, self::$multi)) {
            $set = [];
            if (preg_match_all(self::TOKEN, $display, $all, PREG_SET_ORDER)) {
                foreach ($all as $m) {
                    if (self::splitOpt($m[2] ?? '')['multi']) {
                        $set[self::lower(trim($m[1]))] = true;
                    }
                }
            }
            self::$multi[$display] = array_keys($set);
        }
        return self::$multi[$display];
    }

    /** @return array{multi: bool, fmt: string} */
    private static function splitOpt(string $raw): array {
        $parts = array_values(array_filter(array_map(
            static fn ($p) => self::lower(trim($p)), explode(',', $raw)
        ), static fn ($p) => $p !== ''));
        $fmt = '';
        foreach ($parts as $p) {
            if ($p !== 'mehrere') {
                $fmt = $p;
                break;
            }
        }
        return ['multi' => in_array('mehrere', $parts, true), 'fmt' => $fmt];
    }

    /** Name nach den Regeln, sonst unveraendert. */
    public static function show(array $rules, string $name, string $kind): string {
        return self::apply($rules, $name, $kind) ?? $name;
    }

    /**
     * Name eines Ordners samt Blick auf seine Nachbarn im uebergeordneten
     * Ordner ({x:mehrere}, ab 1.2.3) - fuer den Ordner einer Freigabe.
     */
    public static function showFolder(array $rules, \OCP\Files\Folder $folder): string {
        $name = $folder->getName();
        if ($rules === [] || $name === '') {
            return $name;
        }
        try {
            $names = [];
            foreach ($folder->getParent()->getDirectoryListing() as $sibling) {
                if ($sibling instanceof \OCP\Files\Folder && !str_starts_with($sibling->getName(), '.')) {
                    $names[] = $sibling->getName();
                }
            }
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
            return self::applyAll($rules, $names, 'dir')[$name] ?? $name;
        } catch (\Throwable $e) {
            return self::show($rules, $name, 'dir');
        }
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

    /** @var array<string, array> zerlegte Werte-Listen (ab 1.2.2) */
    private static array $maps = [];

    /** @param array<string, true> $hidden Platzhalter (klein), die entfallen */
    private static function render(array $rule, array $slots, array $values, string $original, array $hidden): string {
        $mapText = (string)($rule['map'] ?? '');
        $map = self::$maps[$mapText] ??= self::parseMap($mapText);
        $types = [];
        foreach ($slots as $slot) {
            $types[self::lower($slot['name'])] = $slot['type'];
        }
        $spaces = ($rule['spaces'] ?? true) !== false;
        $one = static function (string $rawName, string $rawOpt) use ($values, $types, $map, $spaces, $original, $hidden): string {
            $lower = self::lower(trim($rawName));
            if (isset($hidden[$lower])) {
                return '';
            }
            $opt = self::splitOpt($rawOpt)['fmt'];
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
        };
        $part = static function (string $text) use ($one): array {
            $had = false;
            $filled = false;
            $out = (string)preg_replace_callback(self::TOKEN, static function (array $m) use ($one, &$had, &$filled): string {
                $had = true;
                $v = $one($m[1], $m[2] ?? '');
                if ($v !== '') {
                    $filled = true;
                }
                return $v;
            }, $text);
            return [$out, $had, $filled];
        };
        // [ … ] entfaellt, wenn alle Platzhalter darin leer sind
        $outer = (string)preg_replace_callback('/\[([^\[\]]*)\]/u', static function (array $m) use ($part): string {
            [$out, $had, $filled] = $part($m[1]);
            return $had && !$filled ? '' : $out;
        }, (string)($rule['display'] ?? ''));
        return trim((string)preg_replace('/\s+/u', ' ', $part($outer)[0]));
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
        // Jede Schreibweise einzeln pruefen (ab 1.2.2), siehe js/name-rules.js
        $forms = [
            ['/^(\d{4})[-_.](\d{1,2})[-_.](\d{1,2})$/', [1, 2, 3]],
            ['/^(\d{4})(\d{2})(\d{2})$/', [1, 2, 3]],
            ['/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', [3, 2, 1]],
            ['/^(\d{4})[-_.](\d{1,2})$/', [1, 2, 0]],
        ];
        foreach ($forms as [$re, [$y, $mo, $d]]) {
            if (!preg_match($re, $text, $m)) {
                continue;
            }
            $date = self::valid((int)$m[$y], (int)$m[$mo], $d === 0 ? 0 : (int)$m[$d]);
            if ($date !== null) {
                return $date;
            }
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

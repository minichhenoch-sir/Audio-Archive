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
     * $path: Pfad des Namens im geteilten Ordner (ab 1.4.0, fuer Bereiche
     * und eigene Namen); null = der Name selbst.
     *
     * @param list<array> $rules
     * @param string $kind 'file' oder 'dir'
     */
    public static function apply(array $rules, string $name, string $kind, ?string $path = null): ?string {
        if ($rules === [] || $name === '') {
            return null;
        }
        $where = $path === null ? $name : self::cleanPath($path);
        // Fertige Namen merken (ab 1.2.2)
        $key = md5(serialize($rules)) . '|' . $kind . '|' . $where . '|' . $name;
        if (array_key_exists($key, self::$results)) {
            return self::$results[$key];
        }
        if (count(self::$results) > 20000) {
            self::$results = [];
        }
        $shown = self::overrideFor($rules, $kind, $where);
        if ($shown === null) {
            $m = self::match($rules, $name, $kind, $where);
            $shown = $m === null ? null : self::render($m['rule'], $m['slots'], $m['values'], $name, []);
        }
        return self::$results[$key] = ($shown === '' ? null : $shown);
    }

    /** @var array<string, string|null> */
    private static array $results = [];

    /**
     * Alle Namen eines Ordners auf einmal (ab 1.2.3): wie apply(), dazu
     * {x:mehrere} - x entfaellt, wenn kein Nachbar sich nur in x unterscheidet.
     * $parent: Pfad des Ordners (ab 1.4.0); $realNames: echte Dateinamen
     * (mit Endung) in derselben Reihenfolge, falls bekannt.
     *
     * @param list<string> $names
     * @param list<string>|null $realNames
     * @return array<string, string|null>
     */
    public static function applyAll(array $rules, array $names, string $kind, ?string $parent = null, ?array $realNames = null): array {
        $out = [];
        if ($rules === []) {
            return $out;
        }
        $found = [];
        foreach (array_values($names) as $i => $name) {
            $name = (string)$name;
            if ($name === '') {
                continue;
            }
            $real = $realNames !== null && isset($realNames[$i]) ? (string)$realNames[$i] : $name;
            $where = $parent === null ? $name : self::join($parent, $real);
            $own = self::overrideFor($rules, $kind, $where);
            if ($own !== null) {
                $out[$name] = $own;
                continue;
            }
            $m = self::match($rules, $name, $kind, $where);
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
    private static function match(array $rules, string $name, string $kind, string $path): ?array {
        foreach ($rules as $index => $rule) {
            if (!is_array($rule) || self::isOverride($rule)) {
                continue;
            }
            $pattern = (string)($rule['pattern'] ?? '');
            if ($pattern === '' || !self::covers($rule, $kind, $path)) {
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

    // ---- Wo eine Regel gilt (ab 1.4.0), wie in js/name-rules.js ----

    public static function cleanPath(string $p): string {
        return trim((string)preg_replace('#/+#', '/', str_replace('\\', '/', $p)), '/');
    }

    private static function join(string $parent, string $name): string {
        $p = self::cleanPath($parent);
        return $p === '' ? $name : $p . '/' . $name;
    }

    private static function stripExt(string $p): string {
        return (string)preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $p);
    }

    private static function samePath(string $a, string $b, string $kind): bool {
        return $a === $b || ($kind === 'file' && self::stripExt($a) === self::stripExt($b));
    }

    private static function depth(string $p): int {
        return $p === '' ? 0 : count(explode('/', $p));
    }

    private static function isOverride(array $rule): bool {
        return ($rule['kind'] ?? '') === 'name';
    }

    private static function covers(array $rule, string $kind, string $path): bool {
        $applies = (string)($rule['applies'] ?? 'both');
        if (($kind === 'dir' && $applies === 'files') || ($kind === 'file' && $applies === 'folders')) {
            return false;
        }
        $p = self::cleanPath($path);
        $where = is_array($rule['where'] ?? null) ? $rule['where'] : null;
        $area = $where !== null ? self::cleanPath((string)($where['path'] ?? '')) : '';
        if ($area !== '') {
            $sub = ($where['sub'] ?? true) !== false;
            $slash = strrpos($p, '/');
            $parent = $slash === false ? '' : substr($p, 0, $slash);
            if ($sub ? !str_starts_with($p, $area . '/') : $parent !== $area) {
                return false;
            }
        }
        $level = (int)($rule['level'] ?? 0);
        if ($level > 0 && self::depth($p) - self::depth($area) !== $level) {
            return false;
        }
        foreach (is_array($rule['except'] ?? null) ? $rule['except'] : [] as $e) {
            $x = self::cleanPath((string)$e);
            if ($x !== '' && (self::samePath($p, $x, $kind) || str_starts_with($p, $x . '/'))) {
                return false;
            }
        }
        return true;
    }

    private static function overrideFor(array $rules, string $kind, string $path): ?string {
        $p = self::cleanPath($path);
        foreach ($rules as $rule) {
            if (!is_array($rule) || !self::isOverride($rule) || (string)($rule['name'] ?? '') === '') {
                continue;
            }
            if ((($rule['target'] ?? '') === 'file') !== ($kind === 'file')) {
                continue;
            }
            if (self::samePath($p, self::cleanPath((string)($rule['path'] ?? '')), $kind)) {
                return (string)$rule['name'];
            }
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

    /**
     * Zusaetze {x:mehrere,groß,"T. MMMM JJJJ"} (Datumsformat ab 1.4.0).
     *
     * @return array{multi: bool, kase: string, date: string}
     */
    private static function splitOpt(string $raw): array {
        $date = '';
        if (preg_match('/"([^"]*)"/u', $raw, $q)) {
            $date = $q[1];
            $raw = str_replace($q[0], '', $raw);
        }
        $multi = false;
        $kase = '';
        foreach (explode(',', $raw) as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $low = self::lower($p);
            if ($low === 'mehrere') {
                $multi = true;
            } elseif (in_array($low, ['groß', 'gross', 'klein', 'anfang'], true)) {
                $kase = $low;
            } elseif (in_array($low, ['kurz', 'lang', 'jahr', 'monat', 'tag', 'iso'], true)) {
                $date = $date !== '' ? $date : $low;
            } elseif ($date === '' && preg_match("/^(?:'[^']*'|[TMJWDY.\\s\\/:-])+$/u", $p)) {
                $date = $p;
            }
        }
        return ['multi' => $multi, 'kase' => $kase, 'date' => $date];
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
            // Der geteilte Ordner selbst hat den Pfad '' (ab 1.4.0)
            $own = self::overrideFor($rules, 'dir', '');
            if ($own !== null) {
                return $own;
            }
            $shown = self::applyAll($rules, $names, 'dir')[$name] ?? null;
            $m = self::match($rules, $name, 'dir', '');
            return $m === null ? $name : ($shown ?? $name);
        } catch (\Throwable $e) {
            return self::apply($rules, $name, 'dir', '') ?? $name;
        }
    }

    /** Pfad (a/b/c) Abschnitt fuer Abschnitt; der letzte ist $lastKind. */
    public static function showPath(array $rules, string $path, string $lastKind): string {
        if ($rules === [] || $path === '') {
            return $path;
        }
        $parts = explode('/', $path);
        $out = $parts;
        $last = count($parts) - 1;
        foreach ($parts as $i => $part) {
            $kind = $i === $last ? $lastKind : 'dir';
            $base = $kind === 'file' ? self::withoutExtension($part) : $part;
            $shown = self::apply($rules, $base, $kind, implode('/', array_slice($parts, 0, $i + 1)));
            $out[$i] = $shown ?? $part;
        }
        return implode('/', $out);
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
        // Alte Regeln (vor 1.4.0) ohne Sprache: Deutsch wie bisher
        $lang = array_key_exists('lang', $rule) ? (string)$rule['lang'] : 'de';
        $one = static function (string $rawName, string $rawOpt) use ($values, $types, $map, $spaces, $original, $hidden, $lang): string {
            $lower = self::lower(trim($rawName));
            if (isset($hidden[$lower])) {
                return '';
            }
            $o = self::splitOpt($rawOpt);
            $opt = $o['kase'];
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
            if (($types[$lower] ?? '') === 'datum' || $o['date'] !== '') {
                $date = self::parseDate($value);
                if ($date !== null) {
                    return self::applyCase(self::formatDate($date, $o['date'], $lang), $opt);
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

    private static function formatDate(array $date, string $how, string $lang = 'de'): string {
        $two = static fn (int $n): string => str_pad((string)$n, 2, '0', STR_PAD_LEFT);
        return match ($how) {
            'kurz' => $date['d'] ? $two($date['d']) . '.' . $two($date['m']) . '.' . $date['y'] : $two($date['m']) . '.' . $date['y'],
            'jahr' => (string)$date['y'],
            'monat' => self::localName('month', $date['m'], $lang, true),
            'tag' => $date['d'] ? (string)$date['d'] : '',
            'iso' => $date['y'] . '-' . $two($date['m']) . ($date['d'] ? '-' . $two($date['d']) : ''),
            '', 'lang' => ($date['d'] ? $date['d'] . '. ' : '') . self::localName('month', $date['m'], $lang, true) . ' ' . $date['y'],
            default => self::customDate($date, $how, $lang),
        };
    }

    /** Eigenes Datumsformat ("T. MMMM JJJJ"), ab 1.4.0 - wie js/name-rules.js. */
    private static function customDate(array $date, string $fmt, string $lang): string {
        $two = static fn (int $n): string => str_pad((string)$n, 2, '0', STR_PAD_LEFT);
        if (!$date['d']) {
            $fmt = (string)preg_replace('#(WWWW|WW|TT|T|DD|D)[\s.,/-]*#u', '', $fmt);
            $fmt = (string)preg_replace('#^[\s.,/-]+|[\s.,/-]+$#u', '', $fmt);
        }
        $wd = $date['d'] ? (int)gmdate('w', gmmktime(0, 0, 0, $date['m'], $date['d'], $date['y'])) : 0;
        return (string)preg_replace_callback("/'[^']*'|WWWW|WW|MMMM|MMM|MM|M|TT|T|DD|D|JJJJ|JJ|YYYY|YY/u",
            static function (array $t) use ($date, $lang, $wd, $two): string {
                $t = $t[0];
                if ($t[0] === "'") {
                    return substr($t, 1, -1);
                }
                return match ($t) {
                    'WWWW' => self::localName('day', $wd, $lang, true),
                    'WW' => self::localName('day', $wd, $lang, false),
                    'MMMM' => self::localName('month', $date['m'], $lang, true),
                    'MMM' => self::localName('month', $date['m'], $lang, false),
                    'MM' => $two($date['m']),
                    'M' => (string)$date['m'],
                    'TT', 'DD' => $two($date['d']),
                    'T', 'D' => (string)$date['d'],
                    'JJJJ', 'YYYY' => (string)$date['y'],
                    'JJ', 'YY' => substr((string)$date['y'], -2),
                    default => $t,
                };
            }, $fmt);
    }

    /** @var array<string, string> */
    private static array $names = [];
    private static ?string $hearer = null;

    /** Sprache des Hoerers (Nextcloud bzw. Browser), fuer lang ''. */
    private static function hearerLang(): string {
        if (self::$hearer === null) {
            try {
                self::$hearer = (string)\OCP\Server::get(\OCP\L10N\IFactory::class)->findLanguage();
            } catch (\Throwable $e) {
                self::$hearer = 'de';
            }
        }
        return self::$hearer !== '' ? self::$hearer : 'de';
    }

    /** Monats- bzw. Tagesname (Intl, Rueckfall Deutsch/Englisch). */
    private static function localName(string $kind, int $n, string $lang, bool $long): string {
        $loc = str_replace('-', '_', $lang !== '' ? $lang : self::hearerLang());
        $key = $kind . '|' . $n . '|' . $loc . '|' . ($long ? 1 : 0);
        if (isset(self::$names[$key])) {
            return self::$names[$key];
        }
        $out = '';
        if (class_exists(\IntlDateFormatter::class)) {
            try {
                $pattern = $kind === 'month' ? ($long ? 'LLLL' : 'LLL') : ($long ? 'cccc' : 'ccc');
                $f = new \IntlDateFormatter($loc, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, $pattern);
                $ts = $kind === 'month' ? gmmktime(0, 0, 0, $n, 15, 2001) : gmmktime(0, 0, 0, 1, 7 + $n, 2001);
                $out = (string)$f->format($ts);
            } catch (\Throwable $e) {
                $out = '';
            }
        }
        if ($out === '') {
            $out = $kind === 'month'
                ? ($long ? self::MONTHS[$n - 1] : mb_substr(self::MONTHS[$n - 1], 0, 3))
                : ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][$n];
        }
        return self::$names[$key] = $out;
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

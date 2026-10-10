<?php
/**
 * Seite der Anleitung (ab 1.0.2, RENDER_AS_BLANK, siehe ManualController).
 *
 * Bewusst ohne Skripte: nur Markup und css/manual.css. Der Inhalt steht in
 * templates/manual/user.php bzw. admin.php; aus derselben Seite wird auch
 * die PDF-Fassung gedruckt (manual/*.pdf).
 *
 * @var array $_ Parameter aus dem Controller
 */
$escape = static fn (?string $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
// Bild mit Bildunterschrift; $file liegt in img/manual/
$figure = static function (string $file, string $caption, string $class = '') use ($escape, $_): string {
    return '<figure class="m-figure ' . $escape($class) . '"><img src="' . $escape($_['imgBase'] . $file)
        . '" alt="' . $escape($caption) . '" loading="lazy"><figcaption>' . $escape($caption) . '</figcaption></figure>';
};
$isAdmin = $_['kind'] === 'admin';
$color = static fn (string $c): string => preg_match('/^#[0-9a-f]{6}$/', $c) ? $c : '#291c12';
// Mischung zweier Farben (Anteil $t von $a), fuer Hinterlegungen und Links
$mix = static function (string $a, string $b, float $t): string {
    $ca = sscanf(ltrim($a, '#'), '%2x%2x%2x');
    $cb = sscanf(ltrim($b, '#'), '%2x%2x%2x');
    $out = '#';
    for ($i = 0; $i < 3; $i++) {
        $out .= sprintf('%02x', (int)round($ca[$i] * $t + $cb[$i] * (1 - $t)));
    }
    return $out;
};
$accent = $color($_['accent']);
$vars = [
    '--m-accent' => $accent,
    '--m-bar' => $color($_['bar']),
    '--m-tint-light' => $mix($accent, '#ffffff', 0.12),
    '--m-link-light' => $mix($accent, '#000000', 0.72),
    '--m-tint-dark' => $mix($accent, '#1d1a17', 0.2),
    '--m-link-dark' => $mix($accent, '#ffffff', 0.55),
];
$cssVars = implode(' ', array_map(static fn ($k, $v) => $k . ': ' . $v . ';', array_keys($vars), $vars));
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?php echo $escape($_['title']); ?></title>
<link rel="icon" href="<?php echo $escape($_['faviconUrl']); ?>">
<link rel="stylesheet" href="<?php echo $escape($_['cssUrl']); ?>">
<style>:root { <?php echo $cssVars; ?> }</style>
</head>
<body class="manual manual--<?php echo $isAdmin ? 'admin' : 'user'; ?>">
<header class="m-head">
  <div class="m-head-inner">
    <img class="m-logo" src="<?php echo $escape($_['imgBase'] . '../app.svg'); ?>" alt="" width="44" height="44">
    <div class="m-head-titles">
      <p class="m-kicker">Audio Archive <?php echo $escape($_['version']); ?></p>
      <h1><?php echo $isAdmin ? 'Anleitung für Administratoren' : 'Anleitung'; ?></h1>
    </div>
    <nav class="m-actions" aria-label="Aktionen">
      <!-- id="m-back": Der Service Worker setzt das Ziel offline neu (js/service-worker.js) -->
      <a id="m-back" class="m-btn" href="<?php echo $escape($_['backUrl'] !== '' ? $_['backUrl'] : '#'); ?>"<?php if ($_['backUrl'] === '') { ?> hidden<?php } ?>>← <?php echo $escape($_['backLabel']); ?></a>
      <a class="m-btn m-btn--primary" href="<?php echo $escape($_['pdfUrl']); ?>" download>⬇ Als PDF</a>
    </nav>
  </div>
</header>

<main class="m-main">
<?php
if ($isAdmin) {
    include __DIR__ . '/manual/admin.php';
} else {
    include __DIR__ . '/manual/user.php';
}
?>
</main>

<footer class="m-foot">
  <p>
    Audio Archive <?php echo $escape($_['version']); ?> ·
    <?php if ($isAdmin) { ?>
      <a href="<?php echo $escape($_['userUrl']); ?>">Anleitung für Hörer und Nutzer</a>
    <?php } elseif ($_['showAdminLink']) { ?>
      <a href="<?php echo $escape($_['adminUrl']); ?>">Anleitung für Administratoren</a>
    <?php } else { ?>
      Fragen? Oben in der App über den Knopf (i) „Hilfe“.
    <?php } ?>
  </p>
</footer>
</body>
</html>

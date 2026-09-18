<?php
/**
 * Eigenstaendige Seite der App (RENDER_AS_BLANK, siehe PlayerPage):
 * oeffentlicher Link und installierte App, ohne Nextcloud-Leiste.
 *
 * Nextclouds Seitengeruest wird hier bewusst nicht verwendet: Nur so laesst
 * sich ein eigenes Manifest einbinden und die App als eigene Kachel
 * installieren. Die Fassung mit Leiste steht in player-embedded.php.
 *
 * @var array $_ Parameter aus dem Controller
 */
$escape = static fn (?string $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$asset = static fn (string $file): string => $escape($_['assetBase']) . $file . '?v=' . $escape($_['assetVersion']);
?>
<!DOCTYPE html>
<html class="aa-standalone" lang="<?php echo $escape($_['language'] ?? 'de'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo $escape($_['headerTitle']); ?></title>

<link rel="manifest" href="<?php echo $escape($_['manifestUrl']); ?>">
<meta name="theme-color" content="<?php echo $escape($_['themeColor']); ?>">

<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?php echo $escape($_['headerTitle']); ?>">
<link rel="apple-touch-icon" href="<?php echo $escape($_['assetBase']); ?>img/apple-touch-icon.png">
<link rel="icon" href="<?php echo $escape($_['assetBase']); ?>img/icon-192.png">

<?php foreach ($_['themeStylesheets'] as $theme) { ?>
<link rel="stylesheet" media="<?php echo $escape($theme['media']); ?>" href="<?php echo $escape($theme['href']); ?>">
<?php } ?>
<link rel="stylesheet" href="<?php echo $asset('css/style.css'); ?>">
</head>
<body>
<?php include __DIR__ . '/parts/player-body.php'; ?>
</body>
</html>

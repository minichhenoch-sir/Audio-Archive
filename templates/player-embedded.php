<?php
/**
 * Player innerhalb von Nextcloud (RENDER_AS_USER, siehe PlayerPage).
 *
 * Nextcloud liefert Kopf, Kopfleiste und Seitengeruest; diese Vorlage
 * steuert nur den Inhalt bei. Deshalb hier kein Manifest und keine
 * theme-color: Beides gehoert zur eigenstaendigen Fassung (player.php),
 * von der aus die App installiert wird.
 *
 * Stylesheet und Skripte werden bewusst direkt eingebunden statt ueber
 * Util::addStyle/addScript: Nextcloud laedt Skripte dort als Module, in
 * denen die gemeinsame Konstante AudioArchive (config.js) nicht mehr
 * global waere. Das Nonce erlaubt die Skripte trotz Nextclouds
 * Sicherheitsrichtlinie (siehe PlayerPage::cspNonce()).
 *
 * @var array $_ Parameter aus dem Controller
 */
$escape = static fn (?string $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$asset = static fn (string $file): string => $escape($_['assetBase']) . $file . '?v=' . $escape($_['assetVersion']);
?>
<link rel="stylesheet" href="<?php echo $asset('css/style.css'); ?>">

<?php include __DIR__ . '/parts/player-body.php'; ?>

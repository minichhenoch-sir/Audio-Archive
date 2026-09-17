<?php
/**
 * Vollstaendige HTML-Seite (RENDER_AS_BLANK, siehe PlayerPage).
 *
 * @var array $_ Parameter aus dem Controller
 */
$escape = static fn (?string $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?php echo $escape($_['language'] ?? 'de'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo $escape($_['headerTitle']); ?></title>

<link rel="manifest" href="<?php echo $escape($_['manifestUrl']); ?>">
<meta name="theme-color" content="<?php echo $escape($_['themeBar']); ?>">

<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?php echo $escape($_['headerTitle']); ?>">
<link rel="apple-touch-icon" href="<?php echo $escape($_['assetBase']); ?>img/icon-192.png">
<link rel="icon" href="<?php echo $escape($_['assetBase']); ?>img/icon-192.png">

<link rel="stylesheet" href="<?php echo $escape($_['assetBase']); ?>css/style.css">
</head>
<body>

<div id="bg-layer" aria-hidden="true"></div>

<!--
  Startwerte fuer das Skript. Bewusst als data-Attribute statt als
  Inline-Skript: Nextclouds Sicherheitsrichtlinie erlaubt keine
  Inline-Skripte ohne Nonce.
-->
<div id="app-config"
     data-public-token="<?php echo $escape($_['publicToken']); ?>"
     data-header-title="<?php echo $escape($_['headerTitle']); ?>"
     data-header-subtitle="<?php echo $escape($_['headerSubtitle']); ?>"
     data-service-worker="<?php echo $escape($_['serviceWorkerUrl']); ?>"
     data-scope="<?php echo $escape($_['scopeUrl']); ?>"
     hidden></div>

<main id="prototype-check" class="prototype-check">
  <h1><?php echo $escape($_['headerTitle']); ?></h1>
  <p id="sw-state">Service Worker: wird geprüft …</p>
  <p id="manifest-state">Manifest: wird geprüft …</p>
  <p id="mode-state"></p>

  <!-- Anmeldung der oeffentlichen Seite (nur dort sichtbar) -->
  <div id="public-login" hidden>
    <p>Diese Seite ist passwortgeschützt.</p>
    <input type="password" id="public-password" placeholder="Passwort" autocomplete="current-password">
    <button type="button" id="public-login-btn">Anmelden</button>
    <p id="public-login-error"></p>
  </div>

  <h2 id="api-heading" hidden>Aufnahmen</h2>
  <div id="api-result"></div>
</main>

<script nonce="<?php echo $escape($_['cspNonce'] ?? ''); ?>"
        src="<?php echo $escape($_['assetBase']); ?>js/boot.js"></script>
</body>
</html>

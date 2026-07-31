<?php

function seo_current_url(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'agenda.andrepuchetti.com.br';
    $scheme = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    ) ? 'https' : 'https';
    $uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';

    return $scheme . '://' . $host . $uri;
}

function seo_asset_url(string $assetPath): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'agenda.andrepuchetti.com.br';
    return 'https://' . $host . '/' . ltrim($assetPath, '/');
}

function render_seo_meta(string $title, string $description, array $options = []): void
{
    $robots = $options['robots'] ?? 'index, follow';
    $canonical = $options['canonical'] ?? seo_current_url();
    $image = $options['image'] ?? seo_asset_url('assets/logo-salao.png');
    $faviconPath = $options['favicon_path'] ?? 'assets/logo-salao.png';
    $type = $options['type'] ?? 'website';
    ?>
  <meta name="description" content="<?= htmlspecialchars($description, ENT_QUOTES); ?>">
  <meta name="robots" content="<?= htmlspecialchars($robots, ENT_QUOTES); ?>">
  <link rel="canonical" href="<?= htmlspecialchars($canonical, ENT_QUOTES); ?>">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($faviconPath, ENT_QUOTES); ?>">
  <link rel="apple-touch-icon" href="<?= htmlspecialchars($faviconPath, ENT_QUOTES); ?>">
  <meta name="theme-color" content="#0b0b0b">
  <meta property="og:locale" content="pt_BR">
  <meta property="og:type" content="<?= htmlspecialchars($type, ENT_QUOTES); ?>">
  <meta property="og:title" content="<?= htmlspecialchars($title, ENT_QUOTES); ?>">
  <meta property="og:description" content="<?= htmlspecialchars($description, ENT_QUOTES); ?>">
  <meta property="og:url" content="<?= htmlspecialchars($canonical, ENT_QUOTES); ?>">
  <meta property="og:site_name" content="Salão André Puchetti">
  <meta property="og:image" content="<?= htmlspecialchars($image, ENT_QUOTES); ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= htmlspecialchars($title, ENT_QUOTES); ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($description, ENT_QUOTES); ?>">
  <meta name="twitter:image" content="<?= htmlspecialchars($image, ENT_QUOTES); ?>">
<?php
}

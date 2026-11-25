<?php

declare(strict_types=1);

/**
 * Shared bootstrap for both the web front controller (public/index.php) and
 * the CLI console (bin/console) — one place that wires Composer's autoloader,
 * loads config, and builds the Twig environment used everywhere templates
 * are rendered (screens, PDFs and emails alike).
 */

require __DIR__ . '/vendor/autoload.php';

use App\Support\Config;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

Config::load();

$twigLoader = new FilesystemLoader(__DIR__ . '/templates');
$twig = new Environment($twigLoader, [
    'cache' => Config::isDevelopment() ? false : __DIR__ . '/storage/tmp/twig_cache',
    'strict_variables' => false,
    // Recompile when a template file changes. Without this, a production
    // cache keeps serving the old compiled template after new files are
    // copied onto the server.
    'auto_reload' => true,
]);
// {{ asset('/assets/app.js') }} — the URL with ?v=<file modified time>, so a browser can never
// keep serving an old script or stylesheet after a deploy (static files carry no cache rules).
$twig->addFunction(new \Twig\TwigFunction('asset', static function (string $path): string {
    $file = __DIR__ . '/public' . $path;
    return $path . '?v=' . (is_file($file) ? filemtime($file) : '0');
}));
// {{ brand_url('header') }} — current logo for a slot (Administration > Files & branding).
$twig->addFunction(new \Twig\TwigFunction('brand_url', [\App\Support\Branding::class, 'url']));

return $twig;

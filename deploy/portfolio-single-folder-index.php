<?php
/**
 * FEAT-031 single-folder front controller.
 * Install as /home/USER/public_html/portfolio/index.php with Laravel under
 * /home/USER/public_html/portfolio/laravel/.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));
define('LARAVEL_ROOT', __DIR__.'/laravel');

if (file_exists($maintenance = LARAVEL_ROOT.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require LARAVEL_ROOT.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once LARAVEL_ROOT.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->handleRequest(Request::capture());

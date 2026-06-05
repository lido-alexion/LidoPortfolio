<?php
/**
 * Use as: /home/USER/public_html/portfolio/index.php
 * Laravel app MUST live under public_html (open_basedir): ../lidoportfolio/
 *
 * Example: /home/p7xatiz6j0mk/public_html/lidoportfolio/
 */
define('LARAVEL_ROOT', dirname(__DIR__).'/lidoportfolio');

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = LARAVEL_ROOT.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require LARAVEL_ROOT.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once LARAVEL_ROOT.'/bootstrap/app.php';

$app->handleRequest(Request::capture());

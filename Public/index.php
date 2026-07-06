<?php

use Core\File;
use Core\Routing\Http\Request;
use Core\Routing\Http\Response;
use Core\Routing\RouteException;
use Core\Routing\Router;
use Core\System\Environment;
use Core\ViewEngine\View;

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);


define('ROOT_DIR', dirname(__DIR__));
define('APP_DEBUG', true);
define('TIME_ZONE', 'Africa/Casablanca');
date_default_timezone_set(TIME_ZONE);

require_once(ROOT_DIR . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php');
//code...

Core\Autoloader::register();

File::init(ROOT_DIR);

$router = Router::create();
$request = Request::create();
try {
    $env = new Environment();
    if ($env->has('APP_START_UP')) {
        File::include('routes', 'middlewares.php', ['router' => $router]);
        File::include('routes', 'public.php', ['router' => $router]);
        File::include('routes', 'dashboard.php', ['router' => $router]);
    } else {
        File::include('routes', 'installation.php', ['router' => $router]);
    }
    File::include('routes', 'assets.php', ['router' => $router]);
} catch (\Throwable | \Exception $e) {
    return RouteException::handleServiceUnavailable($request);
}
$response = $router->dispatch($request);
$response->send();

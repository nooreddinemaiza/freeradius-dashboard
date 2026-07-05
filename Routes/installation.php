<?php

use App\Configuration;
use Core\Helper\Meta;
use Core\Routing\Http\Request;
use Core\Routing\Router;
use Core\Security\Encrypter;
use Core\System\CSRF;
use Core\System\Environment;
use Core\ViewEngine\View;

/**
 * @var Router $router
 */
$router->redirectAll(
    url: '/installation',
    options: [
        'routes' => [
            '/installation',
            '/installation/welcome',
            '/installation/database',
            '/installation/migration',
            '/installation/administrator',
            '/installation/nas',       
            '/installation/nas/continue',
            '/Assets/styles/output.css',
            '/Assets/styles/install.css',
            "/Assets/images/logo.png",
        ]
    ]
);

$env    = new Environment();
$config = new Configuration($env);

$router->group(['prefix' => '/installation'], function () use ($router, $config) {
    $router->get('/', function (Request $request) use ($config) {
        return $config->redirectToCurrentStep();
    });

    // Étape 0 : Présentation & pré-requis
    $router->get('/welcome', function (Request $request) use ($config) {
        if (!$config->canAccessStep(0)) {
            return $config->redirectToCurrentStep();
        }
        $meta = new Meta();
        $meta->setTitle('Bienvenue — Pré-requis');
        return View::response('admin_views', 'installation.php', [
            'step'        => 0,
            'total_steps' => 5,
            'has_footer' => true,
            'meta'        => $meta,
            'title'       => 'Installation de l\'interface de gestion web',
            'csrf_token'  => CSRF::generateToken(),
            'message'     => '',
        ]);
    });

    $router->post('/welcome', function (Request $request) use ($config) {
        return $config->welcomeConfirm($request);
    });

    // Étape 1 : Configuration de la base de données
    $router->get('/database', function (Request $request) use ($config) {
        if (!$config->canAccessStep(1)) {
            return $config->redirectToCurrentStep();
        }
        new Encrypter(return_key: false);
        $meta = new Meta();
        $meta->setTitle('Configuration de la base de données');
        return View::response('admin_views', 'installation.php', [
            'step'        => 1,
            'total_steps' => 5,
            'has_footer' => true,
            'meta'        => $meta,
            'title'       => 'Installation de l\'interface de gestion web',
            'csrf_token'  => CSRF::generateToken(),
            'message'     => '',
        ]);
    });

    $router->post('/database', function (Request $request) use ($config) {
        return $config->database($request, ['step' => 1]);
    });
    $router->group(['prefix' => '/nas'], function () use ($router, $config) {
        $router->get('', function (Request $request) use ($config) {
            if (!$config->canAccessStep(17)) {
                return $config->redirectToCurrentStep();
            }
            $meta = new Meta();
            $meta->setTitle('Configuration du client NAS');
            return View::response('admin_views', 'installation.php', [
                'step'        => 17,
                'total_steps' => 5,
                'has_footer' => true,
                'meta'        => $meta,
                'title'       => 'Installation de l\'interface de gestion web',
                'csrf_token'  => CSRF::generateToken(),
                'message'     => '',
            ]);
        });
        $router->post('/', function (Request $request) use ($config) {
            return $config->nasAdd($request);
        });
        $router->get('/continue', function (Request $request) use ($config) {
            return $config->nasContinue($request);
        });
    });

    // Étape 1.5 : Migration
    $router->get('/migration', function (Request $request) use ($config) {
        return $config->migrationPreview();
    });

    $router->post('/migration', function (Request $request) use ($config) {
        return $config->migrate($request);
    });

    // Étape 2 : Création de l'administrateur
    $router->get('/administrator', function (Request $request) use ($config) {
        if (!$config->canAccessStep(2)) {
            return $config->redirectToCurrentStep();
        }
        $meta = new Meta();
        $meta->setTitle('Création de l\'administrateur');
        return View::response('admin_views', 'installation.php', [
            'step'        => 2,
            'total_steps' => 5,
            'has_footer' => true,
            'meta'        => $meta,
            'title'       => 'Installation de l\'interface de gestion web',
            'csrf_token'  => CSRF::generateToken(),
            'message'     => '',
        ]);
    });

    $router->post('/administrator', function (Request $request) use ($config) {
        return $config->administrator($request, ['step' => 2]);
    });
});
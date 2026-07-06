<?php

namespace App;

use App\Controllers\AdminController;
use App\Controllers\NasController;
use App\Migrations\Migration;
use Core\Database\Database;
use Core\Exception\ConfigurationException;
use Core\Exception\ConnectionException;
use Core\Exception\CSRFExeption;
use Core\Exception\ValidationException;
use Core\File;
use Core\Helper\Data;
use Core\Helper\Meta;
use Core\Logger;
use Core\Routing\Http\Request;
use Core\Routing\Http\Response;
use Core\Security\Encrypter;
use Core\System\Config;
use Core\System\CSRF;
use Core\System\Environment;
use Core\ViewEngine\View;

class Configuration
{
    private array $data;
    private Environment $env;

    public function __construct(Environment $env, array $data = [])
    {
        $this->setEnvironment();
        $this->data = $data;
        $this->env = $env;
    }

    /**
     * Vérifie si la présentation a été lue
     */
    public function isWelcomeSeen(): bool
    {
        return $this->env->has('SETUP_WELCOME') && $this->env->get('SETUP_WELCOME') === '1';
    }

    /**
     * Vérifie si la base de données est configurée
     */
    public function isDatabaseConfigured(): bool
    {
        return $this->env->has('DB_SET') && $this->env->get('DB_SET') === '1';
    }

    /**
     * Vérifie si l'administrateur est configuré
     */
    public function isAdministratorConfigured(): bool
    {
        return $this->env->has('APP_ADMIN') && $this->env->get('APP_ADMIN') === '1';
    }

    /**
     * Vérifie si l'installation est complète
     */
    public static function isComplete(): bool
    {
        $env = new Environment();
        return $env->has('APP_START_UP');
    }

    /**
     * Vérifie si la migration est complète
     */
    public function isMigrationComplete(): bool
    {
        return $this->env->has('DB_MIGRATED') && $this->env->get('DB_MIGRATED') === '1';
    }

    /**
     * Retourne l'étape courante
     * 0  → Présentation / pré-requis
     * 1  → Configuration base de données
     * 15 → Migration (étape 1.5)
     * 17 → NAS (étape 1.7)
     * 2  → Création administrateur
     * 3  → Installation complète
     */
    public function getCurrentStep(): int
    {
        if (!$this->isWelcomeSeen()) {
            return 0;
        }
        if (!$this->isDatabaseConfigured()) {
            return 1;
        }
        if (!$this->isMigrationComplete()) {
            return 15;
        }
        if (!$this->isNasConfigured()) {
            return 17;
        }
        if (!$this->isAdministratorConfigured()) {
            return 2;
        }
        return 3;
    }

    /**
     * Vérifie si une étape est accessible
     */
    public function canAccessStep(int $step): bool
    {
        return $step === $this->getCurrentStep();
    }

    /**
     * Redirige vers l'étape courante
     */
    public function redirectToCurrentStep(): Response
    {
        switch ($this->getCurrentStep()) {
            case 0:
                return Response::redirect('/installation/welcome');
            case 1:
                return Response::redirect('/installation/database');
            case 15:
                return Response::redirect('/installation/migration');
            case 2:
                return Response::redirect('/installation/administrator');
            case 3:
                if (!self::isComplete()) {
                    $this->env->set('APP_START_UP', '1')->save();
                }
                return Response::redirect('/dashboard/login');
            case 17:
                return Response::redirect('/installation/nas');
            default:
                return Response::redirect('/installation');
        }
    }

    /**
     * Affiche la page de présentation (GET)
     */
    public function welcome(): Response
    {
        if (!$this->canAccessStep(0)) {
            return $this->redirectToCurrentStep();
        }
        return $this->view('installation.php', 0);
    }

    public function setEnvironment()
    {
        if (!File::exists('config', '.env')) File::copy('config', 'env.backup', 'config', '.env');
        if (!File::exists('log', 'app.log')) File::copy('config', 'env.backup', 'log', 'app.log');
    }
    /**
     * Valide la lecture de la présentation (POST)
     */
    public function welcomeConfirm(Request $request): Response
    {
        if (!$this->canAccessStep(0)) {
            return $this->redirectToCurrentStep();
        }

        $post = $this->sanitize($request, ['csrf_token']);

        if (!CSRF::validateToken($post['csrf_token'])) {
            $this->data['errors']['checking'] = ['Jeton CSRF invalide. Veuillez recharger la page.'];
            return $this->view('installation.php', 0);
        }

        $this->env->set('SETUP_WELCOME', '1')->save();
        $this->env->set('MAX_NAS', '1')->save();

        return Response::redirect('/installation/database');
    }

    /**
     * Affiche l'aperçu de la migration (GET)
     */
    public function migrationPreview(): Response
    {
        if (!$this->canAccessStep(15)) {
            return $this->redirectToCurrentStep();
        }

        try {
            $migration = new Migration();
            $preview   = $migration->getSchemaPreview();
            $this->data['preview'] = $preview;
            $this->data['has_footer'] = true;
        } catch (\Exception $e) {
            $this->data['errors']['checking'] = [
                'Erreur preview: ' . $e->getMessage(),
                'Classe: ' . get_class($e),
                'Fichier: ' . $e->getFile() . ':' . $e->getLine(),
            ];
        }

        return $this->view('installation.php', 15);
    }

    /**
     * Lance la migration (POST)
     */
    public function migrate(Request $request): Response
    {
        try {
            if (!$this->canAccessStep(15)) {
                return $this->redirectToCurrentStep();
            }

            $post = $this->sanitize($request, ['csrf_token']);

            if (!CSRF::validateToken($post['csrf_token'])) {
                throw new CSRFExeption();
            }

            $migration = new Migration();
            $migration->setupSchema();

            $this->env->set('DB_MIGRATED', '1')->save();

            return Response::redirect('/installation/administrator');
        } catch (CSRFExeption $e) {
            $this->data['errors']['checking'] = $e->getErrors();
            $this->data['errors']['checking'][] = 'Veuillez recharger la page!';
            return $this->migrationPreview();
        } catch (ConfigurationException $e) {
            $this->data['errors']['checking'] = $e->getErrors();
            return $this->migrationPreviewWithData();
        } catch (\Exception $e) {
            $this->data['errors']['checking'] = [$e->getMessage()];
            return $this->migrationPreviewWithData();
        }
    }

    /**
     * Réaffiche la page migration avec les données de preview + erreurs
     */
    private function migrationPreviewWithData(): Response
    {
        try {
            $migration = new Migration();
            $this->data['preview'] = $migration->getSchemaPreview();
        } catch (\Exception $e) {
            // Si on ne peut même pas faire le preview, on affiche juste l'erreur
        }
        return $this->view('installation.php', 15);
    }

    /**
     * Configuration de la base de données
     */
    public function database(Request $request, array $data = [])
    {
        try {
            if (!$this->canAccessStep(1)) {
                return $this->redirectToCurrentStep();
            }

            $post = $this->sanitize($request, [
                'db_host',
                'db_port',
                'db_database',
                'db_username',
                'db_password',
                'db_charset',
                'csrf_token'
            ]);

            Data::addLabel([
                'db_host'     => 'l\'hôte',
                'db_port'     => 'le port',
                'db_database' => 'Le nom de la base de données',
                'db_username' => 'nom d\'utilisateur',
            ]);

            $errors = $post->validate([
                'db_host'     => 'required|string',
                'db_port'     => 'required|numeric',
                'db_database' => 'required|string',
                'db_username' => 'required|string',
                'db_charset'  => 'string',
            ]);

            if ($errors) throw new ValidationException(errors: $errors);
            if (!CSRF::validateToken($post['csrf_token'])) throw new CSRFExeption();

            $dbPassword = $request->post('db_password');
            $configs = [
                'driver'   => 'mysql',
                'host'     => $post->get('db_host'),
                'database' => $post->get('db_database'),
                'username' => $post->get('db_username'),
                'password' => $dbPassword,
                'port'     => $post->get('db_port'),
                'charset'  => $post->get('db_charset') ?? 'utf8mb4',
            ];

            $testConnextion = Database::test($configs);
            if (!$testConnextion['success']) {
                throw new ConnectionException(errors: [$testConnextion['message']]);
            }

            $crypted_password = (new Encrypter())->encrypt($dbPassword);

            $env_db = $this->env->create([
                'SETUP_WELCOME' => '1',
                'DB_DRIVER'     => $configs['driver'],
                'DB_HOST'       => $configs['host'],
                'DB_DATABASE'   => $configs['database'],
                'DB_USERNAME'   => $configs['username'],
                'DB_PASSWORD'   => $crypted_password,
                'DB_PORT'       => $configs['port'],
                'DB_CHARSET'    => $configs['charset'],
                'DB_SET'        => '1',
            ]);

            if (!$env_db) {
                throw new ConfigurationException(
                    errors: ['Une erreur est survenue lors de l\'écriture des données!']
                );
            }

            return Response::redirect('/installation/migration');
        } catch (CSRFExeption $e) {
            $this->data['errors']['checking'] = $e->getErrors();
            $this->data['errors']['checking'][] = 'Veuillez recharger la page!';
            return $this->view('installation.php', 1);
        } catch (ConnectionException $e) {
            $this->data['errors']['checking'] = $e->getErrors();
            return $this->view('installation.php', 1);
        } catch (ValidationException $e) {
            $this->data['errors'] = $e->getErrors();
            return $this->view('installation.php', 1);
        } catch (ConfigurationException $e) {
            $this->data['errors']['checking'] = $e->getErrors();
            return $this->view('installation.php', 1);
        }
    }

    public function administratorPreview()
    {
        if (!$this->canAccessStep(2)) {
            return $this->redirectToCurrentStep();
        }
        try {
            $this->hasRoot();
        } catch (\Exception $e) {
            $this->data['errors']['checking'] = ['Erreur: ' . $e->getMessage()];
        }
        $this->data['title'] = "Création du compte admministrateur";
        return $this->view(step: 2);
    }
    private function hasRoot()
    {
        $hasRoot = (new AdminController)->hasRoot();
        if ($hasRoot) {
            $this->data['root_detected'] = true;
            throw new \Exception('Un ou plusieurs comptes administrateur root ont été détectés. Un seul compte root est autorisé sur la plateforme.');
        }
    }
    /**
     * Configuration de l'administrateur
     */
    public function administrator(Request $request, array $data = [])
    {
        try {
            $this->data = $data;

            if (!$this->canAccessStep(2)) {
                return $this->redirectToCurrentStep();
            }
            $this->hasRoot();
            $post = $this->sanitize($request, [
                'csrf_token',
                'fullname',
                'email',
                'password',
                'password_confirm'
            ]);

            Data::addLabel([
                'fullname'         => 'Nom complet',
                'email'            => 'email',
                'password'         => 'mot de passe',
                'password_confirm' => 'confirmation du mot de passe',
            ]);

            $errors = $post->validate([
                "csrf_token"       => 'required',
                'fullname'         => 'required|min:2|max:100',
                'email'            => 'required|email|max:255',
                'password'         => 'required|min:6|max:255',
                'password_confirm' => 'required|confirm:password'
            ]);

            if ($errors) {
                throw new ValidationException(errors: $errors);
            }

            if (!CSRF::validateToken($post['csrf_token'])) {
                throw new CSRFExeption();
            }

            $data = [
                'fullname' => trim($post['fullname']),
                'email'    => $request->post('email'),
                'password' => $request->post('password'),
                'type'     => 'root',
            ];

            $result = (new AdminController)->createRootAccount($data);

            if (!$result['success']) {
                throw new ConfigurationException(
                    errors: [$result['message']]
                );
            }

            $this->env->set('APP_ADMIN', '1')->save();

            if (isset($result['exists'])) {
                Logger::warning($result['message']);
            }

            $this->env->set('APP_START_UP', '1')->save();

            return Response::redirect('/dashboard/login');
        } catch (ValidationException $e) {
            $this->data['errors'] = $e->getErrors();
        } catch (CSRFExeption $e) {
            $this->data['errors']['checking'] = $e->getErrors();
            $this->data['errors']['checking'][] = 'Veuillez recharger la page!';
        } catch (ConfigurationException $e) {
            $this->data['errors']['checking'] = $e->getErrors();
        } catch (\Exception $e) {
            $this->data['errors']['checking'] = ['Erreur: ' . $e->getMessage()];
        }
        $this->data['title'] = "Création du compte admministrateur";
        return $this->view(step: 2);
    }
    public function administratorPass(Request $request)
    {
        if (!$this->canAccessStep(2)) {
            return $this->redirectToCurrentStep();
        }
        $this->env->set('APP_ADMIN', '1')->save();

        return Response::redirect('/installation');
    }

    public function administratorEmpty(Request $request)
    {
        if (!$this->canAccessStep(2)) {
            return $this->redirectToCurrentStep();
        }
        try {
            $post = $this->sanitize($request, [
                'csrf_token',
            ]);

            if (!CSRF::validateToken($post['csrf_token'])) {
                throw new CSRFExeption();
            }
            $result = (new AdminController)->dropAllRoots(true);
            if (!$result) {
                throw new \Exception('Une errer est survenue lors de la suppression des comptes, essayez plutard!');
            }
        } catch (\Exception $e) {
            $this->data['errors']['checking'] = ['Erreur: ' . $e->getMessage()];
        }

        return Response::redirect('/installation');
    }
    /**
     * Vérifie si l'étape NAS a été validée par l'admin
     */
    public function isNasConfigured(): bool
    {
        return $this->env->has('APP_NAS') && $this->env->get('APP_NAS') === '1';
    }
    /**
     * Affiche le formulaire NAS (GET)
     */
    public function nasPreview(Request $request): Response
    {
        if (!$this->canAccessStep(17)) {
            return $this->redirectToCurrentStep();
        }
        try {
            $nasController = new NasController;
            $this->data['nas_list'] = $nasController->list();
            $this->data['title'] = "Configuration des NAS";
            if ($request->has('delete_nas')) {
                $to_delete = $request->query('delete_nas');
                if (!is_numeric($to_delete)) {
                    throw new ValidationException('ID du NAS doit être numérique!');
                }
                $nas_exists = $nasController->get($to_delete);
                if (!$nas_exists) {
                    throw new ValidationException('NAS introuvable!');
                }
                $deleted = $nasController->remove($to_delete);
                if (!$deleted) {
                    throw new ValidationException('Une erreur est survenue lors de la suppression du NAS!');
                }
                return Response::redirect('/installation/nas');
            }
        } catch (\Exception $e) {
            $this->data['errors']['checking'] = ['Erreur: ' . $e->getMessage()];
        }
        return $this->view('installation.php', 17);
    }

    /**
     * Ajoute un NAS (POST)
     */
    public function nasAdd(Request $request): Response
    {
        try {
            if (!$this->canAccessStep(17)) {
                return $this->redirectToCurrentStep();
            }

            $post = $this->sanitize($request, ['csrf_token', 'name', 'ip_address', 'zone_name', 'port']);
            Data::addLabel([
                'name'       => 'Nom du NAS',
                'ip_address' => 'Adresse IP / domaine',
                'zone_name'  => 'Nom de zone et Lien du portail captif',
                'port'       => 'Port',
            ]);

            $errors = $post->validate([
                'csrf_token' => 'required',
                'name'       => 'required|string|max:253',
                'ip_address' => 'required|string|max:253',
                'zone_name'  => 'required|string|max:253',
                'port'       => 'numeric',
            ]);

            if ($errors) {
                throw new ValidationException(errors: $errors);
            }

            if (!CSRF::validateToken($post['csrf_token'])) {
                throw new CSRFExeption();
            }

            $nasController = new NasController;
            $nas_list = $nasController->list();
            $app_data = Config::get('app');
            $max_nas = $app_data["max_nas"] ?? 1;
            if (count($nas_list) >= $max_nas) {
                throw new ConfigurationException(message: 'Pour cette version ' . $max_nas . ' est le nombre maximal de clients NAS que vous pouvez avoir!');
            }
            $result = $nasController->add([
                'name'       => trim($post['name']),
                'ip_address' => trim($post['ip_address']),
                'zone_name'  => trim($post['zone_name']),
                'port'       => $post['port'] ?: null,
            ]);
            if ($result) {
                $this->env->set('APP_NAS', '1')->save();
                return Response::redirect('/installation/administrator');
            }
            return Response::redirect('/installation/nas');
        } catch (ValidationException $e) {
            $this->data['errors'] = $e->getErrors();
            return $this->nasPreview($request);
        } catch (CSRFExeption $e) {
            $this->data['errors']['checking'] = array_merge($e->getErrors(), ['Veuillez recharger la page!']);
            return $this->nasPreview($request);
        } catch (\Exception $e) {
            $this->data['errors']['checking'] = [$e->getMessage()];
            return $this->nasPreview($request);
        }
    }
    /**
     * Valide l'étape NAS et passe à l'étape suivante (POST)
     */
    public function nasContinue(Request $request): Response
    {
        if (!$this->canAccessStep(17)) {
            return $this->redirectToCurrentStep();
        }
        $nasController = new NasController;
        $nas_list = $nasController->list();
        if (empty($nas_list)) {
            $this->data['errors']['checking'] = ['Veuillez ajouter au moins un NAS avant de continuer.'];
            return $this->nasPreview($request);
        }

        $this->env->set('APP_NAS', '1')->save();

        return Response::redirect('/installation/administrator');
    }
    /**
     * Nettoie et valide les données de la requête
     */
    private function sanitize(Request $request, ?array $fields = [])
    {
        $data = Data::create($request->all());

        if ($fields) {
            $data = $data->only($fields);
        }

        $this->data['csrf_token'] = $data['csrf_token'];
        return $data->sanitize();
    }

    /**
     * Génère une réponse vue
     */
    private function view(?string $view = null, int $step = 1): Response
    {

        $meta = new Meta();
        $meta->setTitle($this->data['title'] ?? 'Configuration de la plateforme');
        $data = [
            'step'        => $step,
            'total_steps' => 5,
            'message'     => $this->data['message'] ?? "",
            'has_header'  => false,
            'has_footer'  => true,
            'root_detected'  => false,
            'meta'  => $meta,
            'errors'      => $this->data['errors'] ?? [],
            'csrf_token'  => $this->data['csrf_token'] ?? CSRF::generateToken(),
            'data'        => $this->data,
        ];

        if (isset($this->data['token'])) {
            $data['token'] = $this->data['token'];
        }

        $data = array_merge($data, $this->data);
        return View::response('admin_views', $view ?? 'installation.php', $data);
    }
}

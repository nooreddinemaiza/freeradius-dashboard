<?php

namespace App\Controllers;

use App\Models\Admin;
use Core\Controllers\Controller;
use Core\Helper\Data;
use Core\System\CSRF;
use Core\System\Session;
use Core\ViewEngine\View;
use Core\Routing\Http\Request;
use Core\Exception\CSRFExeption;
use Core\Exception\ConnectionException;
use Core\Exception\ValidationException;
use Core\Routing\Http\Response;
use Core\Routing\RouteException;

class AdminController extends Controller
{
    private Admin $model;
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOGIN_BLOCK_DURATION = 900; // 15 minutes
    public function __construct()
    {
        $this->model = new Admin();
    }
    public function createRootAccount(array $data): array
    {
        $data = Data::create($data)->only([
            'fullname',
            'email',
            'password',
            'type',
        ]);
        $no_missing = $data->map(function ($item) {
            $missings = '';
            if (!$item) {
                $missings .=  $item;
            }
            return $missings;
        })->isCompletelyEmpty();

        if ($no_missing) {
            return $this->model->createRootAccount($data);
        }
        return [
            'success' => false,
            'message' => 'Les données ne sont pas valides!'
        ];
    }

    public function listModerators(Request $request)
    {
        try {
            $data = Data::create($request->all())->only([
                'csrf_token',
            ]);

            if (!CSRF::validateToken($data['csrf_token'])) {
                throw new CSRFExeption(errors: [
                    'Le token de sécurité est invalide!'
                ]);
            }
            $list = $this->model->listModerators();
            if (!$list) {
                throw new ConnectionException('Pas de résultat à lister!');
            }
            return Response::json(
                [
                    'success' => true,
                    'data' => $list
                ]
            );
        } catch (CSRFExeption $e) {
            return $this->responseAjax(
                false,
                $e->getErrors()
            );
        } catch (ConnectionException $e) {
            return $this->responseAjax(
                success: false,
                message: $e->getMessage()

            );
        }
    }
    public function listForInUse()
    {
        $list = $this->model->listForInUse();
        return $list ? $list : [];
    }
    /**
     * Trouve un admin par ID
     */
    public function exists(int|string $id): bool
    {
        return $this->model->exists($id);
    }
    public function createModerator(Request $request)
    {
        try {
            $data = Data::create($request->all())->only([
                'fullname',
                'email',
                'password',
                'status',
                'csrf_token',
            ]);
            $errors = $data->validate([
                'fullname' => 'required|min:3|max:100',
                'email' => 'required|min:10|max:160',
                'password' => 'required|min:6',
                'status' => 'required|in:active,inactive',
            ]);
            if ($errors) {
                throw new ValidationException(errors: $errors);
            }
            if (!CSRF::validateToken($data['csrf_token'])) {
                throw new CSRFExeption(errors: [
                    'Le token de sécurité est invalide!'
                ]);
            }
            $email_exists = $this->model->emailExists($data['email']);
            if ($email_exists) {
                throw new ValidationException(errors: [
                    'email' => 'Un compte avec cette adresse existe déjà!'
                ]);
            }
            $created = $this->model->createModerator($data->except(['csrf_token']));
            if (!$created) {
                throw new ConnectionException('Une erreur est survenue lors de la création du compte!');
            }
            return $this->responseAjax(
                success: true,
                message: 'Le compte à été créé avec succès'
            );
        } catch (ValidationException $e) {
            return $this->responseAjax(
                false,
                $e->getErrors(),
                'Les données sont invalides!'
            );
        } catch (CSRFExeption $e) {
            return $this->responseAjax(
                false,
                $e->getErrors()
            );
        } catch (ConnectionException $e) {
            return $this->responseAjax(
                success: false,
                message: $e->getMessage()

            );
        }
    }

    public function editModerator(Request $request)
    {
        try {
            $data = Data::create($request->all())->only([
                'manager_id',
                'fullname',
                'email',
                'password',
                'status',
                'csrf_token',
            ]);
            $rules = [
                'manager_id' => 'required|integer',
                'fullname' => 'required|min:3|max:100',
                'email' => 'required|min:10|max:160',
                'status' => 'required|in:active,inactive',
            ];
            if ($data->has('password')) {
                $rules['password'] = 'required|min:8';
            }
            $errors = $data->validate($rules);
            if ($errors) {
                throw new ValidationException(errors: $errors);
            }
            if (!CSRF::validateToken($data['csrf_token'])) {
                throw new CSRFExeption(errors: [
                    'Le token de sécurité est invalide!'
                ]);
            }
            $moderator_exists = $this->model->findById($data['manager_id']);
            if (!$moderator_exists) {
                throw new ValidationException('Ce compte n\'existe pas!');
            }

            $dataToUpdate = new Data();
            foreach ($moderator_exists as $key => $value) {
                if ($data[$key] !== $moderator_exists[$key]) {
                    $dataToUpdate[$key] = $data[$key];
                }
            }
            $dataToUpdate = $dataToUpdate->removeNulls();

            if ($data->has('password')) {
                $dataToUpdate['password'] = $data['password'];
            }
            if ($dataToUpdate->has('email')) {
                $email_exists = $this->model->emailExists($data['email']);
                if ($email_exists) {
                    throw new ValidationException(errors: [
                        'email' => 'Un compte avec cette adresse existe déjà!'
                    ]);
                }
            }
            if ($dataToUpdate->isCompletelyEmpty()) {
                throw new ValidationException('Aucun changement détecté.!');
            }
            $edited = $this->model->editModerator($data['manager_id'], $dataToUpdate);
            if (!$edited) {
                throw new ConnectionException('Une erreur est survenue lors de la modification du compte!');
            }
            return $this->responseAjax(
                success: true,
                message: 'Le compte à été modifié avec succès'
            );
        } catch (ValidationException $e) {
            return $this->responseAjax(
                false,
                $e->getErrors(),
                $e->getMessage(),
            );
        } catch (CSRFExeption $e) {
            return $this->responseAjax(
                false,
                $e->getErrors()
            );
        } catch (ConnectionException $e) {
            return $this->responseAjax(
                success: false,
                message: $e->getMessage()

            );
        }
    }
    public function removeModerator(Request $request)
    {
        try {
            $data = Data::create($request->all())->only([
                'id',
                'csrf_token',
            ]);
            $rules = [
                'id' => 'required|integer',
            ];
            $errors = $data->validate($rules);
            if ($errors) {
                throw new ValidationException(errors: $errors);
            }
            if (!CSRF::validateToken($data['csrf_token'])) {
                throw new CSRFExeption(errors: [
                    'Le token de sécurité est invalide!'
                ]);
            }
            $moderator_exists = $this->model->findById($data['id']);
            if (!$moderator_exists) {
                throw new ValidationException('Ce compte n\'existe pas!');
            }

            $removed = $this->model->removeModerator($data['id']);

            if (!$removed) {
                throw new ConnectionException('Une erreur est survenue lors de la suppression du compte!');
            }
            return $this->responseAjax(
                success: true,
            );
        } catch (ValidationException $e) {
            return $this->responseAjax(
                false,
                $e->getErrors(),
                $e->getMessage(),
            );
        } catch (CSRFExeption $e) {
            return $this->responseAjax(
                false,
                $e->getErrors()
            );
        } catch (ConnectionException $e) {
            return $this->responseAjax(
                success: false,
                message: $e->getMessage()

            );
        }
    }
    public function toggleModeratorStatus(Request $request)
    {
        try {
            $data = Data::create($request->all())->only([
                'manager_id',
                'status',
                'csrf_token',
            ]);
            $rules = [
                'manager_id' => 'required|integer',
                'status' => 'required|in:active,inactive',
            ];
            $errors = $data->validate($rules);
            if ($errors) {
                throw new ValidationException(errors: $errors);
            }
            if (!CSRF::validateToken($data['csrf_token'])) {
                throw new CSRFExeption(errors: [
                    'Le token de sécurité est invalide!'
                ]);
            }
            $moderator_exists = $this->model->findById($data['manager_id']);
            if (!$moderator_exists) {
                throw new ValidationException('Ce compte n\'existe pas!');
            }

            $newStatus = $moderator_exists['status'] === 'active' ? 'inactive' : 'active';
            $toggled = $this->model->toggleModeratorStatus($data['manager_id'], $newStatus);

            if (!$toggled) {
                throw new ConnectionException('Une erreur est survenue lors de la modification du status!');
            }
            return $this->responseAjax(
                success: true,
            );
        } catch (ValidationException $e) {
            return $this->responseAjax(
                false,
                $e->getErrors(),
                $e->getMessage(),
            );
        } catch (CSRFExeption $e) {
            return $this->responseAjax(
                false,
                $e->getErrors()
            );
        } catch (ConnectionException $e) {
            return $this->responseAjax(
                success: false,
                message: $e->getMessage()

            );
        }
    }
    public function profile(Request $request)
    {
        try {
            $data = Data::create($request->all())->only([
                'csrf_token',
            ]);
            if (!CSRF::validateToken($data['csrf_token'])) {
                throw new CSRFExeption(errors: [
                    'Le token de sécurité est invalide!'
                ]);
            }
            $id = Session::getUserId();

            $profile = $this->model->findById($id);
            if (!$profile) {
                throw new ValidationException('Ce compte n\'existe pas!');
            }

            if ($profile['type'] == 'root') {
                $profile['type'] = 'Administrateur';
            }

            return Response::json([
                'success' => true,
                'data' => $profile
            ]);
        } catch (ValidationException $e) {
            Session::logout();
            return $this->redirect();
        } catch (CSRFExeption $e) {
            return $this->responseAjax(
                false,
                $e->getErrors()
            );
        } catch (ConnectionException $e) {
            return $this->responseAjax(
                success: false,
                message: $e->getMessage()

            );
        }
    }
    public function editProfile(Request $request)
    {
        try {
            $data = Data::create($request->all())->only([
                'fullname',
                'email',
                'username',
                'csrf_token',
            ]);

            if (!CSRF::validateToken($data['csrf_token'])) {
                throw new CSRFExeption(errors: [
                    'Le token de sécurité est invalide!'
                ]);
            }
            $data = $data->except(['csrf_token']);
            $rules = [];
            if ($data->has('fullname')) {
                $rules['fullname'] = 'required|min:3|max:100';
            }
            if ($data->has('email')) {
                $rules['email'] = 'required|email|min:10|max:160';
            }
            if ($data->has('username')) {
                $rules['username'] = 'username';
            }
            if ($data->isCompletelyEmpty()) {
                throw new ValidationException('Aucun changement détecté.');
            }
            $errors = $data->validate($rules);
            if ($errors) {
                throw new ValidationException(errors: $errors);
            }
            $id = Session::getUserId();
            $profile = $this->model->findById($id);
            if (!$profile) {
                throw new ValidationException('Ce compte n\'existe pas!');
            }

            $dataToUpdate = new Data();
            foreach ($profile as $key => $value) {
                if ($data[$key] !== $profile[$key]) {
                    $dataToUpdate[$key] = $data[$key];
                }
            }
            $dataToUpdate = $dataToUpdate->removeNulls();

            if ($dataToUpdate->isCompletelyEmpty()) {
                throw new ValidationException('Aucun changement détecté.');
            }
            if ($dataToUpdate->has('username')) {
                $userController = new UserController;

                $username_exists = $userController->usernameExists($data['username']);
                if ($username_exists) {
                    throw new ValidationException(errors: [
                        'username' => 'Un compte avec ce nom d\'utilisateur existe déjà!'
                    ]);
                }
            }
            if ($dataToUpdate->has('email')) {
                $email_exists = $this->model->emailExists($data['email']);
                if ($email_exists) {
                    throw new ValidationException(errors: [
                        'email' => 'Un compte avec cette adresse existe déjà!'
                    ]);
                }
            }
            $edited = $this->model->editModerator($id, $dataToUpdate);
            if (!$edited) {
                throw new ConnectionException('Une erreur est survenue lors de la modification du compte!');
            }
            return $this->responseAjax(
                success: true,
                message: 'Le profil à été modifié avec succès'
            );
        } catch (ValidationException $e) {
            return $this->responseAjax(
                false,
                $e->getErrors(),
                $e->getMessage(),
            );
        } catch (CSRFExeption $e) {
            return $this->responseAjax(
                false,
                $e->getErrors()
            );
        } catch (ConnectionException $e) {
            return $this->responseAjax(
                success: false,
                message: $e->getMessage()

            );
        }
    }
    public function resetPassword(Request $request)
    {
        try {
            $data = Data::create($request->all())->only([
                'password',
                'password_confirmation',
                'csrf_token',
            ]);

            if (!CSRF::validateToken($data['csrf_token'])) {
                throw new CSRFExeption(errors: [
                    'Le token de sécurité est invalide!'
                ]);
            }

            $rules = [
                'password' => 'required|min:6',
                'password_confirmation' => 'required|confirm:password',
            ];
            if ($data->isCompletelyEmpty()) {
                throw new ValidationException('Aucun changement détecté.');
            }

            $errors = $data->validate($rules);
            if ($errors) {
                throw new ValidationException(errors: $errors);
            }
            $data = $data->except(['csrf_token', 'password_confirmation']);

            $id = Session::getUserId();
            $profile = $this->model->findById($id);
            if (!$profile) {
                throw new ValidationException('Ce compte n\'existe pas!');
            }

            $edited = $this->model->editModerator($id, $data);
            if (!$edited) {
                throw new ConnectionException('Une erreur est survenue lors de la modification du compte!');
            }
            return $this->responseAjax(
                success: true,
                message: 'Le mot de passe à été modifié avec succès'
            );
        } catch (ValidationException $e) {
            return $this->responseAjax(
                false,
                $e->getErrors(),
                $e->getMessage(),
            );
        } catch (CSRFExeption $e) {
            return $this->responseAjax(
                false,
                $e->getErrors()
            );
        } catch (ConnectionException $e) {
            return $this->responseAjax(
                success: false,
                message: $e->getMessage()

            );
        }
    }
    public function login(Request $request)
    {
        // Vérifier si déjà connecté
        if (Session::isAuthenticated()) {
            return $this->redirect();
        }
        try {
            $this->data['old'] = $request->all();
            $data = Data::create($request->all())
                ->only([
                    'csrf_token',
                    'email',
                    'password',
                    'remember_me',
                ])->sanitize();

            // Vérifier la limitation de tentatives
            $attemptKey = 'login:' . ($data['email'] ?? 'unknown');
            $attemptStatus = Session::incrementAttempt(
                $attemptKey,
                self::MAX_LOGIN_ATTEMPTS,
                self::LOGIN_BLOCK_DURATION
            );

            if ($attemptStatus['blocked']) {
                $remainingMinutes = ceil($attemptStatus['remaining_time'] / 60);

                throw new ValidationException(errors: [
                    'global' => [
                        "Trop de tentatives de connexion. Veuillez réessayer dans {$remainingMinutes} minute(s)."
                    ]
                ]);
            }
            Data::addLabel([
                'email' => 'email',
                'password' => 'mot de passe',
            ]);
            $errors = $data->validate([
                'csrf_token' => 'required|string',
                'email' => 'required|email',
                'password' => 'required|min:6',
            ]);

            if ($errors) {
                throw new ValidationException(errors: $errors);
            }

            if (!CSRF::validateToken($data['csrf_token'])) {
                throw new CSRFExeption();
            }

            $result = $this->model->connect($data->except(['csrf_token']));
            $remember_me = $data->has('remember_me') && $data['remember_me'] == 'on';
            Session::login(
                $result['id'],
                $result->all(),
                $remember_me,
                $request->ip(),
                $request->userAgent(),
            );
            return $this->redirect();
        } catch (ValidationException $e) {
            $this->data['errors']['global'] = $e->getErrors();
            return $this->view();
        } catch (CSRFExeption $e) {
            $this->data['errors']['csrf'] = $e->getErrors();
            $this->data['errors']['csrf'][] = 'Veuillez recharger la page!';
            return $this->view();
        } catch (ConnectionException $e) {
            $this->data['errors']['credentials'] = $e->getErrors();
            return $this->view();
        } catch (RouteException $e) {
            return RouteException::handleDesactivated($request);
        }
    }
    public function logout()
    {
        Session::logout();
        return $this->redirect('login');
    }
    public function redirect(?string $suffix = '')
    {
        return Response::redirect('/dashboard/' . $suffix);
    }
    public function loginForm()
    {
        // Vérifier si déjà connecté
        if (Session::isAuthenticated()) {
            return $this->redirect();
        }
        $meta = View::meta();
        $meta->setTitle('Connexion Administrateur');
        $this->data['action'] =  '/dashboard/login';
        $this->data['view'] =   'login.php';
        $this->data['meta'] = $meta;
        $this->data['csrf_token'] = CSRF::getToken();
        $this->data['isBlocked'] = false;
        $this->data['has_footer'] = true;
        return $this->view();
    }
    public function dashboard()
    {
        if (!Session::isAuthenticated()) {
            return $this->redirect('login');
        }
        $this->data['user'] = Session::getUserData();
        $meta = View::meta();
        $meta->setTitle('Tableau de Bord');
        $this->data['meta'] = $meta;
        $this->data['csrf_token'] = CSRF::getToken();
        $this->data['view'] = 'dashboard.php';
        return $this->view();
    }
    public function hasRoot(): bool
    {
        return $this->model->hasRoot();
    }
    public function dropAllRoots(bool $validate = false)
    {
        if (!$validate) return false;
        $result = $this->model->dropAllRoots($validate);
        return $result;
    }
}

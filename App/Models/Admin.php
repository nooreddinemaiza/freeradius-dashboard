<?php

namespace App\Models;

use Core\Exception\ConnectionException;
use Core\Helper\Data;
use Core\Models\Model;
use Core\Routing\RouteException;
use Core\Security\Encrypter;

class Admin extends Model
{
    private const ADMIN_INFOS = [
        'id',
        'fullname',
        'username',
        'email',
        'type',
        'status',
        'password',
        'created_at',
        'updated_at',
    ];

    protected const TABLE = 'admins';

    /**
     * Connexion d'un administrateur
     * Retourne toutes les informations nécessaires pour la session
     */
    public function connect(Data $data): ?Data
    {
        // Récupérer l'admin par email uniquement d'abord
        $admin = $this->findByEmail($data['email']);

        if (!$admin) {
            throw new ConnectionException(errors: [
                'Email ou mot de passe incorrect.'
            ]);
        }


        // Vérifier si le compte est verrouillé
        if ($this->isLocked($admin)) {
            throw new RouteException;
        }

        // Vérifier le mot de passe
        if (!$this->verifyPassword($data['password'], $admin['password'])) {
            throw new ConnectionException(errors: [
                'Email ou mot de passe incorrect.'
            ]);
        }

        // Vérifier le statut du compte
        if ($admin['status'] !== 'active') {
            throw new ConnectionException(errors: [
                'Votre compte est désactivé. Veuillez contacter l\'administrateur.'
            ]);
        }

        // Retourner les données complètes
        return $this->formatAdminData($admin);
    }
    /**
     * Trouve un admin par email
     */
    public function findByEmail(string $email): ?array
    {
        $result = $this->db->select(
            table: self::TABLE,
            columns: implode(',', self::ADMIN_INFOS),
            conditions: 'email = :email',
            params: [':email' => $email],
        );
        return $result ? $result[0] : null;
    }
    public function emailExists(string $email): bool
    {
        $result = $this->db->select(
            table: self::TABLE,
            columns: 'email',
            conditions: 'email = :email',
            params: [':email' => $email],
        );
        return $result ? true : false;
    }
    public function createModerator(Data $data)
    {
        $result = $this->db->insert(
            table: self::TABLE,
            data: [
                'fullname' => $data['fullname'],
                'email' => $data['email'],
                'password' => (new Encrypter)->hashPassword($data['password']),
                'password_crypt' => Encrypter::radiusCryptPassword($data['password']),
                'username' => $this->generateUsername($data['fullname']),
                'type' => 'moderator',
                'status' => $data['status'],
            ]
        );
        return $result;
    }
    public function editModerator(string $id, Data $data)
    {
        if ($data->has('password')) {
            $data['password'] = (new Encrypter)->hashPassword($data['password']);
            $data['password_crypt'] = Encrypter::radiusCryptPassword($data['password']);
        }
        $result = $this->db->update(
            table: self::TABLE,
            data: $data->all(),
            conditions: 'id=:id',
            params: [
                ':id' => $id
            ],
        );
        return $result;
    }
    public function toggleModeratorStatus(string $id, string $newStatus)
    {
        $result = $this->db->update(
            table: self::TABLE,
            data: ['status' => $newStatus],
            conditions: 'id=:id',
            params: [
                ':id' => $id
            ],
        );
        return $result;
    }
    public function removeModerator(int|string $id)
    {
        $result = $this->db->delete(
            table: self::TABLE,
            conditions: 'id=:id',
            params: [
                ':id' => $id
            ]
        );
        return $result;
    }
    public function listModerators()
    {
        $result = $this->db->select(
            table: self::TABLE,
            columns: 'id,email,fullname,status,created_at',
            conditions: 'type=:type',
            params: [
                ':type' => 'moderator'
            ]
        );
        return $result ? $result : [];
    }
    public function listForInUse()
    {
        $result = $this->db->select(
            table: self::TABLE,
            columns: 'id,fullname',
            conditions: 'type=:type',
            params: [
                ':type' => 'moderator'
            ]
        );
        return $result ? $result : [];
    }
    /**
     * Trouve un admin par ID
     */
    public function findById(int|string $id): ?array
    {
        $result = $this->db->select(
            table: self::TABLE,
            columns: implode(',',  [
                'id',
                'fullname',
                'username',
                'email',
                'type',
                'status',
                'created_at',
                'updated_at',
            ]),
            conditions: 'id = :id',
            params: [':id' => $id]
        );

        return $result ? $result[0] : null;
    }
    public function exists(int|string $id): bool
    {
        $result = $this->db->select(
            table: self::TABLE,
            columns: 'id',
            conditions: 'id = :id',
            params: [':id' => $id],
        );
        return $result ? true : false;
    }
    /**
     * Trouve un admin par son token "Remember Me"
     */
    public function findByRememberToken(int|string $userId): ?array
    {
        $result = $this->db->select(
            table: self::TABLE,
            columns: implode(',', self::ADMIN_INFOS),
            conditions: 'id = :id AND remember_token IS NOT NULL',
            params: [':id' => $userId]
        );

        return $result ? $result[0] : null;
    }

    /**
     * Vérifie le mot de passe
     */
    private function verifyPassword(string $inputPassword, string $hashedPassword): bool
    {
        return (new Encrypter)->verifyHashedPassword($inputPassword, $hashedPassword);
    }

    /**
     * Vérifie si un compte est verrouillé
     */
    private function isLocked(array $admin): bool
    {
        return $admin['status'] !== 'active';
    }

    /**
     * Formate les données de l'admin pour la session
     */
    private function formatAdminData(array $admin): Data
    {
        return Data::create([
            'id' => $admin['id'],
            'fullname' => $admin['fullname'] ?? '',
            'username' => $admin['username'] ?? '',
            'email' => $admin['email'],
            'type' => $admin['type'] ?? 'user',
            'status' => $admin['status'] ?? 'inactive',
            'created_at' => $admin['created_at'] ?? null,
        ]);
    }

    /**
     * Crée un compte root
     */
    public function createRootAccount(Data $data): array
    {
        if ($this->hasRoot()) {
            return [
                'success' => true,
                'exists' => true,
                'message' => 'Le compte root existe déjà!'
            ];
        }

        // Hasher le mot de passe
        $hashedPassword = (new Encrypter())->hashPassword($data['password']);

        $result = $this->db->insert(
            self::TABLE,
            [
                'fullname' => $data['fullname'],
                'email' => $data['email'],
                'username' => $this->generateUsername($data['fullname']),
                'password' => $hashedPassword,
                'type' => 'root',
                'password_crypt' => Encrypter::radiusCryptPassword($data['password']),
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );

        if ($result) {
            return [
                'success' => true,
                'message' => 'Compte root créé avec succès!'
            ];
        }

        return [
            'success' => false,
            'message' => 'Une erreur est survenue lors de la création du compte root!'
        ];
    }

    /**
     * Met à jour le profil d'un admin
     */
    public function updateProfile(int|string $adminId, Data $data): array
    {
        $updateData = [];

        // Champs autorisés à être mis à jour
        $allowedFields = ['fullname', 'username', 'email'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return [
                'success' => false,
                'message' => 'Aucune donnée à mettre à jour.'
            ];
        }

        // Vérifier que l'email n'est pas déjà utilisé
        if (isset($updateData['email'])) {
            $existing = $this->db->select(
                table: self::TABLE,
                columns: 'id',
                conditions: 'email = :email AND id != :id',
                params: [
                    ':email' => $updateData['email'],
                    ':id' => $adminId
                ]
            );

            if ($existing) {
                return [
                    'success' => false,
                    'message' => 'Cet email est déjà utilisé par un autre compte.'
                ];
            }
        }

        $updateData['updated_at'] = date('Y-m-d H:i:s');

        $result = $this->db->update(
            self::TABLE,
            $updateData,
            'id = :id',
            [':id' => $adminId]
        );

        if ($result) {
            return [
                'success' => true,
                'message' => 'Profil mis à jour avec succès!'
            ];
        }

        return [
            'success' => false,
            'message' => 'Erreur lors de la mise à jour du profil.'
        ];
    }

    /**
     * Sauvegarde le token "Remember Me"
     */
    public function saveRememberToken(int|string $adminId, string $hashedToken): bool
    {
        $result = $this->db->update(
            self::TABLE,
            [
                'remember_token' => $hashedToken,
                'updated_at' => date('Y-m-d H:i:s')
            ],
            'id = :id',
            [':id' => $adminId]
        );

        return (bool) $result;
    }

    /**
     * Supprime le token "Remember Me"
     */
    public function clearRememberToken(int|string $adminId): bool
    {
        $result = $this->db->update(
            self::TABLE,
            [
                'remember_token' => null,
                'updated_at' => date('Y-m-d H:i:s')
            ],
            'id = :id',
            [':id' => $adminId]
        );

        return (bool) $result;
    }

    /**
     * Change le statut d'un admin
     */
    public function changeStatus(int|string $adminId, string $status): array
    {
        $allowedStatuses = ['active', 'inactive', 'suspended', 'banned'];

        if (!in_array($status, $allowedStatuses)) {
            return [
                'success' => false,
                'message' => 'Statut invalide.'
            ];
        }

        $result = $this->db->update(
            self::TABLE,
            [
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ],
            'id = :id',
            [':id' => $adminId]
        );

        if ($result) {
            return [
                'success' => true,
                'message' => 'Statut mis à jour avec succès!'
            ];
        }

        return [
            'success' => false,
            'message' => 'Erreur lors du changement de statut.'
        ];
    }
    /**
     * Vérifie si un compte root existe
     */
    public function hasRoot(): bool
    {
        $result = $this->db->table(self::TABLE)
            ->select('id')
            ->where('type', 'root')
            ->get();
        return !empty($result);
    }

    public function dropAllRoots(bool $validate = false): bool
    {
        if (!$validate) return false;
        try {
            $result = $this->db->table(self::TABLE)
                ->where('type', 'root')
                ->delete();
        } catch (\Throwable $th) {
            $result = false;
        }
        return $result ? true : false;
    }

    /**
     * Génère un username depuis le nom complet
     */
    private function generateUsername(string $fullname): string
    {
        // Nettoyer et normaliser
        $username = strtolower(trim($fullname));
        $username = preg_replace('/[^a-z0-9]+/', '', $username);

        // Limiter à 20 caractères
        $username = substr($username, 0, 20);

        // Vérifier l'unicité
        $exists = $this->db->select(
            table: self::TABLE,
            columns: 'id',
            conditions: 'username = :username',
            params: [':username' => $username]
        );

        // Si existe, ajouter un nombre
        if ($exists) {
            $counter = 1;
            do {
                $newUsername = $username . $counter;
                $exists = $this->db->select(
                    table: self::TABLE,
                    columns: 'id',
                    conditions: 'username = :username',
                    params: [':username' => $newUsername]
                );
                $counter++;
            } while ($exists);

            $username = $newUsername;
        }

        return $username;
    }
}

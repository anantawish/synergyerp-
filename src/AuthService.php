<?php

namespace Stock2;

use PDO;
use Throwable;

final class AuthService
{
    private PDO $pdo;
    private ?bool $moduleAccessTableAvailable = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function isLoggedIn(): bool
    {
        return isset($_SESSION['stock2_user']) && is_array($_SESSION['stock2_user']);
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        return $_SESSION['stock2_user'];
    }

    public function login(string $username, string $password): bool
    {
        $username = trim($username);
        $password = trim($password);

        if ($username === '' || $password === '') {
            return false;
        }

        $sql = 'SELECT id, username, password, name, user_level FROM unpw WHERE username = :username AND password = :password LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'username' => $username,
            'password' => $password,
        ]);

        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }

        $_SESSION['stock2_user'] = [
            'id' => (int)$row['id'],
            'username' => (string)$row['username'],
            'name' => (string)($row['name'] ?? ''),
            'user_level' => $row['user_level'],
        ];
        $this->refreshPermissionCache();

        return true;
    }

    public function logout(): void
    {
        unset(
            $_SESSION['stock2_user'],
            $_SESSION['stock2_permissions'],
            $_SESSION['stock2_module_permissions'],
            $_SESSION['stock2_module_rights']
        );
    }

    public function refreshPermissionCache(): void
    {
        $_SESSION['stock2_permissions'] = [];
        $_SESSION['stock2_module_permissions'] = [];
        $_SESSION['stock2_module_rights'] = [];
    }

    public function hasPermission(int $formId): bool
    {
        if ($formId <= 0) {
            return true;
        }

        $user = $this->user();
        if (!$user) {
            return false;
        }

        if (!isset($_SESSION['stock2_permissions']) || !is_array($_SESSION['stock2_permissions'])) {
            $_SESSION['stock2_permissions'] = [];
        }

        $cacheKey = (string)$formId;
        if (array_key_exists($cacheKey, $_SESSION['stock2_permissions'])) {
            return (bool)$_SESSION['stock2_permissions'][$cacheKey];
        }

        $sql = 'SELECT permision FROM user_access WHERE user_tid = :uid AND form_id = :form_id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'uid' => (int)$user['id'],
            'form_id' => $formId,
        ]);

        $allowed = false;
        $row = $stmt->fetch();
        if ($row && array_key_exists('permision', $row)) {
            $allowed = $this->toBool($row['permision']);
        }

        $_SESSION['stock2_permissions'][$cacheKey] = $allowed;
        return $allowed;
    }

    public function hasModulePermission(string $moduleKey, int $formId): bool
    {
        $moduleKey = trim($moduleKey);
        if ($moduleKey === '') {
            return $this->hasPermission($formId);
        }

        if (!$this->hasPermission($formId)) {
            return false;
        }

        if (!isset($_SESSION['stock2_module_permissions']) || !is_array($_SESSION['stock2_module_permissions'])) {
            $_SESSION['stock2_module_permissions'] = [];
        }

        $cacheKey = strtolower($moduleKey) . '|' . $formId;
        if (array_key_exists($cacheKey, $_SESSION['stock2_module_permissions'])) {
            return (bool)$_SESSION['stock2_module_permissions'][$cacheKey];
        }

        $rights = $this->moduleRights($moduleKey, $formId);
        $allowed = (bool)($rights['view'] ?? false);
        $_SESSION['stock2_module_permissions'][$cacheKey] = $allowed;
        return $allowed;
    }

    /** @return array{view: bool, add: bool, edit: bool, delete: bool, report: bool} */
    public function moduleRights(string $moduleKey, int $formId): array
    {
        $none = [
            'view' => false,
            'add' => false,
            'edit' => false,
            'delete' => false,
            'report' => false,
        ];

        $moduleKey = trim($moduleKey);
        if ($moduleKey === '') {
            return $none;
        }

        $user = $this->user();
        if (!$user) {
            return $none;
        }

        if (!$this->hasPermission($formId)) {
            return $none;
        }

        if (!isset($_SESSION['stock2_module_rights']) || !is_array($_SESSION['stock2_module_rights'])) {
            $_SESSION['stock2_module_rights'] = [];
        }

        $cacheKey = strtolower($moduleKey) . '|' . $formId;
        if (array_key_exists($cacheKey, $_SESSION['stock2_module_rights'])) {
            /** @var array{view: bool, add: bool, edit: bool, delete: bool, report: bool} $cached */
            $cached = $_SESSION['stock2_module_rights'][$cacheKey];
            return $cached;
        }

        $rights = [
            'view' => true,
            'add' => true,
            'edit' => true,
            'delete' => true,
            'report' => true,
        ];

        if ($this->isModuleAccessTableAvailable()) {
            $sql = 'SELECT can_view, can_add, can_edit, can_delete, can_report
                    FROM user_module_access
                    WHERE user_tid = :uid AND module_key = :module_key
                    LIMIT 1';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'uid' => (int)$user['id'],
                'module_key' => $moduleKey,
            ]);
            $row = $stmt->fetch();
            if ($row) {
                $map = [
                    'view' => 'can_view',
                    'add' => 'can_add',
                    'edit' => 'can_edit',
                    'delete' => 'can_delete',
                    'report' => 'can_report',
                ];
                foreach ($map as $rightKey => $column) {
                    if (array_key_exists($column, $row) && $row[$column] !== null) {
                        $rights[$rightKey] = $this->toBool($row[$column]);
                    }
                }
            }
        }

        $_SESSION['stock2_module_rights'][$cacheKey] = $rights;
        return $rights;
    }

    private function isModuleAccessTableAvailable(): bool
    {
        if ($this->moduleAccessTableAvailable !== null) {
            return $this->moduleAccessTableAvailable;
        }

        try {
            $sql = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_module_access'";
            $count = (int)$this->pdo->query($sql)->fetchColumn();
            $this->moduleAccessTableAvailable = $count > 0;
        } catch (Throwable $_) {
            $this->moduleAccessTableAvailable = false;
        }

        return $this->moduleAccessTableAvailable;
    }

    /** @param mixed $value */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true);
    }
}


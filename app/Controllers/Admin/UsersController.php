<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\UserValidator;
use App\Models\User;

final class UsersController extends BaseController
{
    public function index(array $params = []): string
    {
        $this->freshOwner($this->currentUser());
        return $this->renderIndex(200, null);
    }

    public function create(array $params = []): string
    {
        $this->freshOwner($this->currentUser());
        return $this->renderForm(200, null, ['name' => '', 'login' => '', 'role' => 'editor'], []);
    }

    public function store(array $params = []): string
    {
        $me = $this->currentUser();
        $name = UserValidator::cleanName($_POST['name'] ?? '');
        $login = UserValidator::cleanLogin($_POST['login'] ?? '');
        $role = self::post('role');
        $password = self::post('password');
        $old = ['name' => $name, 'login' => $login, 'role' => $role];

        $errors = $this->validate($name, $login, $role, null);
        if ($err = UserValidator::passwordError($password)) {
            $errors['password'] = $err;
        }
        if ($errors) {
            return $this->renderForm(422, null, $old, $errors);
        }
        try {
            $id = (int)Database::immediate(function () use ($name, $login, $password, $role, $me): int {
                $me = $this->freshOwner($me);
                $id = User::create($name, $login, $password, $role);
                Audit::log((int)$me['id'], 'create', 'user', $id);
                return $id;
            });
        } catch (\PDOException $e) {
            return $this->dbFailure($e, fn(int $st, ?string $msg, array $err) => $this->renderForm($st, null, $old, $err, $msg));
        }
        Flash::set('success', 'users.created', ['name' => $name]);
        redirect(admin_url('/users'));
    }

    public function edit(array $params): string
    {
        $this->freshOwner($this->currentUser());
        $target = $this->target($params);
        return $this->renderForm(200, $target, ['name' => $target['name'], 'login' => $target['login'], 'role' => $target['role']], []);
    }

    public function update(array $params): string
    {
        $me = $this->currentUser();
        $target = $this->target($params);
        $id = (int)$target['id'];
        $name = UserValidator::cleanName($_POST['name'] ?? '');
        $login = UserValidator::cleanLogin($_POST['login'] ?? '');
        $role = array_key_exists('role', $_POST) ? self::post('role') : (string)$target['role'];
        $old = ['name' => $name, 'login' => $login, 'role' => $role];

        $errors = $this->validate($name, $login, $role, $id);
        if ($errors) {
            return $this->renderForm(422, $target, $old, $errors);
        }

        $general = null;
        try {
            Database::immediate(function () use ($id, $me, $name, $login, $role, &$general): void {
                $me = $this->freshOwner($me);
                $cur = User::find($id);
                if ($cur === null) {
                    throw new HttpException(404);
                }
                $roleChanged = $role !== $cur['role'];
                if ($roleChanged && $cur['role'] === 'owner' && $role === 'editor') {
                    if ($id === (int)$me['id']) {
                        $general = 'users.error.self_role';
                        return;
                    }
                    if ((int)$cur['is_active'] === 1 && User::countActiveOwners() <= 1) {
                        $general = 'users.error.last_owner';
                        return;
                    }
                }
                $dataChanged = $name !== $cur['name'] || $login !== mb_strtolower((string)$cur['login']);
                if ($dataChanged || $roleChanged) {
                    User::update($id, $name, $login, $role);
                }
                if ($dataChanged) {
                    Audit::log((int)$me['id'], 'update', 'user', $id);
                }
                if ($roleChanged) {
                    Audit::log((int)$me['id'], 'role_change', 'user', $id);
                }
            });
        } catch (\PDOException $e) {
            return $this->dbFailure($e, fn(int $st, ?string $msg, array $err) => $this->renderForm($st, $target, $old, $err, $msg));
        }
        if ($general !== null) {
            return $this->renderForm(422, $target, $old, [], t($general));
        }
        Flash::set('success', 'users.saved');
        redirect(admin_url('/users'));
    }

    public function block(array $params): string
    {
        $me = $this->currentUser();
        $target = $this->target($params);
        $id = (int)$target['id'];
        $error = null;
        $done = false;
        try {
        Database::immediate(function () use ($id, $me, &$error, &$done): void {
            $me = $this->freshOwner($me);
            $cur = User::find($id);
            if ($cur === null) {
                throw new HttpException(404);
            }
            if ($id === (int)$me['id']) {
                $error = 'users.error.self_block';
                return;
            }
            if ((int)$cur['is_active'] === 0) {
                return;
            }
            if ($cur['role'] === 'owner' && User::countActiveOwners() <= 1) {
                $error = 'users.error.last_owner';
                return;
            }
            User::setActive($id, false);
            Auth::bumpVersion($id);
            Audit::log((int)$me['id'], 'block', 'user', $id);
            $done = true;
        });
        } catch (\PDOException $e) {
            return $this->dbFailure($e, fn(int $st, ?string $msg) => $this->renderIndex($st, $msg));
        }
        if ($error !== null) {
            return $this->renderIndex(422, t($error));
        }
        if ($done) {
            Flash::set('success', 'users.blocked', ['name' => $target['name']]);
        }
        redirect(admin_url('/users'));
    }

    public function unblock(array $params): string
    {
        $me = $this->currentUser();
        $target = $this->target($params);
        $id = (int)$target['id'];
        $done = false;
        try {
            Database::immediate(function () use ($id, $me, &$done): void {
                $me = $this->freshOwner($me);
                $cur = User::find($id);
                if ($cur !== null && (int)$cur['is_active'] === 0) {
                    User::setActive($id, true);
                    Audit::log((int)$me['id'], 'unblock', 'user', $id);
                    $done = true;
                }
            });
        } catch (\PDOException $e) {
            return $this->dbFailure($e, fn(int $st, ?string $msg) => $this->renderIndex($st, $msg));
        }
        if ($done) {
            Flash::set('success', 'users.unblocked', ['name' => $target['name']]);
        }
        redirect(admin_url('/users'));
    }

    public function passwordForm(array $params): string
    {
        $me = $this->freshOwner($this->currentUser());
        $target = $this->target($params);
        if ((int)$target['id'] === (int)$me['id']) {
            redirect(admin_url('/profile'));
        }
        return $this->renderPassword(200, $target, []);
    }

    public function resetPassword(array $params): string
    {
        $me = $this->currentUser();
        $target = $this->target($params);
        $id = (int)$target['id'];
        if ($id === (int)$me['id']) {
            throw new HttpException(403);
        }
        $password = self::post('password');
        if ($err = UserValidator::passwordError($password)) {
            return $this->renderPassword(422, $target, ['password' => $err]);
        }
        try {
            Database::immediate(function () use ($id, $me, $password): void {
                $me = $this->freshOwner($me);
                User::setPassword($id, $password);
                Auth::bumpVersion($id);
                Audit::log((int)$me['id'], 'password_reset', 'user', $id);
            });
        } catch (\PDOException $e) {
            return $this->dbFailure($e, fn(int $st, ?string $msg) => $this->renderPassword($st, $target, [], $msg));
        }
        Flash::set('success', 'users.password.done', ['name' => $target['name']]);
        redirect(admin_url('/users'));
    }

    /** Роль и активность актора перечитываются под блокировкой записи (сессия могла устареть). */
    private function freshOwner(array $me): array
    {
        $cur = User::find((int)$me['id']);
        if ($cur === null || $cur['role'] !== 'owner' || (int)$cur['is_active'] !== 1) {
            throw new HttpException(403);
        }
        return $cur;
    }

    /**
     * Ошибка БД → ответ: UNIQUE → «логин занят», занято (SQLITE_BUSY) → «сервер занят», остальное — дальше.
     * @param callable(int,?string,array<string,string>):string $render
     */
    private function dbFailure(\PDOException $e, callable $render): string
    {
        if (str_contains($e->getMessage(), 'UNIQUE constraint')) {
            return $render(422, null, ['login' => 'validation.login.taken']);
        }
        if (Database::isBusy($e)) {
            return $render(503, t('error.busy'), []);
        }
        throw $e;
    }

    private function target(array $params): array
    {
        $target = User::find((int)($params['id'] ?? 0));
        if ($target === null) {
            throw new HttpException(404);
        }
        return $target;
    }

    /** @return array<string,string> */
    private function validate(string $name, string $login, string $role, ?int $exceptId): array
    {
        $errors = [];
        if ($e = UserValidator::nameError($name)) {
            $errors['name'] = $e;
        }
        if ($e = UserValidator::loginError($login, $exceptId)) {
            $errors['login'] = $e;
        }
        if ($e = UserValidator::roleError($role)) {
            $errors['role'] = $e;
        }
        return $errors;
    }

    private function renderIndex(int $status, ?string $generalError): string
    {
        $me = $this->currentUser();
        $users = [];
        foreach (User::all() as $u) {
            $u['is_self'] = (int)$u['id'] === (int)$me['id'];
            $u['last_login_fmt'] = self::formatDate($u['last_login_at']);
            $users[] = $u;
        }
        return $this->page('users/index', t('users.title'), [
            $this->home(),
            ['label' => t('users.title'), 'url' => null],
        ], ['users' => $users, 'generalError' => $generalError], $status);
    }

    private function renderForm(int $status, ?array $target, array $old, array $errors, ?string $generalError = null): string
    {
        $me = $this->currentUser();
        $locked = null;
        if ($target !== null && $target['role'] === 'owner') {
            if ((int)$target['id'] === (int)$me['id']) {
                $locked = 'self';
            } elseif ((int)$target['is_active'] === 1 && User::countActiveOwners() <= 1) {
                $locked = 'last_owner';
            }
        }
        $leaf = $target === null ? t('users.new.title') : t('users.edit.title');
        return $this->page('users/form', $leaf, [
            $this->home(),
            ['label' => t('users.title'), 'url' => admin_url('/users')],
            ['label' => $leaf, 'url' => null],
        ], [
            'target'       => $target,
            'old'          => $old,
            'errors'       => $errors,
            'generalError' => $generalError,
            'roleLocked'   => $locked,
            'formAction'   => $target === null ? admin_url('/users') : admin_url('/users/' . (int)$target['id']),
        ], $status);
    }

    private function renderPassword(int $status, array $target, array $errors, ?string $generalError = null): string
    {
        return $this->page('users/password', t('users.password.title'), [
            $this->home(),
            ['label' => t('users.title'), 'url' => admin_url('/users')],
            ['label' => t('users.password.title'), 'url' => null],
        ], [
            'target'       => $target,
            'errors'       => $errors,
            'generalError' => $generalError,
            'formAction'   => admin_url('/users/' . (int)$target['id'] . '/password'),
        ], $status);
    }

    public static function formatDate(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $d = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        return $d->setTimezone(new \DateTimeZone('Asia/Almaty'))->format('d.m.Y H:i');
    }
}

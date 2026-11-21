<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\AuthResult;
use App\Core\Flash;
use App\Core\Guard;
use App\Core\View;

final class AuthController extends BaseController
{
    public function showLogin(array $params = []): string
    {
        if (Auth::user() !== null) {
            redirect(admin_url());
        }
        $reason = Auth::endReason();
        if ($reason === 'timeout') {
            Flash::set('info', 'auth.timeout');
        } elseif ($reason === 'version') {
            Flash::set('info', 'auth.password_changed_elsewhere');
        }
        $flash = Flash::pull();
        return $this->form(200, [], [], null, $flash['text'] ?? null, $this->nextValue($_GET['next'] ?? null));
    }

    public function login(array $params = []): string
    {
        $login = trim(self::post('login'));
        $password = self::post('password');
        $next = $this->nextValue($_POST['next'] ?? null);
        $old = ['login' => $login];

        if (!csrf_verify(self::post('_csrf'))) {
            return $this->form(400, $old, [], t('auth.error.csrf'), null, $next);
        }
        if (Auth::user() !== null) {
            redirect(admin_url());
        }
        $errors = [];
        if ($login === '') {
            $errors['login'] = 'validation.login.enter';
        }
        if ($password === '') {
            $errors['password'] = 'validation.password.enter';
        }
        if ($errors) {
            return $this->form(422, $old, $errors, null, null, $next);
        }

        $res = Auth::attempt($login, $password, self::post('remember') === '1', Auth::ip());
        switch ($res->status) {
            case AuthResult::OK:
                redirect(Guard::safeNext($next));
            case AuthResult::LOCKED:
                return $this->form(429, $old, [], t('auth.error.locked', ['minutes' => $res->minutes]), null, $next);
            case AuthResult::BLOCKED:
                return $this->form(422, $old, [], t('auth.error.blocked'), null, $next);
            default:
                return $this->form(422, $old, [], t('auth.error.invalid'), null, $next);
        }
    }

    public function logout(array $params = []): never
    {
        Auth::logout();
        Flash::set('info', 'auth.logged_out');
        redirect(admin_url('/login'));
    }

    /** next для скрытого поля: только допустимый и не равный главной (иначе пусто). */
    private function nextValue(mixed $raw): string
    {
        $safe = Guard::safeNext($raw);
        return $safe === admin_url() ? '' : $safe;
    }

    private function form(int $status, array $old, array $errors, ?string $generalError, ?string $notice, string $next): string
    {
        http_response_code($status);
        $siteName = $this->siteName();
        return View::render('auth/login', [
            'title'        => t('auth.title') . ' — ' . $siteName,
            'siteName'     => $siteName,
            'old'          => $old,
            'errors'       => $errors,
            'generalError' => $generalError,
            'notice'       => $notice,
            'next'         => $next,
        ], 'layouts/auth');
    }
}

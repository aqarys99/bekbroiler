<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Flash;
use App\Core\LoginThrottle;
use App\Core\UserValidator;
use App\Models\User;

final class ProfileController extends BaseController
{
    public function show(array $params = []): string
    {
        return $this->render(200, []);
    }

    public function changePassword(array $params = []): string
    {
        $me = $this->currentUser();
        $id = (int)$me['id'];
        $fresh = User::find($id) ?? $me;
        $current = self::post('current_password');
        $new = self::post('new_password');
        $confirm = self::post('new_password_confirm');

        $errors = [];
        $slot = null;
        if ($current === '') {
            $errors['current_password'] = 'validation.current_password.required';
        } else {
            // отдельный счётчик попыток текущего пароля: 5 за 15 минут
            [$left, $slot] = LoginThrottle::reserve('profile:' . $id, 'password');
            if ($slot === null) {
                return $this->render(429, [], t('profile.error.locked', ['minutes' => (int)ceil($left / 60)]));
            }
            if (!password_verify($current, (string)$fresh['password_hash'])) {
                $left = LoginThrottle::lockedFor('profile:' . $id, 'password');
                if ($left > 0) {
                    return $this->render(429, [], t('profile.error.locked', ['minutes' => (int)ceil($left / 60)]));
                }
                $errors['current_password'] = 'validation.current_password.wrong';
            } else {
                LoginThrottle::release($slot);
            }
        }
        if ($err = UserValidator::passwordError($new)) {
            $errors['new_password'] = $err;
        } elseif ($current !== '' && $new === $current) {
            $errors['new_password'] = 'validation.password.same';
        } elseif ($new !== $confirm) {
            $errors['new_password_confirm'] = 'validation.password.confirm';
        }
        if ($errors) {
            return $this->render(422, $errors);
        }

        User::setPassword($id, $new);
        Auth::bumpVersion($id);
        Audit::log($id, 'password_change', 'user', $id);
        Flash::set('success', 'profile.changed');
        redirect(admin_url('/profile'));
    }

    private function render(int $status, array $errors, ?string $generalError = null): string
    {
        return $this->page('profile/show', t('profile.title'), [
            $this->home(),
            ['label' => t('profile.title'), 'url' => null],
        ], ['errors' => $errors, 'generalError' => $generalError], $status);
    }
}

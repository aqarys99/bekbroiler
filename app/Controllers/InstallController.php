<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AlreadyInstalledException;
use App\Core\Installer;
use App\Core\InstallException;
use App\Core\Requirements;
use App\Core\ValidationException;
use App\Core\View;

final class InstallController
{
    public function show(array $params = []): string
    {
        if (Installer::isInstalled()) {
            return $this->locked();
        }
        return $this->form(200, [], [], null);
    }

    public function store(array $params = []): string
    {
        if (Installer::isInstalled()) {
            return $this->locked();
        }
        $old = [
            'site_name'  => (string)($_POST['site_name'] ?? ''),
            'owner_name' => (string)($_POST['owner_name'] ?? ''),
            'login'      => (string)($_POST['login'] ?? ''),
        ];
        $token = $_POST['_csrf'] ?? null;
        if (!is_string($token) || !csrf_verify($token)) {
            return $this->form(400, $old, [], t('install.error.csrf'));
        }
        try {
            Installer::install($_POST);
        } catch (AlreadyInstalledException) {
            return $this->locked();
        } catch (ValidationException $e) {
            return $this->form(422, $old, $e->errors(), null);
        } catch (InstallException $e) {
            return $this->form($e->status(), $old, [], t($e->messageKey()));
        }
        http_response_code(200);
        return View::render('install/done', [
            'title' => t('install.done.title'),
            'login' => mb_strtolower(trim($old['login'])),
        ]);
    }

    private function locked(): string
    {
        http_response_code(403);
        return View::render('install/locked', ['title' => t('install.locked.title')]);
    }

    private function form(int $status, array $old, array $errors, ?string $generalError): string
    {
        $requirements = Requirements::check();
        http_response_code($status);
        return View::render('install/form', [
            'title'        => t('install.title'),
            'requirements' => $requirements,
            'hasFail'      => Requirements::hasFail($requirements),
            'errors'       => $errors,
            'old'          => $old,
            'generalError' => $generalError,
            'debug'        => (bool)config('debug', false),
            'exception'    => null,
        ]);
    }
}

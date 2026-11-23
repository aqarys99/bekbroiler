<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Backup;
use App\Core\Flash;
use App\Core\HttpException;

final class BackupsController extends BaseController
{
    public function index(array $params = []): string
    {
        return $this->page('backups/index', t('backups.title'), [$this->home(), ['label' => t('backups.title'), 'url' => null]], [
            'items'  => Backup::listDb(),
            'zip'    => Backup::zipAvailable(),
            'size'   => Backup::estimateSize(),
            'keep'   => Backup::KEEP,
        ]);
    }

    public function create(array $params = []): string
    {
        $me = $this->currentUser();
        try {
            Backup::createDbBackup();
        } catch (\Throwable $e) {
            \App\Core\Logger::error('Копия базы не создана', ['error' => $e->getMessage()]);
            Flash::set('error', 'backups.error.create');
            redirect(admin_url('/backups'));
        }
        Audit::log((int)$me['id'], 'backup.create', 'backup');
        Flash::set('success', 'backups.created');
        redirect(admin_url('/backups'));
    }

    public function delete(array $params): string
    {
        $me = $this->currentUser();
        $name = (string)($params['name'] ?? '');
        if (!Backup::validName($name) || !Backup::delete($name)) {
            Flash::set('error', 'backups.error.notfound');
            redirect(admin_url('/backups'));
        }
        Audit::log((int)$me['id'], 'backup.delete', 'backup');
        Flash::set('success', 'backups.deleted');
        redirect(admin_url('/backups'));
    }

    /** Скачать одну автокопию базы. */
    public function download(array $params): string
    {
        $me = $this->currentUser();
        $path = Backup::path((string)($params['name'] ?? ''));
        if ($path === null) {
            throw new HttpException(404);
        }
        Audit::log((int)$me['id'], 'backup.download_db', 'backup');
        Backup::stream($path, basename($path), 'application/vnd.sqlite3');
        exit;
    }

    /** Архив «база + файлы + config»; без расширения zip — только база. */
    public function archive(array $params = []): string
    {
        $me = $this->currentUser();
        $date = gmdate('Ymd-His');
        try {
            if (!Backup::zipAvailable()) {
                $tmp = Backup::dir() . '/.tmp-' . bin2hex(random_bytes(4)) . '.sqlite';
                Backup::snapshotDb($tmp);
                Audit::log((int)$me['id'], 'backup.download_db', 'backup');
                Backup::stream($tmp, 'database-' . $date . '.sqlite', 'application/vnd.sqlite3', true);
                exit;
            }
            if (!Backup::enoughSpace()) {
                Flash::set('error', 'backups.error.space');
                redirect(admin_url('/backups'));
            }
            $zip = Backup::buildArchive();
        } catch (\App\Core\RedirectException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \App\Core\Logger::error('Архив не создан', ['error' => $e->getMessage()]);
            Flash::set('error', 'backups.error.archive');
            redirect(admin_url('/backups'));
        }
        Audit::log((int)$me['id'], 'backup.download', 'backup');
        Backup::stream($zip, 'site-backup-' . $date . '.zip', 'application/zip', true);
        exit;
    }
}

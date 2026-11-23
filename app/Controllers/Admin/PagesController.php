<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Flash;
use App\Models\Page;

final class PagesController extends BaseController
{
    public function index(array $params = []): string
    {
        return $this->page('pages/index', t('pages.title'), [
            $this->home(), ['label' => t('pages.title'), 'url' => null],
        ], ['rows' => Page::all()]);
    }

    public function edit(array $params): string
    {
        $row = $this->mustFind($params);
        return $this->form($row, $row, null, [], [], 200);
    }

    public function update(array $params): string
    {
        $row = $this->mustFind($params);
        $isRezhim = $row['slug'] === 'rezhim';
        $prep = Page::prepare($_POST, $isRezhim);
        if ($prep['errors'] || $prep['schedule_errors']) {
            $old = $prep['data'] + ['slug' => $row['slug']];
            $old['body'] = is_string($_POST['body'] ?? null) ? $_POST['body'] : '';
            $old['body_kk'] = is_string($_POST['body_kk'] ?? null) ? $_POST['body_kk'] : '';
            return $this->form($row, $old, $isRezhim ? $this->postedSchedule() : null, $prep['errors'], $prep['schedule_errors'], 422);
        }
        Page::update((int)$row['id'], $prep['data'], $isRezhim ? $prep['schedule'] : null);
        Audit::log((int)$this->currentUser()['id'], 'pages.update', 'pages', (int)$row['id']);
        Flash::set('success', 'pages.flash.saved');
        redirect(admin_url('/pages'));
    }

    public function publish(array $params): string
    {
        $row = $this->mustFind($params);
        $on = !empty($_POST['is_published']);
        Page::setPublished((int)$row['id'], $on);
        Audit::log((int)$this->currentUser()['id'], $on ? 'pages.publish' : 'pages.unpublish', 'pages', (int)$row['id']);
        Flash::set('success', $on ? 'pages.flash.shown' : 'pages.flash.hidden');
        redirect(admin_url('/pages'));
    }

    private function postedSchedule(): array
    {
        $out = [];
        $times = (array)($_POST['sched_time'] ?? []);
        foreach ($times as $i => $t) {
            $out[] = [
                'time_range' => is_string($t) ? $t : '',
                'text' => is_string($_POST['sched_text'][$i] ?? null) ? $_POST['sched_text'][$i] : '',
                'text_kk' => is_string($_POST['sched_text_kk'][$i] ?? null) ? $_POST['sched_text_kk'][$i] : '',
            ];
        }
        return $out;
    }

    private function form(array $row, array $old, ?array $schedule, array $errors, array $rowErrors, int $status): string
    {
        $label = (string)$row['title'];
        return $this->page('pages/form', $label, [
            $this->home(), ['label' => t('pages.title'), 'url' => admin_url('/pages')], ['label' => $label, 'url' => null],
        ], [
            'row' => $row, 'old' => $old, 'errors' => $errors, 'rowErrors' => $rowErrors,
            'schedule' => $row['slug'] === 'rezhim' ? ($schedule ?? Page::schedule()) : null,
        ], $status);
    }

    private function mustFind(array $params): array
    {
        $row = Page::find((int)($params['id'] ?? 0));
        if ($row === null) {
            abort(404);
        }
        return $row;
    }
}

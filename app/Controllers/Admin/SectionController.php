<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Flash;
use App\Models\Media;

/**
 * Общий CRUD для списков раздела сайта: список, форма, создание, правка, порядок, показ, удаление.
 * Дочерний класс задаёт KEY (staff, groups, …), MODEL и при необходимости extras()/afterSave().
 */
abstract class SectionController extends BaseController
{
    protected const KEY = '';
    protected const MODEL = '';
    protected const HAS_PUBLISH = true;

    /** Доп. данные для шаблона формы/списка. */
    protected function extras(?array $row, bool $form = true): array
    {
        return [];
    }

    protected function listRows(): array
    {
        $m = static::MODEL;
        return $m::all();
    }

    protected function afterSave(int $id, array $prep): void
    {
    }

    protected function blank(): array
    {
        return ['is_published' => 1];
    }

    /** Значения формы из строки БД. */
    protected function valuesOf(array $row): array
    {
        return $row;
    }

    protected function title(array $row): string
    {
        return (string)($row['title'] ?? $row['full_name'] ?? $row['name'] ?? $row['author'] ?? '');
    }

    public function index(array $params = []): string
    {
        $k = static::KEY;
        return $this->page($k . '/index', t($k . '.title'), [
            $this->home(), ['label' => t($k . '.title'), 'url' => null],
        ], ['rows' => $this->listRows(), 'base' => admin_url('/' . $k)] + $this->extras(null, false));
    }

    public function create(array $params = []): string
    {
        return $this->form(null, $this->blank(), [], 200);
    }

    public function edit(array $params): string
    {
        $row = $this->mustFind($params);
        return $this->form($row, $this->valuesOf($row), [], 200);
    }

    public function store(array $params = []): string
    {
        $m = static::MODEL;
        $prep = $m::prepare($_POST);
        if ($prep['errors']) {
            return $this->form(null, $this->posted(), $prep['errors'], 422);
        }
        $id = $m::create($prep['data']);
        $this->afterSave($id, $prep);
        Audit::log((int)$this->currentUser()['id'], static::KEY . '.create', static::KEY, $id);
        Flash::set('success', 'sections.flash.created');
        redirect(admin_url('/' . static::KEY));
    }

    public function update(array $params): string
    {
        $m = static::MODEL;
        $row = $this->mustFind($params);
        $prep = $m::prepare($_POST);
        if ($prep['errors']) {
            return $this->form($row, $this->posted(), $prep['errors'], 422);
        }
        $m::update((int)$row['id'], $prep['data']);
        $this->afterSave((int)$row['id'], $prep);
        Audit::log((int)$this->currentUser()['id'], static::KEY . '.update', static::KEY, (int)$row['id']);
        Flash::set('success', 'sections.flash.saved');
        redirect(admin_url('/' . static::KEY));
    }

    public function publish(array $params): string
    {
        $m = static::MODEL;
        $row = $this->mustFind($params);
        $on = !empty($_POST['is_published']);
        $m::setPublished((int)$row['id'], $on);
        Audit::log((int)$this->currentUser()['id'], static::KEY . ($on ? '.publish' : '.unpublish'), static::KEY, (int)$row['id']);
        Flash::set('success', $on ? 'sections.flash.shown' : 'sections.flash.hidden');
        redirect(admin_url('/' . static::KEY));
    }

    public function move(array $params): string
    {
        $m = static::MODEL;
        $row = $this->mustFind($params);
        if ($m::move((int)$row['id'], self::post('dir') === 'up' ? -1 : 1)) {
            Audit::log((int)$this->currentUser()['id'], static::KEY . '.move', static::KEY, (int)$row['id']);
            Flash::set('success', 'sections.flash.moved');
        }
        redirect(admin_url('/' . static::KEY));
    }

    public function delete(array $params): string
    {
        $m = static::MODEL;
        $row = $m::delete((int)($params['id'] ?? 0));
        if ($row === null) {
            abort(404);
        }
        Audit::log((int)$this->currentUser()['id'], static::KEY . '.delete', static::KEY, (int)$row['id']);
        Flash::set('success', 'sections.flash.deleted');
        redirect(admin_url('/' . static::KEY));
    }

    // ------------------------------------------------------------------ служебное

    private function form(?array $row, array $old, array $errors, int $status): string
    {
        $k = static::KEY;
        $label = $row === null ? t($k . '.new') : $this->title($row);
        return $this->page($k . '/form', $label, [
            $this->home(),
            ['label' => t($k . '.title'), 'url' => admin_url('/' . $k)],
            ['label' => $label, 'url' => null],
        ], ['row' => $row, 'old' => $old, 'errors' => $errors, 'base' => admin_url('/' . $k)] + $this->extras($row), $status);
    }

    /** Введённые значения для повторного показа формы. */
    protected function posted(): array
    {
        $v = [];
        foreach ($_POST as $key => $val) {
            if (is_string($key) && (is_string($val) || is_array($val))) {
                $v[$key] = $val;
            }
        }
        $v['is_published'] = !empty($_POST['is_published']) ? 1 : 0;
        return $v;
    }

    /** Поле media-field для одного фото. */
    protected function photoField(string $name, string $label, ?int $selected): array
    {
        $max = Media::maxUploadBytes();
        return [
            'name' => $name, 'label' => $label, 'multiple' => false,
            'values' => Media::pickerValues($selected !== null ? [$selected] : []),
            'upload' => [
                'url' => admin_url('/media/upload'), 'maxBytes' => $max,
                'maxSide' => (int)config('media.client_max_side', 2560),
                'quality' => (float)config('media.client_quality', 0.85), 'csrf' => csrf_token(),
            ],
            'pickerUrl' => admin_url('/media/picker'), 'maxLabel' => format_bytes($max),
        ];
    }

    private function mustFind(array $params): array
    {
        $m = static::MODEL;
        $row = $m::find((int)($params['id'] ?? 0));
        if ($row === null) {
            abort(404);
        }
        return $row;
    }
}

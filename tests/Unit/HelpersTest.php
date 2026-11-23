<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use Tests\TestCase;

final class HelpersTest extends TestCase
{
    public function testEscape(): void
    {
        $this->assertSame('&lt;script&gt;&quot;&#039;&amp;', e('<script>"\'&'));
        $this->assertSame('', e(null));
        $this->assertSame('42', e(42));
        $this->assertSame('1.5', e(1.5));
        $this->assertIsString(e("bad \xB1 utf8"));
    }

    public function testTranslate(): void
    {
        $this->assertSame('нет.ключа', t('нет.ключа'));
        $this->assertSame('Установка завершена', t('install.done.title'));
        $this->assertStringContainsString('Ваш логин: a.', t('install.done.text', ['login' => 'a']));
    }

    public function testTranslatePlaceholderPrefixes(): void
    {
        $this->assertSame('Страница 1 из 3', t('media.page.info', ['page' => 1, 'pages' => 3]));
        $this->assertSame('Страница 2 из 5', t('media.page.info', ['pages' => 5, 'page' => 2]));
        $this->assertSame('Пользователь Аня добавлен', t('users.created', ['name' => 'Аня', 'names' => 'X']));
    }

    public function testUrlAndAssetAndAdminUrl(): void
    {
        Config::set(['base_path' => '']);
        $this->assertSame('/x?a=1', url('/x', ['a' => 1]));
        $this->assertSame('/', url());
        $this->assertSame('/admin', admin_url());
        Config::set(['base_path' => '/sadik']);
        $this->assertSame('/sadik/x?a=1', url('/x', ['a' => 1]));
        $this->assertSame('/sadik/admin/news', admin_url('/news'));
        $this->assertMatchesRegularExpression('~^/sadik/assets/admin/admin\.css\?v=\d+$~', asset('admin/admin.css'));
        $this->assertSame('/sadik/assets/nope.css', asset('nope.css'));
    }

    public function testConfigHelper(): void
    {
        $this->assertSame('dflt', config('no.such.key', 'dflt'));
        $this->assertSame('ru', config('locale'));
    }

    public function testCsrf(): void
    {
        $_SESSION = [];
        $token = csrf_token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, csrf_token());
        $this->assertStringContainsString('name="_csrf" value="' . $token . '"', csrf_field());
        $this->assertTrue(csrf_verify($token));
        $this->assertFalse(csrf_verify('wrong'));
        $this->assertFalse(csrf_verify(''));
        $this->assertFalse(csrf_verify(null));
    }
}

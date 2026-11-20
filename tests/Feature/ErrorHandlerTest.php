<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config;
use App\Core\ErrorHandler;
use App\Core\HttpException;
use Tests\TestCase;

final class ErrorHandlerTest extends TestCase
{
    private string $old;

    protected function setUp(): void
    {
        $this->old = (string)getenv('APP_STORAGE_DIR');
        putenv('APP_STORAGE_DIR=' . $this->tempDir());
    }

    protected function tearDown(): void
    {
        putenv('APP_STORAGE_DIR=' . $this->old);
        unset($_POST['password']);
        parent::tearDown();
    }

    public function testProductionHidesDetails(): void
    {
        Config::set(['debug' => false]);
        $html = ErrorHandler::render(new \RuntimeException('Test error'));
        $this->assertStringContainsString('Что-то пошло не так', $html);
        $this->assertStringContainsString('data-testid="error-500"', $html);
        $this->assertStringNotContainsString('Test error', $html);
        $this->assertStringNotContainsString('RuntimeException', $html);
        $this->assertStringNotContainsString(ROOT, $html);
        $this->assertStringNotContainsString('error-500-details', $html);
    }

    public function testDebugShowsDetailsEscaped(): void
    {
        Config::set(['debug' => true]);
        $html = ErrorHandler::render(new \RuntimeException('Test <b>error</b>'));
        $this->assertStringContainsString('data-testid="error-500-details"', $html);
        $this->assertStringContainsString('RuntimeException', $html);
        $this->assertStringContainsString('Test &lt;b&gt;error&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>error</b>', $html);
    }

    public function testForbiddenPageUsesErrors403InAuthLayout(): void
    {
        $html = ErrorHandler::render(new HttpException(403));
        $this->assertStringContainsString('data-testid="error-403"', $html);
        $this->assertStringContainsString('data-testid="error-403-home"', $html);
        $this->assertStringContainsString('Доступ запрещён', $html);
        $this->assertStringContainsString('<html', $html); // внутри layouts/auth
    }

    public function testHttpPagesRendered(): void
    {
        $this->assertStringContainsString('error-404', ErrorHandler::render(new HttpException(404)));
        $this->assertStringContainsString('Метод не поддерживается', ErrorHandler::render(new HttpException(405)));
    }

    /** I-20 */
    public function testReportLogsContextButNotPostBody(): void
    {
        $_POST['password'] = 'SuperSecret-777';
        $_SERVER['REQUEST_URI'] = '/__test/error';
        ErrorHandler::report(new \RuntimeException('Test error'));
        $log = (string)file_get_contents(Config::storageDir() . '/logs/app-' . gmdate('Y-m-d') . '.log');
        $this->assertStringContainsString('ERROR RuntimeException: Test error', $log);
        $this->assertStringContainsString('/__test/error', $log);
        $this->assertStringContainsString('ErrorHandlerTest.php', $log);
        $this->assertStringNotContainsString('SuperSecret-777', $log);
        unset($_SERVER['REQUEST_URI']);
    }
}

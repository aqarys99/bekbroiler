<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\UserValidator;
use App\Models\User;
use Tests\TestCase;

final class UserValidatorTest extends TestCase
{
    public function testName(): void
    {
        $this->assertSame('Анна Петрова', UserValidator::cleanName("  Анна \t  Петрова "));
        $this->assertNull(UserValidator::nameError('Анна'));
        $this->assertSame('validation.owner_name.required', UserValidator::nameError(''));
        $this->assertSame('validation.owner_name.length', UserValidator::nameError(str_repeat('я', 101)));
        $this->assertNull(UserValidator::nameError(str_repeat('я', 100)));
    }

    public function testLogin(): void
    {
        $this->assertSame('teacher.1', UserValidator::cleanLogin('  Teacher.1 '));
        $this->assertSame('validation.login.required', UserValidator::loginError('', null, false));
        $this->assertSame('validation.login.length', UserValidator::loginError('ab', null, false));
        $this->assertSame('validation.login.length', UserValidator::loginError(str_repeat('a', 51), null, false));
        $this->assertSame('validation.login.format', UserValidator::loginError('иван', null, false));
        $this->assertNull(UserValidator::loginError('a.b-c_1', null, false));
    }

    public function testLoginUniqueCaseInsensitiveAndSelfExcluded(): void
    {
        $this->freshDatabase();
        $id = User::create('Тест', 'owner', 'Owner-pass-2026', 'owner');
        $this->assertSame('validation.login.taken', UserValidator::loginError('owner'));
        $this->assertSame('validation.login.taken', UserValidator::loginError(UserValidator::cleanLogin('OWNER')));
        $this->assertNull(UserValidator::loginError('owner', $id));
    }

    public function testPassword(): void
    {
        $this->assertSame('validation.password.required', UserValidator::passwordError(''));
        $this->assertSame('validation.password.min', UserValidator::passwordError('1234567'));
        $this->assertSame('validation.password.max', UserValidator::passwordError(str_repeat('a', 65)));
        $this->assertSame('validation.password.max', UserValidator::passwordError(str_repeat('я', 40)), 'более 72 байт');
        $this->assertNull(UserValidator::passwordError(str_repeat('a', 64)));
        $this->assertNull(UserValidator::passwordError(' пробелы внутри '));
    }

    public function testRole(): void
    {
        $this->assertNull(UserValidator::roleError('owner'));
        $this->assertNull(UserValidator::roleError('editor'));
        $this->assertSame('validation.role.invalid', UserValidator::roleError('admin'));
        $this->assertSame('validation.role.invalid', UserValidator::roleError(null));
    }
}

<?php

declare(strict_types=1);

use App\Installer\EnvironmentFile;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * P00-05: the installer's .env writer (INSTALLER.md § 4–5): created from
 * .env.example, mode 0600, values quoted and escaped, duplicates removed,
 * written atomically, values never echoed in errors.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/goproperty-env-'.Str::random(10);
    File::ensureDirectoryExists($this->dir);
    file_put_contents($this->dir.'/.env.example', "APP_NAME=GoProperty\nAPP_KEY=\n\n# Database\nDB_HOST=mysql\nDB_PASSWORD=password\nMAIL_FROM_NAME=\"\${APP_NAME}\"\n");
    $this->env = new EnvironmentFile($this->dir.'/.env', $this->dir.'/.env.example');
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('creates .env from .env.example with mode 0600', function () {
    expect($this->env->ensureExists())->toBeTrue()
        ->and(file_get_contents($this->dir.'/.env'))->toBe(file_get_contents($this->dir.'/.env.example'))
        ->and(substr(sprintf('%o', fileperms($this->dir.'/.env')), -4))->toBe('0600')
        ->and($this->env->ensureExists())->toBeFalse();
});

it('replaces keys in place, keeps comments and appends new keys', function () {
    $this->env->set(['DB_HOST' => 'localhost', 'APP_URL' => 'https://goproperty.ng']);

    expect(file_get_contents($this->dir.'/.env'))->toBe(
        "APP_NAME=GoProperty\nAPP_KEY=\n\n# Database\nDB_HOST=localhost\nDB_PASSWORD=password\nMAIL_FROM_NAME=\"\${APP_NAME}\"\nAPP_URL=https://goproperty.ng\n"
    );
});

it('removes duplicate lines of a key it sets', function () {
    file_put_contents($this->dir.'/.env', "DB_HOST=one\nAPP_KEY=\nDB_HOST=two\nexport DB_HOST=three\n");

    $this->env->set(['DB_HOST' => 'localhost']);

    expect(file_get_contents($this->dir.'/.env'))->toBe("DB_HOST=localhost\nAPP_KEY=\n");
});

it('writes values that read back exactly', function (string $value) {
    $this->env->set(['DB_PASSWORD' => $value]);

    expect(Dotenv::parse((string) file_get_contents($this->dir.'/.env'))['DB_PASSWORD'])->toBe($value)
        ->and($this->env->get('DB_PASSWORD'))->toBe($value);
})->with([
    'plain' => ['simple-Pass_1.2'],
    'empty' => [''],
    'spaces' => ['correct horse battery staple'],
    'hash' => ['pa#ss'],
    'double quotes' => ['say "hi"'],
    'single quote' => ["it's"],
    'dollar' => ['pa$$word ${APP_KEY}'],
    'backslash' => ['C:\\path\\n'],
    'equals and semicolon' => ['a=b;c'],
    'unicode' => ['ẹ̀kọ́-₦'],
]);

it('writes booleans, numbers and null', function () {
    $this->env->set(['APP_DEBUG' => false, 'DB_PORT' => 3306, 'MAIL_PASSWORD' => null]);

    $values = Dotenv::parse((string) file_get_contents($this->dir.'/.env'));

    expect($values)->toMatchArray(['APP_DEBUG' => 'false', 'DB_PORT' => '3306', 'MAIL_PASSWORD' => '']);
});

it('refuses control characters without echoing the value', function (string $value) {
    try {
        $this->env->set(['DB_PASSWORD' => $value]);
        $this->fail('The value was accepted.');
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toBe('The value for DB_PASSWORD must not contain line breaks or control characters.')
            ->not->toContain('secret');
    }

    expect($this->env->exists())->toBeFalse();
})->with([
    'new line' => ["secret\nAPP_KEY=base64:forged"],
    'carriage return' => ["secret\rx"],
    'null byte' => ["secret\0"],
]);

it('refuses invalid setting names', function (string $key) {
    expect(fn () => $this->env->set([$key => 'x']))->toThrow(InvalidArgumentException::class, 'is not a valid setting name.')
        ->and($this->env->exists())->toBeFalse();
})->with(['db_host', "APP_KEY\n", 'APP KEY', '1APP']);

it('leaves no temporary files behind', function () {
    $this->env->set(['DB_HOST' => 'localhost']);
    $this->env->set(['DB_HOST' => 'db.internal']);

    expect(array_values(array_diff(scandir($this->dir), ['.', '..'])))->toBe(['.env', '.env.example']);
});

it('keeps mode 0600 when rewriting', function () {
    $this->env->set(['DB_HOST' => 'localhost']);
    chmod($this->dir.'/.env', 0644);
    $this->env->set(['DB_HOST' => 'db.internal']);

    expect(substr(sprintf('%o', fileperms($this->dir.'/.env')), -4))->toBe('0600');
});

<?php

use Behat\Behat\Context\Context;
use Behat\Hook\BeforeScenario;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\When;
use Behat\Step\Then;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Container\Container;
use Symfony\Component\HttpFoundation\Response;

class FeatureContext implements Context
{
    private ?Application $app = null;
    private ?HttpKernel $kernel = null;
    private ?Response $response = null;

    private array $formData = [];
    private array $cookies = [];
    private ?string $csrfToken = null;

    #[BeforeScenario]
    public function beforeScenario(): void
    {
        // Set lingkungan khusus pengujian sebelum Laravel boot.
        $environment = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'SESSION_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BCRYPT_ROUNDS' => '4',
        ];

        foreach ($environment as $key => $value) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        // Bootstrap Laravel tanpa PHPUnit TestCase.
        $backendPath = dirname(__DIR__, 2);

        $this->app = require $backendPath . '/bootstrap/app.php';

        $this->kernel = $this->app->make(
            HttpKernel::class
        );

        $this->kernel->bootstrap();

        // Pemeriksaan keselamatan SEBELUM migration.
        $database = $this->app->make('config');

        if (
            $database->get('database.default') !== 'sqlite'
            || $database->get(
                'database.connections.sqlite.database'
            ) !== ':memory:'
        ) {
            throw new RuntimeException(
                'BDD dibatalkan: database harus SQLite :memory:.'
            );
        }

        // Migration hanya pada database in-memory.
        $exitCode = $this->app
            ->make(ConsoleKernel::class)
            ->call('migrate:fresh', [
                '--force' => true,
            ]);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                'Migration BDD gagal.'
            );
        }

        $this->formData = [];
        $this->cookies = [];
        $this->csrfToken = null;
        $this->response = null;
    }

    #[AfterScenario]
    public function afterScenario(): void
    {
        // Bersihkan referensi aplikasi dan Facade.
        // Tidak memanggil PHPUnit tearDown().
        $this->kernel = null;

        if ($this->app !== null) {
            $this->app->flush();
            $this->app = null;
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        $this->response = null;
        $this->formData = [];
        $this->cookies = [];
        $this->csrfToken = null;
    }

    /**
     * Mengirim request melalui HTTP Kernel Laravel.
     */
    private function sendRequest(
        string $method,
        string $url,
        array $data = []
    ): Response {
        if ($this->kernel === null) {
            throw new RuntimeException(
                'Laravel belum diinisialisasi.'
            );
        }

        $request = Request::create(
            $url,
            $method,
            $data,
            $this->cookies
        );

        $response = $this->kernel->handle($request);

        $this->kernel->terminate($request, $response);

        // Simpan cookie untuk request berikutnya.
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] =
                $cookie->getValue();
        }

        $this->response = $response;

        return $response;
    }

    #[Given('I am on :path')]
    public function iAmOn(string $path): void
    {
        $this->formData = [];

        $response = $this->sendRequest('GET', $path);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(
                'Halaman gagal dibuka. HTTP '
                . $response->getStatusCode()
            );
        }

        // Ambil CSRF token asli dari form Laravel.
        $html = (string) $response->getContent();

        if (
            preg_match(
                '/name="_token"\s+value="([^"]+)"/',
                $html,
                $matches
            ) === 1
        ) {
            $this->csrfToken = $matches[1];
        }
    }

    #[When('I fill in :field with :value')]
    public function iFillIn(
        string $field,
        string $value
    ): void {
        $this->formData[$field] = $value;
    }

    #[When('I press :label')]
    public function iPress(string $label): void
    {
        if ($label !== 'Daftar Akun') {
            throw new RuntimeException(
                "Tombol tidak dikenali: {$label}"
            );
        }

        if ($this->csrfToken === null) {
            throw new RuntimeException(
                'CSRF token formulir tidak ditemukan.'
            );
        }

        $payload = array_merge(
            $this->formData,
            ['_token' => $this->csrfToken]
        );

        $response = $this->sendRequest(
            'POST',
            '/register',
            $payload
        );

        // Ikuti redirect setelah submit.
        if (in_array(
            $response->getStatusCode(),
            [301, 302, 303],
            true
        )) {
            $location = $response->headers->get('Location');

            if ($location === null) {
                throw new RuntimeException(
                    'Redirect tidak memiliki lokasi tujuan.'
                );
            }

            $this->sendRequest('GET', $location);
        }
    }

    #[Then('I should see :text')]
    public function iShouldSee(string $text): void
    {
        if ($this->response === null) {
            throw new RuntimeException(
                'Belum ada respons aplikasi.'
            );
        }

        $html = (string) $this->response->getContent();

        if (!str_contains($html, $text)) {
            throw new RuntimeException(
                "Teks tidak ditemukan: {$text}"
            );
        }
    }

    #[Then('the new account should have role :role')]
    public function newAccountRole(string $role): void
    {
        $email = strtolower(
            $this->formData['email'] ?? ''
        );

        $user = $this->app
            ->make('db')
            ->table('users')
            ->where('email', $email)
            ->first();

        if ($user === null) {
            throw new RuntimeException(
                'Akun tidak ditemukan pada database BDD.'
            );
        }

        if ($user->role !== $role) {
            throw new RuntimeException(
                "Role tidak sesuai: {$user->role}"
            );
        }
    }
    
    // ===== REGISTER: SKENARIO TAMBAHAN =====

    #[Given('I have an existing account with email :email')]
    public function existingAccount(string $email): void
    {
        $this->app->make('db')->table('users')->insert([
            'name' => 'Existing Reporter',
            'email' => strtolower($email),
            'password' => password_hash('Password123!', PASSWORD_BCRYPT),
            'role' => 'REPORTER',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Then('I should not see :text')]
    public function iShouldNotSee(string $text): void
    {
        if ($this->response === null) {
            throw new RuntimeException('Respons belum tersedia.');
        }

        if (str_contains((string) $this->response->getContent(), $text)) {
            throw new RuntimeException("Teks tidak seharusnya muncul: {$text}");
        }
    }

    #[When('I submit a registration request with a password shorter than 8 characters')]
    public function submitShortPassword(): void
    {
        $this->formData = [
            'name' => 'Reporter BDD',
            'email' => 'short.bdd@example.com',
            'password' => '1234567',
            'password_confirmation' => '1234567',
        ];

        $this->iPress('Daftar Akun');
    }

    #[Then('I should receive a password validation error')]
    public function passwordValidationError(): void
    {
        $this->iShouldSee(
            'The password field must be at least 8 characters.'
        );
    }

    #[When('I submit a registration request without required fields')]
    public function submitEmptyRegistration(): void
    {
        $this->formData = [];
        $this->iPress('Daftar Akun');
    }

    #[Then('I should receive validation errors for "name", "email" and "password"')]
    public function requiredFieldsErrors(): void
    {
        foreach ([
            'The name field is required.',
            'The email field is required.',
            'The password field is required.',
        ] as $message) {
            $this->iShouldSee($message);
        }
    }

    #[Then('I should not have a new user account created')]
    public function noNewUserCreated(): void
    {
        $count = $this->app->make('db')->table('users')->count();

        if ($count !== 0) {
            throw new RuntimeException(
                "Tidak boleh ada akun baru. Ditemukan: {$count}"
            );
        }
    }

    #[When('I submit valid registration data with role :role')]
    public function submitRegistrationWithRole(string $role): void
    {
        $this->formData = [
            'name' => 'Reporter BDD',
            'email' => 'role-injection.bdd@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => $role,
        ];

        $this->iPress('Daftar Akun');
    }

    #[Then('I should have a new account with role :role')]
    public function accountHasRole(string $role): void
    {
        $this->newAccountRole($role);
    }

    #[Then('I should not have role :role')]
    public function accountDoesNotHaveRole(string $role): void
    {
        $email = strtolower($this->formData['email'] ?? '');

        $user = $this->app->make('db')->table('users')
            ->where('email', $email)
            ->first();

        if ($user === null) {
            throw new RuntimeException('Akun tidak ditemukan.');
        }

        if ($user->role === $role) {
            throw new RuntimeException(
                "Akun tidak boleh memiliki role {$role}."
            );
        }
    }
}

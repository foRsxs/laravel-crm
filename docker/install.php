<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Webkul\Installer\Database\Seeders\DatabaseSeeder;
use Webkul\Installer\Helpers\DatabaseManager;
use Webkul\User\Repositories\UserRepository;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Bootstrap only a new database. Never reset an existing CRM or regenerate its key.
if (Schema::getTableListing() !== []) {
    fwrite(STDERR, "Installation requires an empty database; use migrations for updates.\n");
    exit(1);
}

$email = getenv('ADMIN_EMAIL') ?: '';
$password = getenv('ADMIN_PASSWORD') ?: '';
$key = config('app.key') ?? '';
if (str_starts_with($key, 'base64:')) {
    $key = base64_decode(substr($key, 7), true);
}
if (! is_string($key) || ! Encrypter::supported($key, config('app.cipher'))
    || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "Set APP_KEY, valid ADMIN_EMAIL and ADMIN_PASSWORD of at least 12 characters.\n");
    exit(1);
}

if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(1);
}

$manager = app(DatabaseManager::class);
$manager->markInstallationInProgress();
DB::transaction(function () use ($email, $password) {
    app(DatabaseSeeder::class)->run(['locale' => config('app.locale'), 'currency' => config('app.currency')]);
    app(UserRepository::class)->update([
        'name' => getenv('ADMIN_NAME') ?: 'Administrator',
        'email' => $email,
        'password' => Hash::make($password),
    ], 1);
});
$manager->markInstallationCompleted();
if (! $manager->isInstallationCompleted()) {
    throw new RuntimeException('Could not record installation completion.');
}
file_put_contents(storage_path('installed'), 'Krayin is successfully installed');
$manager->clearInstallationInProgress();
fwrite(STDOUT, "CRM installed. Sign in with the administrator credentials you supplied.\n");

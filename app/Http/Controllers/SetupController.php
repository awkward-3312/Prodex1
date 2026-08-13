<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TestDbController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\ClientRepository;

class SetupController extends Controller
{

    public function changeEnv($data = array())
    {
        if (count($data) > 0) {

            // Read .env-file
            $env = file_get_contents(base_path() . '/.env');

            // Split string on every " " and write into array
            $env = preg_split('/(\r\n|\n|\r)/', $env);

            // Loop through given data
            foreach ((array) $data as $key => $value) {

                // Loop through .env-data
                foreach ($env as $env_key => $env_value) {

                    // Turn the value into an array and stop after the first split
                    // So it's not possible to split e.g. the App-Key by accident
                    $entry = explode("=", $env_value, 2);

                    // Check, if new key fits the actual .env-key
                    if ($entry[0] == $key) {
                        // If yes, overwrite it with the new one
                        if ($value !== null) {

                            $env[$env_key] = $key . "=" . $value;
                        }
                    } else {
                        // If not, keep the old one
                        $env[$env_key] = $env_value;
                    }
                }
            }

            // Turn the array back to an String
            $env = implode("\n", $env);

            // And overwrite the .env with the new data
            file_put_contents(base_path() . '/.env', $env);

            return true;
        } else {
            return false;
        }
    }


    public function viewStep1()
    {

        $data = array(
            "APP_NAME" => session('env.APP_NAME') ? str_replace('"', '', session('env.APP_NAME')) : str_replace('"', '', config('app.name')),
            "APP_ENV" => session('env.APP_ENV') ? session('env.APP_ENV') : config('app.env'),
            "APP_DEBUG" => session('env.APP_DEBUG') ? session('env.APP_DEBUG') : config('app.debug'),
            "APP_KEY" => session('env.APP_KEY') ? session('env.APP_KEY') : config('app.key'),
        );

        return view('setup.step1', compact('data'));
    }

    public function viewCheck()
    {
        return view('setup.check');
    }

    public function viewStep2()
    {
        $connection = session('env.DB_CONNECTION') ?: config('database.default');
        $db = config("database.connections.{$connection}", []);

        $data = array(
            "DB_CONNECTION" => session('env.DB_CONNECTION') ? session('env.DB_CONNECTION') : config("database.default"),
            "DB_HOST" => session('env.DB_HOST') ?: ($db['host'] ?? ''),
            "DB_PORT" => session('env.DB_PORT') ?: ($db['port'] ?? ''),
            "DB_DATABASE" => session('env.DB_DATABASE') ?: ($db['database'] ?? ''),
            "DB_USERNAME" => session('env.DB_USERNAME') ?: ($db['username'] ?? ''),
            "DB_PASSWORD" => session('env.DB_PASSWORD') ? str_replace('"', '', session('env.DB_PASSWORD')) : str_replace('"', '', (string) ($db['password'] ?? '')),
        );

        return view('setup.step2', ["data" => $data]);
    }

    public function viewStep3()
    {
        $dbtype = session('env.DB_CONNECTION') ?: config('database.default');
        $db = config("database.connections.{$dbtype}", []);
        $dbDatabase = session('env.DB_DATABASE');

        $data = array(
            "APP_NAME" => str_replace('"', '', session('env.APP_NAME')) == str_replace('"', '', config('app.name')) ? 'old' : str_replace('"', '', session('env.APP_NAME')),
            "APP_ENV" => session('env.APP_ENV') == config('app.env') ? 'old' : session('env.APP_ENV'),
            "APP_DEBUG" => session('env.APP_DEBUG') == config('app.debug') ? 'old' : session('env.APP_DEBUG'),
            "APP_KEY" => session('env.APP_KEY') == config('app.key') ? 'old' : session('env.APP_KEY'),
            "DB_CONNECTION" => session('env.DB_CONNECTION') == config("database.default") ? 'old' : session('env.DB_CONNECTION'),
            "DB_HOST" => session('env.DB_HOST') == ($db['host'] ?? '') ? 'old' : session('env.DB_HOST'),
            "DB_PORT" => session('env.DB_PORT') == ($db['port'] ?? '') ? 'old' : session('env.DB_PORT'),
            "DB_DATABASE" => $dbDatabase == ($db['database'] ?? '') ? 'old' : session('env.DB_DATABASE'),
            "DB_USERNAME" => session('env.DB_USERNAME') == ($db['username'] ?? '') ? 'old' : session('env.DB_USERNAME'),
            "DB_PASSWORD" => str_replace('"', '', session('env.DB_PASSWORD') ?? '') == str_replace('"', '', (string) ($db['password'] ?? '')) ? 'old' : str_replace('"', '', session('env.DB_PASSWORD') ?? ''),

        );

        $count = 0;

        foreach ($data as $mydata) {

            $mydata !== 'old' ? $count++ : false;
        }

        $view = view('setup.step3', compact('data'));

        return $view;
    }

    public function lastStep(Request $request)
    {
        ini_set('max_execution_time', 2000);
        ini_set('memory_limit', '512M');

        try {
            $this->changeEnv([
                'APP_NAME' => session('env.APP_NAME'),
                'APP_ENV' => session('env.APP_ENV'),
                'APP_KEY' => session('env.APP_KEY'),
                'APP_DEBUG' => session('env.APP_DEBUG'),
                'APP_URL' => session('env.APP_URL') ?: $request->getSchemeAndHttpHost(),
                'LOG_CHANNEL' => session('env.LOG_CHANNEL') ?: config('logging.default') ?: 'stack',

                'DB_CONNECTION' => session('env.DB_CONNECTION'),
                'DB_HOST' => session('env.DB_HOST'),
                'DB_PORT' => session('env.DB_PORT'),
                'DB_DATABASE' => session('env.DB_DATABASE'),
                'DB_USERNAME' => session('env.DB_USERNAME'),
                'DB_PASSWORD' => session('env.DB_PASSWORD'),
            ]);

            Artisan::call('config:clear');

            // The app booted with the OLD .env, so config() and the live DB
            // connection still point at the pre-setup database. config:clear only
            // wipes the on-disk cache — it does NOT refresh the running process.
            // Push the just-written credentials into the runtime config and
            // reconnect, otherwise migrate:fresh runs against the stale connection
            // (tables land in the wrong DB while setup still reports success).
            $connection = session('env.DB_CONNECTION') ?: config('database.default');

            config([
                "database.connections.{$connection}.host"     => session('env.DB_HOST'),
                "database.connections.{$connection}.port"     => session('env.DB_PORT'),
                "database.connections.{$connection}.database" => session('env.DB_DATABASE'),
                "database.connections.{$connection}.username" => session('env.DB_USERNAME'),
                "database.connections.{$connection}.password" => str_replace('"', '', session('env.DB_PASSWORD') ?? ''),
                'database.default'                            => $connection,
            ]);

            DB::purge($connection);
            DB::reconnect($connection);

            // Central DB schema only — no tenant seeding
            $migrateExit = Artisan::call('migrate:fresh', [
                '--force' => true,
            ]);

            if ($migrateExit !== 0) {
                throw new \RuntimeException(
                    'Migration failed (exit ' . $migrateExit . '): ' . trim(Artisan::output())
                );
            }

            // Remove leftover tenant storage from previous installations
            $tenantsPath = storage_path('tenants');
            if (is_dir($tenantsPath)) {
                $items = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tenantsPath, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($items as $item) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
            }

            // Seed super admin + subscription plans
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Central\\CentralUsersSeeder',
                '--force' => true,
            ]);
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Central\\PlansSeeder',
                '--force' => true,
            ]);
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Central\\LandingPageSeeder',
                '--force' => true,
            ]);
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Central\\CmsTranslationSeeder',
                '--force' => true,
            ]);
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Central\\EmailTemplatesSeeder',
                '--force' => true,
            ]);
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Central\\CentralLanguagesSeeder',
                '--force' => true,
            ]);

            // Passport keys + OAuth clients (tables already created by migrations above)
            Artisan::call('passport:keys', [
                '--force' => true,
            ]);

            $clientRepository = app(ClientRepository::class);
            $appUrl = config('app.url') ?: 'http://localhost';

            $clientRepository->createPersonalAccessClient(
                null,
                'Laravel Personal Access Client',
                $appUrl
            );

            $clientRepository->createPasswordGrantClient(
                null,
                'Laravel Password Grant Client',
                $appUrl
            );

            // Only mark the app installed once the schema is verifiably present.
            // Guards against a silently misdirected migration locking the wizard.
            if (!Schema::connection($connection)->hasTable('migrations')) {
                throw new \RuntimeException('Setup ran but the expected schema is missing; not marking as installed.');
            }

            Storage::disk('public')->put('installed', 'OK');

        } catch (\Throwable $e) {
            report($e);

            return redirect('/setup')
                ->withInput()
                ->with('error', 'Setup failed: ' . $e->getMessage());
        }

        $envContent = @file_get_contents(base_path() . '/.env') ?: '';
        preg_match('/^APP_SUPER_ADMIN_EMAIL=(.*)$/m', $envContent, $emailMatch);
        preg_match('/^APP_SUPER_ADMIN_PASSWORD=(.*)$/m', $envContent, $passwordMatch);
        $superAdminEmail = isset($emailMatch[1]) ? trim($emailMatch[1], " \t\"'") : 'superadmin@stockysaas.site';
        $superAdminPassword = isset($passwordMatch[1]) ? trim($passwordMatch[1], " \t\"'") : '123456';

        $appUrl = rtrim(session('env.APP_URL') ?: $request->getSchemeAndHttpHost(), '/');
        $superAdminLoginUrl = $appUrl . '/super/login';

        return view('setup.finishedSetup', compact(
            'superAdminLoginUrl',
            'superAdminEmail',
            'superAdminPassword'
        ));
    }

    public function getNewAppKey()
    {

        Artisan::call('key:generate', ['--show' => true]);
        $output = (Artisan::output());
        $output = substr($output, 0, -2);
        return $output;
    }

    public function setupStep1(Request $request)
    {
        $request->session()->put('env.APP_ENV', $request->app_env);
        $request->session()->put('env.APP_DEBUG', $request->app_debug);

        if (strlen($request->app_name) > 0) {
            $request->session()->put('env.APP_NAME', '"' . $request->app_name . '"');
        }

        if (strlen($request->app_key) > 0) {
            $request->session()->put('env.APP_KEY', $request->app_key);
        }

        if (strlen($request->app_url) > 0) {
            $request->session()->put('env.APP_URL', rtrim($request->app_url, '/'));
        }

        return $this->viewStep2();
    }

    public function setupStep2(Request $request)
    {

        if (strlen($request->db_password) > 0) {
            $request->session()->put('env.DB_PASSWORD', '"' . $request->db_password . '"');
        }
        $request->session()->put('env.DB_CONNECTION', $request->db_connection);
        $request->session()->put('env.DB_HOST', $request->db_host);
        $request->session()->put('env.DB_PORT', $request->db_port);
        $request->session()->put('env.DB_DATABASE', $request->db_database);
        $request->session()->put('env.DB_USERNAME', $request->db_username);

        if ($request->db_connection == 'sqlite') {
            TestDbController::testSqLite();
        }

        return $this->viewStep3();
    }

    /**
     * Optional step 4 handler (form may post here in some flows). Advances to summary.
     */
    public function setupStep3(Request $request)
    {
        return $this->viewStep3();
    }

    /**
     * Optional step 5 handler (form may post here in some flows). Advances to summary.
     */
    public function setupStep4(Request $request)
    {
        return $this->viewStep3();
    }
}

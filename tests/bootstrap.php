<?php

/**
 * Bootstrap de PHPUnit.
 *
 * Varios tests (Passport, webhooks, servicios que resuelven el resource server) asumen dos archivos de runtime que
 * git no versiona: las llaves OAuth de Passport y el marcador "installed". En un clon limpio (o en CI) no existen y
 * ~19 tests fallaban con "Invalid key supplied" / redirect a /setup, aunque el código estuviera bien.
 * Aquí se crean si faltan, solo bajo storage/ (ignorado por git) y nunca se sobrescriben los existentes.
 */
require __DIR__.'/../vendor/autoload.php';

$storage = dirname(__DIR__).'/storage';

if (! is_file($storage.'/oauth-private.key') || ! is_file($storage.'/oauth-public.key')) {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($key !== false) {
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];
        @mkdir($storage, 0775, true);
        file_put_contents($storage.'/oauth-private.key', $private);
        file_put_contents($storage.'/oauth-public.key', $public);
        @chmod($storage.'/oauth-private.key', 0600);
        @chmod($storage.'/oauth-public.key', 0600);
    }
}

$installedDir = $storage.'/app/public';
if (! is_file($installedDir.'/installed')) {
    @mkdir($installedDir, 0775, true);
    @touch($installedDir.'/installed');
}

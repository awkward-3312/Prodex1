<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\CloudBackupUploader;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class BackupController extends Controller
{
    public function Get_Backup(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'backup', User::class);

        $data = [];
        $id = 0;
        foreach (glob(storage_path().'/app/public/backup/*') as $filename) {
            $item['id'] = $id += 1;
            $item['date'] = basename($filename);
            $item['size'] = $this->formatSizeUnits(filesize($filename));
            $data[] = $item;
        }

        return response()->json([
            'backups' => $data,
            'totalRows' => count($data),
        ]);
    }

    public function Generate_Backup(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'backup', User::class);

        $exitCode = Artisan::call('database:backup');
        $output = Artisan::output();

        if ($exitCode !== 0) {
            $errorMsg = trim($output);

            if (preg_match('/ERROR_DETAILS:\s*(.+)/s', $output, $matches)) {
                $errorMsg = trim($matches[1]);
            }

            if (empty($errorMsg) || strlen($errorMsg) < 10) {
                $errorMsg = 'Falló el comando de copia de seguridad de la base de datos. Causas comunes:'."\n";
                $errorMsg .= '1. DUMP_PATH en .env es incorrecto o no se encontró mysqldump'."\n";
                $errorMsg .= '2. Las credenciales de base de datos (DB_USERNAME, DB_PASSWORD, DB_HOST) son incorrectas'."\n";
                $errorMsg .= '3. El servidor MySQL no está en ejecución'."\n";
                $errorMsg .= '4. El usuario de la base de datos no tiene permisos para realizar copias de seguridad'."\n";
                $errorMsg .= '5. El nombre de la base de datos (DB_DATABASE) es incorrecto';
            }

            return response()->json([
                'success' => false,
                'message' => 'No se pudo generar la copia de seguridad',
                'error' => $errorMsg,
                'cloud' => null,
            ], 500);
        }

        usleep(500000);

        $cloud = null;
        try {
            $dir = storage_path().'/app/public/backup';

            if (!is_dir($dir)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El directorio de copias de seguridad no existe',
                    'error' => 'No se encontró el directorio de copias de seguridad: '.$dir,
                    'cloud' => null,
                ], 500);
            }

            $latest = null;
            $latestMtime = 0;
            $files = glob($dir.'/*.sql');

            if (empty($files)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró el archivo de copia de seguridad después de generarlo',
                    'error' => 'El comando terminó, pero no se creó ningún archivo .sql. Revisa las credenciales de la base de datos y la ruta de mysqldump.',
                    'cloud' => null,
                ], 500);
            }

            foreach ($files as $filename) {
                if (!is_file($filename)) continue;
                $mt = @filemtime($filename) ?: 0;
                if ($mt >= $latestMtime) {
                    $latestMtime = $mt;
                    $latest = $filename;
                }
            }

            if ($latest && file_exists($latest)) {
                if (filesize($latest) === 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El archivo de copia de seguridad está vacío',
                        'error' => 'El archivo fue creado, pero está vacío (0 bytes). Revisa las credenciales de la base de datos y la ruta de mysqldump.',
                        'cloud' => null,
                    ], 500);
                }

                $setting = Setting::whereNull('deleted_at')->first();
                $uploader = new CloudBackupUploader();
                $cloud = $uploader->uploadIfConfigured($latest, basename($latest), $setting);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró el archivo de copia de seguridad después de generarlo',
                    'error' => 'El comando terminó, pero no se encontró un archivo de copia de seguridad válido.',
                    'cloud' => null,
                ], 500);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al generar la copia de seguridad',
                'error' => $e->getMessage(),
                'cloud' => null,
            ], 500);
        }

        $message = 'Copia de seguridad generada correctamente';
        if ($cloud && isset($cloud['success']) && $cloud['success']) {
            $message .= ' y subida a '.ucfirst($cloud['provider']);
        } elseif ($cloud && isset($cloud['error'])) {
            $message .= ' (falló la carga a la nube: '.$cloud['error'].')';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'cloud' => $cloud,
        ]);
    }

    public function Delete_Backup(Request $request, $name)
    {
        $this->authorizeForUser($request->user('api'), 'backup', User::class);

        foreach (glob(storage_path().'/app/public/backup/*') as $filename) {
            $path = storage_path().'/app/public/backup/'.basename($name);
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    public function formatSizeUnits($bytes)
    {
        if ($bytes >= 1073741824) {
            $bytes = number_format($bytes / 1073741824, 2).' GB';
        } elseif ($bytes >= 1048576) {
            $bytes = number_format($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            $bytes = number_format($bytes / 1024, 2).' KB';
        } elseif ($bytes > 1) {
            $bytes = $bytes.' bytes';
        } elseif ($bytes == 1) {
            $bytes = $bytes.' byte';
        } else {
            $bytes = '0 bytes';
        }

        return $bytes;
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Nwidart\Modules\Facades\Module;

class ModuleSettingsController extends Controller
{
    public function get_modules_info()
    {
        $modules = Module::all();
        $result = [];
        foreach ($modules as $name => $module) {
            $moduleJson = json_decode(File::get($module->getPath() . '/module.json'), true);
            $result[] = ['module_name' => $name, 'current_version' => $moduleJson['version'] ?? '1.0.0', 'status' => $module->isEnabled()];
        }
        return response()->json($result);
    }

    public function update_status_module(Request $request)
    {
        $request->validate(['name' => 'required|string', 'status' => 'required']);
        $module = Module::find($request->name);
        if (!$module) return response()->json(['message' => 'Módulo no encontrado'], 404);
        $request->status ? $module->enable() : $module->disable();
        return response()->json(['message' => 'Estado del módulo actualizado']);
    }

    public function upload_module(Request $request)
    {
        $request->validate(['module_zip' => 'required|file|mimes:zip']);
        $file = $request->file('module_zip');
        $modulesPath = base_path('Modules');
        if (!File::isDirectory($modulesPath)) File::makeDirectory($modulesPath, 0755, true);
        $tmpPath = storage_path('app/tmp_module_' . time());
        File::makeDirectory($tmpPath, 0755, true);
        $zip = new \ZipArchive;
        if ($zip->open($file->getRealPath()) === true) { $zip->extractTo($tmpPath); $zip->close(); }
        else { File::deleteDirectory($tmpPath); return response()->json(['message' => 'No se pudo extraer el archivo ZIP'], 422); }
        $extracted = File::directories($tmpPath);
        $sourceDir = count($extracted) === 1 ? $extracted[0] : $tmpPath;
        if (!File::exists($sourceDir . '/module.json')) {
            if (File::exists($tmpPath . '/module.json')) $sourceDir = $tmpPath;
            else { File::deleteDirectory($tmpPath); return response()->json(['message' => 'Módulo no válido: no se encontró module.json'], 422); }
        }
        $moduleJson = json_decode(File::get($sourceDir . '/module.json'), true);
        $moduleName = $moduleJson['name'] ?? null;
        if (!$moduleName) { File::deleteDirectory($tmpPath); return response()->json(['message' => 'El archivo module.json no es válido'], 422); }
        $targetPath = $modulesPath . '/' . $moduleName;
        if (File::isDirectory($targetPath)) File::deleteDirectory($targetPath);
        File::moveDirectory($sourceDir, $targetPath);
        File::deleteDirectory($tmpPath);
        $module = Module::find($moduleName);
        if ($module) $module->enable();
        return response()->json(['message' => 'Módulo cargado correctamente']);
    }
}

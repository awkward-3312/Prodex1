<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;

class DatabaseBackUp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'database:backup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $backupDir = storage_path().'/app/public/backup';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }

        foreach (glob($backupDir.'/*') as $filename) {
            $path = $backupDir.'/'.basename($filename);
            @unlink($path);
        }

        $conn = config('database.default');
        $dumpPath = config("database.connections.$conn.dump_path", 'mysqldump');
        $db_user  = config("database.connections.$conn.username");
        $db_pass  = config("database.connections.$conn.password");
        $db_host  = config("database.connections.$conn.host");
        $db_name  = config("database.connections.$conn.database");

        $filename = 'backup-'.Carbon::now()->format('Y-m-d').'.sql';
        $outputPath = $backupDir.'/'.$filename;

        if ($db_pass != '') {
            $command = $dumpPath.' --user='.$db_user." --password='".$db_pass."' --host=".$db_host.' '.$db_name.' > '.$outputPath;
        } else {
            $command = $dumpPath.' --user='.$db_user.' --password='.$db_pass.' --host='.$db_host.' '.$db_name.' > '.$outputPath;
        }

        $output = [];
        $returnVar = null;
        \exec($command.' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            $err = 'Exit code: '.$returnVar;
            if (!empty($output)) {
                $err .= "\n".implode("\n", $output);
            } elseif (file_exists($outputPath) && filesize($outputPath) > 0) {
                $err .= "\n".trim(@file_get_contents($outputPath));
            }
            $this->line('ERROR_DETAILS: '.$err);
        }

        return $returnVar ?? 0;
    }
}

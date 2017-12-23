<?php
namespace Concrete\Package\GondBackupFiles\Job;

use \Concrete\Core\Job\Job as AbstractJob;
use Illuminate\Filesystem\Filesystem;

class GondBackupDatabase extends AbstractJob
{
    private $backupDirectory;

    public function getJobName()
    {
        return t("Backup Database");
    }

    public function getJobDescription()
    {
        return t("Lets you download a copy of your site's database (but not your site's other files).");
    }

    public function run()
    {
        ini_set("max_execution_time", 3600);    // this might fail and return false, but we'll try our luck anyway

        // Determine filenames and paths:
        $cwd = getcwd();
        if ($cwd !== false && $cwd !== '/') {
            $backupFilenamePrefix = basename($cwd);
        } else {
            $backupFilenamePrefix = 'site';
        }
        $sqlFilename = $backupFilenamePrefix . ' database.sql';
        $filesystem = new Filesystem();
        $pathUtilities = new \Concrete\Package\GondBackupFiles\Src\Job\PathUtilities();
        $this->backupDirectory = $pathUtilities->getBackupDirectory($filesystem);
        $sqlFilepath = $this->backupDirectory . $sqlFilename;

        // Get concrete5 database settings:
        $app = \Concrete\Core\Support\Facade\Application::getFacadeApplication();
        $config = $app->make('config');
        $server = $config['database.connections.concrete.server'];
        $databaseName = $config['database.connections.concrete.database'];
        $username = $config['database.connections.concrete.username'];
        $password = $config['database.connections.concrete.password'];

        // Create mysqldump config file (which is more secure than using command-line args).
        // This is done whenever the job runs just in case the database config changes.
        $configFilepath = $this->backupDirectory . 'gond-backup.cnf';
        $config = <<<EOT
[mysqldump]
host="$server"
user="$username"
password="$password"
default-character-set=utf8
complete-insert
lock-tables
events
routines
EOT;
        $filesystem->put($configFilepath,$config);

        // Find path to mysql bin directory (for mysqldump):
        $db = \Database::connection();
        $mysqlPath = $db->fetchColumn('SELECT @@basedir');
        if ($mysqlPath != '') {     // ensure $mysqlPath ends with '/' (sometimes it does and sometimes it doesn't)
            $lastChar = $mysqlPath[strlen($mysqlPath)-1];
        }
        if ($lastChar != '/') {     // can't use DIRECTORY_SEPARATOR because it's \ on Windows but $mysqlPath contains /
            $mysqlPath .= '/';      // even on Windows, php prefers / rather than DIRECTORY_SEPARATOR
        }

        // Tweak the 'jobs' table to indicate that this job is ENABLED rather than RUNNING.
        // Otherwise, the job will show as RUNNING when the backup database is uploaded, which precludes
        // running the job again without deleting and reinstalling the job.
        $runningJobStatus = $this->getJobStatus();      // save current value to reinstate
        $this->setJobStatus();                          // temporarily force status to 'ENABLED'

        // Run mysqldump to get SQL:
        $command = $mysqlPath .
            "bin/mysqldump --defaults-extra-file=$configFilepath --verbose --log-error=\"mysqldump-log-error.txt\" -r \"$sqlFilepath\" $databaseName > mysqldump-stdout.txt 2> mysqldump-stderr.txt";
        system($command, $retcode);
        if ($retcode != 0) {
            \Log::addEntry("Simple Backup (Backup Database): failed to execute $command (return code: $retcode)");
            throw new \Exception(t('Couldn\'t dump database (') . '<a href="../../reports/logs">' . t('more') . '</a>' .
                t(')'));
        } else {
            $filesystem->delete($configFilepath);
        }

        // Reinstate job status:
        $this->setJobStatus($runningJobStatus);

        // Try to compress the .sql using gzip or zip. gzip is preferred for maximum compatibility with CPanel.
        $downloadFilepath = $this->gzip($filesystem, $sqlFilepath);  // the final file for download
        if ($downloadFilepath == false) {   // couldn't do gzip, so try zip
            $downloadFilepath = $this->zip($filesystem, $sqlFilepath, $sqlFilename);
            if ($downloadFilepath == false) {   // couldn't do zip either, so deliver uncompressed sql
                $downloadFilepath = $sqlFilepath;
            }
        }

        // Announce success:
        return t('Success!') . ' <a href="' . BASE_URL . "/$downloadFilepath\">" . t('Download') .
            '</a>. ' . t('Don\'t forget to backup your other files as well.');
    }

    /**
     * gzip the specified file, then delete it.
     * @param Filesystem $filesystem
     * @param string $sqlFilepath path of .sql file to compress
     * @return string|bool file path of .gz created, or false on failure
     */
    private function gzip($filesystem, $sqlFilepath)
    {
        try {
            $gzFilepath = $sqlFilepath . '.gz';
            $gzFile = gzopen($gzFilepath, 'wb');
            if ($gzFile == false) {
                return false;
            }
            $sqlFile=fopen($sqlFilepath,'rb');
            if ($sqlFile == false) {
                gzclose($gzFile);
                $filesystem->delete($gzFilepath);
                return false;
            }
            while (!feof($sqlFile)) {
                gzwrite($gzFile, fread($sqlFile,1048576));
            }
            fclose($sqlFile);
            gzclose($gzFile);
            $filesystem->delete($sqlFilepath);
            return $gzFilepath;
        } catch (\Exception $e) {
            if ($sqlFile !== null) {
                fclose($sqlFile);
            }
            if ($gzFile !== null) {
                gzclose($gzFile);
                $filesystem->delete($gzFilepath);
            }
            return false;
        }
    }

    /**
     * zip the specified file, then delete it.
     * @param Filesystem $filesystem
     * @param string $sqlFilepath path of .sql file to compress
     * @param string $sqlFilename name (only) of .sql file to compress
     * @return string|bool file path of .zip created, or false on failure
     */
    private function zip($filesystem, $sqlFilepath, $sqlFilename)
    {
        try {
            $zip = new \ZipArchive;
            $zipFilepath = $sqlFilepath . '.zip';
            $retcode = $zip->open($zipFilepath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if ($retcode !== true) {
                $filesystem->delete($zipFilepath);
                return false;
            }
            if ($zip->addFile($sqlFilepath, $sqlFilename) !== true) {
                $zip->close();
                $filesystem->delete($zipFilepath);
                return false;
            }
            $zip->close();
            $filesystem->delete($sqlFilepath);
            return $zipFilepath;
        } catch (\Exception $e) {
            try {
                $zip->close();
            } catch (\Exception $e) {
            }
            $filesystem->delete($zipFilepath);
            return false;
        }
    }
}

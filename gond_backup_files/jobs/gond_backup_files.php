<?php
namespace Concrete\Package\GondBackupFiles\Job;

use \Concrete\Core\Job\Job as AbstractJob;
use Illuminate\Filesystem\Filesystem;

class GondBackupFiles extends AbstractJob
{
    private $backupDirectory;
    private $excludePaths = array(
        'concrete',
        'updates',
        'application/files/cache',
        'application/files/backup'
    );

    public function getJobName()
    {
        return t("Backup Files");
    }

    public function getJobDescription()
    {
        return t("Lets you download a copy of your site's files (but not your site's database).");
    }

    public function run()
    {
        ini_set("max_execution_time", 3600);    // this might fail and return false, but we'll try our luck anyway

        $cwd = getcwd();
        if ($cwd !== FALSE && $cwd !== '/')
            $zipFilenamePrefix = basename($cwd);
        else
            $zipFilenamePrefix = 'site';

        $zipFilename = $zipFilenamePrefix . ' files.zip';
        $filesystem = new Filesystem();
        $pathUtilities = new \Concrete\Package\GondBackupFiles\Src\Job\PathUtilities();
        $this->backupDirectory = $pathUtilities->getBackupDirectory($filesystem);
        $zipFilepath = $this->backupDirectory . $zipFilename;

        // If a previous backup file exists, delete it. This capability could be
        // removed, but doing so would leave an out-of-date backup file on disk
        // if a subsequent run fails. This, in turn, could lead to developers
        // losing their changes if they don’t notice.
        if ($filesystem->exists($zipFilepath)) {
            $filesystem->delete($zipFilepath);
        }

        $zip = new \ZipArchive;
        $ret = $zip->open($zipFilepath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($ret !== TRUE) throw new \Exception(t('Error opening .zip file') . " ($ret)");
        $this->addDir($zip, null);
        $zip->close();

        return t('Success!') . ' <a href="' . BASE_URL . "/$zipFilepath\">" . t('Download') .
            '</a>. ' . t('Don\'t forget to backup your database as well.');
    }

    private function addDir($zip, $path)
    {
        if ($path != null) {
            if ($zip->addEmptyDir($path) !== TRUE) {  // ensures creation of directories even if empty
                throw new \Exception(t('Error adding directory to .zip'));
            }
        }

        if (in_array($path, $this->excludePaths, TRUE)) {
            return;     // skip system and cache directories
        }

        $globPath = $path!=null? $path.'/' : '';
        $nodes = glob($globPath . '*');
        foreach ($nodes as $node) {
            if (is_dir($node)) {
                $this->addDir($zip, $node);
            } else if (is_file($node))  {
                if ($zip->addFile($node) !== TRUE)
                    throw new \Exception(t('Error adding file'));
            }
        }
    }
}

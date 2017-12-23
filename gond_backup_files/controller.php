<?php
namespace Concrete\Package\GondBackupFiles;

use Concrete\Core\Package\Package;
use Illuminate\Filesystem\Filesystem;

defined('C5_EXECUTE') or die("Access Denied.");

class Controller extends Package
{
    protected $pkgHandle = 'gond_backup_files';
    protected $appVersionRequired = '5.7.5';
    protected $pkgVersion = '2.1.1';

    public function getPackageDescription()
    {
        return t('\'Automated jobs\' that let you download copies of your site\'s files and database.');
    }

    public function getPackageName()
    {
        return t('Simple Backup');
    }

    public function install()
    {
        $pkg = parent::install();
        \Concrete\Core\Job\Job::installByPackage('gond_backup_files', $pkg);
        \Concrete\Core\Job\Job::installByPackage('gond_backup_database', $pkg);
    }

    public function upgrade()
    {
        parent::upgrade();

        // 'Backup Database' job was not included in versions prior to 2.0.0; add it if necessary:
        $pkg = Package::getByHandle($this->pkgHandle);
        $dbJob = \Concrete\Core\Job\Job::getByHandle('gond_backup_database');
        if (!is_object($dbJob)) {
            \Concrete\Core\Job\Job::installByPackage('gond_backup_database', $pkg);
        }

        // Rename 'backup' folder to something secure:
        $filesystem = new Filesystem();
        $badDirectory = 'application/files/backup/';
        if ($filesystem->exists($badDirectory)) {
            $pathUtilities = new \Concrete\Package\GondBackupFiles\Src\Job\PathUtilities();
            $backupDirectory = $pathUtilities->getSecureDirectoryPath();
            if ($filesystem->move($badDirectory, $backupDirectory) == false) {
                throw new \Exception(t('Couldn\'t rename backup directory'));
            }
        }
    }
}
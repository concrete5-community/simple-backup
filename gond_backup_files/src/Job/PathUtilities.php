<?php
namespace Concrete\Package\GondBackupFiles\Src\Job;

class PathUtilities
{
    private $filesDirectory = 'application/files/';
    private $directoryPrefix = 'gond_backup_';

    public function getBackupDirectory($filesystem) {
        $directories = $filesystem->directories($this->filesDirectory);
        foreach($directories as $directory) {
            $backupDirectory = strstr($directory, $this->directoryPrefix);
            if ($backupDirectory !== FALSE) {
                break;
            }
        }
        if ($backupDirectory !== FALSE) {     // found a pre-existing directory; use it
            $backupDirectory = $this->filesDirectory . $backupDirectory . '/';
        } else {                                    // didn't find a pre-existing directory; create it
            $backupDirectory = $this->getSecureDirectoryPath();
            if ($filesystem->makeDirectory($backupDirectory) == false) {
                throw new \Exception(t('Couldn\'t create backup directory'));
            }
        }
        return $backupDirectory;
    }

    public function getSecureDirectoryPath() {
        return $this->filesDirectory . $this->directoryPrefix . $this->random_str(12) . '/';
    }

    /**
     * Create a secure password-like string.
     * Adapted from https://stackoverflow.com/questions/6101956/generating-a-random-password-in-php/31284266#31284266
     */
    private function random_str($length)
    {
        $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $max = 61;
        $str = '';
        for ($i = 0; $i < $length; ++$i) {
            $str .= $keyspace[$this->random_int($max)];
        }
        return $str;
    }

    private function random_int($max) {
        do {
            $byte = ord(openssl_random_pseudo_bytes(1)[0]);
        } while ($byte > $max);
        return $byte;
    }
}
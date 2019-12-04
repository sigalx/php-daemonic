<?php

namespace sigalx\Daemonic;

/**
 * Trait LockFileTrait
 * @package sigalx\Daemonic
 * @method string getLockDirectory()
 */
trait LockFileTrait
{
    protected $_hlocks;

    protected function _acquireLock(string $lockName): bool
    {
        $filename = sha1($lockName);
        $this->_hlocks[$lockName] = @fopen("{$this->getLockDirectory()}/{$filename}.lock", 'w+');
        return !empty($this->_locks[$lockName]) && @flock($this->_hlocks[$lockName], LOCK_EX | LOCK_NB);
    }

    protected function _releaseLock(string $lockName): bool
    {
        if (empty($this->_hlocks[$lockName])) {
            return false;
        }
        $hlock = $this->_hlocks[$lockName];
        unset($this->_hlocks[$lockName]);
        return @flock($hlock, LOCK_UN) && @fclose($hlock);
    }
}

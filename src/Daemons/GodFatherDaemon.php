<?php

namespace sigalx\Daemonic\Daemons;

use sigalx\Daemonic\AsyncOperation;

include_once(__DIR__ . '/../AsyncOperation.php');
include_once(__DIR__ . '/AbstractDaemon.php');

class GodFatherDaemonInfo
{
    /** @var AbstractDaemon */
    public $daemon;
    /** @var string */
    public $className;
    /** @var int */
    public $numberOfProcesses;
    /** @var string */
    public $filename;
}

class GodFatherDaemon extends AbstractDaemon
{
    /** @var string[] */
    protected $_childPids = [];

    /** @var GodFatherDaemonInfo[] */
    protected $_registeredDaemons = [];

    /** @var int */
    protected $_waitForDie = 30;

    /** @var string */
    protected $_configFile;

    /** @var string */
    protected $_heartbeatFile;

    /** @var resource */
    protected $_hHeartbeat;

    protected function _init(): bool
    {
        if (!parent::_init()) {
            return false;
        }
        $this->_addSignalHandler(SIGINT, function () {
            $this->terminate();
        });
        $this->_addSignalHandler(SIGCHLD, function () {
            $this->_handleSigChild();
        });
        $this->setSleepSeconds(1);
        $this->setTtl(86400);
        if ($this->_heartbeatFile) {
            if (($heartbeatDir = dirname($this->_heartbeatFile)) && !file_exists($heartbeatDir)) {
                @mkdir($heartbeatDir, 0777, true);
            }
            $this->_hHeartbeat = @fopen($this->_heartbeatFile, 'c+');
            if ($this->_heartbeatFile && (!$this->_hHeartbeat || !@flock($this->_hHeartbeat, LOCK_EX | LOCK_NB))) {
                printf("Cannot acquire exclusive access to heartbeat-file (already running?)\n");
                return false;
            }
        }
        return true;
    }

    protected function _die(bool $exit = true): void
    {
        if ($this->_hHeartbeat) {
            @fclose($this->_hHeartbeat);
        }
        parent::_die($exit);
    }

    public function setWaitForDie(int $seconds): GodFatherDaemon
    {
        $this->_waitForDie = $seconds;
        return $this;
    }

    public function setConfigFile(string $filename): GodFatherDaemon
    {
        $this->_configFile = $filename;
        return $this;
    }

    public function setHeartbeatFile(string $filename): GodFatherDaemon
    {
        $this->_heartbeatFile = $filename;
        return $this;
    }

    public function registerChildDaemon(string $className, int $numberOfWorkers = 1, string $filename = null): GodFatherDaemon
    {
        if (!isset($this->_registeredDaemons[$className])) {
            $childInfo = new GodFatherDaemonInfo();
            $childInfo->className = $className;
            $this->_registeredDaemons[$className] = $childInfo;
        }
        $this->_registeredDaemons[$className]->numberOfProcesses = $numberOfWorkers;
        $this->_registeredDaemons[$className]->filename = $filename;
        return $this;
    }

    public function unregisterChildDaemon(string $className): GodFatherDaemon
    {
        unset($this->_registeredDaemons[$className]);
        return $this;
    }

    public function unregisterChildDaemons(): GodFatherDaemon
    {
        $this->_registeredDaemons = [];
        return $this;
    }

    protected function _runDaemon(string $className)
    {
        $this->_talk("is going to run {$className}...");
        $childPid = AsyncOperation::justRun(function () use ($className) {
            if ($this->_hHeartbeat) {
                @fclose($this->_hHeartbeat);
                $this->_hHeartbeat = null;
            }
            $execArgs = [
                $this->_fatherScriptPath,
                "--daemon={$className}",
            ];
            if ($this->_configFile) {
                $execArgs[] = "--config={$this->_configFile}";
            }
            if (!empty($this->_registeredDaemons[$className]->filename)) {
                $execArgs[] = "--filename={$this->_registeredDaemons[$className]->filename}";
            }
            pcntl_exec($this->_phpInterpreter, $execArgs);
        });
        $this->_childPids[$childPid] = $className;
        $this->_talk("has forked into {$className}, {$childPid}");
    }

    protected function _work(): bool
    {
        $daemonProcessCount = [];
        foreach ($this->_childPids as $childPid => $daemonName) {
            if (!isset($daemonProcessCount[$daemonName])) {
                $daemonProcessCount[$daemonName] = 0;
            }
            $daemonProcessCount[$daemonName]++;
        }
        if ($this->_hHeartbeat) {
            @ftruncate($this->_hHeartbeat, 0);
            @fseek($this->_hHeartbeat, 0);
            @fwrite($this->_hHeartbeat, @json_encode([
                'current_time' => date('c'),
                'daemons' => array_map(function ($daemonName, $workingProcessCount) {
                    return [
                        'daemon_name' => $daemonName,
                        'working_processes_count' => $workingProcessCount,
                    ];
                },
                    array_keys($daemonProcessCount),
                    array_values($daemonProcessCount)
                ),
            ]));
        }
        foreach ($this->_registeredDaemons as $daemonName => $childInfo) {
            $pc = isset($daemonProcessCount[$daemonName]) ? $daemonProcessCount[$daemonName] : 0;
            $pd = $childInfo->numberOfProcesses - $pc;
            if ($pd > 0) {
                for ($n = 1; $n <= $pd; $n++) {
                    $this->_runDaemon($childInfo->className);
                    usleep(100000);
                }
            }
        }
        return false;
    }

    public function terminate(): void
    {
        parent::terminate();
        $this->_talk('terminating forked children...');
        $terminatedAt = time();
        $signalToSend = SIGTERM;
        $stillWaiting = true;
        $killSent = false;
        while ($this->_childPids) {
            $time = time();
            foreach ($this->_childPids as $childPid => $daemonName) {
                if ($signalToSend) {
                    $signalAlias = static::signalAliases[$signalToSend];
                    $this->_talk("sending {$signalAlias} to the child process ({$daemonName}, {$childPid})");
                    posix_kill($childPid, $signalToSend);
                }
                if ($stillWaiting && ($time - $terminatedAt > $this->_waitForDie / 2)) {
                    $this->_talk("still waiting for the child process ({$daemonName}, {$childPid})");
                }
                $childStatus = null;
                $childPid = pcntl_waitpid($childPid, $childStatus, WNOHANG);
                if ($childPid > 0) {
                    unset($this->_childPids[$childPid]);
                    $this->_talk("child process ({$daemonName}, {$childPid}) has been shut down");
                }
            }
            $signalToSend = null;
            if ($stillWaiting && ($time - $terminatedAt > $this->_waitForDie / 2)) {
                $stillWaiting = false;
            }
            sleep(1);
            if (!$killSent && ($time - $terminatedAt > $this->_waitForDie)) {
                $signalToSend = SIGKILL;
                $killSent = true;
            }
        }
    }

    protected function _handleSigChild(): void
    {
        $childStatus = null;
        while ($childPid = pcntl_wait($childStatus, WNOHANG)) {
            if ($childPid == -1) {
                $this->_talk("error while calling pcntl_wait()");

                return;
            }
            if (!isset($this->_childPids[$childPid])) {
                $this->_talk("child PID is unknown ({$childPid})");
                return;
            }
            $exitCode = pcntl_wexitstatus($childStatus);
            $daemonName = $this->_childPids[$childPid];
            $this->_talk("child is dead with exit code {$exitCode} ({$daemonName}, {$childPid})");
            unset($this->_childPids[$childPid]);
        }
    }
}

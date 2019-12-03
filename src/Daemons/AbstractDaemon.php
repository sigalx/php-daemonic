<?php

namespace sigalx\Daemonic\Daemons;

abstract class AbstractDaemon
{
    /** @var bool */
    public $verbose = true;

    /** @var string */
    protected $_phpInterpreter = '/usr/bin/php';

    /** @var string */
    protected $_fatherScriptPath;

    /** @var bool */
    protected $_whileTrue = false;

    /** @var float */
    protected $_sleepSeconds = 0.5;

    /** @var int */
    protected $_runAt;

    /** @var int */
    protected $_ttl = 21600; // 6 hours

    /** @var string */
    protected $_lockDirectory;

    /** @var string */
    protected $_logFile;

    /** @var resource */
    protected $_hLogFile;

    /** @var int */
    protected $_pid;

    const signalAliases = [
        1 => 'SIGHUP',
        2 => 'SIGINT',
        3 => 'SIGQUIT',
        4 => 'SIGILL',
        5 => 'SIGTRAP',
        6 => 'SIGABRT',
        7 => 'SIGBUS',
        8 => 'SIGFPE',
        9 => 'SIGKILL',
        10 => 'SIGUSR1',
        11 => 'SIGSEGV',
        12 => 'SIGUSR2',
        13 => 'SIGPIPE',
        14 => 'SIGALRM',
        15 => 'SIGTERM',
        16 => 'SIGSTKFLT',
        17 => 'SIGCHLD',
        18 => 'SIGCONT',
        19 => 'SIGSTOP',
        20 => 'SIGTSTP',
        21 => 'SIGTTIN',
        22 => 'SIGTTOU',
        23 => 'SIGURG',
        24 => 'SIGXCPU',
        25 => 'SIGXFSZ',
        26 => 'SIGVTALRM',
        27 => 'SIGPROF',
        28 => 'SIGWINCH',
        29 => 'SIGIO',
        30 => 'SIGPWR',
        31 => 'SIGSYS',
    ];

    public static function name(): string
    {
        return get_called_class();
    }

    abstract protected function _work(): bool;

    protected function _init(): bool
    {
        $this->_runAt = time();
        $this->_pid = posix_getpid();
        $fatherScriptPathHash = sha1($this->_fatherScriptPath);
        if (!$this->_lockDirectory) {
            $this->_lockDirectory = "/var/lock/{$fatherScriptPathHash}";
        }
        if (!file_exists($this->_lockDirectory)) {
            mkdir($this->_lockDirectory, 0777, true);
        }
//        if (!$this->_logFile) {
//            $ym = date('Y-m');
//            $this->_logFile = "/var/log/{$fatherScriptPathHash}/{$ym}/{$this->_runAt}-{$this->name()}-{$this->_pid}.log";
//        }
        if ($this->_logFile && ($logDir = dirname($this->_logFile)) && !file_exists($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $this->_hLogFile = @fopen($this->_logFile, 'x');
        if ($this->_logFile && !$this->_hLogFile) {
            $this->_talk('has FAILED to initialize log file');
            return false;
        }
        $this->_talk('has been initialized');
        $this->_addSignalHandler(SIGTERM, function () {
            $this->terminate();
        });
        return true;
    }

    protected function _die(bool $exit = true): void
    {
        if ($this->_hLogFile) {
            fclose($this->_hLogFile);
            $this->_hLogFile = null;
        }
        if ($exit) {
            exit();
        }
    }

    protected function _log(string $message): void
    {
        if (!$this->_hLogFile) {
            return;
        }
        fwrite($this->_hLogFile, $message . PHP_EOL);
    }

    public function setPhpInterpreter(string $path): AbstractDaemon
    {
        $this->_phpInterpreter = $path;
        return $this;
    }

    public function setFatherScriptPath(string $fatherScriptPath): AbstractDaemon
    {
        $this->_fatherScriptPath = $fatherScriptPath;
        return $this;
    }

    public function setSleepSeconds(float $value): AbstractDaemon
    {
        $this->_sleepSeconds = $value;
        return $this;
    }

    public function setLockDirectory(string $lockDirectory): AbstractDaemon
    {
        $this->_lockDirectory = $lockDirectory;
        return $this;
    }

    public function setTtl(int $ttl): AbstractDaemon
    {
        $this->_ttl = $ttl;
        return $this;
    }

    public function run(): void
    {
        if (!$this->_pid) {
            if (!$this->_init()) {
                $this->_talk('NOT initialized');
                return;
            }
        }
        $this->_talk('has been started');
        $this->_whileTrue = true;
        while ($this->_whileTrue) {
            pcntl_signal_dispatch();
            if ($this->_whileTrue) {
                if (!$this->_work()) {
                    usleep(1000000 * $this->_sleepSeconds);
                }
            }
            if (time() - $this->_runAt > $this->_ttl) {
                $this->_talk('is going to die');
                $this->_whileTrue = false;
            }
        }
        $this->_talk('has been ended');
        $this->_die(false);
    }

    public function terminate(): void
    {
        $this->_talk('has been terminated');
        $this->_whileTrue = false;
    }

    protected function _talk(string $message): void
    {
        if (!$this->verbose) {
            return;
        }
        $className = static::class;
        $timeH = date('c');
        $this->_log("[{$timeH}] {$message}");
        echo "[{$timeH}] {$className} ({$this->_pid}): {$message}\n";
    }

    protected function _verboseArray(array $data): void
    {
        if (!$this->verbose) {
            return;
        }
        $this->_talk(PHP_EOL . json_encode($data, JSON_PRETTY_PRINT));
    }

    protected function _verboseObject(\stdClass $data): void
    {
        if (!$this->verbose) {
            return;
        }
        $this->_verboseArray((array)$data);
    }

    protected function _addSignalHandler(int $signo, callable $handler): void
    {
        if ($this->verbose) {
            $signalAlias = static::signalAliases[$signo];
            $this->_talk("now is handling {$signalAlias}");
            pcntl_signal($signo, function () use ($signo, $handler) {
                $signalAlias = static::signalAliases[$signo];
                $this->_talk("has got {$signalAlias}");
                call_user_func($handler);
            });
        } else {
            pcntl_signal($signo, $handler);
        }
    }

}

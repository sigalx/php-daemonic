<?php

namespace sigalx\Daemonic;

class AsyncException extends \Exception implements \Serializable
{
    public function serialize()
    {
        return @json_encode([
            'message' => $this->message,
            'code' => $this->code,
            'file' => $this->file,
            'line' => $this->line,
        ]);
    }

    public function unserialize($serialized)
    {
        $decoded = json_decode($serialized);
        $this->message = $decoded->message;
        $this->code = $decoded->code;
        $this->file = $decoded->file;
        $this->line = $decoded->line;
    }
}

class AsyncOperation
{
    /** @var int */
    protected $_returnCode;
    /** @var mixed */
    protected $_result;
    /** @var callable */
    protected $_callable;
    /** @var array */
    protected $_callableParams;
    /** @var int */
    protected $_childPid;
    /** @var int */
    protected $_childStatus;
    /** @var callable */
    protected $_onSuccess;
    /** @var callable */
    protected $_onException;
    /** @var int */
    protected $_sharedMemoryKey;

    /** @var AsyncOperation[] */
    protected static $_asyncOperations = [];

    public function __construct(callable $callable, array $params = [])
    {
        $this->_callable = $callable;
        $this->_callableParams = $params;
    }

    public function __destruct()
    {
    }

    public function setParams(array $params): AsyncOperation
    {
        $this->_callableParams = $params;
        return $this;
    }

    public function run(): AsyncOperation
    {
        if ($this->_onSuccess || $this->_onException) {
            pcntl_signal(SIGCHLD, function () {
                static::_handleSigChild();
            });
        }
        mt_srand();
        $this->_sharedMemoryKey = mt_rand(1, mt_getrandmax());
        shm_attach($this->_sharedMemoryKey);
        $this->_childPid = pcntl_fork();
        if ($this->_childPid) {
            static::$_asyncOperations[$this->_childPid] = $this;
            return $this;
        }
        posix_setsid();
        $result = null;
        $success = true;
        try {
            $result = call_user_func_array($this->_callable, $this->_callableParams);
        } /** @noinspection PhpRedundantCatchClauseInspection */
        catch (AsyncException $exception) {
            $result = $exception;
            $success = false;
        } catch (\Exception $exception) {
            $result = (string)$exception;
            $success = false;
        }
        $sharedMemory = shm_attach($this->_sharedMemoryKey);
        @shm_put_var($sharedMemory, 1, $result);
        shm_detach($sharedMemory);
        exit($success ? 0 : 1);
    }

    public function isRunning(): bool
    {
        return $this->_childPid && !$this->isFinished(true);
    }

    public function check(): void
    {
        pcntl_signal_dispatch();
    }

    public function isFinished(bool $check = false): ?bool
    {
        if ($check) {
            $this->check();
        }
        if (!$this->_childPid) {
            return null;
        }
        return isset($this->_returnCode);
    }

    public function getChildPid(): int
    {
        return $this->_childPid;
    }

    protected static function _handleSigChild(): void
    {
        $childStatus = null;
        $childPid = null;
        while ($childPid = pcntl_wait($childStatus, WNOHANG)) {
            if ($childPid == -1) {
                return;
            }
            if (isset(static::$_asyncOperations[$childPid])) {
                // got the finished process
                $asyncOperation = static::$_asyncOperations[$childPid];
                $asyncOperation->_returnCode = pcntl_wexitstatus($asyncOperation->_childStatus);
                $sharedMemory = shm_attach($asyncOperation->_sharedMemoryKey);
                if (shm_has_var($sharedMemory, 1)) {
                    $asyncOperation->_result = shm_get_var($sharedMemory, 1);
                }
                shm_remove($sharedMemory);
                unset(static::$_asyncOperations[$asyncOperation->_childPid]);
                if ($asyncOperation->_returnCode) {
                    call_user_func($asyncOperation->_onException, $asyncOperation);
                } else {
                    call_user_func($asyncOperation->_onSuccess, $asyncOperation);
                }
            }
        }
    }

    public function onSuccess(callable $callable): AsyncOperation
    {
        $this->_onSuccess = $callable;
        return $this;
    }

    public function onException(callable $callable): AsyncOperation
    {
        $this->_onException = $callable;
        return $this;
    }

    public function getReturnCode(): int
    {
        return $this->_returnCode;
    }

    public function getResult()
    {
        return $this->_result;
    }

    public function getParams(): array
    {
        return $this->_callableParams;
    }

    public static function justRun(callable $callable, array $params = []): ?int
    {
        $childPid = pcntl_fork();
        if ($childPid) {
            return $childPid;
        }
        posix_setsid();
        $result = call_user_func_array($callable, $params);
        if ($result === false) {
            exit(-1);
        }
        if (is_integer($result)) {
            exit($result);
        }
        exit(0);
    }

}

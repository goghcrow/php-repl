<?php
/**
 * User: xiaofeng
 * Date: 2016/4/10
 * Time: 17:40
 */
namespace xiaofeng\cli;
require_once __DIR__ . DIRECTORY_SEPARATOR . "cli.php";
require_once __DIR__ . DIRECTORY_SEPARATOR . "error.php";

class Fork
{
    private $workers = [];
    private $ppid;

    public function __construct() {
        _env_check();
        $this->init();
    }

    protected function init() {
        $this->ppid = posix_getpid();
    }

    private function add_worker($pid, Worker $worker) {
        if(isset($this->workers[$pid])) {
            return;
        }
        $worker->is_child = false;
        $worker->is_alive = true;
        $worker->pid = $pid;
        $this->workers[$pid] = $worker;
    }

    public function worker_status() {
        /* @var Worker $worker */
        foreach($this->workers as $pid => $worker) {
            $is_alive = $worker->is_alive ? "active" : "stop";
            log("[$worker->pid]::$is_alive:$worker->desc");
        }
    }

    public function kill($pid = -1, $signo = SIGTERM) {
        if($pid === -1) {
            /* @var Worker $worker */
            foreach($this->workers as $pid => $worker) {
                if($worker->is_alive) {
                    if(posix_kill($pid, $signo)) {
                        $this->workers[$pid]->is_alive = false;
                    }
                }
            }
            return true;
        } else if(isset($this->workers[$pid])) {
            if($this->workers[$pid]->is_alive) {
                if(posix_kill($pid, $signo)) {
                    $this->workers[$pid]->is_alive = false;
                    return true;
                }
            }
            return false;
        }
        return false;
    }

    // block
    private function _wait($pid) {
        if(!isset($this->workers[$pid])) {
            return false;
        }
        /* @var Worker $worker */
        $worker = $this->workers[$pid];
        $desc = "";
        // !!!block
        pcntl_waitpid($pid, $status);
        switch(true) {
            case pcntl_wifexited($status): // 是否正常退出
                $desc = "exited => return code: " . pcntl_wexitstatus($status);
                $return = true;
                break;
            case pcntl_wifstopped($status): // [信号]是否已经停止
                $desc = "stopped => signal: " . pcntl_wstopsig($status);
                $return = false;
                break;
            case pcntl_wifsignaled($status): // [信号]是否由于某个信号中断
                $desc = "signaled => signal: " . pcntl_wtermsig($status);
                $return = false;
                break;
            default:
                $return = false;
        }
        $worker->is_alive = false;
        $worker->desc = $desc;
        // log($desc);
        return $return;
    }

    public function wait($pid = -1 ) {
        if($pid === -1) {
            foreach($this->workers as $pid => $_) {
                $this->wait($pid);
            }
            return true;
        } else if(isset($this->workers[$pid])) {
            return $this->_wait($pid);
        }
        return false;
    }

    public function task(callable $task) {
        if(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $fd) === false) {
            throw new \RuntimeException("socket_create_pair() failed. Reason: ".socket_strerror(socket_last_error()));
        }

        $pid = pcntl_fork();
        if($pid === -1) {
            throw new \RuntimeException("Could not fork Process.");
        }

        list($to_parent, $to_child) = $fd;
        $worker = new Worker($this, $this->ppid);
        if($pid === 0) {
            // child
            socket_close($to_child);
            $worker->is_child = true;
            $worker->socket = $to_parent;
            $worker->run($task);
            return null;
        } else {
            // parent
            socket_close($to_parent);
            $worker->socket = $to_child;
            $this->add_worker($pid, $worker);
            return new Future($this, $worker, $to_child, $pid);
        }
    }
}

// child exec & parent exec
class Worker
{
    public $is_child;
    public $ppid; // parent process id
    public $pid; // process id
    public $socket; // parent指向socket_to_child, child指向socket_to_parent
    public $is_alive;
    public $desc;
    /* @var Fork $fork */
    private $fork;

    // parent exec
    public function __construct($fork, $ppid) {
        $this->fork = $fork;
        $this->ppid = $ppid;
        $this->desc = "";
    }

    // child exec
    protected function init() {
        if(!$this->is_child) {
            return;
        }
        $this->is_alive = true;
        $this->pid = posix_getpid();
    }

    public function suicide($code = 0) {
        if($this->is_child) {
            exit($code);
        } else {
            return $this->fork->kill($this->pid);
        }
    }

    // child exec
    public function run(callable $f) {
        if(!$this->is_child) {
            return;
        }
        $this->init();
        try {
            $result = $f($this);
            write_pkg($this->socket, $result);
            exit(0);
        } catch(\Exception $e) {
            error_log($e->getMessage());
            exit(-1);
        }
    }
}

// parent exec
class Future
{
    private $pid;
    private $socket;
    /* @var $fork Fork */
    private $fork;
    /* @var $worker Worker*/
    private $worker;

    public function pid() {
        return $this->pid;
    }

    /**
     * @return Worker
     */
    public function worker() {
        return $this->worker;
    }

    public function __construct($fork, $worker, $socket, $pid) {
        $this->fork = $fork;
        $this->worker = $worker;
        $this->socket = $socket;
        $this->pid = $pid;
    }

    public function cancel() {
        return $this->fork->kill($this->pid);
    }

    public function combine(Future $another, callable $combiner) {
        return $combiner($this->get(), $another->get());
    }

    // block
    public function get() {
        try {
            return read_pkg($this->socket);
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }
        return false;
    }
}

function _env_check() {
    if(!is_cli()) {
        throw new \RuntimeException('!is_cli()');
    }
    if(strtoupper(substr(PHP_OS, 0, 3)) === "WIN") {
        throw new \RuntimeException('strtoupper(substr(PHP_OS, 0, 3)) === "WIN"');
    }
    if(!function_exists("pcntl_fork")) {
        throw new \RuntimeException('!function_exists("pcntl_fork")');
    }
    if(!function_exists("posix_getpid")) {
        throw new \RuntimeException('!function_exists("posix_getpid")');
    }
}

function _read_size($socket, $size) {
    if(!is_resource($socket) || $size <= 0) {
        throw new \RuntimeException('!is_resource($socket) || $len <= 0');
    }
    $buffer = "";
    do {
        // http://php.net/manual/en/function.socket-read.php
        $rec = @socket_read($socket, $size - strlen($buffer), PHP_BINARY_READ);
        if($rec === false) {
            throw new \RuntimeException("socket_read failed. Reason: "  . socket_strerror(socket_last_error()));
        }
        // no more data to read
        if($rec === "") {
            return "";
        }
        $buffer .= $rec;
    } while(strlen($buffer) < $size); // pack N
    return $buffer;
}

function _read_body($socket) {
    $header = _read_size($socket, 4); // pack N
    $arr = @unpack("N", $header);
    if($arr === false) {
        throw new \RuntimeException('@unpack("N", $header) === false. Reason: ' . error_get_last_msg());
    }
    list($len) = array_values($arr); // $arr[1]
    return _read_size($socket, $len);
}

function _encode($var) {
    if(is_resource($var)) {
        throw new \InvalidArgumentException("can`t serialize resource");
    }
    $body = @serialize($var);
    if($body === false) {
        throw new \RuntimeException('@serialize($var) === false. Reason: ' . error_get_last_msg());
    }
    $len = strlen($body);
    $head = @pack("N", $len); // unsigned long 4byte | 32bit | big endian byte order
    if($head === false) {
        throw new \RuntimeException('@pack("N", $len) === false. Reason: ' . error_get_last_msg());
    }
    return $head . $body;
}

function _decode($body) {
    $result = @unserialize($body);
    // 返回false两种可能,1.序列化false 2.出错
    if($result === false) {
        $err = error_get_last_msg();
        if($err === "") {
            // serialize(false) 的结果
            return $result;
        } else {
            throw new \RuntimeException('@unpack("N", $header) === false. Reason: ' . $err);
        }
    } else {
        return $result;
    }
}

function read_pkg($socket) {
    return _decode(_read_body($socket));
}

function write_pkg($socket, $var) {
    $payload = _encode($var);
    $ret = @socket_write($socket, $payload, strlen($payload));
    if($ret === false) {
        throw new \RuntimeException("@socket_write fail. Reason: " . socket_strerror(socket_last_error()));
    }
}

function msgbox($fd) {
    if(!is_resource($fd)) {
        throw new \RuntimeException('!is_resource($fd)');
    }
    return [
        function($var) use($fd) {
            write_pkg($fd, $var);
        },
        function() use($fd) {
            return read_pkg($fd);
        }
    ];
}

function log($msg) {
    echo date("y-m-d H:i:s") . " " . rtrim($msg) . "\n";
}

/**
 * 参考 http://log.codes/post/php-multiprocessing-experience/
 * 1. 信号可能产生覆盖
 */
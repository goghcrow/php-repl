<?php
/**
 * Created by PhpStorm.
 * User: 乌鸦
 * Date: 2016/4/10
 * Time: 17:40
 */
namespace xiaofeng\cli;
require_once __DIR__ . DIRECTORY_SEPARATOR . "error.php";
error_reporting(E_ALL);
ini_set("error_log", "error.log");

$w = new Fork();
$w->run();
// 开发中...



function readSize($socket, $size) {
    if(!is_resource($socket) || $size <= 0) {
        throw new \RuntimeException('!is_resource($socket) || $len <= 0');
    }
    $buffer = "";
    do {
        // http://php.net/manual/en/function.socket-read.php
        $rec = socket_read($socket, $size - strlen($buffer), PHP_BINARY_READ);
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

function readBody($socket) {
    $header = readSize($socket, 4); // pack N
    $arr = @unpack("N", $header);
    if($arr === false) {
        throw new \RuntimeException('@unpack("N", $header) === false. Reason: ' . error_get_last_msg());
    }
    list($len) = array_values($arr); // $arr[1]
    return readSize($socket, $len);
}

function encode($var) {
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

function decode($body) {
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

function readPkg($socket) {
    return decode(readBody($socket));
}

function writePkg($socket, $var) {
    $payload = encode($var);
    $ret = @socket_write($socket, $payload, strlen($payload));
    if($ret === false) {
        throw new \RuntimeException("@socket_write fail. Reason: " . socket_strerror(socket_last_error()));
    }
}


function log($msg) {
    echo date("y-m-d H:i:s") . " " . rtrim($msg) . "\n";
}
function env_check() {
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
function msgbox($fd) {
    if(!is_resource($fd)) {
        throw new \RuntimeException('!is_resource($fd)');
    }
    return [
        function($msg) use($fd) {
            return socket_write($fd, $msg, strlen($msg));
        },
        function($len = 8192) use($fd) {
            return socket_read($fd, $len, PHP_BINARY_READ);
        }
    ];
}


class Fork
{
    private $workers = [];
    private $ppid;

    public function __construct() {
        env_check();
        $this->init();
    }

    protected function init() {
        $this->ppid = posix_getpid();
    }

    private function add_worker($pid, $fd) {
        $this->workers[$pid] = [
            "socket" => $fd,
        ];
    }

    private function log($msg) {
        log("[{$this->ppid}] => $msg");
    }

    public function run() {
        $this->log("Start ...");

        $task = function() {
            sleep(2);
            return microtime(true);
        };

        $future1 = $this->task($task);
        $future2 = $this->task($task);
        $future3 = $this->task(function() {
            exit(-1);
        });

        var_dump($future1->get());
        var_dump($future2->get());
        var_dump($future3->get());


        // fixme
//        posix_kill();
//        $child_pid = pcntl_wait($status, WUNTRACED);
//        if(pcntl_wifexited($status)) {
//        }
    }

    protected function task(callable $task) {
        if(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $fd) === false) {
            throw new \RuntimeException("socket_create_pair() failed. Reason: ".socket_strerror(socket_last_error()));
        }

        $pid = pcntl_fork();
        if($pid === -1) {
            throw new \RuntimeException("Could not fork Process.");
        }

        list($to_parent, $to_child) = $fd;
        if($pid === 0) {
            socket_close($to_child);
            $worker = new Worker($to_parent, $this->ppid);
            $worker->run($task);
        } else {
            socket_close($to_parent);
            $this->add_worker($pid, $to_child);
        }
        return new Future($to_child, $pid);
    }
}

class Worker
{
    private $pid;
    private $socket;

    public function __construct($socket) {
        $this->socket = $socket;
    }

    protected function init() {
        $this->pid = posix_getpid();
    }

    public function run(callable $f) {
        $this->init();
        try {
            $result = $f();
            writePkg($this->socket, $result);
            exit(0);
        } catch(\Exception $e) {
            error_log($e->getMessage());
            exit(-1);
        }
    }
}

class Future
{
    private $pid;
    private $socket;

    public function __construct($socket, $pid) {
        $this->socket = $socket;
        $this->pid = $pid;
    }

    public function compose() {

    }

    public function combine() {

    }

    public function get($timeout = 0) {
        // pcntl_wifexited
        // todo timeout
        // todo  unserialize valid
        try {
            return readPkg($this->socket);
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }
        return false;
    }
}

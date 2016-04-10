<?php
/**
 * Created by PhpStorm.
 * User: 乌鸦
 * Date: 2016/4/10
 * Time: 17:40
 */
namespace xiaofeng\cli;
error_reporting(E_ALL);

$w = new Fork();
$w->run();

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
    private $pid;

    public function __construct() {
        env_check();
        $this->init();
    }

    protected function init() {
        $this->pid = posix_getpid();
    }

    private function add_worker($pid, $fd) {
        $this->workers[$pid] = [
            "socket" => $fd
        ];
    }

    private function log($msg) {
        log("[{$this->pid}] => $msg");
    }

    public function run() {
        $this->log("Start ...");

        list($send, $receive) = $this->fork_one(function($send, $receive) {
            $send("Im working hard.");
            sleep(1);
        });

        $rec = $receive();
        if($rec !== false) {
            $this->log("rec: $rec");
            $send("Hello !");
        }
        $rec = $receive();
        if($rec !== false) {
            $this->log("rec: $rec");
        }
        $rec = $receive();
        if($rec !== false) {
            $this->log("rec: $rec");
        }


        $child_pid = pcntl_wait($status, WUNTRACED);
        if(pcntl_wifexited($status)) {
            echo "\n\n* Sub process: {$child_pid} exited with {$status}\n\n";
        }
    }

    protected function fork_one(callable $task) {
        if(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $fd) === false) {
            throw new \RuntimeException("socket_create_pair() failed. Reason: ".socket_strerror(socket_last_error()));
        }

        $child_pid = pcntl_fork();
        if($child_pid === -1) {
            throw new \RuntimeException("Could not fork Process.");
        }

        list($to_parent, $to_child) = $fd;
        if($child_pid === 0) {
            socket_close($to_child);
            $worker = new Worker($to_parent, $this->pid);
            $worker->run($task);
        } else {
            socket_close($to_parent);
            $this->add_worker($child_pid, $to_child);
        }
        return msgbox($to_child);
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

    private function log($msg) {
        log("[{$this->pid}] => $msg");
    }

    public function run(callable $f) {
        $this->init();

        list($send, $receive) = msgbox($this->socket);
        $send("Hi, Im $this->pid !");

        $done = false;
        while(!$done) {
            $rec = $receive();
            if(false === $rec) continue;

            $this->log("rec: $rec");
            $f($send, $receive);
            $send("Done!");

            $done = true;
        }
        exit(0);
    }
}

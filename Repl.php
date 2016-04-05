<?php
/**
 * User: xiaofeng
 * Date: 2016/4/3
 * Time: 14:23
 */

namespace xiaofeng\cli;
require_once __DIR__ . DIRECTORY_SEPARATOR . "cli.php";
require_once __DIR__ . DIRECTORY_SEPARATOR . "Console.php";
error_reporting(E_ALL);

/**
 * Class Repl
 * @package xiaofeng\cli
 * @author xiaofeng
 * FIXME 加入各种f*函数的错误检测
 * 问题：
 * window无信号控制扩展，无法响应ctrl+c
 * TODO:
 * 1. 扩展支持其他op
 * 2. 扩展op支持参数
 * 3. 环境的load与save
 */
class Repl {
    const OP_IGNORE         = 0;        // 接受当前行输入，无操作
    const OP_CONTINUE       = 1;        // 接受当前行输入，继续等待输入
    const OP_BREAK          = 2;        // 接受当前行输入，执行命令
    const OP_PASS           = 3;        // 忽略当前行

    public static $debug    = true;

    private $console;           // 控制台输出
    private $hisf;              // 历史文件: 记录输入历史
    private $hisfd;             // 历史文件句柄

    private $env;               // 执行环境，实际是输入命令的stack
    private $cmd = "";          // 当前未执行命令

    private $prompt = "";       // 当前提示符

    private $op_array = [];     // 内置命令数组

    public function __construct($colorfy = false) {
        if(!is_cli()) {
            throw new \RuntimeException("Repl can only run in cli");
        }
        $this->console = new Console($colorfy);
        $tip = ">>> XIAOFENG PHP REPL v0.1 <<<";
        $this->console->info(PHP_EOL . str_pad($tip, strlen($tip) + 90, " ", STR_PAD_BOTH) . PHP_EOL);
    }

    private function init() {
        // 关闭输出缓存
        ob_implicit_flush(true);
        $this->cmd_clear();
        $this->env_clear();
        $this->history_init();
        $this->register_internal_op();
    }

    private function clear() {
        ob_implicit_flush(false);
        $this->cmd_clear();
        $this->env_clear();
        $this->history_clear();
    }

    private function register_internal_op() {
        $this->op_register("help",  [$this, "op_help"]);
        $this->op_register("q",     [$this, "op_exit"],     "quit", ["exit", "quit"]);
        $this->op_register("c",     [$this, "op_cancel"],   "cancel state of input", "cancel");
        $this->op_register("env",   [$this, "op_env"],      "show env");
        $this->op_register("cmd",   [$this, "op_cmd"],      "show cmd");
        $this->op_register("reset", [$this, "op_reset"],    "clear env");
        $this->op_register("clear", [$this, "op_clear"],    "clear");
        $this->op_register("status",[$this, "op_status"],   "show status");
        $this->op_register("color", [$this, "op_color"],    "toggle color");
    }

    private function evaluate_dump(array $env, $cmd) {
        array_unshift($env, "ob_start();");
        $env[] = "ob_end_clean();";
        $env[] = "var_dump(" . trim($cmd, "\t\n\r\0\x0b;") . ");";
        list($cmdout, $cmderr) = exec_code(implode(PHP_EOL, $env));
        if(!trim($cmderr) && trim($cmdout)) {
            echo $cmdout;
        }
    }

    private function evaluate(array $env, $cmd) {
        array_unshift($env, "ob_start();");
        $env[] = "ob_end_clean();";
        $env[] = $cmd;
        list($stdout, $strerr) = exec_code(implode(PHP_EOL, $env));
        if($strerr) {
            $this->console->error($strerr);
        } else {
            $this->env_push($cmd);
            if($stdout) {
                echo $stdout . PHP_EOL;
            }
        }
    }

    private function history_init() {
        $this->hisf = tempnam(sys_get_temp_dir(), "#php_repl_history");
        if(self::$debug) {
            $this->hisf = "#php_repl_history.php";
        }
        $this->hisfd = fopen($this->hisf, "a");
    }

    private function env_clear() {
        $this->env = [];
    }

    private function env_push($cmd) {
        $this->env[] = $cmd;
    }

    private function cmd_clear() {
        $this->cmd = "";
    }

    private function cmd_push($line) {
        $this->cmd .= $line . PHP_EOL;
    }

    private function cmd_pop($line) {
        $this->cmd = substr($this->cmd, 0, strlen($this->cmd) - strlen($line . PHP_EOL));
    }

    private function op_register($cmd, callable $handler, $desc = "", $alias = null) {
        if(!$desc) {
            $desc = $cmd;
        }
        // 命令与别名均会无条件覆盖已经注册的命令
        $this->op_array[":$cmd"] = [$handler, $desc];
        if($alias) {
            if(!is_array($alias)) {
                $alias = [$alias];
            }
            foreach($alias as $a) {
                $this->op_array[":$a"] = [$handler, "alias for $cmd"];
            }
        }
    }

    private function history_clear() {
        fflush($this->hisfd);
        fclose($this->hisfd);
    }

    private function history_push($line) {
        $his = sprintf("%s # %s" . PHP_EOL, date("Y-m-d h:i:s"), $line);
        fwrite($this->hisfd, $his);
    }

    private function op_execute($line) {
        foreach($this->op_array as $cmd => list($handler)) {
            if(cmdcmp($cmd, $line)) {
                return $handler($line);
            }
        }
        return self::OP_IGNORE;
    }

    /**
     * 是否接受当前命令，准备执行
     * 用来检测命令是否完整
     * 现在的实现简单的判断是否以";"结束
     * @param $line
     * @return mixed
     */
    protected function accept_command($line) {
        return endwith($line, ";");
    }

    public function run() {
        $this->init();

        while(true) {
            if($this->cmd) {
                $this->prompt = str_pad($this->console->log(">", true) . " ", strlen($this->prompt), " ", STR_PAD_LEFT);
            } else {
                $this->prompt = $this->console->log("php>", true) . " ";
            }

            $line = readline($this->prompt);            // 读取用户输入
            $this->history_push($line);                 // 追加历史
            $this->cmd_push($line);                     // 追加到命令

            switch($this->op_execute($line)) {          // 检测执行内置命令
                case self::OP_BREAK:                    // 接受当前行输入，执行命令
                    break 2;
                case self::OP_CONTINUE;                 // 接受当前行输入，继续等待输入
                    continue 2;
                case self::OP_PASS:                     // 忽略当前行
                    $this->cmd_pop($line);
                    break;
                case self::OP_IGNORE:                   // 接受当前行输入，无操作
                    break;
            }

            if(!$this->accept_command($line)) {         // 是否接受当前语句
                continue;                               // 直到遇到以分号结果的命令
            }                                           // 否则全部追加到command

            $this->evaluate_dump($this->env, $this->cmd);
            $this->evaluate($this->env, $this->cmd);
            $this->cmd_clear();
        }

        $this->clear();
    }

    private function op_exit() {
        // fixme 用 exit die 之类正则来结束, 否则把exit写入环境就bug了
        return self::OP_BREAK;
    }

    private function op_help() {
        foreach($this->op_array as $cmd => list(, $desc)) {
            echo str_pad($cmd, 10, " ", STR_PAD_RIGHT), $desc, PHP_EOL;
        }
        return self::OP_PASS;
    }

    private function op_reset() {
        $this->env_clear();
        $this->cmd_clear();
        return self::OP_CONTINUE;
    }

    private function op_cancel() {
        $this->cmd_clear();
        return self::OP_CONTINUE;
    }

    private function op_status() {
        cost();
        return self::OP_PASS;
    }

    private function op_clear() {
        clear();
        return self::OP_PASS;
    }

    private function op_cmd() {
        echo $this->cmd, PHP_EOL;
        return self::OP_PASS;
    }

    private function op_env() {
        if($this->env) {
            foreach(array_values($this->env) as $i => $code) {
                $this->console->info($i + 1);
                echo str_repeat(" ", max(0, 5 - strlen($i)));
                echo str_replace(PHP_EOL, " ", $code), PHP_EOL;
            }
        }
        return self::OP_PASS;
    }

    private function op_color() {
        $this->console->toggleColor();
        return self::OP_PASS;
    }
}
<?php
/**
 * User: xiaofeng
 * Date: 2016/4/3
 * Time: 14:23
 */

namespace xiaofeng\cli;
require_once __DIR__ . DIRECTORY_SEPARATOR . "cli.php";
require_once __DIR__ . DIRECTORY_SEPARATOR . "Console.php";

/**
 * Class Repl
 * @package xiaofeng\cli
 * @author xiaofeng
 *
 * 问题：
 * window无信号控制扩展，无法响应ctrl+c
 *
 * TODO:
 * 0. 加入各种f*函数的错误检测
 * 1. 扩展支持其他op
 * 2. 支持处理复制粘贴的片段代码
 * 3. 加入载入时环境合法性的验证
 */
class Repl {
    const OP_BREAK          = 0;                // 退出REPL
    const OP_IGNORE         = 1;                // 无操作
    const OP_CONTINUE       = 2;                // 接受当前行输入，继续repl
    const OP_PASS           = 3;                // 忽略当前行，继续repl
    const OP_CLEAR          = 4;                // 清除当前命令，继续repl

    const EXEC_PROC         = "xiaofeng\\cli\\exec_code";      // 额外进程执行命令,速度慢,稳定
    const EXEC_EVAL         = "xiaofeng\\cli\\exec_eval";      // eval执行命令,速度快,错误可能导致程序退出

    /* @var callable $exec_type */
    private $exec_type;         // 命令执行方式
    private $console;           // 控制台输出
    private $hisf;              // 历史文件: 记录输入历史
    private $hisfd;             // 历史文件句柄

    private $env;               // 执行环境，实际是输入命令的stack
    private $cmd = "";          // 当前未执行命令

    private $prompt = "";       // 当前提示符

    private $op_array = [];     // 内置命令数组

    public function __construct($exec_type = self::EXEC_EVAL, $colorfy = false) {
        if(!is_cli()) {
            throw new \RuntimeException("Repl can only run in cli");
        }
        if($exec_type !== self::EXEC_EVAL && $exec_type !== self::EXEC_PROC) {
            throw new \InvalidArgumentException('$exec_type !== self::EXEC_EVAL || $exec_type !== self::EXEC_PROC');
        }
        $this->exec_type = $exec_type;
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

        register_shutdown_function(function() {
            $error = error_get_last();
            if($error) {
                $this->console->error("Bye! " . $error["message"] . PHP_EOL);
            }
        });
    }

    private function clear() {
        ob_implicit_flush(false);
        $this->cmd_clear();
        $this->env_clear();
        $this->history_clear();
    }

    private function register_internal_op() {
        $this->op_register("help",   [$this, "op_help"]);
        $this->op_register("q",      [$this, "op_exit"],     "quit", ["exit", "quit"]);
        $this->op_register("c",      [$this, "op_cancel"],   "cancel state of input", "cancel");
        $this->op_register("cmd",    [$this, "op_cmd"],      "show cmd");
        $this->op_register("history",[$this, "op_history"],  "show history");
        $this->op_register("env",    [$this, "op_env"],      "show env");
        $this->op_register("reset",  [$this, "op_reset"],    "clear env");
        $this->op_register("save",   [$this, "op_save"],     "save env :save:file");
        $this->op_register("load",   [$this, "op_load"],     "load env :load:file");
        $this->op_register("clear",  [$this, "op_clear"],    "clear");
        $this->op_register("status", [$this, "op_status"],   "show status");
        $this->op_register("color",  [$this, "op_color"],    "toggle color");
    }

    private function exec($code) {
        return call_user_func($this->exec_type, $code);
    }

    // 打印无显式返回值命令
    private function pre_evaluate(array $env, $cmd) {
        if($env) {
            array_unshift($env, "error_reporting(E_ALL^E_NOTICE);ob_start();");
            $env[] = "ob_end_clean();";
        }
        $code = <<<'CODE'
ob_start();
$__ret__ = {{cmd}}
$__ob__=ob_get_clean();
if(!$__ob__ && $__ret__) {
    var_dump($__ret__);
}
CODE;
        list($cmdout, $cmderr) = $this->exec(implode(PHP_EOL, $env) . str_replace("{{cmd}}", $cmd, $code));
        if(!trim($cmderr) && trim($cmdout)) {
            echo rtrim($cmdout) . PHP_EOL;
        }
    }

    private function evaluate(array $env, $cmd) {
        if($env) {
            array_unshift($env, "error_reporting(E_ALL^E_NOTICE);ob_start();");
            $env[] = "ob_end_clean();";
        }
        $env[] = "ob_start();";
        $env[] = $cmd;
        $env[] = '$_____=ob_get_clean();if(rtrim($_____)) echo rtrim($_____).PHP_EOL;';
        list($stdout, $stderr) = $this->exec(implode(PHP_EOL, $env));
        if(rtrim($stderr, PHP_EOL)) {
            $this->console->error($stderr);
        } else {
            $this->env_push($cmd);
            if($stdout) {
                echo rtrim($stdout) . PHP_EOL;
            }
        }
    }

    private function history_init() {
        $this->hisf = tempnam(sys_get_temp_dir(), "#php_repl_history");
        $this->hisfd = fopen($this->hisf, "a");
    }

    private function env_clear() {
        $this->env = [];
    }

    private function env_save($file) {
        return false !== file_put_contents($file, implode("…", $this->env));
    }

    private function env_load($file) {
        if(!file_exists($file)) {
            return false;
        }
        if($text = file_get_contents($file)) {
            $this->env = explode("…", $text);
            return true;
        }
        return false;
    }

    private function env_push($cmd) {
        $this->env[] = $cmd;
    }

    private function cmd_clear() {
        $this->cmd = "";
    }

    private function cmd_fix() {
        $this->cmd = rtrim($this->cmd, " \t\n\r\0\x0B;") . ";";
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
        fwrite($this->hisfd, $line . PHP_EOL);
    }

    private function op_execute($line) {
        foreach($this->op_array as $cmd => list($handler)) {
            if($this->matched_op($cmd, $line, $arg)) {
                return $handler($arg);
            }
        }
        return self::OP_IGNORE;
    }

    /**
     * 当前行是否匹配内置命令
     * @param string $cmd
     * @param string $line
     * @param string $arg out
     * @return bool
     */
    protected function matched_op($cmd, $line, &$arg = "") {
        if(0 === strncasecmp($line, $cmd, max(strlen($cmd), strlen($line)))) {
            return true;
        }
        $cmd = preg_quote($cmd);
        if(preg_match("/^$cmd:(.*)/i", $line, $matches)) {
            $arg = $matches[1];
            return true;
        }
        return false;
    }

    /**
     * 是否接受当前命令，准备执行
     * 用来检测命令是否完整
     * 现在的实现简单的判断是否以";"结束
     * @param $line
     * @return mixed
     */
    protected function accept_command($line, $cmd) {
        if(!$line || !$cmd) {
            return false;
        }
        if(trim($cmd) === ";") {
            $this->cmd_clear();
            return false;
        }

        $syntax_right = syntax_right($cmd);
        // 语法正确则，补全分号，接受
        if($syntax_right) {
            $this->cmd_fix();
            return true;
        }
        /*
        // 语法错误，则遇到结束字符结束，执行过程会提示具体错误原因
        $end_mark = [";", "}"];
        return in_array(substr(rtrim($line), -1), $end_mark, true);
        */
        // 语法错误，输入:c手动结束~
        return false;
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
                case self::OP_BREAK:                    // 退出REPL
                    break 2;
                case self::OP_CONTINUE;                 // 接受当前行输入，继续repl
                    break;
                case self::OP_PASS:                     // 忽略当前行, 继续repl
                    $this->cmd_pop($line);
                    break;
                case self::OP_CLEAR:
                    $this->cmd_clear();                 // 清除当前命令，继续repl
                    continue 2;
                case self::OP_IGNORE:                   // 无操作
                    break;
            }

            if(!$this->accept_command($line, $this->cmd)) {
                                                        // 是否接受当前语句
                continue;                               // 直到遇到以分号结果的命令
            }                                           // 否则全部追加到command

            $this->pre_evaluate($this->env, $this->cmd);
            $this->evaluate($this->env, $this->cmd);
            $this->cmd_clear();
        }

        $this->clear();
    }

    private function op_exit() {
        //  self::EXEC_PROC方式得把exit die之类正则捕获,结束程序
        return self::OP_BREAK;
    }

    private function op_help() {
        foreach($this->op_array as $cmd => list(, $desc)) {
            echo str_pad($cmd, 10, " ", STR_PAD_RIGHT), $desc, PHP_EOL;
        }
        return self::OP_CLEAR;
    }

    private function op_reset() {
        $this->env_clear();
        $this->cmd_clear();
        return self::OP_CLEAR;
    }

    private function op_save($arg) {
        if(!$this->env_save($arg)) {
            $this->console->error(rtrim("Save fail: $arg") . PHP_EOL);
        }
        return self::OP_CLEAR;
    }

    private function op_load($arg) {
        if(!$this->env_load($arg)) {
            $this->console->error(rtrim("Load Fail: $arg") . PHP_EOL);
        }
        return self::OP_CLEAR;
    }

    private function op_cancel() {
        $this->cmd_clear();
        return self::OP_CLEAR;
    }

    private function op_status() {
        cost();
        return self::OP_CLEAR;
    }

    private function op_clear() {
        clear();
        return self::OP_CLEAR;
    }

    private function op_cmd() {
        echo $this->cmd, PHP_EOL;
        return self::OP_CLEAR;
    }

    private function op_history() {
        foreach(new \SplFileObject($this->hisf) as $i => $line) {
            $this->console->info($i + 1);
            echo str_repeat(" ", max(0, 5 - strlen($i))), $line;
        }
        echo PHP_EOL;
        return self::OP_CLEAR;
    }

    private function op_env() {
        if($this->env) {
            foreach(array_values($this->env) as $i => $code) {
                $this->console->info($i + 1);
                echo str_repeat(" ", max(0, 5 - strlen($i)));
                echo str_replace(PHP_EOL, " ", $code), PHP_EOL;
            }
        }
        return self::OP_CLEAR;
    }

    private function op_color() {
        $this->console->toggleColor();
        return self::OP_CLEAR;
    }
}

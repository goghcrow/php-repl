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
 * fixme 加入各种f*函数的错误检测
 */
class Repl {
    const OP_IGNORE         = 0;        // 接受当前行输入，无操作
    const OP_CONTINUE       = 1;        // 接受当前行输入，继续等待输入
    const OP_BREAK          = 2;        // 接受当前行输入，执行命令
    const OP_PASS           = 3;        // 忽略当前行

    public static $color    = false;
    public static $debug    = true;

    private static $phptag  = "<?php\n";

    private $hisf;              // 历史文件: 记录输入历史
    private $hisfd;             // 历史文件句柄
    private $envf;              // 环境文件: 用文件内php代码来模拟repl环境
    private $envfd;             // 环境文件句柄
    private $prompt = "";       // 当前提示符
    private $command = "";      // 当前未执行命令
    private $outaccu = "";      // 累计输出,增量替换,可以换个靠谱的方式提高性能
    private $op_array = [];     // 内置命令数组
    private $console;

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

        $this->envf = tempnam(sys_get_temp_dir(), "#php_repl_env");
        $this->hisf = tempnam(sys_get_temp_dir(), "#php_repl_history");
        if(self::$debug) {
            $this->envf = "#php_repl_env.php";
            $this->hisf = "#php_repl_history.php";
        }
        $this->envfd = fopen($this->envf, "w");
        $this->hisfd = fopen($this->hisf, "a");

        // 不能加读锁，否则后面fwrite都写不了
        // flock($this->envfd, LOCK_SH | LOCK_NB);
        // 不能用排它锁，why?
        // flock($this->envfd, LOCK_EX | LOCK_NB); // no block win 无效

        // 清空文件写入标签
        // ftruncate($this->envfd, 0);
        // rewind($this->envfd);
        fwrite($this->envfd, self::$phptag);

        // 注册内置命令
        $this->op_array[":q"]           = [$this, "op_exit"];           // 结束命令 !q
        $this->op_array[":exit"]        = [$this, "op_exit"];           // 结束命令 !exit
        $this->op_array[":help"]        = [$this, "op_help"];           // 帮助 !help
        $this->op_array[":cancel"]      = [$this, "op_cancel"];         // 取消当前命令 !cancel
        $this->op_array[":c"]           = [$this, "op_cancel"];         // 取消当前命令 !c
        $this->op_array[":clearenv"]    = [$this, "op_clearenv"];       // 清空环境 !clear
        $this->op_array[":clear"]       = [$this, "op_clear"];          // 清屏 !clear
        $this->op_array[":status"]      = [$this, "op_status"];         // 状态 !status

        // fixme 修改成正则匹配来支持!debug:xxxx
        $this->op_array[":debug"] = [$this, "op_debug"];        // !debug::要查看的属性 [仅限debug模式]
    }

    private function clear() {
        $this->command = "";
        $this->outaccu = "";
        fflush($this->envfd);
        // flock($this->envfd, LOCK_UN);                // 5.3.2 文件资源句柄关闭时不再自动解锁
        fclose($this->envfd);
        fflush($this->hisfd);
        fclose($this->hisfd);
        if(!self::$debug) {
            unlink($this->envf);
        }
    }

    // php需要加入环境变量
    private function execute() {
        // ob_start();
        // $stdout = system("php $envFile"); 或者 $stdout = fgets(popen("php $envFile", "r"));
        // 均无法检测文件是否执行错误错误
        // $stdout = trim(ob_get_clean(), PHP_EOL);
        // 检索源码发现php中运行外部程序的函数,实际上都是使用了popen函数
        // http://lxr.php.net/xref/PHP_7_0/ext/standard/exec.h
        // http://xiezhenye.com/2012/09/php-中运行外部程序的一个潜在风险.html
        // 利用vfork 来启动一个shell子进程来执行命令。
        // http://coolshell.cn/articles/12103.html
        // 但是popen并没有在子进程中关闭原有的进程的文件描述符。
        // 这样子进程也会占有这些文件描述符，即使它们并不需要，如果子进程长时间运行，还会导致这些资源没法释放
        // so 最终采取proc_open方式定制执行
        // http://php.net/manual/zh/function.proc-open.php
        $descriptorspec  = [
            /*stdin*/ /* 0 => ["pipe", "r"],*/
            /*stdout*/   1 => ["pipe", "w"],
            /*stderr*/   2 => ["pipe", "w"],
            /*others .... */
        ];
        // "bypass_shell" in Windows allows you to pass a command of length around ~32767 characters.
        // If you do not use it, your limit is around ~8191 characters only.
        // See https://support.microsoft.com/en-us/kb/830473.
        $other_options = ["suppress_errors" => true, /*"bypass_shell" => true,*/];
        $evalProcess = proc_open("php {$this->envf}", $descriptorspec, $pipes, null, null, $other_options);
        $stdout = stream_get_contents($pipes[1]);
        $strerr = stream_get_contents($pipes[2]);
        // [!!!] 必须先关闭pipe再关闭子进程
        foreach($pipes as $pipe) fclose($pipe);
        proc_close($evalProcess);

        return [$stdout, $strerr];
    }

    // fixme 用 exit die 之类正则来结束, 否则把exit写入环境就bug了
    private function op_exit() {
        return self::OP_BREAK;
    }

    private function op_help() {
        echo implode(PHP_EOL, array_keys($this->op_array)) . PHP_EOL;
        return self::OP_PASS;
    }

    private function op_clearenv() {
        ftruncate($this->envfd, strlen(self::$phptag));
        fseek($this->envfd, strlen(self::$phptag), SEEK_SET);
        $this->command = "";
        $this->outaccu = "";
        return self::OP_CONTINUE;
    }

    private function op_cancel() {
        $this->command = "";
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

    private function op_debug($line) {
        $prop = substr($line, strlen(":debug::"));
        if(property_exists($this, $prop)) {
            echo PHP_EOL, print_r($this->$prop, true), PHP_EOL;
        }
        return self::OP_PASS;
    }

    private function op_execute($line) {
        if(self::$debug && 0 === strncasecmp($line, ":debug", strlen(":debug"))) {
            return $this->op_debug($line);
        }
        foreach($this->op_array as $op_code => $op) {
            if(cmdcmp($op_code, $line)) {
                return $op($line);
            }
        }
        return self::OP_IGNORE;
    }

    public function run() {
        $this->init();

        while(true) {
            if($this->command) {
                $this->prompt = str_pad($this->console->log(">", true) . " ", strlen($this->prompt), " ", STR_PAD_LEFT);
            } else {
                $this->prompt = $this->console->log("php>", true) . " ";
            }

            $line = readline($this->prompt);            // 读取用户输入
            $his = sprintf("%s # %s" . PHP_EOL, date("Y-m-d h:i:s"), $line);
            fwrite($this->hisfd, $his);
            $this->command .= $line . PHP_EOL;          // 追加到命令
            switch($this->op_execute($line)) {          // 检测执行内置命令
                case self::OP_BREAK:
                    break 2;
                case self::OP_CONTINUE;
                    continue 2;
                case self::OP_PASS:
                    $this->command = substr($this->command,
                        0, strlen($this->command) - strlen($line . PHP_EOL));
                    break;
                case self::OP_IGNORE:
                    break;
            }

            if(!endwith($line, ";")) {                  // 以分号作为求值标记
                continue;                               // 直到遇到以分号结果的命令
            }                                           // 否则全部追加到command
            $pos = ftell($this->envfd);                 // 记录执行前文件指针位置
            fwrite($this->envfd, $this->command);       // 写入命令道环境执行
            list($stdout, $strerr) = $this->execute();  // execute

            if($strerr) {
                ftruncate($this->envfd, $pos);          // 执行失败退回文件指针
                fseek($this->envfd, -strlen($this->command), SEEK_CUR);
                $this->console->error($strerr);
            } else {
                $result = $stdout;
                if($this->outaccu) {                    // 清除已输出内容后打印结果
                    $result = substr($stdout, strlen($this->outaccu));
                }
                echo $result . PHP_EOL;
                // $this->console->log($result . PHP_EOL);
                $this->outaccu .= $result;              // 增量记录结果
            }
            $this->command = "";                        // 无论成功与否都清空命令
        }

        $this->clear();                                 // 关闭句柄删除文件等清理工作
    }
}
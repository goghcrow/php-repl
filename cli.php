<?php
namespace xiaofeng\cli;
error_reporting(E_ALL);

/**
 * @return int
 * @author xiaofeng
 */
function is_cli() {
    return 0 === strncasecmp(php_sapi_name(), "cli", 3);
}

function is_win() {
    return PHP_OS === "WINNT";
}

function tasklist() {
    if(!is_win()) {
        throw new \RuntimeException("only support win");
    }
    return array_map('str_getcsv', explode("\n", trim(`tasklist /FO csv /NH`)));
}

function clear() {
    if(is_win()) {
        echo str_repeat(PHP_EOL, 100);
    } else {
        system("clear");
    }
}

if(!function_exists("readline")) {
    function readline($prompt = "") {
        echo $prompt;
        return stream_get_line(STDIN, 1024, PHP_EOL);
    }
}

function cmdcmp($cmd, $line) {
    return 0 === strncasecmp($line, $cmd, max(strlen($cmd), strlen($line)));
}

function endwith($line, $end) {
    return rtrim($end) === substr(rtrim($line), -strlen(rtrim($end)));
}

/**
 * @param $code
 * @param $code_file
 * @return array
 * @author xiaofeng
 * @notice php需要加入环境变量
 * $code 与 $code_file 二选一
 */
function _execute($code, $code_file = null) {
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
        /*stdin*/    0 => ["pipe", "r"],
        /*stdout*/   1 => ["pipe", "w"],
        /*stderr*/   2 => ["pipe", "w"],
        /*others .... */
    ];
    // "bypass_shell" in Windows allows you to pass a command of length around ~32767 characters.
    // If you do not use it, your limit is around ~8191 characters only.
    // See https://support.microsoft.com/en-us/kb/830473.
    $other_options = ["suppress_errors" => true, /*"bypass_shell" => true,*/];
    $cmd = (!$code && $code_file) ? "php {$code_file}" : "php";
    $evalProcess = proc_open($cmd, $descriptorspec, $pipes, null, null, $other_options);
    if($code) {
        fwrite($pipes[0], "<?php " . $code);
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    // 必须先关闭pipe再关闭子进程
    foreach($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($evalProcess);
    return [$stdout, $stderr];
}

function exec_code($code) {
    return _execute($code);
}

function exec_file($php_file) {
    return _execute(null, $php_file);
}

/**
 * 排它锁锁定文件并处理
 * @param $file
 * @param string $mode
 * @param callable $handler
 * @param string $err
 * @return bool 只代表拿到锁并且进行处理,不表示是否处理成功
 * @author xiaofeng
 * 对程序读写都要在handler中处理
 */
function flockhandle($file, $mode = "w", callable $handler, &$err = "") {
    $f = fopen($file, $mode);
    if($f === false) {
        $err = "fopen($file, $mode) === false";
        return false;
    }

    $locked = flock($f, LOCK_EX | LOCK_NB);
    if($locked) {
        try {
            $handler($f);
        } catch (\Exception $e) {
            $err = '$handler($f) exception' . $e->getMessage();
        } finally {
            fflush($f);
            flock($f, LOCK_UN);
            fclose($f);
        }
        return true;
    } else {
        $err = "flock($f, LOCK_EX | LOCK_NB) fail";
        fclose($f);
        return false;
    }
}

/**
 * 格式化函数生成
 * @param  array  $units 单位由小到大排列
 * @param  int $base 单位之间关系必须一致
 * @return \Closure
 * @author xiaofeng
 */
function formatUnits(array $units, $base) {
    /**
     * @param int $numbers 待格式化数字，以$units[0]为单位
     * @param string $prefix
     * @return string
     * 递归闭包必须以引用方式传递自身
     */
	return $iter = function($numbers, $prefix = "") use($units, $base, &$iter) {
		if($numbers == 0) {
            return ltrim($prefix);
        }
		if($numbers < $base) {
            return ltrim("$prefix {$numbers}{$units[0]}");
        }

		$i = intval(floor(log($numbers, $base)));
		$unit = $units[$i];
		$unitBytes = pow($base, $i); // 1024 可优化为 1 << ($i * 10);
		$n = floor($numbers / $unitBytes);
		return $iter($numbers - $n * $unitBytes, "$prefix $n$unit");
	};
}

function cost(callable $func = null, $n = 1) {
    /**
     * https://en.wikipedia.org/wiki/Units_of_information
     */
    $formatBytes = formatUnits(["Byte", "KB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"], 1024);

    /**
     * https://en.wikipedia.org/wiki/Orders_of_magnitude_(time)
     * second s 1 -> millisecond ms 10^-3 -> microsecond μs 10^-6 -> nanosecond  ns 10^-9 ...
     */
    $formatMillisecond = formatUnits(["us", "ms", "s"], 1000); // μs乱码用us代替

    if($func === null) goto status;

    $start = microtime(true);
    $startMemUsage = memory_get_usage();
	for ($i=0; $i < $n; $i++) $func($i);
	$elapsed = microtime(true) - $start;
    $memUsage = memory_get_peak_usage() - $startMemUsage;

    echo PHP_EOL, str_repeat("=", 60), PHP_EOL;
	echo "Cost Summary:", PHP_EOL;
    echo str_pad("elapsed seconds", 30), $formatMillisecond($elapsed * 1000000), PHP_EOL;
    echo str_pad("memory usage", 30), $formatBytes($memUsage), PHP_EOL;
    status:
    echo str_pad("    emalloc memory", 30), $formatBytes(memory_get_usage()), PHP_EOL;
    echo str_pad("    malloc memory", 30), $formatBytes(memory_get_usage(true)), PHP_EOL;
    echo str_pad("    emalloc peak memory", 30), $formatBytes(memory_get_peak_usage()), PHP_EOL;
    echo str_pad("    malloc peak memory", 30), $formatBytes(memory_get_peak_usage(true)), PHP_EOL;
	echo str_repeat("=", 60), PHP_EOL;
}
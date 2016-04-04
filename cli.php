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
 * 排它锁锁定文件并处理
 * @param $file
 * @param string $mode
 * @param callable $handler
 * @param string $err
 * @return bool 只代表拿到锁并且进行处理,不表示是否处理成功
 * @author xiaofeng
 * fixme 加上sleep 用多个进程进行测试
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
            flock($f, LOCK_UN);
            fclose($f);
        }
        return true;
    } else {
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
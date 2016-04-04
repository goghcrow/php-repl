<?php
namespace xiaofeng\utils;
require __DIR__ . "/Repl.php";

use \xiaofeng\cli;
// system("CMD.EXE /T:F0 /C CLS");

// 如果需要颜色支持，window下需要安装ansicon
$colorfy = isset($argv[1]) ? $argv[1] : false;
$repl = new cli\Repl($colorfy);
$repl->run();

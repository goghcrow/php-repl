<?php
namespace xiaofeng\utils;
use \xiaofeng\cli;
require __DIR__ . "/Repl.php";
error_reporting(E_ALL);

$colorfy = isset($argv[1]);
// $repl = new cli\Repl(cli\Repl::EXEC_EVAL/**/, $colorfy);
$repl = new cli\Repl(cli\Repl::EXEC_PROC, $colorfy);
$repl->run();

<?php
namespace xiaofeng\utils;
use \xiaofeng\cli;
require __DIR__ . "/Repl.php";
error_reporting(E_ALL);

$repl = new cli\Repl(cli\Repl::EXEC_EVAL);
// $repl = new cli\Repl(cli\Repl::EXEC_PROC);
$repl->run();

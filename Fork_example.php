<?php
/**
 * User: xiaofeng
 * Date: 2016/4/11
 * Time: 20:27
 */
namespace xiaofeng\cli;
require_once __DIR__ . DIRECTORY_SEPARATOR . "Fork.php";

error_reporting(E_ALL);
ini_set("error_log", "error.log");

$w = new Fork();
log("start: " . microtime(true));

$i = 0;
$futures = [];
while($i < 10){
    $futures[] = $w->task(function() {
        sleep(5);
        return microtime(true);
    });
    $i++;
}

// echo "future1 pid $future1->pid() \n";

// 自杀
/* @var _Future $f */
$f = $futures[1];
$f->cancel();
$w->wait_all();


/* @var $f _Future */
foreach($futures as $f) {
    $pid = $f->pid();
    log("[$pid]finished: " . $f->get());
}
$w->worker_status();

## 支持window的简易PHP-REPL

~~~ php
namespace xiaofeng\utils;
use \xiaofeng\cli;
require __DIR__ . "/Repl.php";

// 如果需要颜色支持，window下需要安装ansicon
$colorfy = isset($argv[1]) ? $argv[1] : false;
$repl = new cli\Repl($colorfy);
$repl->run();
~~~

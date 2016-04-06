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

命令以;结尾则执行
~~~
php> 1 + 1;
int(2)
php> $x = 1;
int(1)
php> $y = 2;
int(2)
php> echo $x + $y;
3
php> function hello($name) {
   >     echo "hello ", $name;};
php> echo hello("xiaofeng");
hello xiaofeng
~~~

暂时添加了这么多命令, 可自行扩展
~~~
php> :help
:q				退出
:exit			退出
:help			帮助
:cancel			取消当前片段
:c 				取消当前片段
:clearenv 		清楚当前环境
:clear 			清屏
:status			查看状态
:debug 			调试 :debug::envf

php> function() {
   > :c
php> :clear
php> :debug::envf
php> :status
    emalloc memory            442KB 424Byte
    malloc memory             2MB
    emalloc peak memory       470KB 528Byte
    malloc peak memory        2MB
============================================================
~~~

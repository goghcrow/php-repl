## 支持window的简易PHP-REPL

~~~ php
namespace xiaofeng\utils;
use \xiaofeng\cli;
require __DIR__ . "/Repl.php";

// 如果需要颜色支持，window下需要安装ansicon
$repl = new cli\Repl(cli\Repl::EXEC_EVAL);
$repl->run();
~~~

提供了两种命令执行方式：

~~~
cli\Repl::EXEC_EVAL
cli\Repl::EXEC_PROC
~~~

1. eval执行命令，速度快，致命错误(Fatal error)，比如函数重定义会导致程序退出，so，请使用匿名函数
2. proc额外进程执行命令，速度慢，无eval问题


命令以;结尾则执行
~~~
php> 1 + 1;
int(2)
php> $x = 1;
int(1)
php> $y = 2;
int(2)
php> $x + $y;
int(3)
php> $hello = function ($name) {
   >     echo "hello ", $name;};
php> echo $hello("xiaofeng");
hello xiaofeng
~~~

暂时添加了这么多命令
~~~
php> :help
:help     help
:q        quit
:exit     alias for q
:quit     alias for q
:c        cancel state of input
:cancel   alias for c
:env      show env
:cmd      show cmd
:reset    clear env
:clear    clear
:status   show status
:color    toggle color

php> function() {
   > :c
php> :clear
php> :status
    emalloc memory            442KB 424Byte
    malloc memory             2MB
    emalloc peak memory       470KB 528Byte
    malloc peak memory        2MB
============================================================
~~~

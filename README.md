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


命令可选以;结尾则执行

提示：输入**:c**结束当前命令

~~~
php> 1 + 1
int(2)
php> $x = 1
int(1)
php> $y = 2
int(2)
php> $x + $y
int(3)
php> $hello = function ($name) {
   >     return "hello " . $name;
   > }
object(Closure)#4 (1) {
  ["parameter"]=>
  array(1) {
    ["$name"]=>
    string(10) "<required>"
  }
}
php> $hello("xiaofeng")
string(14) "hello xiaofeng"
~~~


命令(以:开头输入)：

1. 暂时添加了这么多命令
2. 忽略大小写
3. 格式 :cmd[:args]

~~~
php> :help
:help     help
:q        quit
:exit     alias for q
:quit     alias for q
:c        cancel state of input
:cancel   alias for c
:cmd      show cmd
:history  show history
:env      show env
:reset    clear env
:save     save env :save:file
:load     load env :load:file
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


TODO:

1. fixme eval 函数重定义导致eval方式执行退出 // 尝试以 get_defined_functions();
2. fix bug: new XXXXXXXXXXXX block
3. 尝试以token_get_all()方式用户输入

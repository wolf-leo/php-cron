<?php
/**
 * 用户在自己的项目中创建此文件
 *
 *   <?php
 *   require __DIR__ . '/vendor/autoload.php';
 *
 *   PhpCron\Scheduler::run(function (PhpCron\Scheduler $s) {
 *       $s->call(fn() => file_get_contents('https://api.x.com/xxx'))->second(3)->name('api');
 *       $s->call(fn() => doCleanup())->minute(5)->name('cleanup');
 *       $s->command('df -h')->hourly()->name('disk');
 *   });
 *
 * 用法:
 *   php xxx.php         启动（后台运行）
 *   php xxx.php status  查看任务列表
 *   php xxx.php stop    停止
 */

require __DIR__ . '/vendor/autoload.php';

PhpCron\Scheduler::run(function (PhpCron\Scheduler $s) {
    $s->call(function () {
        echo "Task runs every 3 seconds\n";
    })->second(3)->name('demo-3s');
});

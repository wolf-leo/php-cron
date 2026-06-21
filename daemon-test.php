<?php

use PhpCron\Timezone;
use PhpCron\Scheduler;

require __DIR__ . '/vendor/autoload.php';

Scheduler::run(function(Scheduler $s) {
    $s->call(function() {
        $time = date('Y-m-d H:i:s');
        file_put_contents(__DIR__ . '/storage/ping.log', "[$time] ping\n", FILE_APPEND);
    })->second(3)->name('ping');
}, Timezone::ASIA_SHANGHAI);
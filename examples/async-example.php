#!/usr/bin/env php
<?php

if (PHP_SAPI != 'cli') {
    die("Restricted\n");
}

require(__DIR__ . '/../vendor/autoload.php');

$t = (new \sigalx\Daemonic\AsyncOperation(function () {
    $n = 1;
    while ($n <= 3) {
        echo "foo {$n}\n";
        sleep(1);
        $n++;
    }
    return 0;
}))
    ->onSuccess(function () {
        echo "bar\n";
    })
    ->onException(function () {
        echo "yeah\n";
    })
    ->run();

while (!$t->isFinished(true)) {
    sleep(1);
}

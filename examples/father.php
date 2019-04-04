#!/usr/bin/env php
<?php

if (PHP_SAPI != 'cli') {
    die("Restricted\n");
}

$options = getopt('', [
    'daemon:',
    'config:',
    'filename:',
]);

require(__DIR__ . '/../vendor/autoload.php');

$helloBye = true;
$daemonName = \sigalx\Daemonic\Daemons\GodFatherDaemon::class;
if (!empty($options['daemon'])) {
    $helloBye = false;
    $daemonName = $options['daemon'];
}

if ($helloBye) {
    echo "Hello\n";
}

if (!empty($options['filename'])) {
    /** @noinspection PhpIncludeInspection */
    require_once($options['filename']);
}

$configFile = null;
$daemonInit = null;
if (!empty($options['config'])) {
    $configFile = $options['config'];
    if ($configFile[0] != '/') {
        $configFile = getcwd() . "/{$configFile}";
    }
}
if (!$configFile) {
    $configFile = __DIR__ . '/daemon-config.php';
}

/** @noinspection PhpIncludeInspection */
$daemonConfig = include($configFile);
$daemonInit = $daemonConfig[$daemonName] ?? null;

/** @var \sigalx\Daemonic\Daemons\AbstractDaemon $daemon */
$daemon = new $daemonName;
$daemon
    ->setFatherScriptPath(__FILE__)
    ->setPhpInterpreter(PHP_BINARY);

if ($daemon instanceof \sigalx\Daemonic\Daemons\GodFatherDaemon && $configFile) {
    $daemon->setConfigFile($configFile);
}

if ($daemonInit) {
    $fs = [];
    if (is_callable($daemonInit)) {
        $fs[] = $daemonInit;
    } elseif (is_array($daemonInit)) {
        $fs = $daemonInit;
    } else {
        echo "Invalid type of daemon config init key\n";
        exit(1);
    }
    foreach ($fs as $f) {
        $f($daemon);
    }
}

$daemon->run();

if ($helloBye) {
    echo "Bye\n";
}

<?php

return [
    \sigalx\Daemonic\Daemons\GodFatherDaemon::class => [
        'init' => function (\sigalx\Daemonic\Daemons\GodFatherDaemon $daemon): \sigalx\Daemonic\Daemons\GodFatherDaemon {
            return ($daemon
                ->registerChildDaemon(ExampleDaemon::class, 1, __DIR__ . '/ExampleDaemon.php')
            );
        },
    ],
    ExampleDaemon::class => [
        'init' => function (ExampleDaemon $daemon): ExampleDaemon {
            $daemon
                ->setTalkAbout('Daemons in PHP');
            return $daemon;
        },
    ],

];

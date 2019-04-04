# Daemonic PHP #

Simple and powerful tools to create daemons for your own PHP-application.

Create your own long-running PHP daemon processes by extending the GodFatherDaemon class. Use a crontab to make your daemons undying. Build servers using libraries like Socket and LibEvent or create background services or choose true parallel processing in PHP with persistent background workers.

> Note: For many reasons PHP is not an optimal language choice for creating servers or daemons. I created this library so if you *must* use PHP for these things, you can do it with ease and produce great results. But if you have the choice, C++/C#, Java, NodeJS, etc, may be better suited for this. 

#### Requires: ####
* PHP 7.1 (due to using type hints)
* A POSIX compatible operating system (Linux, OSX, BSD)
* POSIX and PCNTL Extensions for PHP

#### Example: ####

Just run in CLI:
> ./examples/father.php

Or place in crontab:
> \* * * * * /path-to-your-dir/examples/cron

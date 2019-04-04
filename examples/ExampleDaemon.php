<?php

include_once(__DIR__ . '/../src/Daemons/AbstractDaemon.php');

class ExampleDaemon extends \sigalx\Daemonic\Daemons\AbstractDaemon
{
    /** @var string */
    protected $_talkAbout;

    protected function _init(): bool
    {
        // you must call the parent initializer when override it by your own
        parent::_init();

        // let's talk about every five seconds (sleep time between every working loop)
        $this->setSleepSeconds(5);

        // answering that init completes successfully
        return true;
    }

    /*
     * main function - endlessly called in main working loop
     * with getting some sleep (if returns false) or without it (if true)
     */
    protected function _work(): bool
    {
        $this->_talk("Let's talk about... {$this->_talkAbout}"); // ...about Daemons. Daemons in PHP.

        // to get some sleep before next call
        return false;
    }

    // define setter for setting value in a config file
    public function setTalkAbout(string $talkAbout): ExampleDaemon
    {
        $this->_talkAbout = $talkAbout;
        return $this;
    }

}
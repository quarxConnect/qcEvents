<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;

  final class ProcessTest extends TestCase {
    function testPing () {
      $eventBase = Events\Base::singleton ();
      $eventProcess = new Events\Process ($eventBase);
      
      $processPromise = $eventProcess->spawnCommand ('ping', [ '-c', '2', 'google.com' ]);
      $processOutput = 0;
      
      $eventProcess->addHook (
        'eventReadable',
        function () use ($eventProcess, &$processOutput) {
          if (($stdOut = $eventProcess->read ()) === false)
            return;
          
          $processOutput += strlen ($stdOut);
        }
      );
      
      $exitCode = Events\Synchronizer::do (
        $eventBase,
        $processPromise
      );
      
      $this->assertIsInt ($exitCode);
      $this->assertGreaterThan (
        64,
        $processOutput
      );
    }
  }
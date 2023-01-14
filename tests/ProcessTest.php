<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;

  final class ProcessTest extends TestCase {
    function testPing () {
      $eventBase = Events\Base::singleton ();
      $eventProcess = new Events\Process ($eventBase);
      
      $processPromise = $eventProcess->spawnCommand ('sh', [ __DIR__ . '/ProcessTest.sh' ]);
      $processOutput = 0;
      
      $eventProcess->addHook (
        'eventReadable',
        function () use ($eventProcess, &$processOutput) {
          if (($stdOut = $eventProcess->read ()) === null)
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
        11,
        $processOutput
      );
    }
  }
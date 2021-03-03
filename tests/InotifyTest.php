<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;

  final class InotifyTest extends TestCase {
    function testGithub () {
      $eventBase = Events\Base::singleton ();
      
      $testFile = tempnam (sys_get_temp_dir (), 'InotifyTest');
      $this->assertFileIsWritable ($testFile);
      
      $inotify = new Events\inotify ($eventBase, $testFile, Events\inotify::MASK_MODIFY);
      
      $eventPromise = $inotify->once ('fileModify')->then (
        function () {
          return true;
        }
      );
      
      $f = fopen ($testFile, 'w');
      fwrite ($f, 'testtest');
      fclose ($f);
      
      $this->assertTrue (
        Events\Synchronizer::do (
          $eventBase,
          Events\Promise::race ([
            $eventPromise,
            $eventBase->addTimeout (1)->then (
              function () {
                throw new Exception ('Event was not raised within time');
              }
            )
          ])
        )
      );
      
      @unlink ($testFile);
    }
  }

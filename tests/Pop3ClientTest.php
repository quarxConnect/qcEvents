<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;

  final class Pop3ClientTest extends TestCase {
    function testWithEnvironment () : void {
      if (!isset ($_ENV ['POP3_SERVER']) ||
          !isset ($_ENV ['POP3_USERNAME']) ||
          !isset ($_ENV ['POP3_PASSWORD'])) {
        $this->markTestSkipped ('Environment-Variables POP3_SERVER, POP3_USERNAME and/or POP3_PASSWORD missing');
        
        return;
      }
        
      $eventBase = Events\Base::singleton ();
      $popClient = new Events\Client\POP3 ($eventBase);
      
      $this->assertTrue (
        Events\Synchronizer::do (
          $eventBase,
          $popClient->connect ($_ENV ['POP3_SERVER'], $_ENV ['POP3_PORT'] ?? null, $_ENV ['POP3_USERNAME'], $_ENV ['POP3_PASSWORD'])
        )
      );
    }
  }
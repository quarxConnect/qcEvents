<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;

  final class HttpClientTest extends TestCase {
    function testGithub () {
      $eventBase = Events\Base::singleton ();
      $httpClient = new Events\Client\HTTP ($eventBase);

      $httpResult = Events\Synchronizer::do (
        $eventBase,
        $httpClient->request ('https://www.github.com')
      );
      
      $this->assertIsString ($httpResult);
      $this->assertGreaterThan (
        1024,
        strlen ($httpResult)
      );
    }
  }
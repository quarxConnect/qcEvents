<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;

  final class FTPClientTest extends TestCase {
    public function testWithEnvironment () : ?Events\Client\FTP {
      if (!isset ($_ENV ['FTP_SERVER']) ||
          !isset ($_ENV ['FTP_USERNAME']) ||
          !isset ($_ENV ['FTP_PASSWORD'])) {
        $this->markTestSkipped ('Environment-Variables FTP_SERVER, FTP_USERNAME and/or FTP_PASSWORD missing');
        
        return null;
      }
        
      $eventBase = Events\Base::singleton ();
      $ftpClient = new Events\Client\FTP ($eventBase);
      
      $this->assertTrue (
        Events\Synchronizer::do (
          $eventBase,
          $ftpClient->connect ($_ENV ['FTP_SERVER'], $_ENV ['FTP_USERNAME'], $_ENV ['FTP_PASSWORD'], $_ENV ['FTP_ACCOUNT'] ?? null, $_ENV ['POP3_PORT'] ?? null)
        )
      );
      
      return $ftpClient;
    }
    
    /**
     * @depends testWithEnvironment
     **/
    public function testListFiles (Events\Client\FTP $ftpClient) : void {
      $listResult = Events\Synchronizer::do (
        $ftpClient->getEventBase (),
        $ftpClient->getFilenames ()
      );
      
      $this->assertIsArray ($listResult);
      $this->assertGreaterThan (0, count ($listResult));
    }
  }
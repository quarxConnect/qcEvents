<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;

  final class SSHClientTest extends TestCase {
    public function testConnection () : Events\Stream\SSH {
      if (!isset ($_ENV ['SSH_SERVER'])) {
        $this->markTestSkipped ('Environment-Variable SSH_SERVER missing');
        
        throw new Exception ('Skipped');
      }
      
      $eventBase = Events\Base::singleton ();
      $sshSocket = new Events\Socket ($eventBase);
      
      $sshClient = Events\Synchronizer::do (
        $eventBase,
        $sshSocket->connect (
          $_ENV ['SSH_SERVER'],
          (int)($_ENV ['SSH_PORT'] ?? 22),
          $sshSocket::TYPE_TCP
        )->then (
          function () use ($sshSocket) {
            $sshClient = new Events\Stream\SSH ();
            
            return $sshSocket->pipeStream ($sshClient)->then (
              function () use ($sshClient) {
                return $sshClient;
              }
            );
          }
        )
      );
      
      $this->assertIsObject ($sshClient);
      
      return $sshClient;
    }
    
    /**
     * @depends testConnection
     **/
    public function testAuthentication (Events\Stream\SSH $sshClient) : Events\Stream\SSH {
      if (!isset ($_ENV ['SSH_USERNAME'])) {
        $this->markTestSkipped ('Environment-Variable SSH_USERNAME missing');
        
        throw new Exception ('Skipped');
      }
      
      $this->assertFalse (
        Events\Synchronizer::do (
          $sshClient->getStream ()->getEventBase (),
          $sshClient->authPassword ($_ENV ['SSH_USERNAME'], md5 ($_ENV ['SSH_PASSWORD'] ?? (string)time ()))->then (
            function () {
              return true;
            },
            function () {
              return false;
            }
          )
        )
      );
      
      if (isset ($_ENV ['SSH_PASSWORD']))
        $this->assertTrue (
          Events\Synchronizer::do (
            $sshClient->getStream ()->getEventBase (),
            $sshClient->authPassword ($_ENV ['SSH_USERNAME'], $_ENV ['SSH_PASSWORD'])->then (
              function () {
                return true;
              }
            )
          )
        );
      
      return $sshClient;
    }
    
    /**
     * @depends testAuthentication
     **/
    public function testStartCommand (Events\Stream\SSH $sshClient) : void {
      $this->assertEquals (
        1024 * 1024 * 10,
        Events\Synchronizer::do (
          $sshClient->getStream ()->getEventBase (),
          $sshClient->startCommand (
            '/system/bin/dd if=/system/dev/zero bs=1M count=10'
          )->then (
            function (Events\Stream\SSH\Channel $sshChannel) {
              $bytesRead = 0;
              
              $sshChannel->addHook (
                'eventReadable',
                function ($sshChannel) use (&$bytesRead) {
                  $bytesRead += strlen ($sshChannel->read ());
                }
              );
              
              return $sshChannel->once ('eventClosed')->then (
                function () use (&$bytesRead) {
                  return $bytesRead;
                }
              );
            }
          )
        )
      );
    }
  }

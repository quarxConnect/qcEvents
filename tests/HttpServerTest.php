<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;

  final class HttpServerTest extends TestCase {
    public function testServerCreate () : Events\Socket\Server {
      // Create Socket-Server
      $eventBase = Events\Base::singleton ();
      $httpServerPool = new Events\Socket\Server ($eventBase);
      
      // Set HTTP-Server-Class as handler
      $this->assertNull (
        $httpServerPool->setChildClass (
          Events\Server\HTTP::class,
          true
        )
      );
      
      // Open listening socket
      $this->assertNull (
        $httpServerPool->listen ($httpServerPool::TYPE_TCP)
      );
      
      return $httpServerPool;
    }
    
    /**
     * @depends testServerCreate
     **/
    public function testServerConnection (Events\Socket\Server $httpServerPool) : void {
      // Create callback for incoming request
      $httpServerPool->addChildHook (
        'httpdRequestReceived',
        function (Events\Server\HTTP $httpServer, Events\Stream\HTTP\Request $httpRequest, string $requestBody = null) {
          // Create a response
          $httpResponse = new Events\Stream\HTTP\Header ([
            'HTTP/' . $httpRequest->getVersion (true) . ' 200 Ok',
            'Server: quarxConnect httpd/0.1',
            'Content-Type: text/plain'
          ]);
          
          // Push back the response
          $httpServer->httpdSetResponse ($httpRequest, $httpResponse, 'Well done! :-)');
        }
      );
      
      // Create a new HTTP-Request to the server and wait for it to finish
      $httpClient = new Events\Client\HTTP (Events\Base::singleton ());
      
      $httpResult = Events\Synchronizer::do (
        Events\Base::singleton (),
        $httpClient->request (
          'http://127.0.0.1:' . $httpServerPool->getLocalPort () . '/'
        )
      );
      
      $this->assertIsString ($httpResult);
      $this->assertEquals (
        'Well done! :-)',
        $httpResult
      );
    }
  }

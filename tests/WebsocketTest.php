<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;

  final class WebsocketTest extends TestCase {
    public function testWebsockets () : void {
      // Create HTTP-Server
      $eventBase = Events\Base::singleton ();
      $httpServerPool = new Events\Socket\Server ($eventBase);
      $httpServerPool->setChildClass (Events\Server\HTTP::class, true);
      $httpServerPool->listen ($httpServerPool::TYPE_TCP);
      
      $httpServerPool->addChildHook (
        'httpdRequestReceived',
        function (Events\Server\HTTP $httpServer, Events\Stream\HTTP\Request $httpRequest, string $requestBody = null) {
          // Extract the URI from request
          $requestURI = $httpRequest->getURI ();
          
          $this->assertEquals (
            '/websocket',
            $requestURI
          );
          
          // Try to upgrade to websocket
          return $httpServer->upgradeToWebsocket ($httpRequest)->then (
            function (Events\Stream\Websocket $websocketServer) {
              // Wait for incoming messages
              $websocketServer->addHook (
                'websocketMessage',
                function (Events\Stream\Websocket $websocketServer, Events\Stream\Websocket\Message $websocketMessage) {
                  // Ignore non-text-messages
                  if ($websocketMessage->getOpcode () != $websocketServer::OPCODE_TEXT)
                    return;
                  
                  // Push message back
                  $websocketServer->sendMessage (
                    new Events\Stream\Websocket\Message (
                      $websocketServer,
                      $websocketMessage->getOpcode (),
                      'PONG: ' . $websocketMessage->getData ()
                    )
                  );
                }
              );
            },
            function (Throwable $error) use ($httpServer, $httpRequest) {
              return $httpServer->httpdSetResponse (
                $httpRequest,
                new Events\Stream\HTTP\Header ([
                  'HTTP/' . $httpRequest->getVersion (true) . ' 500 Internal Server Error',
                ]),
                strval ($error)
              );
            }
          );
        }
      );
      
      // Try to open websocket to our server
      $serverAddress = $httpServerPool->getLocalName ();
      $portSeparator = strrpos ($serverAddress, ':');
      
      $this->assertGreaterThan (
        0,
        $portSeparator
      );
      
      $clientSocket = new Events\Socket (Events\Base::singleton ());
      
      $websocketResult = Events\Synchronizer::do (
        Events\Base::singleton (),
        $clientSocket->connect (
          substr ($serverAddress, 0, $portSeparator),
          (int)substr ($serverAddress, $portSeparator + 1),
          $clientSocket::TYPE_TCP
        )->then (
          function () use ($clientSocket, $serverAddress) {
            $websocketClient = new Events\Stream\Websocket (Events\Stream\Websocket::TYPE_CLIENT, null, '/websocket', 'http://' . $serverAddress);
            
            return $clientSocket->pipeStream ($websocketClient)->then (
              function () use ($websocketClient) {
                return $websocketClient;
              }
            );
          }
        )->then (
          function (Events\Stream\Websocket $websocketClient) {
            $websocketClient->sendMessage (
              new Events\Stream\Websocket\Message (
                $websocketClient,
                Events\Stream\Websocket::OPCODE_TEXT,
                'Websocket-Test'
              )
            );
            
            return $websocketClient->once ('websocketMessage');
          }
        )
      );
      
      $this->assertInstanceOf (
        Events\Stream\Websocket\Message::class,
        $websocketResult
      );
      
      $this->assertEquals (
        Events\Stream\Websocket::OPCODE_TEXT,
        $websocketResult->getOpcode ()
      );
      
      $this->assertEquals (
        'PONG: Websocket-Test',
        $websocketResult->getData ()
      );
    }
  }

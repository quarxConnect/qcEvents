<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;

  final class CompressedStreamTest extends TestCase {
    public function testCreateStream () : Events\Stream\Compressed {
      $compressedStream = new Events\Stream\Compressed ();
      
      $this->assertIsObject ($compressedStream);
      
      return $compressedStream;
    }
    
    /**
     * @depends testCreateStream
     **/
    public function testPipeSource (Events\Stream\Compressed $compressedStream) : array {
      $eventBase = Events\Base::singleton ();
      $virtualSource = new Events\Virtual\Source ($eventBase);
      
      $virtualSource->pipe ($compressedStream);
      
      $this->assertTrue (true);
      
      return [ $virtualSource, $compressedStream ];
    }
    
    /**
     * @depends testPipeSource
     **/
    public function testDecompressDeflate (array $pipeEndpoints) : void {
      $virtualSource = $pipeEndpoints [0];
      $compressedStream = $pipeEndpoints [1];
      
      // Wait for events
      $readablePromise = $compressedStream->once ('eventReadable')->then (
        function () use ($compressedStream) {
          return $compressedStream->read ();
        }
      );
      
      $closedPromise = $compressedStream->once ('eventClosed')->then (
        function () {
          throw new Exception ('Stream closed before read');
        }
      );
      
      // Insert compressed data
      $uncompressedData = random_bytes (16);
      
      $virtualSource->sourceInsert (gzcompress ($uncompressedData, -1, ZLIB_ENCODING_DEFLATE));
      
      $uncompressResult = Events\Synchronizer::do (
         $virtualSource->getEventBase (),
         Events\Promise::race ([
           $readablePromise,
           $closedPromise
         ])
      );
      
      $this->assertEquals (
        $uncompressedData,
        $uncompressResult
      );
    }
  }

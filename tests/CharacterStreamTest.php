<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;

  final class CharacterStreamTest extends TestCase {
    public function testCreateStream () : Events\Stream\Character {
      $characterStream = new Events\Stream\Character ();
      
      $this->assertIsObject ($characterStream);
      
      return $characterStream;
    }
    
    /**
     * @depends testCreateStream
     **/
    public function testPipeSource (Events\Stream\Character $characterStream) : array {
      $eventBase = Events\Base::singleton ();
      $virtualSource = new Events\Virtual\Source ($eventBase);
      
      $virtualSource->pipe ($characterStream);
      
      $this->assertTrue (true);
      
      return [ $virtualSource, $characterStream ];
    }
    
    /**
     * @depends testPipeSource
     **/
    public function testDetectBOM (array $pipeEndpoints) : array {
      $virtualSource = $pipeEndpoints [0];
      $characterStream = $pipeEndpoints [1];
      
      // Wait for the event
      $detectedPromise = $characterStream->once ('charactersetDetected');
      
      // Insert UTF-32-BE BOM
      $virtualSource->sourceInsert ("\x00\x00\xFE\xFF");
      
      $detectedCharaterset = Events\Synchronizer::do (
        $virtualSource->getEventBase (),
        $detectedPromise
      );
      
      $this->assertEquals (
        'UTF-32BE',
        $detectedCharaterset
      );
      
      return $pipeEndpoints;
    }
    
    /**
     * @depends testDetectBOM
     **/
    public function testConverter (array $pipeEndpoints) : void {
      $virtualSource = $pipeEndpoints [0];
      $characterStream = $pipeEndpoints [1];
      
      // Wait for the converter to be readable
      $readablePromise = $characterStream->once ('eventReadable')->then (
        function () use ($characterStream) {
          return $characterStream->read ();
        }
      );
      
      // Insert UTF-32-BE Data
      $virtualSource->sourceInsert ("\x00\x00\x00\x68\x00\x00\x00\x65\x00\x00\x00\x6c\x00\x00\x00\x6c\x00\x00\x00\x6f\x00\x00\x00\x20\x00\x00\x00\x77\x00\x00\x00\x6f\x00\x00\x00\x72\x00\x00\x00\x6c\x00\x00\x00\x64\x00\x00\x00\x20\x00\x00\x00\xe4\x00\x00\x00\xfc\x00\x00\x00\xf6");
      
      $convertedData = Events\Synchronizer::do (
        $virtualSource->getEventBase (),
        $readablePromise
      );

      $this->assertEquals (
        'hello world äüö',
        $convertedData
      );
    }
  }

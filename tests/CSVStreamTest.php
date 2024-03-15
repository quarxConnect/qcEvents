<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;

  final class CSVStreamTest extends TestCase {
    public function testCreateStream () : Events\Stream\CSV {
      $csvStream = new Events\Stream\CSV ();
      
      $this->assertIsObject ($csvStream);
      
      return $csvStream;
    }
    
    /**
     * @depends testCreateStream
     **/
    public function testPipeSource (Events\Stream\CSV $csvStream) : array {
      $eventBase = Events\Base::singleton ();
      $virtualSource = new Events\Virtual\Source ($eventBase);
      
      $virtualSource->pipe ($csvStream);
      
      $this->assertTrue (true);
      
      return [ $virtualSource, $csvStream ];
    }
    
    /**
     * @depends testPipeSource
     **/
    public function testCSVParser (array $pipeEndpoints): void
    {
      $virtualSource = $pipeEndpoints [0];
      $csvStream = $pipeEndpoints [1];

      $headerPromise = $csvStream->addEventPromise (Events\Stream\CSV\Event\Header::class);
      $recordPromise = $csvStream->addEventPromise (Events\Stream\CSV\Event\Record::class);

      $virtualSource->sourceInsert ('Column 1,"Column 2","Column,3"' . "\r\n");
      $virtualSource->sourceInsert ('hello,world;,"	tab white,comma"' . "\r\n");

      $csvHeader = Events\Synchronizer::do (
        $virtualSource->getEventBase (),
        $headerPromise
      );

      $this->assertCount (3, $csvHeader);
      $this->assertEquals (
        [
          'Column 1',
          'Column 2',
          'Column,3',
        ],
        iterator_to_array ($csvHeader)
      );

      $csvRecord = Events\Synchronizer::do (
        $virtualSource->getEventBase (),
        $recordPromise
      );

      $this->assertCount (3, $csvRecord);
      $this->assertEquals (
        [
          'Column 1' => 'hello',
          'Column 2' => 'world;',
          'Column,3' => '	tab white,comma',
        ],
        iterator_to_array ($csvRecord)
      );
    }
  }
<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;

  final class TimerTest extends TestCase {
    private const TOLERANCE = 0.03;
    
    /**
     * @dataProvider timerParameters
     **/
    public function testTimeParameters ($parameter, $expectedTime) : void {
      $eventBase = Events\Base::singleton ();
      
      // Create the timer
      $startTime = microtime (true);
      $timer = $eventBase->addTimeout ($parameter);
      
      // Wait ...
      Events\Synchronizer::do (
        $eventBase,
        $timer
      );
      
      $stopTime = microtime (true);
      $duration = $stopTime - $startTime;
      
      $this->assertLessThan (
        $expectedTime * self::TOLERANCE,
        abs ($duration - $expectedTime)
      );
    }
    
    public function timerParameters () : array {
      return [
        [ 1, 1 ],
        [ [ 1, 500000 ], 1.5 ],
        [ 1.5, 1.5 ],
      ];
    }
    
    public function testRepeatedTimer () : void {
      $doIterations = 3;
      $timerInterval = 0.1;
      
      $eventBase = Events\Base::singleton ();
      $timer = $eventBase->addTimeout ($timerInterval, true);
      $defered = new Events\Promise\Defered ();
      $startTime = microtime (true);
      $counter = 0;
      
      $timer->then (
        function () use (&$counter, $timer, $defered, $doIterations) {
          if (++$counter < $doIterations)
            return;
          
          if ($counter > $doIterations)
            $this->assertTrue (false);
          
          $timer->cancel ();
          $defered->resolve ();
        }
      );
      
      Events\Synchronizer::do (
        $eventBase,
        $defered->getPromise ()
      );
      
      $stopTime = microtime (true);
      $duration = $stopTime - $startTime;
      $expectedTime = $doIterations * $timerInterval;
      
      $this->assertEquals (
        $doIterations,
        $counter
      );
      
      $this->assertLessThan (
        $expectedTime * self::TOLERANCE,
        abs ($duration - $expectedTime)
      );
    }
    
    public function testTimerCancel () : void {
      $timerInterval = 0.2;
      
      $eventBase = Events\Base::singleton ();
      $timer1 = $eventBase->addTimeout ($timerInterval);
      $timer2 = $eventBase->addTimeout ($timerInterval / 2);
      
      $timer2->then (
        function () use ($timer1) {
          $timer1->cancel ();
        }
      );
      
      $this->expectException (\Exception::class);
      
      Events\Synchronizer::do (
        $eventBase,
        $timer1
      );
    }
    
    public function testTimerCancelOnRepeat () : void {
      $timerInterval = 0.1;
      $doIterations = 2;
      
      $eventBase = Events\Base::singleton ();
      $timer = $eventBase->addTimeout ($timerInterval, true);
      $defered = new Events\Promise\Defered ();
      $startTime = microtime (true);
      $counter = 0;
      
      $timer->then (
        function () use (&$counter, $doIterations, $eventBase, $timerInterval, $timer, $defered) {
          if (++$counter < $doIterations)
            return;
          
          $eventBase->addTimeout ($timerInterval / 2)->then (
            function () use ($timer, $defered) {
              $timer->cancel ();
              $defered->resolve ();
            }
          );
        }
      );
      
      Events\Synchronizer::do (
        $eventBase,
        $defered->getPromise ()
      );
      
      $stopTime = microtime (true);
      $duration = $stopTime - $startTime;
      $expectedTime = $timerInterval * ($doIterations + 0.5);
      
      $this->assertEquals (
        $doIterations,
        $counter
      );
      
      $this->assertLessThan (
        $expectedTime * self::TOLERANCE,
        abs ($duration - $expectedTime)
      );
    }
  }

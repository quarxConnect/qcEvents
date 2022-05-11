<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;
  
  final class PromiseTest extends TestCase {
    public function testSimpleFullfillment () : void {
      $eventBase = Events\Base::singleton ();
      $eventPromise = new Events\Promise (
        function (callable $resolveFunction) {
          $resolveFunction (true);
        }
      );
      
      $this->assertTrue (
        Events\Synchronizer::do (
          $eventBase,
          $eventPromise
        )
      );
      
      unset ($eventPromise);
      gc_collect_cycles ();
    }
    
    public function testFullfillmentWithValue () : void {
      $eventBase = Events\Base::singleton ();
      $eventPromise = new Events\Promise (
        function (callable $resolveFunction) {
          $resolveFunction (42);
        }
      );

      $this->assertEquals (
        42,
        Events\Synchronizer::do (
          $eventBase,
          $eventPromise
        )
      );

      unset ($eventPromise);
      gc_collect_cycles ();
    }
    
    public function testFullfillmentWithManyValues () : void {
      $eventBase = Events\Base::singleton ();
      $eventPromise = new Events\Promise (
        function (callable $resolveFunction) {
          $resolveFunction (42, 23, 19);
        }
      );
      
      $promiseResult = Events\Synchronizer::doAsArray (
        $eventBase,
        $eventPromise
      );
      
      $this->assertCount (
        3,
        $promiseResult
      );
      
      $this->assertEquals (
        42,
        $promiseResult [0]
      );
      
      $this->assertEquals (
        23,
        $promiseResult [1]
      );
      
      $this->assertEquals (
        19,
        $promiseResult [2]
      );
      
      unset ($eventPromise);
      gc_collect_cycles ();
    }
    
    public function testPassedFullfillment () : void {
      $eventBase = Events\Base::singleton ();
      $eventPromise = new Events\Promise (
        function (callable $resolveFunction, callable $rejectFunction) {
          $resolveFunction (42);
        }
      );
      
      $eventPromise = $eventPromise->catch (
        function (Throwable $error) {
          throw $error;
        }
      );
      
      $this->assertEquals (
        42,
        Events\Synchronizer::do (
          $eventBase,
          $eventPromise
        )
      );

      unset ($eventPromise);
      gc_collect_cycles ();
    }
    
    public function testChainedFullfillment () : void {
      $eventBase = Events\Base::singleton ();
      $eventPromise = new Events\Promise (
        function (callable $resolveFunction, callable $rejectFunction) {
          $resolveFunction (1);
        }
      );
      
      $eventPromise = $eventPromise->then (
        function ($v) {
          $this->assertEquals (1, $v);
          
          return 2;
        }
      )->then (
        function ($v) {
          $this->assertEquals (2, $v);
          
          return 3;
        }
      );
      
      $promiseResult = Events\Synchronizer::do (
        $eventBase,
        $eventPromise
      );
      
      $this->assertEquals (3, $promiseResult);
      
      unset ($eventPromise);
      gc_collect_cycles ();
    }
    
    public function testSimpleRejection () : void {
      $eventBase = Events\Base::singleton ();
      $eventPromise = new Events\Promise (
        function (callable $resolveFunction, callable $rejectFunction) {
          $rejectFunction ();
        }
      );
      
      $this->expectException (\Exception::class);
      
      Events\Synchronizer::do (
        $eventBase,
        $eventPromise
      );
    }
    
    public function testRejectionWithValue () : void {
      $eventBase = Events\Base::singleton ();
      $eventPromise = new Events\Promise (
        function (callable $resolveFunction, callable $rejectFunction) {
          $rejectFunction (23);
        }
      );
      
      try {
        Events\Synchronizer::do (
          $eventBase,
          $eventPromise
        );
        
        $this->assertTrue (false);
      } catch (\Exception $rejection) {
        $this->assertEquals (
          23,
          $rejection->getMessage ()
        );
      }
      
      unset ($eventPromise);
      gc_collect_cycles ();
    }
    
    public function testRejectionWithManyValues () : void {
      $eventBase = Events\Base::singleton ();
      $eventPromise = new Events\Promise (
        function (callable $resolveFunction, callable $rejectFunction) {
          $rejectFunction (23, 19, 42);
        }
      );
      
      // We cannot catch multiple arguments via synchronizer
      $rejectionResult = null;
      $eventPromise = $eventPromise->catch (
        function () use (&$rejectionResult) {
          $rejectionResult = func_get_args ();
          
          throw new Events\Promise\Solution ($rejectionResult);
        }
      );
      
      try {
        Events\Synchronizer::do (
          $eventBase,
          $eventPromise
        );
        
        $this->assertTrue (false);
      } catch (\Exception $rejection) {
        $this->assertIsArray ($rejectionResult);
        $this->assertCount (
          3,
          $rejectionResult
        );
        
        $this->assertEquals (
          23,
          $rejectionResult [0]->getMessage ()
        );
        
        $this->assertEquals (
          19,
          $rejectionResult [1]
        );
        
        $this->assertEquals (
          42,
          $rejectionResult [2]
        );
      }

      unset ($eventPromise);
      gc_collect_cycles ();
    }
    
    public function testRejectionOnChain () : void {
      $eventBase = Events\Base::singleton ();
      $eventPromise = new Events\Promise (
        function (callable $resolveFunction) {
          $resolveFunction ();
        }
      );
      
      $eventPromise = $eventPromise->then (
        function () {
          throw new exception ('Rejection on chain');
        }
      );
      
      $this->expectException (\Exception::class);
      
      Events\Synchronizer::do (
        $eventBase,
        $eventPromise
      );
    }
    
    public function testPassedRejection () : void {
      $eventBase = Events\Base::singleton ();
      $eventPromise = new Events\Promise (
        function (callable $resolveFunction, callable $rejectFunction) {
          $rejectFunction ('Rejected');
        }
      );
      
      $eventPromise = $eventPromise->then (
        function () {
          return 99;
        }
      );
      
      $this->expectException (\Exception::class);

      Events\Synchronizer::do (
        $eventBase,
        $eventPromise
      );
    }
    
    public function testUnhandledException () : void {
      if (!defined ('QCEVENTS_THROW_UNHANDLED_REJECTIONS'))
        define ('QCEVENTS_THROW_UNHANDLED_REJECTIONS', true);
      else
        $this->assertTrue (QCEVENTS_THROW_UNHANDLED_REJECTIONS);
      
      $rejectedPromise = new Events\Promise (
        function (callable $resolveFunction, callable $rejectFunction) {
          $rejectFunction ('REJECTED');
        }
      );
      
      $this->expectException (\Exception::class);
      
      unset ($rejectedPromise);
      gc_collect_cycles ();
    }
    
    public function testMultipleExceptions () : void {
      $eventBase = Events\Base::singleton ();
      
      $eventPromise = new Events\Promise (
        function (callable $resolveFunction, callable $rejectFunction) {
          $rejectFunction ('REJECTED');
        },
        $eventBase
      );
      
      $firstFullfillment = false;
      $firstRejection = false;
      $secondRejection = false;
      
      Events\Synchronizer::do (
        $eventBase,
        $eventPromise->then (
          function () use (&$firstFullfillment) {
            $firstFullfillment = true;
          },
          function (\Throwable $error) use (&$firstRejection) {
            $firstRejection = true;
            throw $error;
          }
        )->catch (
          function (\Throwable $error) use (&$secondRejection) {
            $secondRejection = true;
          }
        )
      );
      
      $this->assertFalse ($firstFullfillment);
      $this->assertTrue ($firstRejection);
      $this->assertTrue ($secondRejection);
    }
    
    public function testAll () : void {
      $eventBase = Events\Base::singleton ();
      
      $allResults = Events\Synchronizer::do (
        $eventBase,
        Events\Promise::all ([
          Events\Promise::resolve (42),
          'x' => Events\Promise::resolve (19, 23, 42),
        ])
      );
      
      $this->assertCount (2, $allResults);
      
      $this->assertTrue (isset ($allResults [0]));
      $this->assertEquals (42, $allResults [0]);
      
      $this->assertTrue (isset ($allResults ['x']));
      $this->assertEquals ([ 19, 23, 42 ], $allResults ['x']);
      
      $this->expectException (\Exception::class);
      
      Events\Synchronizer::do (
        $eventBase,
        Events\Promise::all ([
          Events\Promise::resolve (42),
          Events\Promise::reject ('No, no, no'),
        ])
      );
    }
    
    public function testAllSettled () : void {
      $eventBase = Events\Base::singleton ();
      
      $settledPromises = Events\Synchronizer::do (
        $eventBase,
        Events\Promise::allSettled ([
          Events\Promise::resolve (42),
          Events\Promise::reject ('Rejected'),
        ])
      );
      
      $this->assertCount (2, $settledPromises);
      
      $this->assertEquals ('fulfilled', $settledPromises [0]->status);
      $this->assertEquals (42, $settledPromises [0]->value);
      
      $this->assertEquals ('rejected', $settledPromises [1]->status);
      $this->assertInstanceOf (\Exception::class, $settledPromises [1]->reason);
    }
  }

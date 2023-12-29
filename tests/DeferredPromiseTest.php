<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;
  
  final class DeferredPromiseTest extends TestCase {
    public function testSimpleFulfillment () : void {
      $eventBase = Events\Base::singleton ();
      $deferredPromise = new Events\Promise\Deferred ();
      $deferredPromise->resolve (true);
      
      $this->assertTrue (
        Events\Synchronizer::do (
          $eventBase,
          $deferredPromise->getPromise ()
        )
      );
      
      unset ($deferredPromise);
      gc_collect_cycles ();
    }
    
    public function testFulfillmentWithValue () : void {
      $eventBase = Events\Base::singleton ();
      $deferredPromise = new Events\Promise\Deferred ();
      $deferredPromise->resolve (42);

      $this->assertEquals (
        42,
        Events\Synchronizer::do (
          $eventBase,
          $deferredPromise->getPromise ()
        )
      );

      unset ($deferredPromise);
      gc_collect_cycles ();
    }
    
    public function testFulfillmentWithManyValues () : void {
      $eventBase = Events\Base::singleton ();
      $deferredPromise = new Events\Promise\Deferred ();
      $deferredPromise->resolve (42, 23, 19);
      
      $promiseResult = Events\Synchronizer::doAsArray (
        $eventBase,
        $deferredPromise->getPromise ()
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
      
      unset ($deferredPromise);
      gc_collect_cycles ();
    }
    
    public function testSimpleRejection () : void {
      $eventBase = Events\Base::singleton ();
      $deferredPromise = new Events\Promise\Deferred ();
      $deferredPromise->reject ();
      
      $this->expectException (\Exception::class);
      
      Events\Synchronizer::do (
        $eventBase,
        $deferredPromise->getPromise ()
      );
    }
    
    public function testRejectionWithValue () : void {
      $eventBase = Events\Base::singleton ();
      $deferredPromise = new Events\Promise\Deferred ();
      $deferredPromise->reject (23);
      
      try {
        Events\Synchronizer::do (
          $eventBase,
          $deferredPromise->getPromise ()
        );
        
        $this->assertTrue (false);
      } catch (\Exception $rejection) {
        $this->assertEquals (
          23,
          $rejection->getMessage ()
        );
      }
      
      unset ($deferredPromise);
      gc_collect_cycles ();
    }
    
    public function testRejectionWithManyValues () : void {
      $eventBase = Events\Base::singleton ();
      $deferredPromise = new Events\Promise\Deferred ();
      $deferredPromise->reject (23, 19, 42);
      
      // We cannot catch multiple arguments via synchronizer
      $rejectionResult = null;
      $eventPromise = $deferredPromise->getPromise ()->catch (
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

      unset ($eventPromise, $deferredPromise);
      gc_collect_cycles ();
    }
  }

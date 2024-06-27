<?php

  /**
   * quarxConnect Events - Promise
   * Copyright (C) 2018-2021 Bernd Holzmueller <bernd@quarxconnect.de>
   * 
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   * 
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   * GNU General Public License for more details.
   * 
   * You should have received a copy of the GNU General Public License
   * along with this program. If not, see <http://www.gnu.org/licenses/>.
   **/
  
  declare (strict_types=1);
  
  namespace quarxConnect\Events;

  use ArrayIterator;
  use Iterator;
  use IteratorAggregate;
  use Throwable;
  
  class Promise {
    /* Assigned event-base */
    private $eventBase = null;
    
    /* Status-Flags for this promise */
    protected const STATUS_PENDING   = 0x00;
    protected const STATUS_FULFILLED = 0x01;
    protected const STATUS_REJECTED  = 0x02;
    
    private const STATUS_FORWARDED  = 0x80;
    
    private $promiseStatus = Promise::STATUS_PENDING;
    
    /* Result-data of this promise */
    private $result = null;
    
    /* Registered callbacks */
    private $callbacks = [
      Promise::STATUS_FULFILLED => [ ],
      Promise::STATUS_REJECTED  => [ ],
    ];
    
    /* Reset callbacks after use */
    protected $resetCallbacks = true;
    
    // {{{ resolve
    /**
     * Create a resolved promise
     * 
     * @param ...
    b * @param Base $Base (optional)
     * 
     * @access public
     * @return Promise
     **/
    public static function resolve (): Promise
    {
      $resolveParameters = func_get_args ();
      
      if ((count ($resolveParameters) > 0) && ($resolveParameters [count ($resolveParameters) - 1] instanceof Base))
        $eventBase = array_pop ($resolveParameters);
      else
        $eventBase = null;
      
      return new Promise (
        function (callable $resolveFunction) use ($resolveParameters) {
          call_user_func_array ($resolveFunction, $resolveParameters);
        },
        $eventBase
      );
    }
    // }}}
    
    // {{{ reject
    /**
     * Create a rejected promise
     * 
     * @param ...
     * @param Base $Base (optional)
     * 
     * @access public
     * @return Promise
     **/
    public static function reject (): Promise
    {
      $rejectParameter = func_get_args ();
      
      if ((count ($rejectParameter) > 0) && ($rejectParameter [count ($rejectParameter) - 1] instanceof Base))
        $eventBase = array_pop ($rejectParameter);
      else
        $eventBase = null;
      
      return new Promise (
        function (callable $resolveFunction, callable $rejectFunction) use ($rejectParameter) {
          call_user_func_array ($rejectFunction, $rejectParameter);
        },
        $eventBase
      );
    }
    // }}}
    
    // {{{ all
    /**
     * Create a promise that settles when a whole set of promises have settled
     * 
     * @param Iterable $promiseValues
     * @param Base $eventBase (optional) Defer execution of callbacks using this event-base
     * 
     * @access public
     * @return Promise
     **/
    public static function all (Iterable $promiseValues, Base $eventBase = null): Promise
    {
      // Pre-Filter the promises
      $realPromises = [ ];
      $resultValues = [ ];
      
      foreach ($promiseValues as $promiseIndex=>$promiseValue)
        if ($promiseValue instanceof Promise) {
          $realPromises [$promiseIndex] = $promiseValue;
          $resultValues [$promiseIndex] = null;
          
          if (!$eventBase)
            $eventBase = $promiseValue->eventBase;
        } else
          $resultValues [$promiseIndex] = $promiseValue;
      
      // Check if there is any promise to wait for
      if (count ($realPromises) == 0) {
        if ($eventBase)
          return static::resolve ($resultValues, $eventBase);
        
        return static::resolve ($resultValues);
      }
      
      return new Promise (
        function (callable $resolveFunction, callable $rejectFunction)
        use ($resultValues, $realPromises) {
          // Track if the promise is settled
          $promiseDone = false;
          $promisePending = count ($realPromises);
          
          // Register handlers
          foreach ($realPromises as $promiseIndex=>$realPromise)
            $realPromise->then (
              function () use (&$promiseDone, &$resultValues, &$promisePending, $promiseIndex, $resolveFunction) {
                // Check if the promise is already settled
                if ($promiseDone)
                  return;
                
                // Remember the result
                $resultValues [$promiseIndex] = func_get_args ();
                
                // Check if we are done
                if ($promisePending-- > 1)
                  return;
                
                // Mark the promise as settled
                $promiseDone = true;
                
                // Forward the result
                $promiseResult = [ ];
                
                foreach ($resultValues as $resultIndex=>$currentResults)
                  if (count ($currentResults) == 1)
                    $promiseResult [$resultIndex] = array_shift ($currentResults);
                  else
                    $promiseResult [$resultIndex] = $currentResults;
                
                call_user_func ($resolveFunction, $promiseResult);
              },
              function () use (&$promiseDone, $rejectFunction) {
                // Check if the promise is settled
                if ($promiseDone)
                  return;
                
                // Mark the promise as settled
                $promiseDone = true;
                
                // Forward the result
                call_user_func_array ($rejectFunction, func_get_args ());
              }
            );
        },
        $eventBase
      );
    }
    // }}}
    
    // {{{ allSettled
    /**
     * Create a promise that settles if all given promises have settled as well
     * 
     * @param Iterable $promiseValues
     * @param Base $eventBase (optional) Defer execution of callbacks using this event-base
     * 
     * @access public
     * @return Promise
     **/
    public static function allSettled (Iterable $promiseValues, Base $eventBase = null): Promise
    {
      // Pre-Filter the promises
      $realPromises = [ ];
      $resultValues = [ ];
      
      foreach ($promiseValues as $promiseIndex=>$promiseValue) {
        $resultValues [$promiseIndex] = new \stdClass;
        
        if ($promiseValue instanceof Promise) {
          $realPromises [$promiseIndex] = $promiseValue;
          $resultValues [$promiseIndex]->status = 'pending';
          
          if (!$eventBase)
            $eventBase = $promiseValue->eventBase;
        } elseif ($promiseValue instanceof Throwable) {
          $resultValues [$promiseIndex]->status = 'rejected';
          $resultValues [$promiseIndex]->reason = $promiseValue;
          $resultValues [$promiseIndex]->args = [ $promiseValue ];
        } else {
          $resultValues [$promiseIndex]->status = 'fulfilled';
          $resultValues [$promiseIndex]->value = $promiseValue;
          $resultValues [$promiseIndex]->args = [ $promiseValue ];
        }
      }
      
      // Check if there is any promise to wait for
      if (count ($realPromises) == 0) {
        if ($eventBase)
          return static::resolve ($resultValues, $eventBase);
       
        return static::resolve ($resultValues);
      }
      
      return new Promise (
        function (callable $resolveFunction)
        use ($realPromises, $resultValues) {
          // Track if the promise is settled
          $promisePending = count ($realPromises);
          
          // Register handlers
          foreach ($realPromises as $promiseIndex=>$realPromise)
            $realPromise->then (
              function ($resultValue)
              use (&$resultValues, &$promisePending, $promiseIndex, $resolveFunction) {
                // Push to results
                $resultValues [$promiseIndex]->status = 'fulfilled';
                $resultValues [$promiseIndex]->value = $resultValue;
                $resultValues [$promiseIndex]->args = func_get_args (); // NON-STANDARD
                
                // Check if we are done
                if ($promisePending-- > 1)
                  return;
                
                // Forward the result
                call_user_func ($resolveFunction, $resultValues);
              },
              function (Throwable $errorReason)
              use (&$resultValues, &$promisePending, $promiseIndex, $resolveFunction) {
                // Push to results
                $resultValues [$promiseIndex]->status = 'rejected';
                $resultValues [$promiseIndex]->reason = $errorReason;
                $resultValues [$promiseIndex]->args = func_get_args (); // NON-STANDARD
                
                // Check if we are done
                if ($promisePending-- > 1)
                  return;
                
                // Forward the result
                call_user_func ($resolveFunction, $resultValues);
              }
            );
        },
        $eventBase
      );
    }
    // }}}
    
    // {{{ any
    /**
     * Create a promise that settles whenever another promise of a given set settles as well and only reject if all promises were rejected
     * 
     * @param Iterable $watchPromises
     * @param Base $eventBase (optional)
     * @param bool $forceSpec (optional) Enforce behavior along specification, don't fulfill the promise if there are no promises given
     * 
     * @access public
     * @return Promise
     **/
    public static function any (Iterable $watchPromises, Base $eventBase = null, $forceSpec = false): Promise
    {
      // Check for non-promises first
      $promiseCountdown = 0;
      
      foreach ($watchPromises as $watchPromise)
        if (!($watchPromise instanceof Promise)) {
          if ($eventBase)
            return static::resolve ($watchPromise, $eventBase);
          
          return static::resolve ($watchPromise);
        } else
          $promiseCountdown++;
      
      // Check if there is any promise to wait for
      if (!$forceSpec && ($promiseCountdown == 0)) {
        if ($eventBase)
          return static::reject (new \Error ('No promise in Promise::any was resolved'), [ ], $eventBase);
        
        return static::reject (new \Error ('No promise in Promise::any was resolved'), [ ]);
      }
      
      return new Promise (
        function (callable $resolveFunction, callable $rejectFunction)
        use ($watchPromises, $promiseCountdown) {
          // Track if the promise is settled
          $promiseDone = false;
          $promiseRejections = [ ];
          
          // Register handlers
          foreach ($watchPromises as $watchPromise)
            $watchPromise->then (
              function () use (&$promiseDone, $resolveFunction) {
                // Check if the promise is already settled
                if ($promiseDone)
                  return;
                
                // Mark the promise as settled
                $promiseDone = true;
                
                // Forward the result
                call_user_func_array ($resolveFunction, func_get_args ());
              },
              function (Throwable $promiseRejection) use (&$promiseDone, &$promiseCountdown, &$promiseRejections, $rejectFunction) {
                // Collect the rejection
                $promiseRejections [] = $promiseRejection;
                
                // Check if the promise is settled
                if ($promiseDone)
                  return;
                
                // Check if we ignore rejections until one was fulfilled
                if (--$promiseCountdown > 0)
                  return;
                
                // Mark the promise as settled
                $promiseDone = true;
                
                // Forward the result
                call_user_func ($rejectFunction, new \Error ('No promise in Promise::any was resolved'), $promiseRejections);
              }
            );
        },
        $eventBase
      );
    }
    // }}}

    // {{{ race
    /**
     * Create a promise that settles whenever another promise of a given set settles as well
     * 
     * @param Iterable $watchPromises
     * @param Base $eventBase (optional)
     * @param bool $ignoreRejections (optional) NON-STANDARD DEPRECATED Ignore rejections as long as one promise fulfills
     * @param bool $forceSpec (optional) Enforce behavior along specification, don't fulfill the promise if there are no promises given
     * 
     * @access public
     * @return Promise
     **/
    public static function race (Iterable $watchPromises, Base $eventBase = null, $ignoreRejections = false, $forceSpec = false): Promise
    {
      // Check for non-promises first
      $promiseCount = 0;
      
      foreach ($watchPromises as $Promise)
        if (!($Promise instanceof Promise)) {
          if ($eventBase)
            return static::resolve ($Promise, $eventBase);
          
          return static::resolve ($Promise);
        } else
          $promiseCount++;
      
      // Check if there is any promise to wait for
      # TODO: This is a violation of the Spec, but a promise that is forever pending is not suitable for long-running applications
      if (!$forceSpec && ($promiseCount == 0)) {
        if ($eventBase)
          return static::reject ($eventBase);
        
        return static::reject ();
      }
      
      // Prepare to deprecate this flag
      if ($ignoreRejections)
        trigger_error ('Please use Promise::any() instead of Promise::race() if you want to ignore rejections', E_USER_DEPRECATED);
      
      return new Promise (
        function (callable $resolveFunction, callable $rejectFunction)
        use ($watchPromises, $ignoreRejections, $promiseCount) {
          // Track if the promise is settled
          $Done = false;
          
          // Register handlers
          foreach ($watchPromises as $Promise)
            $Promise->then (
              function () use (&$Done, $resolveFunction) {
                // Check if the promise is already settled
                if ($Done)
                  return;
                
                // Mark the promise as settled
                $Done = true;
                
                // Forward the result
                call_user_func_array ($resolveFunction, func_get_args ());
              },
              function () use (&$Done, &$promiseCount, $rejectFunction, $ignoreRejections) {
                // Check if the promise is settled
                if ($Done)
                  return;
                
                // Check if we ignore rejections until one was fulfilled
                if ($ignoreRejections && (--$promiseCount > 0))
                  return;
                
                // Mark the promise as settled
                $Done = true;
                
                // Forward the result
                call_user_func_array ($rejectFunction, func_get_args ());
              }
            );
        },
        $eventBase
      );
    }
    // }}}
    
    // {{{ walk
    /**
     * NON-STANDARD: Walk an array with a callable
     *
     * @param iterable $walkArray
     * @param callable $itemCallback
     * @param bool $justSettle (optional) Don't stop on rejections, but enqueue them as result
     * @param Base $eventBase (optional)
     *
     * @access public
     * @return Promise
     **/
    public static function walk (iterable $walkArray, callable $itemCallback, bool $justSettle = false, Base $eventBase = null): Promise
    {
      // Make sure we have an iterator-instance
      if (is_array ($walkArray))
        $arrayIterator = new ArrayIterator ($walkArray);
      elseif ($walkArray instanceof IteratorAggregate)
        $arrayIterator = $walkArray->getIterator ();
      elseif ($walkArray instanceof Iterator)
        $arrayIterator = $walkArray;

      // Make sure we have an event-base
      if (!$eventBase)
        $eventBase = Base::singleton ();

      // Move to start
      $arrayIterator->rewind ();

      return new Promise (
        function (callable $resolveFunction, callable $rejectFunction)
        use ($arrayIterator, $itemCallback, $justSettle, $eventBase): void
        {
          $walkResults = [ ];
          $walkItem = null;
          $walkItem = function () use (&$walkResults, &$walkItem, $arrayIterator, $itemCallback, $justSettle, $eventBase, $resolveFunction, $rejectFunction): void {
            // Check whether to stop
            if (!$arrayIterator->valid ()) {
              $resolveFunction ($walkResults);

              return;
            }
            
            // Invoke the callback
            $itemKey = $arrayIterator->key ();

            try {
              $itemResult = $itemCallback ($arrayIterator->current ());
              
              if (!($itemResult instanceof Promise))
                $itemResult = Promise::resolve ($itemResult, $eventBase);
            } catch (Throwable $callbackException) {
              $itemResult = Promise::reject ($callbackException, $eventBase);
            }
            
            // Move to next element
            $arrayIterator->next ();
            
            // Process the result
            $itemResult->catch (
              function () use ($justSettle, $rejectFunction): Promise\Solution {
                // Process the rejection as a result if requested
                if ($justSettle)
                  return new Promise\Solution (func_get_args ());
                
                // Or forward the rejection to our initial promise
                call_user_func_array ($rejectFunction, func_get_args ());

                /**
                 * Throw an exception just to leave the processing-loop,
                 * this exception won't be ever seen on the public
                 **/ 
                throw new \Exception ('Stopped by rejection');
              }
            )->then (
              function () use ($itemKey, &$walkResults, &$walkItem): void {
                // Push the result
                $walkResults [$itemKey] = (func_num_args () == 1 ? func_get_arg (0) : func_get_args ());

                // Process the next array-item
                $walkItem ();
              },
              function () {
                // Mute any rejection the gets here
              }
            );
          };
          
          // Process first array-item
          $walkItem ();
        },
        $eventBase
      );
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create a new promise
     * 
     * @param callable $initCallback (optional)
     * @param Base $eventBase (optional)
     + 
     * @access friendly
     * @return void
     **/
    public function __construct (callable $initCallback = null, Base $eventBase = null)
    {
      // Store the assigned base
      $this->eventBase = $eventBase;
      
      // Check whether to invoke the callback
      if ($initCallback === null)
        return;
      
      // Invoke/Enqueue the init-callback
      $invokeCallback = function () use ($initCallback) {
        try {
          call_user_func (
            $initCallback,
            function () {
              $this->finish ($this::STATUS_FULFILLED, func_get_args ());
            },
            function () {
              $this->finish ($this::STATUS_REJECTED,   func_get_args ());
            }
          );
        } catch (Throwable $errorException) {
          $this->finish ($this::STATUS_REJECTED, ($errorException instanceof Promise\Solution ? $errorException->getParameters () : [ $errorException ]));
        }
      };
      
      if ($eventBase)
        $eventBase->forceCallback ($invokeCallback);
      else
        $invokeCallback ();
    }
    // }}}
    
    // {{{ __destruct
    /**
     * Check if this promise was handled after a rejection
     * 
     * @access friendly
     * @return void
     **/
    function __destruct () {
      // Check if this promise was handled
      if ((($this->promiseStatus & 0x0F) != $this::STATUS_REJECTED) || !$this->result || ($this->promiseStatus & self::STATUS_FORWARDED))
        return;
      
      // Push this rejection to log
      if (defined ('EVENTS_THROW_UNHANDLED_REJECTIONS') && EVENTS_THROW_UNHANDLED_REJECTIONS)
        throw $this->result [0];
      
      if (!defined ('EVENTS_LOG_REJECTIONS') || EVENTS_LOG_REJECTIONS)
        error_log ('Unhandled rejection: ' . $this->result [0]);
    }
    // }}}
    
    // {{{ __debugInfo
    /**
     * Return information about this instance to be dumped by var_dump()
     * 
     * @access public
     * @return array
     **/
    public function __debugInfo () : array {
      static $statusMap = [
        self::STATUS_PENDING   => 'pending',
        self::STATUS_FULFILLED => 'fulfilled',
        self::STATUS_REJECTED  => 'rejected',
      ];
      
      return [
        'hasEventBase' => is_object ($this->eventBase),
        'promiseState' => ($statusMap [$this->promiseStatus & 0x0F] ?? 'Unknown (' . ($this->promiseStatus & 0x0F) . ')'),
        'promiseResult' => $this->result,
        'registeredCallbacks' => [
          'fulfill' => count ($this->callbacks [self::STATUS_FULFILLED]),
          'reject'  => count ($this->callbacks [self::STATUS_REJECTED]),
        ],
        'resetCallbacks' => $this->resetCallbacks,
      ];
    }
    // }}}
    
    // {{{ getEventBase
    /**
     * Retrieve the event-base assigned to this promise
     * 
     * @access public
     * @return Base
     **/
    public function getEventBase () : ?Base {
      return $this->eventBase;
    }
    // }}}
    
    // {{{ setEventBase
    /**
     * Assign a new event-base
     * 
     * @param Base $eventBase (optional)
     * @param bool $forwardToChildren (optional)
     * 
     * @access public
     * @return void
     **/
    public function setEventBase (Base $eventBase = null, $forwardToChildren = true) {
      $this->eventBase = $eventBase;
      
      // Forward to child-promises
      if ($forwardToChildren)
        foreach ($this->callbacks as $type=>$callbacks)
          foreach ($callbacks as $callbackData)
            $callbackData [1]->setEventBase ($eventBase, true);
    }
    // }}}
    
    // {{{ getStatus
    /**
     * Retrieve our state
     * 
     * @access protected
     * @return enum
     **/
    protected function getStatus () {
      return ($this->promiseStatus & 0x0F);
    }
    // }}}
    
    // {{{ promiseFulfill
    /**
     * Trigger a fulfillment of this promise
     * 
     * @param ...
     * 
     * @access protected
     * @return void
     **/
    protected function promiseFulfill (): void
    {
      $this->finish ($this::STATUS_FULFILLED, func_get_args ());
    }
    // }}}
    
    // {{{ promiseReject
    /**
     * Trigger a rejection of this promise
     *  
     * @param ...
     * 
     * @access protected
     * @return void
     **/
    protected function promiseReject (): void
    {
      $this->finish ($this::STATUS_REJECTED, func_get_args ());
    }
    // }}}
    
    // {{{ finish
    /**
     * Finish this promise
     * 
     * @param enum $promiseStatus
     * @param array $result
     * 
     * @access private
     * @return void
     **/
    private function finish ($promiseStatus, array $result) {
      // Check if we are already done
      if (($this->promiseStatus & 0x0F) > $this::STATUS_PENDING)
        return;
      
      // Check if there is another promise
      $result = array_values ($result);
      
      if ((count ($result) == 1) && ($result [0] instanceof Promise))
        return $result [0]->then (
          function () { $this->finish ($this::STATUS_FULFILLED, func_get_args ()); },
          function () { $this->finish ($this::STATUS_REJECTED,  func_get_args ()); }
        );
      
      // Make sure first parameter is an exception or error on rejection
      $promiseStatus = ($promiseStatus & 0x0F);
      
      if ($promiseStatus == $this::STATUS_REJECTED) {
        if (count ($result) == 0)
          $result [] = new \exception ('Empty rejection');
        elseif (!($result [0] instanceof \Throwable))
          $result [0] = new \exception ((string)$result [0]);
      }
      
      // Store the result
      $this->promiseStatus = $promiseStatus;
      $this->result = $result;
      
      // Invoke handlers
      if (count ($this->callbacks [$promiseStatus]) > 0)
        foreach ($this->callbacks [$promiseStatus] as $callback)
          $this->invoke ($callback [0], $callback [1]);
      
      // Reset callbacks
      if ($this->resetCallbacks)
        $this->callbacks = [
          Promise::STATUS_FULFILLED => [ ],
          Promise::STATUS_REJECTED  => [ ],
        ];
    }
    // }}}
    
    // {{{ reset
    /**
     * Reset our internal state
     * 
     * @param bool $Deep (optional) Reset child-promises as well
     * 
     * @access protected
     * @return void
     **/
    protected function reset ($Deep = true) {
      // Reset our own state
      $this->promiseStatus = $this::STATUS_PENDING;
      $this->result = null;
      
      // Forward to child-promises
      if ($Deep)
        foreach ($this->callbacks as $type=>$callbacks)
          foreach ($callbacks as $callbackData)
            $callbackData [1]->reset (true);
    }
    // }}}
    
    // {{{ invoke
    /**
     * Invoke a callback for this promise
     * 
     * @param callable $directCallback (optional)
     * @param Promise $childPromise (optional)
     * 
     * @access private
     * @return void
     **/
    private function invoke (callable $directCallback = null, Promise $childPromise = null) {
      // Store that we were called
      if ($directCallback || $childPromise)
        $this->promiseStatus |= self::STATUS_FORWARDED;
      
      // Run the callback
      if ($directCallback) {
        $resultType = $this::STATUS_FULFILLED;
        
        try {
          $resultValues = call_user_func_array ($directCallback, $this->result);
        } catch (\Throwable $errorException) {
          $resultType = $this::STATUS_REJECTED;
          $resultValues = $errorException;
        }
        
        $resultValues = ($resultValues instanceof Promise\Solution ? $resultValues->getParameters () : [ $resultValues ]);
      } else {
        $resultType = ($this->promiseStatus & 0x0F);
        $resultValues = $this->result;
      }
      
      // Quit if there is no child-promise to fulfill
      if (!$childPromise)
        return;
      
      // Finish the child-promise
      $childPromise->finish ($resultType, $resultValues);
    }
    // }}}
    
    // {{{ then
    /**
     * Register callbacks for promise-fulfillment
     * 
     * @param callable $fulfillCallback (optional)
     * @param callable $rejectionCallback (optional)
     * 
     * @access public
     * @return Promise
     **/
    public function then (callable $fulfillCallback = null, callable $rejectionCallback = null) : Promise {
      // Create an empty promise
      $childPromise = new self (null, $this->eventBase);
      
      // Check if we are not already done
      if ((($this->promiseStatus & 0x0F) == $this::STATUS_PENDING) || !$this->resetCallbacks) {
        $this->callbacks [$this::STATUS_FULFILLED][] = [ $fulfillCallback, $childPromise ];
        $this->callbacks [$this::STATUS_REJECTED][]  = [ $rejectionCallback, $childPromise ];
        
        if (($this->promiseStatus & 0x0F) == $this::STATUS_PENDING)
          return $childPromise;
      }
      
      // Check if we were fulfilled
      if (($this->promiseStatus & 0x0F) == $this::STATUS_FULFILLED)
        $this->invoke ($fulfillCallback, $childPromise);
      
      // Check if we were rejected
      elseif (($this->promiseStatus & 0x0F) == $this::STATUS_REJECTED)
        $this->invoke ($rejectionCallback, $childPromise);
      
      // Return a promise
      return $childPromise;
    }
    // }}}
    
    // {{{ catch
    /**
     * Register a callback for exception-handling
     * 
     * @param callable $rejectionCallback
     * 
     * @access public
     * @return Promise
     **/
    public function catch (callable $rejectionCallback) : Promise {
      return $this->then (null, $rejectionCallback);
    }
    // }}}
    
    // {{{ finally
    /**
     * Register a callback that is always fired when the promise has settled
     * 
     * @param callable $finalCallback
     * 
     * @access public
     * @return Promise
     **/
    public function finally (callable $finalCallback) : Promise {
      return $this->then (
        function () use ($finalCallback) {
          // Invoke the callback
          call_user_func ($finalCallback);
          
          // Forward all parameters
          return new Promise\Solution (func_get_args ());
        },
        function () use ($finalCallback) {
          // Invoke the callback
          call_user_func ($finalCallback);
          
          // Forward all parameters
          throw new Promise\Solution (func_get_args ());
        }
      );
    }
    // }}}
  }

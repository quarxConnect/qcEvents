<?PHP

  /**
   * qcEvents - Promise
   * Copyright (C) 2018 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  class qcEvents_Promise {
    /* Assigned event-base */
    private $eventBase = null;
    
    /* Has this promise been done already */
    const DONE_NONE = 0;
    const DONE_FULLFILL = 1;
    const DONE_REJECT = 2;
    
    private $done = qcEvents_Promise::DONE_NONE;
    
    /* Result-data of this promise */
    private $result = null;
    
    /* Registered callbacks */
    private $callbacks = array (
      qcEvents_Promise::DONE_FULLFILL  => array (),
      qcEvents_Promise::DONE_REJECT    => array (),
    );
    
    /* Reset callbacks after use */
    protected $resetCallbacks = true;
    
    // {{{ resolve
    /**
     * Create a resolved promise
     * 
     * @param ...
     * @param qcEvents_Base $Base (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public static function resolve () {
      $args = func_get_args ();
      $base = ((count ($args) > 0) && ($args [count ($args) - 1] instanceof qcEvents_Base) ? array_pop ($args) : null);
      
      return new static (function ($resolve) use ($args) {
        call_user_func_array ($resolve, $args);
      }, $base);
    }
    // }}}
    
    // {{{ reject
    /**
     * Create a rejected promise
     * 
     * @param ...
     * @param qcEvents_Base $Base (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public static function reject () {
      $args = func_get_args ();
      $base = ((count ($args) > 0) && ($args [count ($args) - 1] instanceof qcEvents_Base) ? array_pop ($args) : null);
      
      return new static (function ($resolve, $reject) use ($args) {
        call_user_func_array ($reject, $args);
      }, $base);
    }
    // }}}
    
    // {{{ all
    /**
     * Create a promise that settles when a whole set of promises have settled
     * 
     * @param Iterable $promiseValues
     * @param qcEvents_Base $eventBase (optional) Defer execution of callbacks using this eventbase
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public static function all ($promiseValues, qcEvents_Base $eventBase = null) {
      // Pre-Filter the promises
      $realPromises = array ();
      $resultValues = array ();
      
      foreach ($promiseValues as $promiseIndex=>$promiseValue)
        if ($promiseValue instanceof qcEvents_Promise) {
          $realPromises [$promiseIndex] = $promiseValue;
          $resultValues [$promiseIndex] = null;
          
          if (!$eventBase)
            $eventBase = $promiseValue->eventBase;
        } else
          $resultValues [$promiseIndex] = array ($promiseValue);
      
      // Check if there is any promise to wait for
      if (count ($realPromises) == 0) {
        if ($eventBase)
          return static::resolve ($resultValues, $eventBase);
        
        return static::resolve ($resultValues);
      }
      
      return new static (
        function (callable $resolve, callable $reject)
        use ($resultValues, $realPromises) {
          // Track if the promise is settled
          $promiseDone = false;
          $promisePending = count ($realPromises);
          
          // Register handlers
          foreach ($realPromises as $promiseIndex=>$realPromise)
            $realPromise->then (
              function () use (&$promiseDone, &$resultValues, &$promisePending, $promiseIndex, $resolve) {
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
                $promiseResult = array ();
                
                foreach ($resultValues as $resultIndex=>$currentResults)
                  if ((count ($currentResults) == 1) && !isset ($promiseResult [$resultIndex]))
                    $promiseResult [$resultIndex] = array_shift ($currentResults);
                  else
                    foreach ($currentResults as $currentResult)
                      $promiseResult [] = $currentResult;
                
                call_user_func ($resolve, $promiseResult);
                
                return new qcEvents_Promise_Solution (func_get_args ());
              },
              function () use (&$promiseDone, $reject) {
                // Check if the promise is settled
                if ($promiseDone)
                  return;
                
                // Mark the promise as settled
                $promiseDone = true;
                
                // Forward the result
                call_user_func_array ($reject, func_get_args ());
                
                throw new qcEvents_Promise_Solution (func_get_args ());
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
     * @param qcEvents_Base $eventBase (optional) Defer execution of callbacks using this eventbase
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public static function allSettled ($promiseValues, qcEvents_Base $eventBase = null) {
      // Pre-Filter the promises
      $realPromises = array ();
      $resultValues = array ();
      
      foreach ($promiseValues as $promiseIndex=>$promiseValue) {
        $resultValues [$promiseIndex] = new stdClass;
        
        if ($promiseValue instanceof qcEvents_Promise) {
          $realPromises [$promiseIndex] = $promiseValue;
          $resultValues [$promiseIndex]->status = 'pending';
          
          if (!$eventBase)
            $eventBase = $promiseValue->eventBase;
        } elseif ($promiseValue instanceof Throwable) {
          $resultValues [$promiseIndex]->status = 'rejected';
          $resultValues [$promiseIndex]->reason = $promiseValue;
          $resultValues [$promiseIndex]->args = array ($promiseValue);
        } else {
          $resultValues [$promiseIndex]->status = 'fulfilled';
          $resultValues [$promiseIndex]->value = $promiseValue;
          $resultValues [$promiseIndex]->args = array ($promiseValue);
        }
      }
      
      // Check if there is any promise to wait for
      if (count ($realPromises) == 0) {
        if ($eventBase)
          return static::resolve ($resultValues, $eventBase);
       
        return static::resolve ($resultValues);
      }
      
      return new static (
        function (callable $resolve, callable $reject)
        use ($realPromises, $resultValues) {
          // Track if the promise is settled
          $promisePending = count ($realPromises);
          
          // Register handlers
          foreach ($realPromises as $promiseIndex=>$realPromise)
            $realPromise->then (
              function ($resultValue)
              use (&$resultValues, &$promisePending, $promiseIndex, $resolve) {
                // Push to results
                $resultValues [$promiseIndex]->status = 'fulfilled';
                $resultValues [$promiseIndex]->value = $resultValue;
                $resultValues [$promiseIndex]->args = func_get_args (); // NON-STANDARD
                
                // Check if we are done
                if ($promisePending-- > 1)
                  return;
                
                // Forward the result
                call_user_func ($resolve, $resultValues);
       
                return new qcEvents_Promise_Solution (func_get_args ());
              },
              function (Throwable $errorReason)
              use (&$resultValues, &$promisePending, $promiseIndex, $resolve) {
                // Push to results
                $resultValues [$promiseIndex]->status = 'rejected';
                $resultValues [$promiseIndex]->reason = $errorReason;
                $resultValues [$promiseIndex]->args = func_get_args (); // NON-STANDARD
                
                // Check if we are done
                if ($promisePending-- > 1)
                  return;
                
                // Forward the result
                call_user_func_array ($reject, func_get_args ());
                
                throw new qcEvents_Promise_Solution (func_get_args ());
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
     * @param qcEvents_Base $eventBase (optional)
     * @param bool $forceSpec (optional) Enforce behaviour along specification, don't fullfill the promise if there are no promises given
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public static function any ($watchPromises, qcEvents_Base $eventBase = null, $forceSpec = false) {
      // Check for non-promises first
      $promiseCountdown = 0;
      
      foreach ($watchPromises as $watchPromise)
        if (!($watchPromise instanceof qcEvents_Promise)) {
          if ($eventBase)
            return static::resolve ($watchPromise, $eventBase);
          
          return static::resolve ($watchPromise);
        } else
          $promiseCountdown++;
      
      // Check if there is any promise to wait for
      # TODO: This is a violation of the Spec, but a promise that is forever pending is not suitable for long-running applications
      if (!$forceSpec && ($promiseCountdown == 0)) {
        if ($eventBase)
          return static::reject ($eventBase);
        
        return static::reject ();
      }
      
      return new static (
        function ($resolve, $reject)
        use ($watchPromises, $promiseCountdown) {
          // Track if the promise is settled
          $promiseDone = false;
          
          // Register handlers
          foreach ($watchPromises as $watchPromise)
            $watchPromise->then (
              function () use (&$promiseDone, $resolve) {
                // Check if the promise is already settled
                if ($promiseDone)
                  return;
                
                // Mark the promise as settled
                $promiseDone = true;
                
                // Forward the result
                call_user_func_array ($resolve, func_get_args ());
           
                return new qcEvents_Promise_Solution (func_get_args ());
              },
              function () use (&$promiseDone, &$promiseCountdown, $reject) {
                // Check if the promise is settled
                if ($promiseDone)
                  return;
                
                // Check if we ignore rejections until one was fullfilled
                if (--$promiseCountdown > 0)
                  return;
                
                // Mark the promise as settled
                $promiseDone = true;
                
                // Forward the result
                call_user_func_array ($reject, func_get_args ());
                
                throw new qcEvents_Promise_Solution (func_get_args ());
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
     * @param qcEvents_Base $Base (optional)
     * @param bool $ignoreRejections (optional) NON-STANDARD DEPRECATED Ignore rejections as long as one promise fullfills
     * @param bool $forceSpec (optional) Enforce behaviour along specification, don't fullfill the promise if there are no promises given
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public static function race ($watchPromises, qcEvents_Base $Base = null, $ignoreRejections = false, $forceSpec = false) {
      // Check for non-promises first
      $promiseCount = 0;
      
      foreach ($watchPromises as $Promise)
        if (!($Promise instanceof qcEvents_Promise)) {
          if ($Base)
            return static::resolve ($Promise, $Base);
          
          return static::resolve ($Promise);
        } else
          $promiseCount++;
      
      // Check if there is any promise to wait for
      # TODO: This is a violation of the Spec, but a promise that is forever pending is not suitable for long-running applications
      if (!$forceSpec && ($promiseCount == 0)) {
        if ($Base)
          return static::reject ($Base);
        
        return static::reject ();
      }
      
      // Prepare to deprecate this flag
      if ($ignoreRejections)
        trigger_error ('Please use qcEvents_Promise::any() instead of qcEvents_Promise::race() if you want to ingnore rejections', E_USER_DEPRECATED);
      
      return new static (
        function ($resolve, $reject) use ($watchPromises, $ignoreRejections, $promiseCount) {
          // Track if the promise is settled
          $Done = false;
          
          // Register handlers
          foreach ($watchPromises as $Promise)
            $Promise->then (
              function () use (&$Done, $resolve) {
                // Check if the promise is already settled
                if ($Done)
                  return;
                
                // Mark the promise as settled
                $Done = true;
                
                // Forward the result
                call_user_func_array ($resolve, func_get_args ());
           
                return new qcEvents_Promise_Solution (func_get_args ());
              },
              function () use (&$Done, &$promiseCount, $reject, $ignoreRejections) {
                // Check if the promise is settled
                if ($Done)
                  return;
                
                // Check if we ignore rejections until one was fullfilled
                if ($ignoreRejections && (--$promiseCount > 0))
                  return;
                
                // Mark the promise as settled
                $Done = true;
                
                // Forward the result
                call_user_func_array ($reject, func_get_args ());
                
                throw new qcEvents_Promise_Solution (func_get_args ());
              }
            );
        },
        $Base
      );
    }
    // }}}
    
    
    // {{{ walk
    /**
     * NON-STANDARD: Walk an array with a callback
     * 
     * @param mixed $walkArray
     * @param callable $itemCallback
     * @param bool $justSettle (optional) Don't stop on rejections, but enqueue them as result
     * @param qcEvents_Base $eventBase (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public static function walk ($walkArray, callable $itemCallback, $justSettle = false, qcEvents_Base $eventBase = null) {
      // Make sure we have an iterator
      if (is_array ($walkArray))
        $arrayIterator = new ArrayIterator ($walkArray);
      elseif ($walkArray instanceof IteratorAggregate)
        $arrayIterator = $walkArray->getIterator ();
      elseif ($walkArray instanceof Iterator)
        $arrayIterator = $walkArray;
      # elseif (is_iterable ($walkArray))
      #   TODO
      else
        return qcEvents_Promise::reject ('First parameter must be an iterable');
      
      // Move to start
      $arrayIterator->rewind ();
      
      return new qcEvents_Promise (
        function (callable $resolve, callable $reject)
        use ($arrayIterator, $itemCallback, $justSettle, $eventBase) {
          $walkResults = array ();
          $walkItem = null;
          $walkItem = function () use (&$walkResults, &$walkItem, $arrayIterator, $itemCallback, $justSettle, $eventBase, $resolve, $reject) {
            // Check wheter to stop
            if (!$arrayIterator->valid ())
              return $resolve ($walkResults);
            
            // Invoke the callback
            $itemKey = $arrayIterator->key ();
            $itemResult = $itemCallback ($arrayIterator->current ());
            
            // Move to next element
            $arrayIterator->next ();
            
            // Process the result
            if (!($itemResult instanceof qcEvents_Promise))
              $itemResult = ($eventBase ? qcEvents_Promise::resolve ($itemResult, $eventBase) : qcEvents_Promise::resolve ($itemResult));
            
            $itemResult->then (
              function () use ($itemKey, &$walkResults, &$walkItem) {
                $walkResults [$itemKey] = (func_num_args () == 1 ? func_get_arg (0) : func_get_args ());
                $walkItem ();
              },
              function () use ($justSettle, $itemKey, $reject, &$walkResults, &$walkItem) {
                if (!$justSettle)
                  return call_user_func_array ($reject, func_get_args ());
                
                $walkResults [$itemKey] = (func_num_args () == 1 ? func_get_arg (0) : func_get_args ());
                $walkItem ();
              }
            );
          };
          
          $walkItem ();
        },
        $eventBase
      );
    }
    // }}}
    
    // {{{ ensure
    /**
     * NON-STANDARD: Make sure the input is a promise or return a rejected one
     * 
     * @param mixed $Input
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public static function ensure ($Input) {
      if ($Input instanceof qcEvents_Promise)
        return $Input;
      
      return static::reject ($Input);
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create a new promise
     * 
     * @param callable $initCallback (optional)
     * @param qcEvents_Base $eventBase (optional)
     + 
     * @access friendly
     * @return void
     **/
    function __construct (callable $initCallback = null, qcEvents_Base $eventBase = null) {
      // Store the assigned base
      $this->eventBase = $eventBase;
      
      // Check wheter to invoke the callback
      if ($initCallback === null)
        return;
      
      // Invoke/Enqueue the init-callback
      $invokeCallback = function () use ($initCallback) {
        try {
          call_user_func (
            $initCallback,
            function () {
              $this->finish ($this::DONE_FULLFILL, func_get_args ());
            },
            function () {
              $this->finish ($this::DONE_REJECT,   func_get_args ());
            }
          );
        } catch (Throwable $errorException) {
          $this->finish ($this::DONE_REJECT, ($errorException instanceof qcEvents_Promise_Solution ? $errorException->getArgs () : array ($errorException)));
        }
      };
      
      if ($eventBase)
        return $eventBase->forceCallback ($invokeCallback);
      
      $invokeCallback ();
    }
    // }}}
    
    // {{{ __debugInfo
    /**
     * Return information about this instance to be dumped by var_dump()
     * 
     * @access public
     * @return array
     **/
    public function __debugInfo () {
      static $doneMap = array (
        self::DONE_NONE     => 'pending',
        self::DONE_FULLFILL => 'fullfilled',
        self::DONE_REJECT   => 'rejected',
      );
      
      return array (
        'hasEventBase' => is_object ($this->eventBase),
        'promiseState' => (isset ($doneMap [$this->done]) ? $doneMap [$this->done] : 'Unknown (' . $this->done . ')'),
        'promiseResult' => $this->result,
        'registeredCallbacks' => array (
          'fullfill' => count ($this->callbacks [self::DONE_FULLFILL]),
          'reject'   => count ($this->callbacks [self::DONE_REJECT]),
        ),
        'resetCallbacks' => $this->resetCallbacks,
      );
    }
    // }}}
    
    // {{{ getEventBase
    /**
     * Retrive the event-base assigned to this promise
     * 
     * @access public
     * @return qcEvents_Base
     **/
    public function getEventBase () {
      return $this->eventBase;
    }
    // }}}
    
    // {{{ setEventBase
    /**
     * Assign a new event-base
     * 
     * @param qcEvents_Base $eventBase (optional)
     * @param bool $forwardToChildren (optional)
     * 
     * @access public
     * @return void
     **/
    public function setEventBase (qcEvents_Base $eventBase = null, $forwardToChildren = true) {
      $this->eventBase = $eventBase;
      
      // Forward to child-promises
      if ($forwardToChildren)
        foreach ($this->callbacks as $type=>$callbacks)
          foreach ($callbacks as $callbackData)
            $callbackData [1]->setEventBase ($eventBase, true);
    }
    // }}}
    
    // {{{ getDone
    /**
     * Retrive our done-state
     * 
     * @access protected
     * @return enum
     **/
    protected function getDone () {
      return $this->done;
    }
    // }}}
    
    // {{{ promiseFullfill
    /**
     * Trigger a fullfillment of this promise
     * 
     * @param ...
     * 
     * @access protected
     * @return void
     **/
    protected function promiseFullfill () {
      return $this->finish ($this::DONE_FULLFILL, func_get_args ());
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
    protected function promiseReject () {
      return $this->finish ($this::DONE_REJECT, func_get_args ());
    }
    // }}}
    
    // {{{ finish
    /**
     * Finish this promise
     * 
     * @param enum $done
     * @param array $result
     * 
     * @access private
     * @return void
     **/
    private function finish ($done, array $result) {
      // Check if we are already done
      if ($this->done > $this::DONE_NONE)
        return;
      
      // Check if there is another promise
      $result = array_values ($result);
      
      foreach ($result as $p)
        if ($p instanceof qcEvents_Promise)
          return $this::all ($result)->then (
            function ($results) { $this->finish ($this::DONE_FULLFILL, $results); },
            function () { $this->finish ($this::DONE_REJECT, func_get_args ()); }
          );
      
      // Make sure first parameter is an exception or error on rejection
      if ($done == $this::DONE_REJECT) {
        if (count ($result) == 0)
          $result [] = new exception ('Empty rejection');
        elseif (!($result [0] instanceof Throwable))
          $result [0] = new exception ($result [0]);
      }
      
      // Store the result
      $this->done = $done;
      $this->result = $result;
      
      // Invoke handlers
      if (count ($this->callbacks [$done]) > 0)
        foreach ($this->callbacks [$done] as $callback)
          $this->invoke ($callback [0], $callback [1]);
      
      // Reset callbacks
      if ($this->resetCallbacks)
        $this->callbacks = array (
          qcEvents_Promise::DONE_FULLFILL  => array (),
          qcEvents_Promise::DONE_REJECT    => array (),
        );
    }
    // }}}
    
    // {{{ reset
    /**
     * Reset our interal state
     * 
     * @param bool $Deep (optional) Reset child-promises as well
     * 
     * @access protected
     * @return void
     **/
    protected function reset ($Deep = true) {
      // Reset our own state
      $this->done = $this::DONE_NONE;
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
     * @param qcEvents_Promise $childPromise (optional)
     * 
     * @access private
     * @return void
     **/
    private function invoke (callable $directCallback = null, qcEvents_Promise $childPromise = null) {
      // Run the callback
      if ($directCallback) {
        $resultType = $this::DONE_FULLFILL;
        
        try {
          $resultValues = call_user_func_array ($directCallback, $this->result);
        } catch (Throwable $errorException) {
          $resultType = $this::DONE_REJECT;
          $resultValues = $errorException;
        }
        
        $resultValues = ($resultValues instanceof qcEvents_Promise_Solution ? $resultValues->getArgs () : array ($resultValues));
      } else {
        $resultType = $this->done;
        $resultValues = $this->result;
      }
      
      // Quit if there is no child-promise to fullfill
      if (!$childPromise)
        return;
      
      // Finish the child-promise
      $childPromise->finish ($resultType, $resultValues);
    }
    // }}}
    
    // {{{ then
    /**
     * Register callbacks for promise-fullfillment
     * 
     * @param callable $resolve (optional)
     * @param callable $reject (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function then (callable $resolve = null, callable $reject = null) {
      // Create an empty promise
      $childPromise = new self (null, $this->eventBase);
      
      // Check if we are not already done
      if (($this->done == $this::DONE_NONE) || !$this->resetCallbacks) {
        $this->callbacks [$this::DONE_FULLFILL][] = array ($resolve, $childPromise);
        $this->callbacks [$this::DONE_REJECT][] = array ($reject, $childPromise);
        
        if ($this->done == $this::DONE_NONE)
          return $childPromise;
      }
      
      // Check if we were fullfilled
      if ($this->done == $this::DONE_FULLFILL)
        $this->invoke ($resolve, $childPromise);
      
      // Check if we were rejected
      elseif ($this->done == $this::DONE_REJECT)
        $this->invoke ($reject, $childPromise);
      
      // Return a promise
      return $childPromise;
    }
    // }}}
    
    // {{{ catch
    /**
     * Register a callback for exception-handling
     * 
     * @param callable $callback
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function catch5 (callable $callback) {
      return $this->then (null, $callback);
    }
    // }}}
    
    // {{{ finally
    /**
     * Register a callback that is always fired when the promise has settled
     * 
     * @param callable $callback
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function finally5 (callable $callback) {
      return $this->then (
        function () use ($callback) {
          // Invoke the callback
          call_user_func ($callback);
          
          // Forward all parameters
          return new qcEvents_Promise_Solution (func_get_args ());
        },
        function () use ($callback) {
          // Invoke the callback
          call_user_func ($callback);
          
          // Forward all parameters
          throw new qcEvents_Promise_Solution (func_get_args ());
        }
      );
    }
    // }}}
  }
  
  class qcEvents_Promise_Solution extends Exception {
    private $args = array ();
    
    function __construct (array $Args) {
      $this->args = $Args;
    }
    
    public function getArgs () {
      return $this->args;
    }
  }

?>
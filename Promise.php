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
    /* NoOp-Indicator to create an empty promise */
    private static $noopResolve = null;
    private static $noopReject = null;
    
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
     * @param array $Values
     * @param qcEvents_Base $Base (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public static function all (array $Values, qcEvents_Base $Base = null) {
      // Pre-Filter the promises
      $Promises = $Values;
      
      foreach ($Promises as $ID=>$Promise)
        if (!($Promise instanceof qcEvents_Promise))
          unset ($Promises [$ID]);
        # TODO: Get Base here if undefined?
      
      // Check if there is any promise to wait for
      if (count ($Promises) == 0) {
        if ($Base)
          return static::resolve ($Values, $Base);
        
        return static::resolve ($Values);
      }
      
      return new static (function ($resolve, $reject) use ($Values, $Promises) {
        // Track if the promise is settled
        $Done = false;
        $Pending = count ($Promises);
        
        foreach ($Values as $ID=>$Value)
          $Values [$ID] = array ($Values);
        
        // Register handlers
        foreach ($Promises as $ID=>$Promise)
          $Promise->then (
            function () use (&$Done, &$Values, &$Pending, $ID, $resolve) {
              // Check if the promise is already settled
              if ($Done)
                return;
              
              // Remember the result
              $Values [$ID] = func_get_args ();
              
              // Check if we are done
              if ($Pending-- > 1)
                return;
              
              // Mark the promise as settled
              $Done = true;
              
              // Forward the result
              $Result = array ();
              
              foreach ($Values as $ID=>$Results)
                if ((count ($Results) == 1) && !isset ($Result [$ID]))
                  $Result [$ID] = array_shift ($Results);
                else
                  foreach ($Results as $V)
                    $Result [] = $V;
              
              call_user_func ($resolve, $Result);
              
              return $Result;
            },
            function () use (&$Done, $reject) {
              // Check if the promise is settled
              if ($Done)
                return;
              
              // Mark the promise as settled
              $Done = true;
              
              // Forward the result
              call_user_func_array ($reject, func_get_args ());
              
              throw new qcEvents_Promise_Solution (func_get_args ());
            }
          );
      }, $Base);
    }
    // }}}
    
    // {{{ race
    /**
     * Create a promise that settles whenever another promise of a given set settles as well
     * 
     * @param array $Promises
     * @param qcEvents_Base $Base (optional)
     * @param bool $ignoreRejections (optional) Ignore rejections as long as one promise fullfills
     * @param bool $forceSpec (optional) Enforce behaviour along specification, don't fullfill the promise if there are no promises given
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public static function race (array $Promises, qcEvents_Base $Base = null, $ignoreRejections = false, $forceSpec = false) {
      // Check for non-promises first
      foreach ($Promises as $Promise)
        if (!($Promise instanceof qcEvents_Promise)) {
          if ($Base)
            return static::resolve ($Promise, $Base);
          
          return static::resolve ($Promise);
        }
      
      // Check if there is any promise to wait for
      # TODO: This is a violation of the Spec, but a promise that is forever pending is not suitable for long-running applications
      if (!$forceSpec && (count ($Promises) == 0)) {
        if ($Base)
          return static::reject ($Base);
        
        return static::reject ();
      }
      
      return new static (
        function ($resolve, $reject) use ($Promises, $ignoreRejections) {
          // Track if the promise is settled
          $Done = false;
          $Countdown = count ($Promises);
          
          // Register handlers
          foreach ($Promises as $Promise)
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
              function () use (&$Done, &$Countdown, $reject, $ignoreRejections) {
                // Check if the promise is settled
                if ($Done)
                  return;
                
                // Check if we ignore rejections until one was fullfilled
                if ($ignoreRejections && (--$Countdown > 0))
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
    public static function walk ($walkArray, callable $itemCallback, $justSettle = false, qcEvents_Base $eventBase = null) : qcEvents_Promise {
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
     * @param callable $Callback
     * @param qcEvents_Base $eventBase (optional)
     + 
     * @access friendly
     * @return void
     **/
    function __construct (callable $Callback, qcEvents_Base $eventBase = null) {
      // Make sure we have a NoOp-Indicator
      if (self::$noopResolve === null) {
        self::$noopResolve = function () { return new qcEvents_Promise_Solution (func_get_args ()); };
        self::$noopReject  = function () { throw  new qcEvents_Promise_Solution (func_get_args ()); };
      }
      
      // Store the assigned base
      $this->eventBase = $eventBase;
      
      // Check wheter to invoke the callback
      if ($Callback === self::$noopResolve)
        return;
      
      // Invoke/Enqueue the callback
      $Invoke = function () use ($Callback) {
        try {
          call_user_func (
            $Callback,
            function () { $this->finish ($this::DONE_FULLFILL, func_get_args ()); },
            function () { $this->finish ($this::DONE_REJECT,   func_get_args ()); }
          );
        } catch (Throwable $errorException) {
          $this->finish ($this::DONE_REJECT, ($errorException instanceof qcEvents_Promise_Solution ? $errorException->getArgs () : array ($errorException)));
        }
      };
      
      if ($eventBase)
        return $eventBase->forceCallback ($Invoke);
      
      $Invoke ();
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
    public function getEventBase () : ?qcEvents_Base {
      return $this->eventBase;
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
     * @param callable $callback
     * @param qcEvents_Promise $childPromise (optional)
     * 
     * @access private
     * @return void
     **/
    private function invoke (callable $callback, qcEvents_Promise $childPromise = null) {
      // Run the callback
      try {
        $ResultType = $this::DONE_FULLFILL;
        $Result = call_user_func_array ($callback, $this->result);
        $Result = ($Result instanceof qcEvents_Promise_Solution ? $Result->getArgs () : array ($Result));
      } catch (Throwable $errorException) {
        $ResultType = $this::DONE_REJECT;
        $Result = ($errorException instanceof qcEvents_Promise_Solution ? $errorException->getArgs () : array ($errorException));
      }
      
      // Quit if there is no child-promise to fullfill
      if (!$childPromise)
        return;
      
      // Finish the child-promise
      $childPromise->finish ($ResultType, $Result);
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
      $Promise = new self (self::$noopResolve, $this->eventBase);
      
      // Polyfill callbacks
      if ($resolve === null)
        $resolve = self::$noopResolve;
      
      if ($reject === null)
        $reject = self::$noopReject;
      
      // Check if we are not already done
      if (($this->done == $this::DONE_NONE) || !$this->resetCallbacks) {
        if ($resolve)
          $this->callbacks [$this::DONE_FULLFILL][] = array ($resolve, $Promise);
        
        if ($reject)
          $this->callbacks [$this::DONE_REJECT][] = array ($reject, $Promise);
        
        if ($this->done == $this::DONE_NONE)
          return $Promise;
      }
      
      // Check if we were fullfilled
      if ($this->done == $this::DONE_FULLFILL)
        $this->invoke ($resolve, $Promise);
      
      // Check if we were rejected
      elseif ($this->done == $this::DONE_REJECT)
        $this->invoke ($reject, $Promise);
      
      // Return a promise
      return $Promise;
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
    public function catch (callable $callback) {
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
    public function finally (callable $callback) {
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
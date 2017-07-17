<?PHP

  /**
   * qcEvents - Call multiple asynchronous functions in parallel and wait until they are all finished
   * 
   * Copyright (C) 2016 Bernd Holzmueller <bernd@quarxconnect.de>
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
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/
  
  class qcEvents_Queue {
    /* Operation-Mode */
    const MODE_PARALLEL = 0;
    const MODE_SERIAL = 1;
    
    private $Mode = qcEvents_Queue::MODE_PARALLEL;
    
    /* Queued calls */
    private $Queue = array ();
    
    /* Invoked calls */
    private $Invoked = array ();
    
    /* Call-Results */
    private $Results = array ();
    
    /* Result-Callbacks */
    private $resultCallbacks = array ();
    
    /* Finish-Callbacks */
    private $finishCallbacks = array ();
    
    // {{{ setMode
    /**
     * Set mode of invocation
     * 
     * @param enum $Mode
     * 
     * @access public
     * @return void
     **/
    public function setMode ($Mode) {
      if (($Mode == self::MODE_PARALLEL) || ($Mode == self::MODE_SERIAL))
        $this->Mode = (int)$Mode;
    }
    // }}}
    
    // {{{ addCall
    /**
     * Append a call to our queue and invoke execution
     * The first arguement needs to be a callable or instance of a class,
     * if an instance is given a second arguement is taken as method-name.
     * All other arguments will be passed to the callable.
     * 
     * @param callable $Callable
     * @param ...
     * 
     * @access public
     * @return mixed
     **/
    public function addCall ($Callable) {
      // Peek all given arguments
      $Parameters = func_get_args ();
      $Callable = array_shift ($Parameters);
      
      // Make sure we pick the right callable
      if (is_object ($Callable)) {
        // Check if there were enough arguments given
        if (count ($Parameters) < 1) {
          trigger_error ('Invalid callable given - an object requires a function-name to call');
          
          return false;
        }
        
        // Rewrite the callable
        $Callable = array ($Callable, array_shift ($Parameters));
        
        // Validate the callable
        if (!is_callable ($Callable)) {
          trigger_error ('Method does not exist');
          
          return false;
        }
      
      // Bail out error if not callable
      } elseif (!is_callable ($Callable)) {
        trigger_error ('First argument must be callable');
        
        return false;
      }
      
      // Push to our queue
      if (($this->Mode == self::MODE_SERIAL) && (count ($this->Invoked) > 0)) {
        $this->Queue [] = array ($Callable, $Parameters);
        
        return;
      }
      
      return $this->invokeCall ($Callable, $Parameters);
    }
    // }}}
    
    // {{{ invokeCall
    /**
     * Invoke a callable
     * 
     * @param callable $Callable
     * @param array $Parameters
     * 
     * @access private
     * @return mixed
     **/
    private function invokeCall (callable $Callable, array $Parameters) {
      // Prepare the callback
      $Callback = function () use ($Callable, $Parameters) {
        $this->processResult ($Callable, $Parameters, func_get_args ());
      };
      
      // Create Reflection-Class
      if (is_array ($Callable))
        $Function = new ReflectionMethod ($Callable [0], $Callable [1]);
      else
        $Function = new ReflectionFunction ($Callable);
      
      // Analyze parameters of the call
      $CallbackIndex = null;
      
      foreach ($Function->getParameters () as $Index=>$Parameter)
        if ($Parameter->isCallable ()) {
          $CallbackIndex = $Index;
          
          break;
        }
      
      // Store the callback on parameters
      if ($CallbackIndex !== null) {
        // Make sure parameters is big enough
        if (count ($Parameters) < $CallbackIndex)
          for ($i = 0; $i < $CallbackIndex; $i++)
            if (!isset ($Parameters [$i]))
              $Parameters [$i] = null;
        
        // Set the callback
        $Parameters [$CallbackIndex] = $Callback;
      } else {
        trigger_error ('No position for callback detected, just giving it a try', E_USER_NOTICE);
        
        $Parameters [] = $Callback;
      }
      
      // Store as invoked
      $this->Invoked [] = array ($Callable, $Parameters);
      
      // Do the call
      if (is_array ($Callable))
        return $Function->invokeArgs ($Callable [0], $Parameters);
      
      return $Function->invokeArgs ($Parameters);
    }
    // }}}
    
    // {{{ onResult
    /**
     * Register a callback to forward results to
     * 
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function onResult (callable $Callback, $Private = null) {
      // Register the callback
      $this->resultCallbacks [] = array ($Callback, $Private);
      
      // Push already pending results there
      $Results = $this->Results;
      
      foreach ($Results as $Result)
        call_user_func ($Callback, $this, $Result, $Private);
    }
    // }}}
    
    // {{{ finish
    /**
     * Register a callback to raise once completed
     * 
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function finish (callable $Callback, $Private = null) {
      // Push callback to callbacks
      $this->finishCallbacks [] = array ($Callback, $Private);
      
      // Check if we are already done
      $this->finishQueue ();
    }
    // }}}
    
    // {{{ stop
    /**
     * Stop processing of the queue
     * 
     * @access public
     * @return void
     **/
    public function stop () {
      // Just empty the queue
      $this->Queue = array ();
      $this->finishCallbacks = array ();
      $this->resultCallbacks = array ();
    }
    // }}}
    
    // {{{ processResult
    /**
     * Process an incomming result
     * 
     * @param callable $Callable The initially called function
     * @param array $Parameters All parameters given to that function
     * @param array $Result All parameters given to the callback
     * 
     * @access private
     * @return void
     **/
    private function processResult (callable $Callable, array $Parameters, array $Result) {
      // Find the callable on queue
      $Call = null;
      
      foreach ($this->Invoked as $Key=>$Info)
        if (($Info [0] === $Callable) && ($Info [1] === $Parameters)) {
          $Call = $Key;
          
          break;
        }
      
      // Make sure we found anything
      if ($Call === null) {
        trigger_error ('Result without call recevied');
        
        return false;
      }
      
      // Remove the call from queue
      unset ($this->Invoked [$Call]);
      
      // Push the result to results
      if (isset ($this->Results [$Call]))
        $this->Results [] = $Result;
      else
        $this->Results [$Call] = $Result;
      
      // Run callbacks
      foreach ($this->resultCallbacks as $resultCallback)
        call_user_func ($resultCallback [0], $this, $Result, $resultCallback [1]);
      
      // Try to finish the queue
      return $this->finishQueue ();
    }
    // }}}
    
    // {{{ finishQueue
    /**
     * Finish processing of queue by forwarding all results to all registered callbacks
     * 
     * @access private
     * @return void
     **/
    private function finishQueue () {
      // Check if we are done
      if (count ($this->Invoked) > 0)
        return;
      
      // Check wheter to run from queue
      if (count ($this->Queue) > 0) {
        do {
          // Get the next call
          $Next = array_shift ($this->Queue);
          
          // Invoke
          $this->invokeCall ($Next [0], $Next [1]);
        } while (($this->Mode != self::MODE_SERIAL) && (count ($this->Queue) > 0));
        
        return;
      }
      
      // Check if we may finish
      if (count ($this->finishCallbacks) == 0)
        return;
      
      // Peek results
      $Results = $this->Results;
      $this->Results = array ();
      
      // Run all callbacks
      foreach ($this->finishCallbacks as $Info)
        call_user_func ($Info [0], $this, $Results, $Info [1]);
    }
    // }}}
  }

?>
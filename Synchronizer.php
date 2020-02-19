<?PHP

  /**
   * qcEvents - Call asynchronous functions in a syncronous manner
   * 
   * Copyright (C) 2015-2020 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  class qcEvents_Synchronizer {
    /* Result-modes */
    const RESULT_AS_ARRAY = 0;
    const RESULT_FIRST = 1;
    
    private $resultMode = qcEvents_Synchronizer::RESULT_AS_ARRAY;
    
    /* Store entire results on this class */
    private $storeResult = true;
    
    /* Throw exception if one was received */
    private $throwExceptions = true;
    
    /* The last result received */
    private $lastResult = null;
    
    // {{{ __construct
    /**
     * Create a new Synchronizer
     * 
     * @param enum $resultMode (optional) Return results in this mode
     * @param bool $storeResult (optional) Store results on this class
     * @param bool $throwExceptions (optional) Throw exception if one was received
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($resultMode = null, $storeResult = null, $throwExceptions = null) {
      if ($resultMode !== null)
        $this->resultMode = $resultMode;
      
      if ($storeResult !== null)
        $this->storeResult = !!$storeResult;
      
      if ($throwExceptions !== null)
        $this->throwExceptions = !!$throwExceptions;
      else
        $this->throwExceptions = (!defined ('QCEVENTS_EXCEPTIONS') || constant ('QCEVENTS_EXCEPTIONS'));
    }
    // }}}
    
    // {{{ __invoke
    /**
     * Do an asynchronous call in a synchronous way
     * 
     * If $Handler features a getEventBase()-Call:
     * 
     * @param qcEvents_Promise $Promise
     * 
     * -- OR --
     * 
     * @param object $Handler
     * @param string $Function
     * @param ...
     * 
     * -- OR --
     * 
     * @param qcEvents_Base $Base
     * @param qcEvents_Promise $Promise
     * 
     * -- OR --
     * 
     * @param qcEvents_Base $Base
     * @param object $Handler
     * @param string $Function
     * @param ...
     * 
     * @access friendly
     * @return mixed
     **/
    function __invoke ($Handler, $Function) {
      // Extract parameters
      $Parameters = array_slice (func_get_args (), 2);
      
      // Try to find an eventbase
      if ($Handler instanceof qcEvents_Base) {
        $Base = $Handler;
        $Handler = $Function;
        
        if ((count ($Parameters) == 0) && !($Handler instanceof qcEvents_Promise))
          throw new InvalidArgumentException ('Asyncronous calls on event-base not supported');
        
        $Function = array_shift ($Parameters);
      } elseif (!is_callable (array ($Handler, 'getEventBase')))
        throw new InvalidArgumentException ('Unable to find event-base anywhere');
      elseif (!is_object ($Base = $Handler->getEventBase ()))
        throw new InvalidArgumentException ('Did not get an event-base from object');
      
      // Prepare Callback
      $Ready = $Loop = $isPromise = false;
      $Result = null;
      $Callback = function () use (&$Loop, &$Ready, &$Result, &$isPromise, $Base) {
        // Store the result
        $Ready = true;
        $Result = func_get_args ();
        
        if (count ($Result) == 0)
          $Result [] = true;
        elseif ($isPromise && (count ($Result) == 1) && ($Result [0] === null))
          $Result [0] = true;
        
        // Leave the loop
        if ($Loop)
          $Base->loopBreak ();
      };
      
      if (!($Handler instanceof qcEvents_Promise)) {
        // Analyze parameters of the call
        $Method = new ReflectionMethod ($Handler, $Function);
        
        if (!($isPromise = ($Method->getReturnType () == 'qcEvents_Promise'))) {
          $CallbackIndex = null;
          
          foreach ($Method->getParameters () as $Index=>$Parameter) 
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
        }
        
        // Do the call
        $rc = $Method->invokeArgs ((is_object ($Handler) ? $Handler : null), $Parameters);
      } else {
        $rc = $Handler;
        $isPromise = true;
      }
      
      // Check for a returned promise
      $Exception = null;
      
      if ($rc instanceof qcEvents_Promise) {
        // Check if this was expected
        if (!$isPromise) {
          trigger_error ('Got an unexpected Promise in return');
          
          $isPromise = true;
        }
        
        $rc->then (
          $Callback,
          function ($Error) use (&$Loop, &$Ready, &$Result, &$Exception, $Base) {
            // Store the result
            $Ready = true;
            $Result = array (false);
            
            // Leave the loop
            if ($Loop)
              $Base->loopBreak ();
            
            if ($Error instanceof Throwable)
              $Exception = $Error;
          }
        );
      } elseif ($isPromise) {
        trigger_error ('Expected Promise as return, but did not get one. Things will be weired!');
        
        return false;
      }
      
      // Run the loop until ready
      $Loop = true;
      
      while (!$Ready)
        $Base->loop (true);
      
      if (($Exception !== null) && $this->throwExceptions)
        throw $Exception;
      
      // Process the result
      $c = count ($Result);
      
      if (($c > 0) && ($Result [0] === $Handler)) {
        $c--;
        array_shift ($Result);
      }
      
      if ($this->storeResult)
        $this->lastResult = $Result;
      
      if ($this->resultMode == self::RESULT_AS_ARRAY)
        return $Result;
      
      elseif ($c > 0)
        return $Result [0];
    }
    // }}}
    
    // {{{ getLastResult
    /**
     * Retrive the entire last result
     * 
     * @access public
     * @return array The last retrived result, may be NULL if no result is available (or no results are stored at all)
     **/
    public function getLastResult () {
      return $this->lastResult;
    }
    // }}}
  }
  
  // {{{ qcEvents_Synchronizer
  /**
   * Just an alias for qcEvents_Synchronizer::__invoke()
   * 
   * @param ...
   * 
   * @see qcEvents_Synchronizer::__invoke()
   * @access public
   * @return mixed
   **/
  function qcEvents_Synchronizer () {
    static $Syncronizer = null;
    
    if ($Syncronizer === null)
      $Syncronizer = new qcEvents_Synchronizer (qcEvents_Synchronizer::RESULT_FIRST);
    
    return call_user_func_array ($Syncronizer, func_get_args ());
  }
  // }}}

?>
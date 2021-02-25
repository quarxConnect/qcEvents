<?php

  /**
   * quarxConnect Events - Defered Execution
   * Copyright (C) 2019-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events\Promise;
  use quarxConnect\Events;
  
  class Defered {
    /* Promise for this defered execution */
    private $Promise = null;
    
    /* Resolve-Function of promise */
    private $Resolve = null;
    
    /* Reject-Function of promise */
    private $Reject = null;
    
    // {{{ __construct
    /**
     * Create a new defered execution
     * 
     * @param mixed $BaseOrPromise (optional) An event-base to bind a new promise to or a pre-setup promise (requires $Resolve and $Reject)
     * @param callable $Resolve (optional) If $BaseOrPromise, this is a callable to resolve the promise
     * @param callable $Reject (optional) If $BaseOrPromise, this is a callable to reject the promise
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($BaseOrPromise = null, callable $Resolve = null, callable $Reject = null) {
      // Check if a promise was given
      if ($BaseOrPromise instanceof Events\Promise) {
        // Make sure the promise was given with fullfillment-functions
        if (!$Resolve || !$Reject)
          throw new \InvalidArgumentException ('Promise requires specification of resolve- and reject-functions');
        
        // Assign the promise
        $this->Promise = $BaseOrPromise;
        $this->Resolve = $Resolve;
        $this->Reject = $Reject;
      
      // Check if nothing or an event-base was given
      } elseif (($BaseOrPromise === null) || ($BaseOrPromise instanceof Events\Base))
        // Create a new promise
        $this->Promise = new Events\Promise (
          function (callable $Resolve, callable $Reject) {
            // Check wheter to resolve/reject directly
            if ($this->Resolve !== null)
              call_user_func_array ($Resolve, $this->Resolve);
            
            if ($this->Reject !== null)
              call_user_func_array ($Reject, $this->Reject);
            
            // Store the callbacks
            $this->Resolve = $Resolve;
            $this->Reject = $Reject;
          },
          $BaseOrPromise
        );
      else
        throw new \InvalidArgumentException ('First argument is expected to be Event-Base or promise if present');
    }
    // }}}
    
    // {{{ getPromise
    /**
     * Retrive the promise for this defered execution
     * 
     * @access public
     * @return Events\Promise
     **/
    public function getPromise () : Events\Promise {
      return $this->Promise;
    }
    // }}}
    
    // {{{ resolve
    /**
     * Resolve this defered execution
     * 
     * @param ...
     * 
     * @access public
     * @return void
     **/
    public function resolve () {
      // Check if we may do it directly
      if ($this->Resolve)
        return call_user_func_array ($this->Resolve, func_get_args ());
      
      // Store for later execution
      $this->Resolve = func_get_args ();
    }
    // }}}
    
    // {{{ reject
    /**
     * Reject this defered execution
     * 
     * @param ...
     * 
     * @access public
     * @return void
     **/
    public function reject () {
      // Check if we may do it directly
      if ($this->Reject)
        return call_user_func_array ($this->Reject, func_get_args ());
      
      // Store for later execution
      $this->Reject = func_get_args ();
    }
    // }}}
  }

<?php

  /**
   * quarxConnect Events - Generic Hookable Implementation
   * Copyright (C) 2014-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);

  namespace quarxConnect\Events\Feature;
  use quarxConnect\Events;
  
  trait Hookable {
    /* Debug Hook-Calls */
    public static $debugHooks = false;
    
    /* Registered hooks for all instances of this class */
    private static $classHooks = [ ];
    
    /* Registered hooks for the implementing object */
    private $registeredHooks = [ ];
    
    /* Have hooks from class-scope been adapted? */
    private $hooksAdapted = [ ];
    
    // {{{ getRegisteredHooks
    /**
     * Retrive registered hooks from this class
     * 
     * @param string $hookName (optional) Name of the hook
     * 
     * @access public
     * @return array
     **/
    public static function getRegisteredHooks (string $hookName = null) : array {
      // Initialize Result
      $Result = [ ];
      
      // Make sure the hook-name is lower case
      if ($hookName !== null)
        $hookName = strtolower ($hookName);
      
      // Determine the called class
      $Class = get_called_class ();
      
      // Check hooks for all classes on path
      do {
        // Handle all classes in lower case
        $Class = strtolower ($Class);
        
        // Check if there are hooks for this class
        if (!isset (self::$classHooks [$Class]))
          continue;
        
        // Check wheter to return hooks by a given name
        if ($hookName !== null) {
          // Check if there are such hooks
          if (!isset (self::$classHooks [$Class][$hookName]))
            continue;
          
          // Append hooks to result
          $Result = array_merge ($Result, self::$classHooks [$Class][$hookName]);
        
        // Return all hooks for this class
        } else
          foreach (self::$classHooks [$Class] as $hName=>$Hooks)
            if (isset ($Result [$hName]))
              $Result [$hName] = array_merge ($Result [$hName], $Hooks);
            else
              $Result [$hName] = $Hooks;
        
      // Move foreward to next (parented) class
      } while ($Class = get_parent_class ($Class));
      
      // Return the result
      return $Result;
    }
    // }}}
    
    // {{{ registerHook
    /**
     * Register a new hook for this class
     * 
     * @param string $hookName Name of the hook to bind to
     * @param callable $eventCallback Callback-Function to register
     * @param bool $onlyOnce (optional) Just raise this hook once for each instance of this class
     * 
     * @access public
     * @return void
     **/
    public static function registerHook (string $hookName, callable $eventCallback, bool $onlyOnce = false) : void {
      // Retrive the called class
      $Class = get_called_class ();
      
      // Check if the named hook exists
      if (!method_exists ($Class, $hookName))
        throw new \Exception ('Invalid Hook "' . $hookName . '" on ' . $Class);
      
      // Treat all hooks lower-case to prevent dupes
      $Class = strtolower ($Class);
      $hookName = strtolower ($hookName);
       
      // Register the hook
      if (isset (self::$classHooks [$Class][$hookName])) {
        // Check if the callback is already registered
        foreach (self::$classHooks [$Class][$hookName] as $hookID=>$hookInfo)
          if ($hookInfo [0] === $eventCallback) {
            // Just handle the once-bit
            if (!$onlyOnce)
              self::$classHooks [$Class][$hookName][$hookID][1] = false;
            
            return;
          }
         
        self::$classHooks [$Class][$hookName][] = [ $eventCallback, $onlyOnce ];
      } elseif (isset (self::$classHooks [$Class]))
        self::$classHooks [$Class][$hookName] = [ [ $eventCallback, $onlyOnce ] ];
      else
        self::$classHooks [$Class] = [ $hookName => [ [ $eventCallback, $onlyOnce ] ] ];
    }
    // }}}
    
    // {{{ unregisterHook
    /**
     * Remove a registered hook from this class
     * @remark If the hook has already been forwarded to an instance of this class, it won't be removed there
     * 
     * @param string $hookName Name of the hook to unregister
     * @param callable $eventCallback Callback-Function to unregister
     * 
     * @access public
     * @return void
     **/
    public static function unregisterHook (string $hookName, callable $eventCallback) : void {
      // Treat all hooks lower-case to prevent dupes
      $Class = strtolower (get_called_class ());
      $hookName = strtolower ($hookName);
      
      // Check if there is such hook known
      if (!isset (self::$classHooks [$Class][$hookName]))
        return;
    
      // Search for the callback
      foreach (self::$classHooks [$Class][$hookName] as $hookID=>$hookInfo)
        if ($hookInfo [0] === $eventCallback)
          unset (self::$classHooks [$Class][$hookName][$hookID]);
    }
    // }}}
    
    
    // {{{ getHooks
    /**
     * Retrive all registered hooks for a given callback-function
     * 
     * @param string $Name Name of the hookable function
     * 
     * @access public
     * @return array
     **/
    public function getHooks (string $hookName) : array {
      // Treat all hooks lower-case to prevent dupes
      $hookName = strtolower ($hookName);
      
      // Check wheter to adapt hooks from class-scopes
      if (!isset ($this->hooksAdapted [$hookName])) {
        // Retrive all hooks for this instance
        $Hooks = $this::getRegisteredHooks ($hookName);
        
        // Merge into local hooks
        if (isset ($this->registeredHooks [$hookName]))
          $this->registeredHooks [$hookName] = array_merge ($this->registeredHooks [$hookName], $Hooks);
        else
          $this->registeredHooks [$hookName] = $Hooks;
        
        // Indicate that all hooks have been merged
        $this->hooksAdapted [$hookName] = true;
      }
      
      // Check if there are hooks registered
      if (isset ($this->registeredHooks [$hookName]))
        return $this->registeredHooks [$hookName];
      
      return [ ];
    }
    // }}}
    
    // {{{ addHook
    /**
     * Register a hook for a callback-function
     * 
     * @param string $hookName Name of the hookable function
     * @param callable $eventCallback
     * @param bool $onlyOnce (optional) Use the hook only once
     * 
     * @access public
     * @return void
     **/
    public function addHook (string $hookName, callable $eventCallback, bool $onlyOnce = false) : void {
      // Treat all hooks lower-case to prevent dupes
      $hookName = strtolower ($hookName);
      
      // Check if the named hook exists
      if (!is_callable ([ $this, $hookName ]))
        throw new \Exception ('Invalid Hook "' . $hookName . '" on ' . get_class ($this));
      
      // Register the hook
      if (isset ($this->registeredHooks [$hookName])) {
        // Check if the callback is already registered
        foreach ($this->registeredHooks [$hookName] as $hookID=>$hookInfo)
          if ($hookInfo [0] === $eventCallback) {
            // Just handle the once-bit
            if (!$onlyOnce)
              $this->registeredHooks [$hookName][$hookID][1] = false;
            
            return;
          }
        
        $this->registeredHooks [$hookName][] = [ $eventCallback, $onlyOnce ];
      } else
        $this->registeredHooks [$hookName] = [ [ $eventCallback, $onlyOnce ] ];
    }
    // }}}
    
    // {{{ removeHook
    /**
     * Remove an registered hook again
     * 
     * @param string $hookName Name of the hookable function
     * @param callable $eventCallback
     * 
     * @access public
     * @return void
     **/
    public function removeHook (string $hookName, callable $eventCallback) : void {
      // Treat all hooks lower-case to prevent dupes
      $hookName = strtolower ($hookName);
      
      // Check if there is such hook known
      if (!isset ($this->registeredHooks [$hookName]))
        return;
      
      // Search for the callback
      foreach ($this->registeredHooks [$hookName] as $hookID=>$hookInfo)
        if ($hookInfo [0] === $eventCallback)
          unset ($this->registeredHooks [$hookName][$hookID]);
    }
    // }}}
    
    // {{{ removeHooks
    /**
     * Remove registered hooks
     * 
     * @param string $hookName (optional) Name of hook to remove callbacks for
     * 
     * @access public
     * @return void
     **/
    public function removeHooks (string $hookName = null) : void {
      if ($hookName === null)
        $this->registeredHooks = [ ];
      else
        unset ($this->registeredHooks [strtolower ($hookName)]);
    }
    // }}}
    
    // {{{ once
    /**
     * Register a hook that is triggered once when a given event raises for the first time
     * 
     * @param string $hookName Name of the hookable function
     * 
     * @access public
     * @return Events\Promise
     **/
    public function once (string $hookName) : Events\Promise {
      return new Events\Promise (
        function ($resolve, $reject) use ($hookName) {
          try {
            $this->addHook (
              $hookName,
              function () use ($resolve, $hookName) {
                call_user_func_array (
                  $resolve,
                  array_slice (func_get_args (), 1)
                );
              },
              true
            );
          } catch (\Throwable $error) {
            $reject ($error);
          }
        }
      );
    }
    // }}}
    
    // {{{ ___callback
    /**
     * Fire a callback
     * 
     * @param string $hookName Name of the callback
     * @param ...
     * 
     * @access protected
     * @return mixed
     **/
    protected function ___callback (string $hookName) {
      // Output debug-info
      if (defined ('QCEVENTS_DEBUG_HOOKS') || self::$debugHooks)
        echo substr (number_format (microtime (true), 4, '.', ''), -8, 8), ' Callback: ', $hookName, ' on ', get_class ($this), "\n";
      
      // We are treating hooks in lower-case
      $hookName = strtolower ($hookName);
      
      // Make sure we have all registered hooks
      if (!isset ($this->hooksAdapted [$hookName]))
        $this->getHooks ($hookName);
      
      // Retrive all given parameters
      $localArguments = func_get_args ();
      
      // Prepare arguements for external callbacks
      $externalArguments = $localArguments;
      $externalArguments [0] = $this;
      
      // Prepare arguements for internal callbacks
      array_shift ($localArguments);
      
      // Check hooks
      if (isset ($this->registeredHooks [$hookName]))
        foreach ($this->registeredHooks [$hookName] as $hookID=>$hookInfo) {
          // Call the hook
          if (
            !is_array ($hookInfo [0]) ||
            ($hookInfo [0][0] !== $this)
          )
            $rc = call_user_func_array ($hookInfo [0], $externalArguments);
          else
            $rc = call_user_func_array ($hookInfo [0], $localArguments);
          
          // Remove the hook if it should only be called once
          if ($hookInfo [1])
            unset ($this->registeredHooks [$hookName][$hookID]);
          
          // Exit the loop if the hook failed
          if ($rc === false)
            return false;
        }
         
      // Check if the callback is available
      $Callback = [ $this, $hookName ];
      
      // Issue the callback
      if ($rc = is_callable ($Callback))
        $rc = call_user_func_array ($Callback, $localArguments);
      
      if (defined ('QCEVENTS_DEBUG_HOOKS') || self::$debugHooks)
        echo substr (number_format (microtime (true), 4, '.', ''), -8, 8), ' Done:     ', $hookName, ' on ', get_class ($this), "\n";
      
      return $rc;
    }
    // }}}
    
    // {{{ ___awaitHooks
    /**
     * Run all registered handlers for a given hook, allow asynchronous processing
     * 
     * @param string $hookName
     * @param ...
     * 
     * @access protected
     * @return Events\Promise
     **/
    protected function ___awaitHooks (string $hookName) : Events\Promise {
      // Output debug-info
      if (defined ('QCEVENTS_DEBUG_HOOKS') || self::$debugHooks)
        echo substr (number_format (microtime (true), 4, '.', ''), -8, 8), ' Callback: ', $hookName, ' on ', get_class ($this), ' (async)', "\n";
      
      // We are treating hooks in lower-case
      $hookName = strtolower ($hookName);
      
      // Make sure we have all registered hooks
      if (!isset ($this->hooksAdapted [$hookName]))
        $this->getHooks ($hookName);
      
      // Build queue of registered hooks
      if (isset ($this->registeredHooks [$hookName]))
        $hookQueue = $this->registeredHooks [$hookName];
      else
        $hookQueue = [ ];
      
      if (is_callable ([ $this, $hookName ]))
        $hookQueue [] = [ [ $this, $hookName ], false ];
      
      // Prepare to invoke all hooks
      $hookInvoker = null;
      $hookInvoker = function () use (&$hookQueue, &$hookInvoker, $hookName) {
        $hookResults = func_get_args ();
        
        foreach ($hookQueue as $hookIndex=>$nextHook) {
          // Call the hook
          $lastResult = call_user_func_array ($nextHook [0], $hookResults);
          
          // Remove the hook if it should only be called once
          if ($nextHook [1])
            unset ($this->registeredHooks [$hookName][$hookIndex]);
          
          // Process the result
          if ($lastResult instanceof Events\Promise)
            return $hookResults->then (
              function () use ($hookResults) {
                $lastResult = func_get_args ();
                
                if (
                  !is_array ($lastResult) ||
                  (count ($lastResult) != count ($hookResults))
                )
                  $hookResults [0] = $lastResult;
                else
                  $hookResults = $lastResult;
                
                return new Events\Promise\Solution ($hookResults);
              }
            )->then (
              $hookInvoker
            );
          
          if (
            !is_array ($lastResult) ||
            (count ($lastResult) != count ($hookResults))
          )
            $hookResults [0] = $lastResult;
          else
            $hookResults = $lastResult;
        }
        
        // Output debug-info
        if (defined ('QCEVENTS_DEBUG_HOOKS') || self::$debugHooks)
          echo substr (number_format (microtime (true), 4, '.', ''), -8, 8), ' Done:     ', $hookName, ' on ', get_class ($this), ' (async)', "\n";
        
        return new Events\Promise\Solution ($hookResults);
      };
      
      return call_user_func_array (
        [ Events\Promise::class, 'resolve' ],
        array_slice (func_get_args (), 1)
      )->then (
        $hookInvoker
      );
    }
    // }}}
    
    // {{{ ___raiseCallback
    /**
     * Fire a user-defined callback
     * 
     * @param callable $Callback (optional)
     * @param ...
     * 
     * @access protected
     * @return mixed
     **/
    protected function ___raiseCallback (callable $Callback = null) {
      // Check if there really is a callback
      if ($Callback === null)
        return;
      
      // Prepare parameters
      $Args = func_get_args ();
      
      if (!is_array ($Callback) || ($Callback [0] !== $this))
        $Args [0] = $this;
      else
        array_shift ($Args);
      
      // Run the callback
      return call_user_func_array ($Callback, $Args);
    }
    // }}}
  }

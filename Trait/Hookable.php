<?PHP

  /**
   * qcEvents - Generic Hookable Implementation
   * Copyright (C) 2014 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  trait qcEvents_Trait_Hookable {
    /* Debug Hook-Calls */
    public static $debugHooks = false;
    
    /* Registered hooks for all instances of this class */
    private static $classHooks = array ();
    
    /* Registered hooks for the implementing object */
    private $Hooks = array ();
    
    /* Have hooks from class-scope been adapted? */
    private $hooksAdapted = array ();
    
    // {{{ getRegisteredHooks
    /**
     * Retrive registered hooks from this class
     * 
     * @param string $Name (optional) Name of the hook
     * 
     * @access public
     * @return array
     **/
    public static function getRegisteredHooks ($Name = null) {
      // Initialize Result
      $Result = array ();
      
      // Make sure the hook-name is lower case
      if ($Name !== null)
        $Name = strtolower ($Name);
      
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
        if ($Name !== null) {
          // Check if there are such hooks
          if (!isset (self::$classHooks [$Class][$Name]))
            continue;
          
          // Append hooks to result
          $Result = array_merge ($Result, self::$classHooks [$Class][$Name]);
        
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
     * @param string $Name Name of the hook to bind to
     * @param callable $Hook Callback-Function to register
     * @param mixed $Private (optional) Any private data to pass to the hook
     * @param bool $Once (optional) Just raise this hook once for each instance of this class
     * 
     * @access public
     * @return bool
     **/
    public static function registerHook ($Name, callable $Hook, $Private = null, $Once = false) {
      // Retrive the called class
      $Class = get_called_class ();
      
      // Check if the named hook exists
      if (!method_exists ($Class, $Name)) {
        trigger_error ('Invalid Hook "' . $Name . '" on ' . $Class);
        
        return false;
      }
      
      // Treat all hooks lower-case to prevent dupes
      $Class = strtolower ($Class);
      $Name = strtolower ($Name);
       
      // Register the hook
      if (isset (self::$classHooks [$Class][$Name])) {
        // Check if the callback is already registered
        foreach (self::$classHooks [$Class][$Name] as $ID=>$Callback)
          if (($Callback [0] === $Hook) && ($Callback [1] === $Private)) {
            // Just handle the once-bit
            if (!$Once)
              self::$classHooks [$Class][$Name][$ID][2] = false;
            
            return true;
          }
         
        self::$classHooks [$Class][$Name][] = array ($Hook, $Private, $Once);
      } elseif (isset (self::$classHooks [$Class]))
        self::$classHooks [$Class][$Name] = array (array ($Hook, $Private, $Once));
      else
        self::$classHooks [$Class] = array ($Name => array (array ($Hook, $Private, $Once)));
      
      return true;
    }
    // }}}
    
    // {{{ unregisterHook
    /**
     * Remove a registered hook from this class
     * @remark If the hook has already been forwarded to an instance of this class, it won't be removed there
     * 
     * @param string $Name Name of the hook to unregister
     * @param callable $Hook Callback-Function to unregister
     * 
     * @access public
     * @return void
     **/
    public static function unregisterHook ($Name, callable $Hook) {
      // Treat all hooks lower-case to prevent dupes
      $Class = strtolower (get_called_class ());
      $Name = strtolower ($Name);
      
      // Check if there is such hook known
      if (!isset (self::$classHooks [$Class][$Name]))
        return;
    
      // Search for the callback
      foreach (self::$classHooks [$Class][$Name] as $ID=>$Callback)
        if ($Callback [0] === $Hook)
          unset (self::$classHooks [$Class][$Name][$ID]);
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
    public function getHooks ($Name) {
      // Treat all hooks lower-case to prevent dupes
      $Name = strtolower ($Name);
      
      // Check wheter to adapt hooks from class-scopes
      if (!isset ($this->hooksAdapted [$Name])) {
        // Retrive all hooks for this instance
        $Hooks = $this::getRegisteredHooks ($Name);
        
        // Merge into local hooks
        if (isset ($this->Hooks [$Name]))
          $this->Hooks [$Name] = array_merge ($this->Hooks [$Name], $Hooks);
        else
          $this->Hooks [$Name] = $Hooks;
        
        // Indicate that all hooks have been merged
        $this->hooksAdapted [$Name] = true;
      }
      
      // Check if there are hooks registered
      if (isset ($this->Hooks [$Name]))
        return $this->Hooks [$Name];
      
      return array ();
    }
    // }}}
    
    // {{{ addHook
    /**
     * Register a hook for a callback-function
     * 
     * @param string $Name Name of the hookable function
     * @param callable $Callback
     * @param mixed $Private (optional)
     * @param bool $Once (optional) Use the hook only once
     * 
     * @access public
     * @return bool
     **/
    public function addHook ($Name, callable $Callback, $Private = null, $Once = false) {
      // Treat all hooks lower-case to prevent dupes
      $Name = strtolower ($Name);
      
      // Check if the named hook exists
      if (!is_callable (array ($this, $Name))) {
        trigger_error ('Invalid Hook "' . $Name . '" on ' . get_class ($this));
        
        return false;
      }
      
      // Register the hook
      if (isset ($this->Hooks [$Name])) {
        // Check if the callback is already registered
        foreach ($this->Hooks [$Name] as $ID=>$Hook)
          if (($Hook [0] === $Callback) && ($Hook [1] === $Private)) {
            // Just handle the once-bit
            if (!$Once)
              $this->Hooks [$Name][$ID][2] = false;
            
            return true;
          }
        
        $this->Hooks [$Name][] = array ($Callback, $Private, $Once);
      } else
        $this->Hooks [$Name] = array (array ($Callback, $Private, $Once));
      
      return true;
    }
    // }}}
    
    // {{{ removeHook
    /**
     * Remove an registered hook again
     * 
     * @param string $Name Name of the hookable function
     * @param callable $Callback
     * 
     * @access public
     * @return void
     **/
    public function removeHook ($Name, callable $Callback) {
      // Treat all hooks lower-case to prevent dupes
      $Name = strtolower ($Name);
      
      // Check if there is such hook known
      if (!isset ($this->Hooks [$Name]))
        return;
      
      // Search for the callback
      foreach ($this->Hooks [$Name] as $ID=>$Hook)
        if ($Hook [0] === $Callback)
          unset ($this->Hooks [$Name][$ID]);
    }
    // }}}
    
    // {{{ once
    /**
     * Register a hook that is triggered once when a given event raises for the first time
     * 
     * @param string $Name Name of the hookable function
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function once ($Name) : qcEvents_Promise {
      return new qcEvents_Promise (
        function ($resolve, $reject) use ($Name) {
          $Callback = function () use ($resolve) {
            call_user_func_array ($resolve, array_slice (func_get_args (), 1, -1));
          };
          
          if (!$this->addHook ($Name, $Callback, null, true))
            $reject ('Could not register hook');
        }
      );
    }
    // }}}
    
    // {{{ ___callback
    /**
     * Fire a callback
     * 
     * @param string $Callback Name of the callback
     * @param ...
     * 
     * @access protected
     * @return mixed
     **/
    protected function ___callback ($Name) {
      // Output debug-info
      if (defined ('QCEVENTS_DEBUG_HOOKS') || self::$debugHooks)
        echo substr (number_format (microtime (true), 4, '.', ''), -8, 8), ' Callback: ', $Name, ' on ', get_class ($this), "\n";
      
      // We are treating hooks in lower-case
      $Name = strtolower ($Name);
      
      // Make sure we have all registered hooks
      if (!isset ($this->hooksAdapted [$Name]))
        $this->getHooks ($Name);
      
      // Retrive all given parameters
      $Args = func_get_args ();
      
      // Prepare arguements for external callbacks
      $eArgs = $Args;
      $eArgs [0] = $this;
      $eArgs [] = null;
      $ePrivate = count ($eArgs) - 1;
      
      // Prepare arguements for internal callbacks
      array_shift ($Args);
      
      $lArgs = $Args;
      $lArgs [] = null;
      $lPrivate = count ($lArgs) - 1;
      
      // Check hooks
      if (isset ($this->Hooks [$Name]))
        foreach ($this->Hooks [$Name] as $ID=>$Callback) {
          // Prepare arguements
          $lArgs [$lPrivate] = $eArgs [$ePrivate] = $Callback [1];
          
          // Call the hook
          if (!is_array ($Callback [0]) || ($Callback [0][0] !== $this))
            $rc = call_user_func_array ($Callback [0], $eArgs);
          else
            $rc = call_user_func_array ($Callback [0], $lArgs);
          
          // Remove the hook if it should only be called once
          if ($Callback [2])
            unset ($this->Hooks [$Name][$ID]);
          
          // Exit the loop if the hook failed
          if ($rc === false)
            return false;
        }
         
      // Check if the callback is available
      $Callback = array ($this, $Name);
      
      // Issue the callback
      if ($rc = is_callable ($Callback))
        $rc = call_user_func_array ($Callback, $Args);
      
      if (defined ('QCEVENTS_DEBUG_HOOKS') || self::$debugHooks)
        echo substr (number_format (microtime (true), 4, '.', ''), -8, 8), ' Done:     ', $Name, ' on ', get_class ($this), "\n";
      
      return $rc;
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

?>
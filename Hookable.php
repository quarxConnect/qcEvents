<?PHP

  /**
   * qcEvents - Generic Hookable Class
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
  
  abstract class qcEvents_Hookable {
    private $Hooks = array ();
    
    // {{{ addHook
    /**
     * Register a hook for a callback-function
     * 
     * @param string $Name Name of the hookable function
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return bool
     **/
    public function addHook ($Name, callable $Callback, $Private = null) {
      // Treat all hooks lower-case to prevent dupes
      $Name = strtolower ($Name);
      
      // Check if the named hook exists
      if (!is_callable (array ($this, $Name)))
        return false;
      
      // Register the hook
      if (!isset ($this->Hooks [$Name]))
        $this->Hooks [$Name] = array (array ($Callback, $Private));
      else
        $this->Hooks [$Name][] = array ($Callback, $Private);
      
      return true;
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
      // We are treating hooks in lower-case
      $Name = strtolower ($Name);
      
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
        foreach ($this->Hooks [$Name] as $Callback) {
          $lArgs [$lPrivate] = $eArgs [$ePrivate] = $Callback [1];
          
          if (!is_array ($Callback [0]) || ($Callback [0][0] !== $this))
            $rc = call_user_func_array ($Callback [0], $eArgs);
          else
            $rc = call_user_func_array ($Callback [0], $lArgs);
          
          if ($rc === false)
            return false;
        }
         
      // Check if the callback is available
      $Callback = array ($this, $Name);
      
      if (!is_callable ($Callback))
        return false;
      
      // Issue the callback
      return call_user_func_array ($Callback, $Args);
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
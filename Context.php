<?PHP

  /**
   * qcEvents - Simple Contextual Storage
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
  
  /**
   * Context
   * -------
   * Manage states and flows in async structures
   * 
   * @class qcEvents_Context
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Context {
    /* Callback to raise when this object is invoked as a function-call */
    private $Callback = null;
    
    /* Registered errors */
    private $Errors = array ();
    
    // {{{ __construct
    /**
     * Create a new context-instance
     * 
     * @param callable $Callback (optional) A callback to invoke when this context is used as callback
     * 
     * @access friendly
     * @return void
     **/
    function __construct (callable $Callback = null) {
      $this->Callback = $Callback;
    }
    // }}}
    
    // {{{ __invoke
    /**
     * Make the object callable to allow this to be passed as callback
     * 
     * @access friendly
     * @return void
     **/
    function __invoke () {
      if ($this->Callback)
        return call_user_func_array ($this->Callback, func_get_args ());
      
      trigger_error ('Context used as callback, but no callback defined');
    }
    // }}}
    
    public function getErrors () {
      return $this->Errors;
    }
    
    // {{{ pushError
    /**
     * Register an error for this context
     * 
     * @param mixed $Code
     * @param string $Message
     * 
     * @access public
     * @return void
     **/
    public function pushError ($Code, $Message) {
      $this->Errors [$Code] = $Message;
    }
    // }}}
  }    

?>
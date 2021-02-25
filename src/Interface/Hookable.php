<?php

  /**
   * quarxConnect Events - Interface for hookable functions/events
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

  namespace quarxConnect\Events\Interface;
  use quarxConnect\Events;
  
  interface Hookable {
    // {{{ getRegisteredHooks
    /**
     * Retrive registered hooks from this class
     * 
     * @param string $Name (optional) Name of the hook
     * 
     * @access public
     * @return array
     **/
    public static function getRegisteredHooks ($Name = null);
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
    public static function registerHook ($Name, callable $Hook, $Private = null, $Once = false);
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
    public static function unregisterHook ($Name, callable $Hook);
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
    public function getHooks ($Name);
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
    public function addHook ($Name, callable $Callback, $Private = null, $Once = false);
    // }}}
    
    // {{{ removeHook
    /**
     * Remove a hook for a callback-function
     * 
     * @param string $Name Name of the hookable function
     * @param callable $Callback
     * 
     * @access public
     * @return void
     **/
    public function removeHook ($Name, callable $Callback);
    // }}}
    
    // {{{ once
    /**
     * Register a hook that is triggered once when a given event raises for the first time
     * 
     * @param string $Name Name of the hookable function
     * 
     * @access public
     * @return Events\Promise
     **/
    public function once ($Name) : Events\Promise;
    // }}}
  }

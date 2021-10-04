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

  namespace quarxConnect\Events\ABI;
  use quarxConnect\Events;
  
  interface Hookable {
    // {{{ getRegisteredHooks
    /**
     * Retrive registered hooks from this class
     * 
     * @param string $hookName (optional) Name of the hook
     * 
     * @access public
     * @return array
     **/
    public static function getRegisteredHooks (string $hookName = null) : array;
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
    public static function registerHook (string $hookName, callable $eventCallback, bool $onlyOnce = false) : void;
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
    public static function unregisterHook (string $hookName, callable $eventCallback) : void;
    // }}}
    
    
    // {{{ getHooks
    /**
     * Retrive all registered hooks for a given callback-function
     * 
     * @param string $hookName Name of the hookable function
     * 
     * @access public
     * @return array
     **/
    public function getHooks (string $hookName) : array;
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
    public function addHook (string $hookName, callable $eventCallback, bool $onlyOnce = false) : void;
    // }}}
    
    // {{{ removeHook
    /**
     * Remove a hook for a callback-function
     * 
     * @param string $hookName Name of the hookable function
     * @param callable $eventCallback
     * 
     * @access public
     * @return void
     **/
    public function removeHook (string $hookName, callable $eventCallback) : void;
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
    public function once (string $hookName) : Events\Promise;
    // }}}
  }

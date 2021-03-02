<?php

  /**
   * quarxConnect Events - Interface for Servers
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
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/
  
  declare (strict_types=1);

  namespace quarxConnect\Events\Interface;
  use quarxConnect\Events;
  
  interface Server extends Hookable {
    // {{{ setChildClass
    /**
     * Set class to use for incoming connections
     * 
     * @param string $Classname
     * @param bool $Piped (optional) Treat the class as pipe-consumer, not as socket
     * 
     * @access public
     * @return bool
     **/
    public function setChildClass (string $Classname, bool $Piped = false) : bool;
    // }}}
    
    // {{{ addChildHook
    /**
     * Register a hook for new children
     * 
     * @param string $Hook
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function addChildHook (string $Name, callable $Callback, $Private = null) : void;
    // }}}
    
    // {{{ getLocalName
    /**
     * Retrive the local sock-addr-spec of this server
     * 
     * @access public
     * @return string
     **/
    public function getLocalName () : string;
    // }}}
    
    // {{{ getLocalPort
    /**
     * Retrive the local port of this server
     * 
     * @access public
     * @return int
     **/
    public function getLocalPort () : int;
    // }}}
    
    // {{{ listen
    /**
     * Create a the server-process
     * 
     * @param enum $Type
     * @param int $Port (optional)
     * @param string $Host (optional)
     * @param int $Backlog (optional)
     * 
     * @access public
     * @return bool
     **/
    public function listen (int $Type, int $Port = null, string $Host = null, int $Backlog = null) : bool;
    // }}}
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @access public
     * @return Events\Promise
     **/
    public function close () : Events\Promise;
    // }}}
    
    
    // {{{ serverOnline
    /**
     * Callback: The server went into listening state
     * 
     * @access protected
     * @return void
     **/
    # protected function serverOnline () : void;
    // }}}
    
    // {{{ serverOffline
    /**
     * Callback: The server was closed
     * 
     * @access protected
     * @return void
     **/
    # protected function serverOffline () : void;
    // }}}
    
    // {{{ serverClientAccept
    /**
     * Callback: Accept a new incoming connection
     * 
     * @param string $Remote
     * @param resource $Socket (optional) Stream-Resource for TCP-Connections
     * 
     * @access protected
     * @return bool If FALSE the connection is discared
     **/
    # protected function serverClientAccept (string $Remote, $Socket = null) : ?bool;
    // }}}
    
    // {{{ serverClientNew
    /**
     * Callback: A new client was created
     * 
     * @param Events\Socket $Client
     * @param mixed $Consumer
     * 
     * @access protected
     * @return void
     **/
    # protected function serverClientNew (Events\Socket $Socket, $Consumer = null) : void;
    // }}}
    
    // {{{ serverClientClosed
    /**
     * Callback: Client-Connection was/will be closed
     * 
     * @param string $Remote
     * @param Events\Socket $Client
     * 
     * @access protected
     * @return void
     **/
    # protected function serverClientClosed (string $Remote, Events\Socket $Socket) : void;
    // }}}
  }

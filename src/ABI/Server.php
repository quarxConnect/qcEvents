<?php

  /**
   * quarxConnect Events - Interface for Servers
   * Copyright (C) 2019-2022 Bernd Holzmueller <bernd@quarxconnect.de>
   * Copyright (C) 2023-2025 Bernd Holzmueller <bernd@innorize.gmbh>
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

  interface Server extends Hookable {
    // {{{ setChildClass
    /**
     * Set class to use for incoming connections
     * 
     * @param string $childClassname
     * @param bool $isPiped (optional) Treat the class as pipe-consumer, not as socket
     * 
     * @access public
     * @return void
     **/
    public function setChildClass (string $childClassname, bool $isPiped = false) : void;
    // }}}
    
    // {{{ addChildHook
    /**
     * Register a hook for new children
     * 
     * @param string $hookName
     * @param callable $eventCallback
     * @param bool $onlyOnce (optional)
     * 
     * @access public
     * @return void
     **/
    public function addChildHook (string $hookName, callable $eventCallback, bool $onlyOnce = false) : void;
    // }}}
    
    // {{{ getLocalName
    /**
     * Retrieve the local sock-addr-spec of this server
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
     * Start waiting for incoming connections
     *
     * @param int $socketType
     * @param int|null $serverPort (optional)
     * @param string|null $serverHost (optional)
     * @param int|null $socketBacklog (optional)
     *
     * @return void
     **/
    public function listen (
      int $socketType,
      int $serverPort = null,
      string $serverHost = null,
      int $socketBacklog = null
    ): void;
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

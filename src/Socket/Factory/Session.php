<?php

  /**
   * quarxConnect Events - Socket Factory Session
   * Copyright (C) 2017-2022 Bernd Holzmueller <bernd@quarxconnect.de>
   * 
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   * 
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
   * GNU General Public License for more details.
   * 
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/
  
  declare (strict_types=1);

  namespace quarxConnect\Events\Socket\Factory;
  use quarxConnect\Events;
  
  class Session extends Events\Hookable implements Events\ABI\Socket\Factory {
    private $socketFactory = null;
    
    // {{{ __construct
    /**
     * Create a new pool-session
     * 
     * @param Events\ABI\Socket\Factory $socketFactory
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Events\ABI\Socket\Factory $socketFactory) {
      $this->socketFactory = $socketFactory;
    }
    // }}}
    
    // {{{ getEventBase
    /**
     * Retrive the handle of the current event-loop-handler
     * 
     * @access public
     * @return Events\Base
     **/
    public function getEventBase () : ?Events\Base {
      return $this->socketFactory->getEventBase ();
    }
    // }}}

    // {{{ setEventBase
    /**
     * Set the Event-Base of this source
     * 
     * @param Events\Base $eventBase
     * 
     * @access public
     * @return void
     **/
    public function setEventBase (\quarxConnect\Events\Base $eventBase) : void {
      $this->socketFactory->setEventBase ($eventBase);
    }
    // }}}

    // {{{ unsetEventBase
    /**
     * Remove any assigned event-loop-handler
     * 
     * @access public
     * @return void
     **/
    public function unsetEventBase () : void {
      $this->socketFactory->unsetEventBase ();
    }
    // }}}
    
    // {{{ getSocketFactory
    /**
     * Retrive the socket-pool of this session
     * 
     * @access public
     * @return Events\ABI\Socket\Factory
     **/
    public function getSocketFactory () : Events\ABI\Socket\Factory {
      return $this->socketFactory;
    }
    // }}}
    
    // {{{ createConnection
    /**
     * Request a socket from this pool-session
     * 
     * @param array|string $remoteHost
     * @param int $remotePort
     * @param int $socketType
     * @param bool $useTLS (optional)
     * @param bool $allowReuse (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function createConnection ($remoteHost, int $remotePort, int $socketType, bool $useTLS = false, bool $allowReuse = false) : Events\Promise {
      return $this->socketFactory->createConnection ($remoteHost, $remotePort, $socketType, $useTLS, $allowReuse, $this);
    }
    // }}}
    
    // {{{ releaseConnection
    /**
     * Return a connected socket back to the factory
     * 
     * @param Events\ABI\Stream $leasedConnection
     * 
     * @access public
     * @return void
     **/
    public function releaseConnection (Events\ABI\Stream $leasedConnection) : void {
      $this->socketFactory->releaseConnection ($leasedConnection);
    }
    // }}}
    
    // {{{ close
    /**
     * Close this session
     * 
     * @access public
     * @return void
     **/
    public function close () : void {
      $this->socketFactory->removeSession ($this);
    }
    // }}}
  }

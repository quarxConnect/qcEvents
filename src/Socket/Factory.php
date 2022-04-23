<?php

  /**
   * quarxConnect Events - Socket Factory
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

  namespace quarxConnect\Events\Socket;
  use quarxConnect\Events;
  
  class Factory extends Events\Hookable implements Events\ABI\Socket\Factory {
    use Events\Feature\Based;
    
    // {{{ __construct
    /**
     * Create a new socket-pool
     * 
     * @param Events\Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Events\Base $eventBase) {
      $this->setEventBase ($eventBase);
    }
    // }}}
    
    // {{{ createConnection
    /**
     * Request a connected socket from this factory
     * 
     * @param array|string $remoteHost
     * @param int $remotePort
     * @param int $socketType
     * @param bool $useTLS (optional)
     * @param bool $allowReuse (optional)
     * @param Pool\Session $poolSession (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function createConnection ($remoteHost, int $remotePort, int $socketType, bool $useTLS = false, bool $allowReuse = false, Pool\Session $poolSession = null) : Events\Promise {
      // Sanatize socket-type
      if (
        ($socketType != Events\Socket::TYPE_TCP) &&
        ($socketType != Events\Socket::TYPE_UDP)
      )
        return Events\Promise::reject ('Invalid socket-type given');
      
      // Sanatize the port
      if (
        ($remotePort < 1) ||
        ($remotePort > 0xffff)
      )
        return Events\Promise::reject ('Invalid port given');
      
      // Make sure remote host is an array
      if (!is_array ($remoteHost))
        $remoteHost = [ $remoteHost ];
      
      // Try to connect
      $newSocket = new Events\Socket ($this->getEventBase ());
      
      return $newSocket->connect (
        $remoteHost,
        $remotePort,
        $socketType,
        $useTLS
      )->then (
        function () use ($newSocket) {
          return $newSocket;
        }
      );
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
      
    }
    // }}}
  }

<?php

  /**
   * quarxConnect Events - Socks Socket Factory
   * Copyright (C) 2020-2023 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  namespace quarxConnect\Events\Socket\Factory;

  use quarxConnect\Events\ABI;
  use quarxConnect\Events\Base;
  use quarxConnect\Events\Emitter;
  use quarxConnect\Events\Feature;
  use quarxConnect\Events\Promise;
  use quarxConnect\Events\Socket;
  use quarxConnect\Events\Stream;
  
  class Socks extends Emitter implements ABI\Socket\Factory {
    use Feature\Based;
    
    /* Hostname/IP of our SOCKS-Server */
    private $socksHost = '::1';
    
    /* Port of our SOCKS-Server */
    private $socksPort = 9050;
    
    // {{{ __construct
    /**
     * Create a new SOCKS-Socket-Factory
     * 
     * @param Base $eventBase
     * @param mixed $socksHost
     * @param int $socksPort
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Base $eventBase, $socksHost, int $socksPort) {
      $this->setEventBase ($eventBase);
      
      $this->socksHost = $socksHost;
      $this->socksPort = $socksPort;
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
     * 
     * @access public
     * @return Promise
     **/
    public function createConnection (
      array|string $remoteHost,
      int $remotePort,
      int $socketType,
      bool $useTLS = false,
      bool $allowReuse = false
    ): Promise {
      // Sanitize parameters
      if ($useTLS)
        return Promise::reject ('No TLS-Support yet');
      
      if (!is_array ($remoteHost))
        $remoteHost = [ $remoteHost ];
      
      if (count ($remoteHost) < 1)
        return Promise::reject ('Empty list of hosts');
      
      // Connect to SOCKS-Server
      $socksSocket = new Socket ($this->getEventBase ());
      $myHost = array_shift ($remoteHost);
      
      return $socksSocket->connect (
        $this->socksHost,
        $this->socksPort,
        $socksSocket::TYPE_TCP
      )->then (
        function () use ($socksSocket, $myHost, $remoteHost, $remotePort, $socketType) {
          // Negotiate SOCKS-Connection
          $socksStream = new Stream\Socks ();
          
          return $socksSocket->pipeStream ($socksStream)->then (
            function () use ($socksStream, $myHost, $remoteHost, $remotePort, $socketType) {
              // Try to connect to requested host
              return $socksStream->connect (
                $myHost,
                $remotePort,
                $socketType
              )->catch (
                function () use ($remoteHost, $remotePort, $socketType) {
                  // Check for remaining hosts
                  if (count ($remoteHost) > 0)
                    return $this->createConnection ($remoteHost, $remotePort, $socketType, false, false);
                  
                  // Just pass the rejection
                  throw new Promise\Solution (func_get_args ());
                }
              );
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ releaseConnection
    /**
     * Return a connected socket back to the factory
     * 
     * @param ABI\Stream $leasedConnection
     * 
     * @access public
     * @return void
     **/
    public function releaseConnection (ABI\Stream $leasedConnection): void {
      
    }
    // }}}
  }

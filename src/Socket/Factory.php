<?php

  /**
   * quarxConnect Events - Socket Factory
   * Copyright (C) 2017-2023 Bernd Holzmueller <bernd@quarxconnect.de>
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

  use quarxConnect\Events\ABI;
  use quarxConnect\Events\Base;
  use quarxConnect\Events\Emitter;
  use quarxConnect\Events\Feature;
  use quarxConnect\Events\Promise;
  use quarxConnect\Events\Socket;
  use quarxConnect\Events\Socket\Exception\InvalidPort;
  use quarxConnect\Events\Socket\Exception\InvalidType;

  class Factory extends Emitter implements ABI\Socket\Factory {
    use Feature\Based;

    /**
     * Idle-Timeout for unused sockets
     **/
    public const IDLE_TIMEOUT = 5.0;

    /**
     * List of useable sockets
     *
     * @var array
     **/
    private array $usableSockets = [];

    /**
     * List of sockets that are leased by may be reused
     *
     * @var array
     **/
    private array $leasedSockets = [];

    // {{{ __construct
    /**
     * Create a new socket-pool
     *
     * @param Base $eventBase
     *
     * @access friendly
     * @return void
     **/
    function __construct (Base $eventBase) {
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
     * @return Promise
     **/
    public function createConnection (
      array|string $remoteHost,
      int $remotePort,
      int $socketType,
      bool $useTLS = false,
      bool $allowReuse = false,
      Pool\Session $poolSession = null
    ): Promise {
      // Sanitize socket-type
      if (
        ($socketType != Socket::TYPE_TCP) &&
        ($socketType != Socket::TYPE_UDP)
      )
        return Promise::reject (new InvalidType ());

      // Sanitize the port
      if (
        ($remotePort < 0x0001) ||
        ($remotePort > 0xffff)
      )
        return Promise::reject (new InvalidPort ());

      // Make sure remote host is an array
      if (!is_array ($remoteHost))
        $remoteHost = [ $remoteHost ];

      // Try to connect
      $newSocket = new Socket ($this->getEventBase ());

      return $newSocket->connect (
        $remoteHost,
        $remotePort,
        $socketType,
        $useTLS
      )->then (
        function () use ($newSocket, $allowReuse) {
          if ($allowReuse) {
            $this->leasedSockets [] = $newSocket;

            $newSocket->addHook (
              'eventClosed',
              function () use ($newSocket): void {
                $socketIndex = array_search ($this->leasedSockets, $newSocket, true);

                if ($socketIndex) {
                  unset ($this->leasedSockets [$socketIndex]);

                  return;
                }

                $socketIndex = array_search ($this->useableSockets, $newSocket, true);

                if ($socketIndex)
                  unset ($this->useableSockets [$socketIndex]);
              },
              true
            );
          }

          return $newSocket;
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
      $socketIndex = array_search ($leasedConnection, $this->leasedSockets, true);

      if ($socketIndex) {
        $this->useableSockets [] = $leasedConnection;

        $leasedConnection->setIdleTimeout (self::IDLE_TIMEOUT);

        unset ($this->leasedSockets [$socketIndex]);
      } else
        $leasedConnection->close ();
    }
    // }}}
  }

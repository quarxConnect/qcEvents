<?php

  /**
   * quarxConnect Events - Socket Factory
   * Copyright (C) 2017-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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

  use InvalidArgumentException;

  use quarxConnect\Events\ABI;
  use quarxConnect\Events\Base;
  use quarxConnect\Events\Emitter;
  use quarxConnect\Events\Feature;
  use quarxConnect\Events\Promise;
  use quarxConnect\Events\Socket;
  use quarxConnect\Events\Socket\Exception\Disconnected;
  use quarxConnect\Events\Socket\Exception\InvalidPort;
  use quarxConnect\Events\Socket\Exception\InvalidType;

  class Factory extends Emitter implements ABI\Socket\Factory
  {
    use Feature\Based;

    private const STATE_CONNECTING = 0x00;
    private const STATE_CONNECTED  = 0x01;
    private const STATE_LEASED     = 0x02;
    private const STATE_EXCLUSIVE  = 0x03;

    /**
     * Idle-Timeout for unused sockets
     **/
    public const IDLE_TIMEOUT = 5.0;

    /**
     * List of all sockets
     *
     * @var array
     **/
    private array $socketInstances = [];

    /**
     * List of consumers for our sockets
     *
     * @var array
     **/
    private array $socketConsumers = [];

    /**
     * Status of our sockets
     *
     * @var array
     **/
    private array $socketStates = [];

    /**
     * Index for the next socket
     *
     * @var int
     **/
    private int $nextIndex = 0;

    // {{{ __construct
    /**
     * Create a new socket-pool
     *
     * @param Base $eventBase
     *
     * @access friendly
     * @return void
     **/
    public function __construct (Base $eventBase)
    {
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
     * @param Pool\Session|null $poolSession (optional)
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
    ): Promise
    {
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

      // Try to find a reusable socket
      foreach ($this->socketStates as $socketIndex=>$socketState)
        if (
          ($socketState === self::STATE_CONNECTED) &&
          ($this->socketInstances [$socketIndex]->getType () === $socketType) &&
          ($this->socketInstances [$socketIndex]->getRemotePort () === $remotePort) &&
          (
            in_array ($this->socketInstances [$socketIndex]->getRemoteHost (), $remoteHost) ||
            in_array ($this->socketInstances [$socketIndex]->getRemoteAddress (), $remoteHost)
          )
        ) {
          $this->socketStates [$socketIndex] = ($allowReuse ? self::STATE_LEASED : self::STATE_EXCLUSIVE);

          return Promise::resolve ($this->socketInstances [$socketIndex], $this->socketConsumers [$socketIndex] ?? null);
        }

      // Try to connect
      $socketIndex = $this->nextIndex++;

      $theSocket = new Socket ($this->getEventBase ());
      $connectedEvent = new Event\Connected ($theSocket);

      $this->socketInstances [$socketIndex] = $theSocket;
      $this->socketStates [$socketIndex] = self::STATE_CONNECTING;

      return $theSocket->connect (
        $remoteHost,
        $remotePort,
        $socketType,
        $useTLS
      )->then (
        fn (): Promise => $this->dispatch ($connectedEvent)
      )->then (
        function () use ($theSocket, $connectedEvent, $allowReuse, $socketIndex): Promise\Solution
        {
          // Check if the socket is still connected
          if (!$theSocket->isConnected ())
            throw new Disconnected ();

          // Store the consumer if there is one
          if ($connectedEvent->theConsumer)
            $this->socketConsumers [$socketIndex] = $connectedEvent->theConsumer;

          // Update the state of the socket
          $this->socketStates [$socketIndex] = ($allowReuse ? self::STATE_LEASED : self::STATE_EXCLUSIVE);

          // Watch close-event
          $theSocket->addHook (
            'eventClosed',
            function () use ($socketIndex): void
            {
              unset (
                $this->socketStates [$socketIndex],
                $this->socketInstances [$socketIndex]
              );
            },
            true
          );

          // Forward the socket
          return new Promise\Solution ([ $theSocket, $connectedEvent->theConsumer ]);
        },
        function () use ($socketIndex): void
        {
          unset (
            $this->socketStates [$socketIndex],
            $this->socketInstances [$socketIndex]
          );

          throw new Promise\Solution (func_get_args ());
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
    public function releaseConnection (ABI\Stream $leasedConnection): void
    {
      // Find the socket
      $socketIndex = array_search ($leasedConnection, $this->socketInstances, true);

      if ($socketIndex === false) {
        // Don't be too harsh if the connection already was disconnected
        if (
          ($leasedConnection instanceof Socket) &&
          $leasedConnection->isDisconnected ()
        )
          return;

        throw new InvalidArgumentException ('The socket was not created here');
      }

      // Check if the socket must not be reused
      if ($this->socketStates [$socketIndex] === self::STATE_EXCLUSIVE) {
        $leasedConnection->close ();

        return;
      // Sanitize the state
      } elseif ($this->socketStates [$socketIndex] !== self::STATE_LEASED)
        throw new InvalidArgumentException ('Socket not in leased state');

      // Move back to connected state
      $this->socketStates [$socketIndex] = self::STATE_CONNECTED;

      // Set timeout on the socket
      if ($leasedConnection instanceof Socket)
        $leasedConnection->setIdleTimeout (self::IDLE_TIMEOUT);
    }
    // }}}
  }

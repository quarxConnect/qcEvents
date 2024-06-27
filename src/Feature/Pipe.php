<?php

  /**
   * quarxConnect Events - Pipe Trait
   * Copyright (C) 2015-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events\Feature;

  use quarxConnect\Events;

  trait Pipe {
    public static int $pipeBlockSize = 40960;

    /* Pipe-References */
    private array $activePipes = [];

    // {{{ isPiped
    /**
     * Check if this handle is piped to others
     *
     * @access public
     * @return bool
     **/
    public function isPiped (): bool
    {
      return (count ($this->activePipes) > 0);
    }
    // }}}

    // {{{ getPipeConsumers
    /**
     * Retrieve all handles that we are piped to
     *
     * @access public
     * @return array
     **/
    public function getPipeConsumers (): array
    {
      $Result = [];

      foreach ($this->activePipes as $Pipe)
        $Result [] = $Pipe [0];

      return $Result;
    }
    // }}}

    // {{{ pipe
    /**
     * Forward any data received from this source to another handler
     *
     * @param Events\ABI\Consumer $dataReceiver
     * @param bool $forwardClose (optional) Raise close on the handler if we are finished (default)
     *
     * @access public
     * @return Events\Promise
     **/
    public function pipe (Events\ABI\Consumer $dataReceiver, bool $forwardClose = true): Events\Promise
    {
      // Try to register the pipe
      if ($this->registerPipe ($dataReceiver, $forwardClose) === false)
        return Events\Promise::resolve ();

      // Try to initialize consumer
      return $dataReceiver->initConsumer ($this)->then (
        function (callable $customConsumer = null) use ($dataReceiver): void {
          // Register a that new pipe
          if ($customConsumer)
            $this->activePipes [$this->getPipeHandlerKey ($dataReceiver)][2] = $customConsumer;
        }
      );
    }
    // }}}

    // {{{ pipeStream
    /**
     * Create a bidirectional pipe
     *
     * Forward any data received from this source to another handler and
     * allow the handler to write back to this stream
     *
     * @param Events\ABI\Stream\Consumer $dataReceiver
     * @param bool $forwardClose (optional) Raise close on the handler if we are finished (default)
     *
     * @access public
     * @return Events\Promise
     **/
    public function pipeStream (Events\ABI\Stream\Consumer $dataReceiver, bool $forwardClose = true): Events\Promise
    {
      // Try to register the pipe
      if ($this->registerPipe ($dataReceiver, $forwardClose) === false)
        return Events\Promise::resolve ();

      // Try to initialize consumer
      return $dataReceiver->initStreamConsumer ($this)->then (
        function (callable $customConsumer = null) use ($dataReceiver): void {
          // Register a that new pipe
          if ($customConsumer)
            $this->activePipes [$this->getPipeHandlerKey ($dataReceiver)][2] = $customConsumer;
        }
      );
    }
    // }}}

    // {{{ registerPipe
    /**
     * Register a new pipe
     *
     * @param Events\ABI\Consumer\Common $dataReceiver
     * @param bool $forwardClose
     *
     * @return bool
     **/
    private function registerPipe (Events\ABI\Consumer\Common $dataReceiver, bool $forwardClose = true): bool
    {
      // Check if there is already such pipe
      $key = $this->getPipeHandlerKey ($dataReceiver);

      if ($key !== null) {
        $this->activePipes [$key][1] = $forwardClose;

        return false;
      }

      // Make sure we are receiving data
      if (!$this->isPiped ()) {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->addHook ('eventReadable', [ $this, '___pipeDo' ]);

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->addHook ('eventClosed', [ $this, '___pipeClose' ]);
      }

      // Make sure we are being informed about changes on the handler itself
      /** @noinspection PhpUnhandledExceptionInspection */
      $dataReceiver->addHook ('eventClosed', [ $this, '___pipeHandlerClose' ]);

      // Register a that new pipe
      $this->activePipes [] = [ $dataReceiver, $forwardClose, null ];

      return true;
    }
    // }}}

    // {{{ unpipe
    /**
     * Remove a handler that is currently being piped
     *
     * @param Events\ABI\Consumer\Common $dataReceiver
     *
     * @access public
     * @return Events\Promise
     **/
    public function unpipe (Events\ABI\Consumer\Common $dataReceiver): Events\Promise
    {
      // Check if there is already such pipe
      if (($key = $this->getPipeHandlerKey ($dataReceiver)) === null)
        return Events\Promise::resolve ();

      // Remove the pipe-reference
      unset ($this->activePipes [$key]);

      // Raise an event at the handler
      return $dataReceiver->deinitConsumer ($this);
    }
    // }}}

    // {{{ getPipeHandlerKey
    /**
     * Search the internal key for a given handler
     *
     * @param Events\ABI\Consumer\Common $dataReceiver
     *
     * @access private
     * @return int|null
     **/
    private function getPipeHandlerKey (Events\ABI\Consumer\Common $dataReceiver): ?int
    {
      foreach ($this->activePipes as $key=>$Pipe)
        if ($Pipe [0] === $dataReceiver)
          return $key;

      return null;
    }
    // }}}

    // {{{ ___pipeDo
    /**
     * Callback: Data is available, process all pipes
     *
     * @access public
     * @return void
     **/
    public function ___pipeDo (): void
    {
      // Check if there are pipes to process
      if (count ($this->activePipes) == 0) {
        $this->removeHook ('eventReadable', [ $this, '___pipeDo' ]);
        $this->removeHook ('eventClosed', [ $this, '___pipeClose' ]);

        return;
      }

      // Try to read the data
      $Data = $this->read ($this::$pipeBlockSize);

      if (
        !is_string ($Data) ||
        (strlen ($Data) < 1)
      )
        return;

      // Process all pipes
      foreach ($this->activePipes as $Pipe)
        if ($Pipe [2])
          call_user_func ($Pipe [2], $Data, $this);
        else
          $Pipe [0]->consume ($Data, $this);
    }
    // }}}

    // {{{ ___pipeClose
    /**
     * Callback: The readable stream was/is being closed
     *
     * @access public
     * @return void
     **/
    public function ___pipeClose (): void
    {
      // Forward the close to all piped handles
      foreach ($this->activePipes as $Pipe) {
        if ($Pipe [1]) {
          if (is_callable ([ $Pipe [0], 'finishConsume' ]))
            $Pipe [0]->finishConsume ();
          else
            $Pipe [0]->close ();
        }

        $Pipe [0]->removeHook ('eventClosed', [ $this, '___pipeHandlerClose' ]);
        $Pipe [0]->deinitConsumer ($this);
      }

      // Reset the local register
      $this->activePipes = [];

      // Unregister hooks
      $this->removeHook ('eventReadable', [ $this, '___pipeDo' ]);
      $this->removeHook ('eventClosed', [ $this, '___pipeClose' ]);
    }
    // }}}

    // {{{ ___pipeHandlerClose
    /**
     * Callback: A piped handler was closed
     *
     * @param Events\ABI\Consumer\Common $dataReceiver
     *
     * @access public
     * @return void
     **/
    public function ___pipeHandlerClose (Events\ABI\Consumer\Common $dataReceiver): void {
      // Lookup the handler and remove
      $key = $this->getPipeHandlerKey ($dataReceiver);

      if ($key !== null) {
        $dataReceiver->removeHook ('eventClosed', [ $this, '___pipeHandlerClose' ]);

        unset ($this->activePipes [$key]);
      }

      // Check if there are consumers left
      if (count ($this->activePipes) == 0) {
        $this->removeHook ('eventReadable', [ $this, '___pipeDo' ]);
        $this->removeHook ('eventClosed', [ $this, '___pipeClose' ]);
      }
    }
    // }}}
  }

<?php

  /**
   * quarxConnect Events - Websocket Message
   * Copyright (C) 2017-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events\Stream\Websocket;

  use quarxConnect\Events\Stream;
  use quarxConnect\Events;

  class Message extends Events\Virtual\Source {
    /* Default opcode for messages */
    protected const MESSAGE_OPCODE = 0x00;

    /**
     * Opcode of this message
     *
     * @var integer
     **/
    private int $Opcode;

    /**
     * Buffered data after eventClose was raised here
     *
     * @var string
     **/
    private string|null $Buffer = null;

    // {{{ factory
    /**
     * Create a new Websocket-Message with a handler-class suitable for $Opcode
     *
     * @param int $messageOpcode
     *
     * @access public
     * @return Message
     **/
    public static function factory (int $messageOpcode): Message {
      if ($messageOpcode === Close::MESSAGE_OPCODE)
        $messageClass = Close::class;
      elseif ($messageOpcode === Ping::MESSAGE_OPCODE)
        $messageClass = Ping::class;
      elseif ($messageOpcode === Pong::MESSAGE_OPCODE)
        $messageClass = Pong::class;
      else
        $messageClass = get_called_class ();

      return new $messageClass ($messageOpcode);
    }
    // }}}

    // {{{ __construct
    /**
     * Create a new Websocket-Message
     *
     * @param int $messageOpcode (optional)
     * @param string $messagePayload (optional)
     *
     * @access friendly
     * @return void
     **/
    public function __construct (int $messageOpcode = null, string $messagePayload = null) {
      $this->Opcode = $messageOpcode ?? $this::MESSAGE_OPCODE;

      // Collect all pending data on close
      $this->once ('eventClosed')->then (
        function () {
          $this->Buffer = $this->read ();
        }
      );

      // Push payload if there is some
      if ($messagePayload !== null) {
        $this->sourceInsert ($messagePayload);
        $this->close ();
      }
    }
    // }}}

    // {{{ __debugInfo
    /**
     * Return debug-information for var_dump()
     *
     * @access friendly
     * @return array
     **/
    public function __debugInfo (): array {
      return [
        'Opcode' => $this->Opcode,
        'Closed' => $this->isClosed (),
        'Buffer' => $this->Buffer,
      ];
    }
    // }}}

    // {{{ isControlMessage
    /**
     * Check if this message represents a control-message
     *
     * @access public
     * @return bool
     **/
    public function isControlMessage (): bool {
      return (($this->Opcode & Stream\Websocket::OPCODE_CONTROL_MASK) == Stream\Websocket::OPCODE_CONTROL_MASK);
    }
    // }}}

    // {{{ getOpcode
    /**
     * Retrieve the opcode of this message
     *
     * @access public
     * @return int
     **/
    public function getOpcode (): int {
      return $this->Opcode;
    }
    // }}}

    // {{{ getData
    /**
     * Retrieve pending data from this message
     *
     * @access public
     * @return string
     **/
    public function getData (): string {
      if (
        $this->isClosed () &&
        ($this->Buffer !== null)
      )
        return $this->Buffer;

      return $this->read ();
    }
    // }}}
  }

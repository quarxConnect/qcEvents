<?php

  /**
   * quarxConnect Events - Websocket Close-Message
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

  class Close extends Message {
    /* Opcode of this message-class */
    protected const MESSAGE_OPCODE = 0x08;

    /**
     * Code for close-reason
     *
     * @var integer
     **/
    private int $closeCode = 0x00;
    
    /**
     * Additional info for close-reason
     *
     * @var string
     */
    private string $closeReason = '';

    // {{{ __construct
    /**
     * Create a new Close-Message
     *
     * @param int $closeCode (optional)
     * @param string $closeReason (optional)
     *
     * @access friendly
     * @return void
     **/
    public function __construct (int $closeCode = null, string $closeReason = null) {
      // Check whether to prepare payload
      if (
        ($closeCode !== null) ||
        ($closeReason !== null)
      )
        $messagePayload = pack ('n', (int)$closeCode) . $closeReason;
      else
        $messagePayload = null;

      // Initialize at out parent
      parent::__construct ($this::MESSAGE_OPCODE, $messagePayload);

      // Collect all pending data on close
      $this->addHook (
        'eventClosed',
        function (): void {
          // Retrieve the payload
          $Payload = $this->getData ();

          // Reset ourself
          $this->closeCode = 0;
          $this->closeReason = '';

          // Check whether to parse anything from payload
          if (strlen ($Payload) < 2)
            return;

          // Parse the payload
          $this->closeCode = (ord ($Payload [0]) << 8) | ord ($Payload [1]);
          $this->closeReason = substr ($Payload, 2);
        },
        true
      );
    }
    // }}}

    // {{{ getCode
    /**
     * Retrieve the code from this message
     *
     * @access public
     * @return int
     **/
    public function getCode (): int {
      return $this->closeCode;
    }
    // }}}

    // {{{ getReason
    /**
     * Get the reason of this message
     *
     * @access public
     * @return string
     **/
    public function getReason (): string {
      return $this->closeReason;
    }
    // }}}
  }

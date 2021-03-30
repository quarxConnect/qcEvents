<?php

  /**
   * quarxConnect Events - Websocket Message
   * Copyright (C) 2017-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  use \quarxConnect\Events\Stream;
  use \quarxConnect\Events;
  
  class Message extends Events\Virtual\Source {
    /* Default opcode for messages */
    protected const MESSAGE_OPCODE = 0x00;
    
    /* Websocket-Stream assigned to this message */
    private $Stream = null;
    
    /* Opcode of this message */
    private $Opcode = null;
    
    /* Buffered data after eventClose was raised here */
    private $Buffer = null;
    
    // {{{ factory
    /**
     * Create a new Websocket-Message with a handler-class suitable for $Opcode
     * 
     * @param Stream\Websocket $Stream
     * @param int $Opcode
     * 
     * @access public
     * @return Message
     **/
    public static function factory (Stream\Websocket $Stream, int $Opcode) : Message {
      if ($Opcode == Close::MESSAGE_OPCODE)
        $Class = Close::class;
      elseif ($Opcode == Ping::MESSAGE_OPCODE)
        $Class = Ping::class;
      elseif ($Opcode == Pong::MESSAGE_OPCODE)
        $Class = Pong::class;
      else
        $Class = get_called_class ();
      
      return new $Class ($Stream, $Opcode);
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create a new Websocket-Message
     * 
     * @param Stream\Websocket $Stream
     * @param int $Opcode (optional)
     * @param string $Payload (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Stream\Websocket $Stream, int $Opcode = null, string $Payload = null) {
      # $this->Stream = $Stream;
      $this->Opcode = $Opcode ?? self::MESSAGE_OPCODE;
      
      // Collect all pending data on close
      $this->once ('eventClosed')->then (
        function () {
          $this->Buffer = $this->read ();
        }
      );
      
      // Push payload if there is some
      if ($Payload !== null) {
        $this->sourceInsert ($Payload);
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
    function __debugInfo () {
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
    public function isControlMessage () : bool {
      return (($this->Opcode & Stream\Websocket::OPCODE_CONTROL_MASK) == Stream\Websocket::OPCODE_CONTROL_MASK);
    }
    // }}}
    
    // {{{ getOpcode
    /**
     * Retrive the opcode of this message
     * 
     * @access public
     * @return int
     **/
    public function getOpcode () : int {
      return $this->Opcode;
    }
    // }}}
    
    // {{{ getData
    /**
     * Retrive pending data fromt his message
     * 
     * @access public
     * @return string
     **/
    public function getData () : string {
      if ($this->isClosed () && ($this->Buffer !== null))
        return $this->Buffer;
      
      return $this->read ();
    }
    // }}}
  }

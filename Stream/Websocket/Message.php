<?PHP

  /**
   * qcEvents - Websocket Message
   * Copyright (C) 2017 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Abstract/Source.php');
  
  class qcEvents_Stream_Websocket_Message extends qcEvents_Abstract_Source {
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
     * @param qcEvents_Stream_Websocket $Stream
     * @param int $Opcode
     * 
     * @access public
     * @return qcEvents_Stream_Websocket_Message
     **/
    public static function factory (qcEvents_Stream_Websocket $Stream, $Opcode) {
      if ($Opcode == qcEvents_Stream_Websocket_Close::MESSAGE_OPCODE)
        $Class = 'qcEvents_Stream_Websocket_Close';
      elseif ($Opcode == qcEvents_Stream_Websocket_Ping::MESSAGE_OPCODE)
        $Class = 'qcEvents_Stream_Websocket_Ping';
      elseif ($Opcode == qcEvents_Stream_Websocket_Pong::MESSAGE_OPCODE)
        $Class = 'qcEvents_Stream_Websocket_Pong';
      else
        $Class = get_called_class ();
      
      return new $Class ($Stream, $Opcode);
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create a new Websocket-Message
     * 
     * @param qcEvents_Stream_Websocket $Stream
     * @param int $Opcode
     * @param string $Payload (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Stream_Websocket $Stream, $Opcode, $Payload = null) {
      # $this->Stream = $Stream;
      $this->Opcode = $Opcode;
      
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
      return array (
        'Opcode' => $this->Opcode,
        'Closed' => $this->isClosed (),
        'Buffer' => $this->Buffer,
      );
    }
    // }}}
    
    // {{{ isControlMessage
    /**
     * Check if this message represents a control-message
     * 
     * @access public
     * @return bool
     **/
    public function isControlMessage () {
      return (($this->Opcode & qcEvents_Stream_Websocket::OPCODE_CONTROL_MASK) == qcEvents_Stream_Websocket::OPCODE_CONTROL_MASK);
    }
    // }}}
    
    // {{{ getOpcode
    /**
     * Retrive the opcode of this message
     * 
     * @access public
     * @return int
     **/
    public function getOpcode () {
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
    public function getData () {
      if ($this->isClosed () && ($this->Buffer !== null))
        return $this->Buffer;
      
      return $this->read ();
    }
    // }}}
  }
  
  // Load well-known message-classes
  require_once ('qcEvents/Stream/Websocket/Close.php');
  require_once ('qcEvents/Stream/Websocket/Ping.php');
  require_once ('qcEvents/Stream/Websocket/Pong.php');

?>
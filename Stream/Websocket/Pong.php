<?PHP

  /**
   * qcEvents - Websocket PONG-Message
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
  
  require_once ('qcEvents/Stream/Websocket/Message.php');
  
  class qcEvents_Stream_Websocket_Pong extends qcEvents_Stream_Websocket_Message {
    /* Opcode for this message-class */
    const MESSAGE_OPCODE = 0x0A;
    
    // {{{ __construct
    /**
     * Create a new PONG-Message
     * 
     * @param qcEvents_Stream_Websocket $Stream
     * @param string $Payload (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Stream_Websocket $Stream, $Payload = null) {
      // Initialize at out parent
      parent::__construct ($Stream, $this::MESSAGE_OPCODE, $Payload);
    }
    // }}}
  }

?>
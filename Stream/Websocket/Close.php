<?PHP

  /**
   * qcEvents - Websocket Close-Message
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

  class qcEvents_Stream_Websocket_Close extends qcEvents_Stream_Websocket_Message {
    /* Opcode of this message-class */
    const MESSAGE_OPCODE = 0x08;
    
    /* Code for close-reason */
    private $Code = 0x00;
    
    /* Additional info for close-reason */
    private $Reason = '';
    
    // {{{ __construct
    /**
     * Create a new Close-Message
     * 
     * @param qcEvents_Stream_Websocket $Stream
     * @param int $Code (optional)
     * @param string $Reason (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Stream_Websocket $Stream, $Code = null, $Reason = null) {
      // Check wheter to prepare payload
      if (($Code !== null) || ($Reason !== null))
        $Payload = pack ('n', $Code) . $Reason;
      else
        $Payload = null;
      
      // Initialize at out parent
      parent::__construct ($Stream, $this::MESSAGE_OPCODE, $Payload);
      
      // Collect all pending data on close
      $this->addHook ('eventClosed', function (qcEvents_Stream_Websocket_Message $Self) {
        // Retrive the payload
        $Payload = $this->getData ();
        
        // Reset ourself
        $this->Code = null;
        $this->Reason = null;
        
        // Check wheter to parse anything from payload
        if (strlen ($Payload) < 2)
          return;
        
        // Parse the payload
        $this->Code = (ord ($Payload [0]) << 8) | ord ($Payload [1]);
        $this->Reason = substr ($Payload, 2);
      }, null, true);
    }
    // }}}
    
    // {{{ getCode
    /**
     * Retrive the code from this message
     * 
     * @access public
     * @return int
     **/
    public function getCode () {
      return $this->Code;
    }
    // }}}
    
    // {{{ getReson
    /**
     * Get the reson of this message
     * 
     * @access public
     * @return string
     **/
    public function getReason () {
      return $this->Reason;
    }
    // }}}
  }

?>
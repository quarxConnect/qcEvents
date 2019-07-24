<?PHP

  /**
   * qcEvents - SSH Global Request Message
   * Copyright (C) 2019 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Stream/SSH/Message.php');
  
  class qcEvents_Stream_SSH_GlobalRequest extends qcEvents_Stream_SSH_Message {
    const MESSAGE_TYPE = 80;
    
    /* Name of this request */
    public $Name = '';
    
    /* Is a reply expected */
    public $wantReply = false;
    
    /* Unparsed payload of the request */
    public $Payload = null;
    
    /* Attributes for tcpip-listeners */
    public $Address = '';
    public $Port = 0;
    
    // {{{ unpack
    /**
     * Try to unpack data from a packet into this message-instance
     * 
     * @param string $Packet
     * 
     * @access public
     * @return bool
     **/
    public function unpack ($Packet) {
      // Read general header of packet
      $Length = strlen ($Packet);
      $Offset = 0;
      
      if ((($Name = self::readString ($Packet, $Offset, $Length)) === null) ||
          (($wantReply = self::readBoolean ($Packet, $Offset, $Length)) === null))
        return false;
      
      // Get remaining payload
      $Payload = substr ($Packet, $Offset);
      $Length -= $Offset;
      $Offset = 0;
      unset ($Packet);
      
      // Try to parse type-dependant stuff
      if (($Name == 'tcpip-forward') || ($Name == 'cancel-tcpip-forward')) {
        if ((($Address = self::readString ($Payload, $Offset, $Length)) === null) ||
            (($Port = self::readUInt32 ($Payload, $Offset, $Length)) === null))
          return false;
        
        if ($Length != $Offset)
          return false;
        
        $this->Address = $Address;
        $this->Port = $Port;
      } else
        $this->Payload = $Payload;
      
      // Push general header to instance
      $this->Name = $Name;
      $this->wantReply = $wantReply;
      
      return true;
    }
    // }}}
    
    // {{{ pack
    /**
     * Convert this message into binary
     * 
     * @access public
     * @return string
     **/
    public function pack () {
      $Result =
        self::writeString ($this->Name) .
        self::writeBoolean ($this->wantReply);
      
      if (($this->Name == 'tcpip-forward') || ($this->Name == 'cancel-tcpip-forward'))
        $Result .=
          self::writeString ($this->Address) .
          self::writeUInt32 ($this->Port);
      else
        $Result .= $this->Payload;
      
      return $Result;
    }
    // }}}
  }

?>
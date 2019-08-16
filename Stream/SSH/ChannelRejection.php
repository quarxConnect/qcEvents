<?PHP

  /**
   * qcEvents - SSH Channel Open Failure Message
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
  
  class qcEvents_Stream_SSH_ChannelRejection extends qcEvents_Stream_SSH_Message {
    const MESSAGE_TYPE = 91;
    
    /* Number of channel on recipient-side */
    public $RecipientChannel = 0x00000000;
    
    /* Machine-readable reason-code */
    const CODE_ADMINISTRATIVELY_PROHIBITED = 0x00000001;
    const CODE_CONNECT_FAILED              = 0x00000002;
    const CODE_UNKNOWN_CHANNEL_TYPE        = 0x00000003;
    const CODE_RESOURCE_SHORTAGE           = 0x00000004;
    
    public $Code = 0x00000000;
    
    /* Human-readable reason */
    public $Reason = '';
    
    /* Language of reason */
    public $Language = '';
    
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
      $Length = strlen ($Packet);
      $Offset = 0;
      
      if ((($RecipientChannel = self::readUInt32 ($Packet, $Offset, $Length)) === null) ||
          (($Code = self::readUInt32 ($Packet, $Offset, $Length)) === null) ||
          (($Reason = self::readString ($Packet, $Offset, $Length)) === null) ||
          (($Language = self::readString ($Packet, $Offset, $Length)) === null))
        return false;
      
      $this->RecipientChannel = $RecipientChannel;
      $this->Code = $Code;
      $this->Reason = $Reason;
      $this->Language = $Language;
      
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
      return
        self::writeUInt32 ($this->RecipientChannel) .
        self::writeUInt32 ($this->Code) .
        self::writeString ($this->Reason) .
        self::writeString ($this->Language);
    }
    // }}}
  }

?>
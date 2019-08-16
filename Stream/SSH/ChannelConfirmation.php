<?PHP

  /**
   * qcEvents - SSH Channel Confirmation Message
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
  
  class qcEvents_Stream_SSH_ChannelConfirmation extends qcEvents_Stream_SSH_Message {
    const MESSAGE_TYPE = 91;
    
    /* Number of channel on recipient-side */
    public $RecipientChannel = 0x00000000;
    
    /* Number of channel on sender-side */
    public $SenderChannel = 0x00000000;
    
    /* Initial window-size */
    public $InitialWindowSize = 0x00000000;
    
    /* Maximum packet size */
    public $MaximumPacketSize = 0x00000000;
    
    /* Any unparsed payload */
    public $Payload = null;
    
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
          (($SenderChannel = self::readUInt32 ($Packet, $Offset, $Length)) === null) ||
          (($InitialWindowSize = self::readUInt32 ($Packet, $Offset, $Length)) === null) ||
          (($MaximumPacketSize = self::readUInt32 ($Packet, $Offset, $Length)) === null))
        return false;
      
      $this->RecipientChannel = $RecipientChannel;
      $this->SenderChannel = $SenderChannel;
      $this->InitialWindowSize = $InitialWindowSize;
      $this->MaximumPacketSize = $MaximumPacketSize;
      
      if ($Length > $Offset)
        $this->Payload = substr ($Packet, $Offset);
      
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
        self::writeUInt32 ($this->SenderChannel) .
        self::writeUInt32 ($this->InitialWindowSize) .
        self::writeUInt32 ($this->MaximumPacketSize) .
        $this->Payload;
    }
    // }}}
  }

?>
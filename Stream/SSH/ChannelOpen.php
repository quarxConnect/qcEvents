<?PHP

  /**
   * qcEvents - SSH Channel Open Message
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
  
  class qcEvents_Stream_SSH_ChannelOpen extends qcEvents_Stream_SSH_Message {
    const MESSAGE_TYPE = 90;
    
    /* Type of channel to open */
    public $Type = '';
    
    /* Number of channel on sender-side */
    public $SenderChannel = 0x00000000;
    
    /* Initial window-size */
    public $InitialWindowSize = 0x00000000;
    
    /* Maximum packet size */
    public $MaximumPacketSize = 0x00000000;
    
    /* Any unparsed payload */
    public $Payload = null;
    
    /* Address that was or should be connected to */
    public $DestinationAddress = null;
    
    /* Port that was or should be connected to */
    public $DestinationPort = null;
    
    /* Address that was connected from */
    public $OriginatorAddress = null;
    
    /* Port that was connected from */
    public $OriginatorPort = null;
    
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
      
      if ((($Type = self::readString ($Packet, $Offset, $Length)) === null) ||
          (($SenderChannel = self::readUInt32 ($Packet, $Offset, $Length)) === null) ||
          (($InitialWindowSize = self::readUInt32 ($Packet, $Offset, $Length)) === null) ||
          (($MaximumPacketSize = self::readUInt32 ($Packet, $Offset, $Length)) === null))
        return false;
      
      // Process known channel-types
      $DestinationAddress = $DestinationPort = null;
      $OriginatorAddress = $OriginatorPort = null;
      
      if ($Type == 'session') {
        // Nothing more to parse
      
      } elseif ($Type == 'x11') {
        if ((($OriginatorAddress = self::readString ($Packet, $Offset, $Length)) === null) ||
            (($OriginatorPort = self::readUInt32 ($Packet, $Offset, $Length)) === null))
          return false;
      
      } elseif (($Type == 'forwarded-tcpip') || ($Type == 'direct-tcpip')) {
        if ((($DestinationAddress = self::readString ($Packet, $Offset, $Length)) === null) ||
            (($DestinationPort = self::readUInt32 ($Packet, $Offset, $Length)) === null) ||
            (($OriginatorAddress = self::readString ($Packet, $Offset, $Length)) === null) ||
            (($OriginatorPort = self::readUInt32 ($Packet, $Offset, $Length)) === null))
          return false;
          
      } else {
        $this->Payload = substr ($Packet, $Offset);
        $Offset = $Length;
      }
      
      if ($Length != $Offset)
        return false;
      
      $this->Type = $Type;
      $this->SenderChannel = $SenderChannel;
      $this->InitialWindowSize = $InitialWindowSize;
      $this->MaximumPacketSize = $MaximumPacketSize;
      $this->DestinationAddress = $DestinationAddress;
      $this->DestinationPort = $DestinationPort;
      $this->OriginatorAddress = $OriginatorAddress;
      $this->OriginatorPort = $OriginatorPort;
      
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
        self::writeString ($this->Type) .
        self::writeUInt32 ($this->SenderChannel) .
        self::writeUInt32 ($this->InitialWindowSize) .
        self::writeUInt32 ($this->MaximumPacketSize);
      
      if (($this->Type == 'forwarded-tcpip') || ($this->Type == 'direct-tcpip'))
        $Result .=
          self::writeString ($this->DestinationAddress) .
          self::writeUInt32 ($this->DestinationPort);
      
      if (($this->Type == 'forwarded-tcpip') || ($this->Type == 'direct-tcpip') || ($this->Type == 'x11'))
        $Result .=
          self::writeString ($this->OriginatorAddress) .
          self::writeUInt32 ($this->OriginatorPort);
      
      return $Result;
    }
    // }}}
  }

?>
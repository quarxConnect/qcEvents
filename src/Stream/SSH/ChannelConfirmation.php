<?php

  /**
   * qcEvents - SSH Channel Confirmation Message
   * Copyright (C) 2019-2022 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);

  namespace quarxConnect\Events\Stream\SSH;
  
  class ChannelConfirmation extends Message {
    protected const MESSAGE_TYPE = 91;
    
    /* Number of channel on recipient-side */
    public $RecipientChannel = 0x00000000;
    
    /* Number of channel on sender-side */
    public $SenderChannel = 0x00000000;
    
    /* Initial window-size */
    public $InitialWindowSize = Channel::DEFAULT_WINDOW_SIZE;
    
    /* Maximum packet size */
    public $MaximumPacketSize = Channel::DEFAULT_MAXIMUM_PACKET_SIZE;
    
    /* Any unparsed payload */
    public $Payload = null;
    
    // {{{ unpack
    /**
     * Try to unpack data from a packet into this message-instance
     * 
     * @param string $packetData
     * 
     * @access public
     * @return bool
     **/
    public function unpack (string $packetData) : bool {
      $packetLength = strlen ($packetData);
      $packetOffset = 0;
      
      if (
        (($recipientChannel = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
        (($senderChannel = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
        (($initialWindowSize = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
        (($maximumPacketSize = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null)
      )
        return false;
      
      $this->RecipientChannel = $recipientChannel;
      $this->SenderChannel = $senderChannel;
      $this->InitialWindowSize = $initialWindowSize;
      $this->MaximumPacketSize = $maximumPacketSize;
      
      if ($packetLength > $packetOffset)
        $this->Payload = substr ($packetData, $packetOffset);
      
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
    public function pack () : string {
      return
        self::writeUInt32 ($this->RecipientChannel) .
        self::writeUInt32 ($this->SenderChannel) .
        self::writeUInt32 ($this->InitialWindowSize) .
        self::writeUInt32 ($this->MaximumPacketSize) .
        $this->Payload;
    }
    // }}}
  }

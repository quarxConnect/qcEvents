<?php

  /**
   * qcEvents - SSH Channel Extended Data Message
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
  
  class ChannelExtendedData extends Message {
    protected const MESSAGE_TYPE = 95;
    
    /* Number of channel on sender-side */
    public $RecipientChannel = 0x00000000;
    
    /* Type of extended data */
    const TYPE_STDERR = 0x00000001;
    
    public $Type = 0x00000000;
    
    /* Data-Transmission for that channel */    
    public $Data = '';
    
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
        (($dataType = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
        (($channelData = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
        ($packetOffset != $packetLength)
      )
        return false;
      
      $this->RecipientChannel = $recipientChannel;
      $this->Type = $dataType;
      $this->Data = $channelData;
      
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
        self::writeUInt32 ($this->Type) .
        self::writeString ($this->Data);
    }
    // }}}
  }

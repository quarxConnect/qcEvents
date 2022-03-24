<?php

  /**
   * qcEvents - SSH Channel Open Message
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
  
  class ChannelOpen extends Message {
    protected const MESSAGE_TYPE = 90;
    
    /* Type of channel to open */
    public $Type = '';
    
    /* Number of channel on sender-side */
    public $SenderChannel = 0x00000000;
    
    /* Initial window-size */
    public $InitialWindowSize = Channel::DEFAULT_WINDOW_SIZE;
    
    /* Maximum packet size */
    public $MaximumPacketSize = Channel::DEFAULT_MAXIMUM_PACKET_SIZE;
    
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
     * @param string $packetData
     * 
     * @access public
     * @return bool
     **/
    public function unpack (string $packetData) : bool {
      $packetLength = strlen ($packetData);
      $packetOffset = 0;
      
      if (
        (($channelType = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
        (($senderChannel = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
        (($initialWindowSize = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
        (($maximumPacketSize = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null)
      )
        return false;
      
      // Process known channel-types
      $destinationAddress = $destinationPort = null;
      $originatorAddress = $originatorPort = null;
      
      if ($channelType == 'session') {
        // Nothing more to parse
      
      } elseif ($channelType == 'x11') {
        if (
          (($originatorAddress = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
          (($originatorPort = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null)
        )
          return false;
      
      } elseif (($channelType == 'forwarded-tcpip') || ($channelType == 'direct-tcpip')) {
        if (
          (($destinationAddress = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
          (($destinationPort = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
          (($originatorAddress = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
          (($originatorPort = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null)
        )
          return false;
          
      } else {
        $this->Payload = substr ($packetData, $packetOffset);
        $packetOffset = $packetLength;
      }
      
      if ($packetLength != $packetOffset)
        return false;
      
      $this->Type = $channelType;
      $this->SenderChannel = $senderChannel;
      $this->InitialWindowSize = $initialWindowSize;
      $this->MaximumPacketSize = $maximumPacketSize;
      $this->DestinationAddress = $destinationAddress;
      $this->DestinationPort = $destinationPort;
      $this->OriginatorAddress = $originatorAddress;
      $this->OriginatorPort = $originatorPort;
      
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
      $packetData =
        self::writeString ($this->Type) .
        self::writeUInt32 ($this->SenderChannel) .
        self::writeUInt32 ($this->InitialWindowSize) .
        self::writeUInt32 ($this->MaximumPacketSize);
      
      if (($this->Type == 'forwarded-tcpip') || ($this->Type == 'direct-tcpip'))
        $packetData .=
          self::writeString ($this->DestinationAddress) .
          self::writeUInt32 ($this->DestinationPort);
      
      if (($this->Type == 'forwarded-tcpip') || ($this->Type == 'direct-tcpip') || ($this->Type == 'x11'))
        $packetData .=
          self::writeString ($this->OriginatorAddress) .
          self::writeUInt32 ($this->OriginatorPort);
      
      return $packetData;
    }
    // }}}
  }

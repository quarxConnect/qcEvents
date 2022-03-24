<?php

  /**
   * qcEvents - SSH Channel Open Failure Message
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
  
  class ChannelRejection extends Message {
    protected const MESSAGE_TYPE = 92;
    
    /* Number of channel on recipient-side */
    public $RecipientChannel = 0x00000000;
    
    /* Machine-readable reason-code */
    public const CODE_ADMINISTRATIVELY_PROHIBITED = 0x00000001;
    public const CODE_CONNECT_FAILED              = 0x00000002;
    public const CODE_UNKNOWN_CHANNEL_TYPE        = 0x00000003;
    public const CODE_RESOURCE_SHORTAGE           = 0x00000004;
    
    public $Code = 0x00000000;
    
    /* Human-readable reason */
    public $Reason = '';
    
    /* Language of reason */
    public $Language = '';
    
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
        (($rejectionCode = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
        (($reasonMessage = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
        (($reasonLanguage = self::readString ($packetData, $packetOffset, $packetLength)) === null)
      )
        return false;
      
      $this->RecipientChannel = $recipientChannel;
      $this->Code = $rejectionCode;
      $this->Reason = $reasonMessage;
      $this->Language = $reasonLanguage;
      
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
        self::writeUInt32 ($this->Code) .
        self::writeString ($this->Reason) .
        self::writeString ($this->Language);
    }
    // }}}
  }

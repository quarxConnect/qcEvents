<?php

  /**
   * qcEvents - SSH Disconnect Message
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
  
  class Disconnect extends Message {
    protected const MESSAGE_TYPE = 1;
    
    public const REASON_HOST_NOT_ALLOWED_TO_CONNECT = 1;
    public const REASON_PROTOCOL_ERROR = 2;
    public const REASON_KEY_EXCHANGE_FAILED = 3;
    public const REASON_RESERVED = 4;
    public const REASON_MAC_ERROR = 5;
    public const REASON_COMPRESSION_ERROR = 6;
    public const REASON_SERVICE_NOT_AVAILABLE = 7;
    public const REASON_PROTOCOL_VERSION_NOT_SUPPORTED = 8;
    public const REASON_HOST_KEY_NOT_VERIFIABLE = 9;
    public const REASON_CONNECTION_LOST = 10;
    public const REASON_BY_APPLICATION = 11;
    public const REASON_TOO_MANY_CONNECTIONS = 12;
    public const REASON_AUTH_CANCELLED_BY_USER = 13;
    public const REASON_NO_MORE_AUTH_METHODS_AVAILABLE = 14;
    public const REASON_ILLEGAL_USER_NAME = 15;
    
    private $Reason = 0;
    private $Description = '';
    private $Language = '';
    
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
        (($Reason = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
        (($Description = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
        (($Language = self::readString ($packetData, $packetOffset, $packetLength)) === null)
      )
        return false;
      
      if ($packetLength != $packetOffset)
        return false;
      
      $this->Reason = $Reason;
      $this->Description = $Description;
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
    public function pack () : string {
      return
        self::writeUInt32 ($this->Reason) .
        self::writeString ($this->Description) .
        self::writeString ($this->Language);
    }
    // }}}
  }

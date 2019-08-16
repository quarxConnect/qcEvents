<?PHP

  /**
   * qcEvents - SSH Disconnect Message
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
  
  class qcEvents_Stream_SSH_Disconnect extends qcEvents_Stream_SSH_Message {
    const MESSAGE_TYPE = 1;
    
    const REASON_HOST_NOT_ALLOWED_TO_CONNECT = 1;
    const REASON_PROTOCOL_ERROR = 2;
    const REASON_KEY_EXCHANGE_FAILED = 3;
    const REASON_RESERVED = 4;
    const REASON_MAC_ERROR = 5;
    const REASON_COMPRESSION_ERROR = 6;
    const REASON_SERVICE_NOT_AVAILABLE = 7;
    const REASON_PROTOCOL_VERSION_NOT_SUPPORTED = 8;
    const REASON_HOST_KEY_NOT_VERIFIABLE = 9;
    const REASON_CONNECTION_LOST = 10;
    const REASON_BY_APPLICATION = 11;
    const REASON_TOO_MANY_CONNECTIONS = 12;
    const REASON_AUTH_CANCELLED_BY_USER = 13;
    const REASON_NO_MORE_AUTH_METHODS_AVAILABLE = 14;
    const REASON_ILLEGAL_USER_NAME = 15;
    
    private $Reason = 0;
    private $Description = '';
    private $Language = '';
    
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
      
      if ((($Reason = self::readUInt32 ($Packet, $Offset, $Length)) === null) ||
          (($Description = self::readString ($Packet, $Offset, $Length)) === null) ||
          (($Language = self::readString ($Packet, $Offset, $Length)) === null))
        return false;
      
      if ($Length != $Offset)
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
    public function pack () {
      return
        self::writeUInt32 ($this->Reason) .
        self::writeString ($this->Description) .
        self::writeString ($this->Language);
    }
    // }}}
  }

?>
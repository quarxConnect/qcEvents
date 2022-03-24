<?php

  /**
   * qcEvents - SSH DH Key-Exchange Reply Message
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
  
  class KeyExchangeDHReply extends Message {
    protected const MESSAGE_TYPE = 31;
    
    /* Public Key of the server */
    public $serverHostKey = '';
    
    /* Public product from the server */
    public $f = 0;
    
    /* Signature of Public Key */
    public $Signature = '';
    
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
      // Initialize
      $packetOffset = 0;
      $packetLength = strlen ($packetData);
      
      // Try to read everything from packet
      if (
        (($serverHostKey = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
        (($f = self::readMPInt ($packetData, $packetOffset, $packetLength)) === null) ||
        (($Signature = self::readString ($packetData, $packetOffset, $packetLength)) === null)
      )
        return false;
      
      // Make sure there is no garbage at the end
      if ($packetOffset != $packetLength)
        return false;
      
      // Commit values to this instance
      $this->serverHostKey = $serverHostKey;
      $this->f = $f;
      $this->Signature = $Signature;
      
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
        self::writeString ($this->serverHostKey) .
        self::writeMPInt ($this->f) .
        self::writeString ($this->Signature);
    }
    // }}}
  }

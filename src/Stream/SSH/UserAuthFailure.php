<?php

  /**
   * qcEvents - SSH User-Authentication Failure Message
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
  
  class UserAuthFailure extends Message {
    protected const MESSAGE_TYPE = 51;
    
    /* Authentication-Methods that can continue */
    public $Methods = [ ];
    
    /* Indicates partial success, but other authentication has to be performed as well */
    public $PartialSuccess = false;
    
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
        (($Methods = self::readNameList ($packetData, $packetOffset, $packetLength)) === null) ||
        (($PartialSuccess = self::readBoolean ($packetData, $packetOffset, $packetLength)) === null)
      )
        return false;
      
      if ($packetLength != $packetOffset)
        return false;
      
      $this->Methods = $Methods;
      $this->PartialSuccess = $PartialSuccess;
      
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
        self::writeNameList ($this->Methods) .
        self::writeBoolean ($this->PartialSuccess);
    }
    // }}}
  }

<?php

  /**
   * qcEvents - SSH User Authentication Banner Message
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
  
  class Banner extends Message {
    protected const MESSAGE_TYPE = 53;
    
    public $Message = '';
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
        (($Message = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
        (($Langauge = self::readString ($packetData, $packetOffset, $packetLength)) === null)
      )
        return false;
      
      if ($packetLength != $packetOffset)
        return false;
      
      $this->Message = $Message;
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
        self::writeString ($this->Message) .
        self::writeString ($this->Language);
    }
    // }}}
  }

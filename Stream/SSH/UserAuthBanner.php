<?PHP

  /**
   * qcEvents - SSH User Authentication Banner Message
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
  
  class qcEvents_Stream_SSH_Banner extends qcEvents_Stream_SSH_Message {
    const MESSAGE_TYPE = 53;
    
    public $Message = '';
    public $Language = '';
    
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
      
      if ((($Message = self::readString ($Packet, $Offset, $Length)) === null) ||
          (($Langauge = self::readString ($Packet, $Offset, $Length)) === null))
        return false;
      
      if ($Length != $Offset)
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
    public function pack () {
      return
        self::writeString ($this->Message) .
        self::writeString ($this->Language);
    }
    // }}}
  }

?>
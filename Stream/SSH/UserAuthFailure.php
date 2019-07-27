<?PHP

  /**
   * qcEvents - SSH User-Authentication Failure Message
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
  
  class qcEvents_Stream_SSH_UserAuthFailure extends qcEvents_Stream_SSH_Message {
    const MESSAGE_TYPE = 51;
    
    /* Authentication-Methods that can continue */
    public $Methods = array ();
    
    /* Indicates partial success, but other authentication has to be performed as well */
    public $PartialSuccess = false;
    
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
      
      if ((($Methods = self::readNameList ($Packet, $Offset, $Length)) === null) ||
          (($PartialSuccess = self::readBoolean ($Packet, $Offset, $Length)) === null))
        return false;
      
      if ($Length != $Offset)
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
    public function pack () {
      return
        self::writeNameList ($this->Methods) .
        self::writeBoolean ($this->PartialSuccess);
    }
    // }}}
  }

?>
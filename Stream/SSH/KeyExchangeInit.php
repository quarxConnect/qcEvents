<?PHP

  /**
   * qcEvents - SSH Key-Exchange Initialization Message
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
  
  class qcEvents_Stream_SSH_KeyExchangeInit extends qcEvents_Stream_SSH_Message {
    const MESSAGE_TYPE = 20;
    
    public $Cookie = '';
    public $KexAlgorithms = array ();
    public $serverHostKeyAlgortihms = array ();
    public $ciphersClientServer = array ();
    public $ciphersServerClient = array ();
    public $macClientServer = array ();
    public $macServerClient = array ();
    public $compClientServer = array ();
    public $compServerClient = array ();
    public $langClientServer = array ();
    public $langServerClient = array ();
    public $kexFollows = false;
    
    function __construct () {
      for ($i = 0; $i < 16; $i++)
        $this->Cookie .= chr (rand (0, 255));
    }
    
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
      // Initialize
      $Offset = 0;
      $Length = strlen ($Packet);
      
      // Try to read everything from packet
      if ((($Cookie = self::readBytes ($Packet, $Offset, 16, $Length)) === null) ||
          (($KexAlgorithms = self::readNameList ($Packet, $Offset, $Length)) === null) ||
          (($serverHostKeyAlgortihms = self::readNameList ($Packet, $Offset, $Length)) === null) ||
          (($ciphersClientServer = self::readNameList ($Packet, $Offset, $Length)) === null) ||
          (($ciphersServerClient = self::readNameList ($Packet, $Offset, $Length)) === null) ||
          (($macClientServer = self::readNameList ($Packet, $Offset, $Length)) === null) ||
          (($macServerClient = self::readNameList ($Packet, $Offset, $Length)) === null) ||
          (($compClientServer = self::readNameList ($Packet, $Offset, $Length)) === null) ||
          (($compServerClient = self::readNameList ($Packet, $Offset, $Length)) === null) ||
          (($langClientServer = self::readNameList ($Packet, $Offset, $Length)) === null) ||
          (($langServerClient = self::readNameList ($Packet, $Offset, $Length)) === null) ||
          (($kexFollows = self::readBoolean ($Packet, $Offset, $Length)) === null) ||
          (($reserved = self::readUInt32 ($Packet, $Offset, $Length)) === null))
        return false;
      
      // Make sure there is no garbage at the end
      if ($Offset != $Length)
        return false;
      
      // Commit values to this instance
      $this->Cookie = $Cookie;
      $this->KexAlgorithms = $KexAlgorithms;
      $this->serverHostKeyAlgortihms = $serverHostKeyAlgortihms;
      $this->ciphersClientServer = $ciphersClientServer;
      $this->ciphersServerClient = $ciphersServerClient;
      $this->macClientServer = $macClientServer;
      $this->macServerClient = $macServerClient;
      $this->compClientServer = $compClientServer;
      $this->compServerClient = $compServerClient;
      $this->langClientServer = $langClientServer;
      $this->langServerClient = $langServerClient;
      $this->kexFollows = $kexFollows;
      
      // Indicate success
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
        self::writeBytes ($this->Cookie, 16) .
        self::writeNameList ($this->KexAlgorithms) .
        self::writeNameList ($this->serverHostKeyAlgortihms) .
        self::writeNameList ($this->ciphersClientServer) .
        self::writeNameList ($this->ciphersServerClient) .
        self::writeNameList ($this->macClientServer) .
        self::writeNameList ($this->macServerClient) .
        self::writeNameList ($this->compClientServer) .
        self::writeNameList ($this->compServerClient) .
        self::writeNameList ($this->langClientServer) .
        self::writeNameList ($this->langServerClient) .
        self::writeBoolean ($this->kexFollows) .
        self::writeUInt32 (0);
    }
    // }}}
  }

?>
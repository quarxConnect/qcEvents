<?php

  /**
   * qcEvents - SSH User-Authentication Request Message
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
  
  class UserAuthRequest extends Message {
    protected const MESSAGE_TYPE = 50;
    
    public $Username = '';
    public $Service = '';
    public $Method = '';
    
    // Public-Key-Authentication
    public const METHOD_PUBLICKEY = 'publickey';
    
    public $Signed = false;
    public $Algorithm = '';
    public $PublicKey = '';
    public $Signature = null;
    
    // Password-Authentication
    public const METHOD_PASSWORD = 'password';
    
    public $ChangePassword = false;
    public $Password = '';
    public $newPassword = null;
    
    // Hostbased Authentication
    public $clientHostname = '';
    public $clientUsername = '';
    
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
        (($Username = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
        (($Service = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
        (($Method = self::readString ($packetData, $packetOffset, $packetLength)) === null)
      )
        return false;
      
      $Signed = $Algorithm = $PublicKey = $Signature = null;
      $ChangePassword = $Password = $newPassword = null;
      $clientHostname = $clientUsername = null;
      
      if ($Method === self::METHOD_PUBLICKEY) {
        if (
          (($Signed = self::readBoolean ($packetData, $packetOffset, $packetLength)) === null) ||
          (($Algorithm = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
          (($PublicKey = self::readString ($packetData, $packetOffset, $packetLength)) === null)
        )
          return false;
        
        if ($Signed && (($Signature = self::readString ($packetData, $packetOffset, $packetLength)) === null))
          return false;
      } elseif ($Method == self::METHOD_PASSWORD) {
        if (
          (($ChangePassword = self::readBoolean ($packetData, $packetOffset, $packetLength)) === null) ||
          (($Password = self::readString ($packetData, $packetOffset, $packetLength)) === null)
        )
          return false;
        
        if ($ChangePassword && (($newPassword = self::readString ($packetData, $packetOffset, $packetLength)) === null))
          return false;
      } elseif ($Method == 'hostbased') {
        if (
          (($Algorithm = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
          (($PublicKey = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
          (($clientHostname = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
          (($clientUsername = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
          (($Signature = self::readString ($packetData, $packetOffset, $packetLength)) === null)
        )
          return false;
      } else
        return false;
      
      if ($packetOffset != $packetLength)
        return false;
      
      $this->Username = $Username;
      $this->Service = $Service;
      $this->Method = $Method;
      
      $this->Signed = $Signed;
      $this->Algorithm = $Algorithm;
      $this->PublicKey = $PublicKey;
      $this->Signature = $Signature;
      $this->ChangePassword = $ChangePassword;
      $this->Password = $Password;
      $this->newPassword = $newPassword;
      $this->clientHostname = $clientHostname;
      $this->clientUsername = $clientUsername;
      
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
        self::writeString ($this->Username) .
        self::writeString ($this->Service) .
        self::writeString ($this->Method);
      
      if ($this->Method == self::METHOD_PUBLICKEY) {
        $packetData .=
          self::writeBoolean ($this->Signed) .
          self::writeString ($this->Algorithm) .
          self::writeString ($this->PublicKey);
        
        if ($this->Signed)
          $packetData .= self::writeString ($this->Signature ?? '');
      } elseif ($this->Method == self::METHOD_PASSWORD) {
        $packetData .=
          self::writeBoolean ($this->ChangePassword) .
          self::writeString ($this->Password);
        
        if ($this->ChangePassword)
          $packetData .= self::writeString ($this->newPassword);
      } elseif ($this->Method == self::METHOD_HOSTBASED) {
        $packetData .=
          self::writeString ($this->Algorithm) .
          self::writeString ($this->PublicKey) .
          self::writeString ($this->clientHostname) .
          self::writeString ($this->clientUsername) .
          self::writeString ($this->Signature);
      } else
        return false;
      
      return $packetData;
    }
    // }}}
  }

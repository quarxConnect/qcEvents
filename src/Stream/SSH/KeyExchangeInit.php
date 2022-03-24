<?php

  /**
   * qcEvents - SSH Key-Exchange Initialization Message
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
  
  class KeyExchangeInit extends Message {
    protected const MESSAGE_TYPE = 20;
    
    public $Cookie = '';
    public $KexAlgorithms = [ ];
    public $serverHostKeyAlgortihms = [ ];
    public $ciphersClientServer = [ ];
    public $ciphersServerClient = [ ];
    public $macClientServer = [ ];
    public $macServerClient = [ ];
    public $compClientServer = [ ];
    public $compServerClient = [ ];
    public $langClientServer = [ ];
    public $langServerClient = [ ];
    public $kexFollows = false;
    
    // {{{ __construct
    /**
     * Initialize this message
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      for ($i = 0; $i < 16; $i++)
        $this->Cookie .= chr (rand (0, 255));
    }
    // }}}
    
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
        (($Cookie = self::readBytes ($packetData, $packetOffset, 16, $packetLength)) === null) ||
        (($KexAlgorithms = self::readNameList ($packetData, $packetOffset, $packetLength)) === null) ||
        (($serverHostKeyAlgortihms = self::readNameList ($packetData, $packetOffset, $packetLength)) === null) ||
        (($ciphersClientServer = self::readNameList ($packetData, $packetOffset, $packetLength)) === null) ||
        (($ciphersServerClient = self::readNameList ($packetData, $packetOffset, $packetLength)) === null) ||
        (($macClientServer = self::readNameList ($packetData, $packetOffset, $packetLength)) === null) ||
        (($macServerClient = self::readNameList ($packetData, $packetOffset, $packetLength)) === null) ||
        (($compClientServer = self::readNameList ($packetData, $packetOffset, $packetLength)) === null) ||
        (($compServerClient = self::readNameList ($packetData, $packetOffset, $packetLength)) === null) ||
        (($langClientServer = self::readNameList ($packetData, $packetOffset, $packetLength)) === null) ||
        (($langServerClient = self::readNameList ($packetData, $packetOffset, $packetLength)) === null) ||
        (($kexFollows = self::readBoolean ($packetData, $packetOffset, $packetLength)) === null) ||
        (($reserved = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null)
      )
        return false;
      
      // Make sure there is no garbage at the end
      if ($packetOffset != $packetLength)
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
    public function pack () : string {
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

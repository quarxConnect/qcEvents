<?php

  /**
   * qcEvents - SSH Channel Request Message
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
  
  class ChannelRequest extends Message {
    protected const MESSAGE_TYPE = 98;
    
    /* Number of channel on sender-side */
    public $RecipientChannel = 0x00000000;
    
    /* Type of the request */
    public $Type = '';
    
    /* Reply to this request is wanted */
    public $wantReply = false;
    
    /* Unparsed Payload */
    public $Payload = null;
    
    public $Term = null;
    public $terminalColWidth = null;
    public $terminalColHeight = null;
    public $terminalWidth = null;
    public $terminalHeight = null;
    public $terminalModes = null;
    public $singleConnection = null;
    public $x11authProtocol = null;
    public $x11authCookie = null;
    public $x11screenNumber = null;
    public $envName = null;
    public $envValue = null;
    public $Command = null;
    public $Signal = null;
    public $Status = null;
    public $CoreDumped = null;
    public $errorMessage = null;
    public $errorLanguage = null;
    public $Setting = null;
    public $breakLength = null;
    
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
        (($RecipientChannel = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
        (($Type = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
        (($wantReply = self::readBoolean ($packetData, $packetOffset, $packetLength)) === null)
      )
        return false;
      
      $Term = $terminalColWidth = $terminalColHeight = $terminalWidth = $terminalHeight = $terminalModes = null;
      $singleConnection = $x11authProtocol = $x11authCookie = $x11screenNumber = null;
      $envName = $envValue = null;
      $Command = $Signal = $Status = $CoreDumped = $errorMessage = $errorLanguage = $Setting = $breakLength = null;
      
      if ($Type == 'pty-req') {
        if (
          (($Term = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
          (($terminalColWidth = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
          (($terminalColHeight = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
          (($terminalWidth = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
          (($terminalHeight = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
          (($terminalModes = self::readString ($packetData, $packetOffset, $packetLength)) === null)
        )
          return false;
        
      } elseif ($Type == 'window-change') {
        if (
          (($terminalColWidth = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
          (($terminalColHeight = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
          (($terminalWidth = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null) ||
          (($terminalHeight = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null)
        )
          return false;
        
      } elseif ($Type == 'x11-req') {
        if (
          (($singleConnection = self::readBoolean ($packetData, $packetOffset, $packetLength)) === null) ||
          (($x11authProtocol = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
          (($x11authCookie = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
          (($x11screenNumber = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null)
        )
          return false;
        
      } elseif ($Type == 'env') {
        if (
          (($envName = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
          (($envValue = self::readString ($packetData, $packetOffset, $packetLength)) === null)
        )
          return false;
      
      } elseif (($Type == 'exec') || ($Type == 'subsystem')) {
        if (($Command = self::readString ($packetData, $packetOffset, $packetLength)) === null)
          return false;
      
      } elseif ($Type == 'signal') {
        if (($Signal = self::readString ($packetData, $packetOffset, $packetLength)) === null)
          return false;
        
      } elseif ($Type == 'exit-status') {
        if (($Status = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null)
          return false;
        
      } elseif ($Type == 'exit-signal') {
        if (
          (($Signal = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
          (($CoreDumped = self::readBoolean ($packetData, $packetOffset, $packetLength)) === null) ||
          (($errorMessage = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
          (($errorLanguage = self::readString ($packetData, $packetOffset, $packetLength)) === null)
        )
          return false;
        
      } elseif ($Type == 'xon-xoff') {
        if (($Setting = self::readBoolean ($packetData, $packetOffset, $packetLength)) === null)
          return false;
        
      } elseif ($Type == 'break') {
        if (($breakLength = self::readUInt32 ($packetData, $packetOffset, $packetLength)) === null)
          return false;
        
      } elseif ($Type == 'shell') {
        // Nothing to do
      
      } elseif ($packetOffset != $packetLength) {
        $this->Payload = substr ($packetData, $packetOffset);
        $packetOffset = $packetLength;
      } else
        $this->Payload = null;
      
      if ($packetOffset != $packetLength)
        return false;
      
      $this->RecipientChannel = $RecipientChannel;
      $this->Type = $Type;
      $this->wantReply = $wantReply;
      
      $this->Term = $Term;
      $this->terminalColWidth = $terminalColWidth;
      $this->terminalColHeight = $terminalColHeight;
      $this->terminalWidth = $terminalWidth;
      $this->terminalHeight = $terminalHeight;
      $this->terminalModes = $terminalModes;
      $this->singleConnection = $singleConnection;
      $this->x11authProtocol = $x11authProtocol;
      $this->x11authCookie = $x11authCookie;
      $this->x11screenNumber = $x11screenNumber;
      $this->envName = $envName;
      $this->envValue = $envValue;
      $this->Command = $Command;
      $this->Signal = $Signal;
      $this->Status = $Status;
      $this->CoreDumped = $CoreDumped;
      $this->errorMessage = $errorMessage;
      $this->errorLanguage = $errorLanguage;
      $this->Setting = $Setting;
      $this->breakLength = $breakLength;
      
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
        self::writeUInt32 ($this->RecipientChannel) .
        self::writeString ($this->Type) .
        self::writeBoolean ($this->wantReply);
      
      if ($this->Type == 'pty-req')
        $packetData .=
          self::writeString ($this->Term) .
          self::writeUInt32 ($this->terminalColWidth) .
          self::writeUInt32 ($this->terminalColHeight) .
          self::writeUInt32 ($this->terminalWidth) .
          self::writeUInt32 ($this->terminalHeight) .
          self::writeString ($this->terminalModes);
      elseif ($this->Type == 'window-change')
        $packetData .=
          self::writeUInt32 ($this->terminalColWidth) .
          self::writeUInt32 ($this->terminalColHeight) .
          self::writeUInt32 ($this->terminalWidth) .
          self::writeUInt32 ($this->terminalHeight);
      elseif ($this->Type == 'x11-req')
        $packetData .=
          self::writeBoolean ($this->singleConnection) .
          self::writeString ($this->x11authProtocol) .
          self::writeString ($this->x11authCookie) .
          self::writeUInt32 ($this->x11screenNumber);
      elseif ($this->Type == 'env')
        $packetData .=
          self::writeString ($this->envName) .
          self::writeString ($this->envValue);
      elseif (($this->Type == 'exec') || ($this->Type == 'subsystem'))
        $packetData .=
          self::writeString ($this->Command);
      elseif ($this->Type == 'signal')
        $packetData .=
          self::writeString ($this->Signal);
      elseif ($this->Type == 'exit-status')
        $packetData .=
          self::writeUInt32 ($this->Status);
      elseif ($this->Type == 'exit-signal')
        $packetData .=
          self::writeString ($this->Signal) .
          self::writeBoolean ($this->CoreDumped) .
          self::writeStirng ($this->errorMessage) .
          self::writeString ($this->errorLanguage);
      elseif ($this->Type == 'xon-xoff')
        $packetData .=
          self::writeBoolean ($this->Setting);
      elseif ($this->Type == 'break')
        $packetData .=
          self::writeUInt32 ($this->breakLength);
      elseif ($this->Type != 'shell')
        $packetData .= $this->Payload;
      
      return $packetData;
    }
    // }}}
  }

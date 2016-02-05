<?PHP

  /**
   * qcEvents - DNS Resource Record
   * Copyright (C) 2014 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Stream/DNS/Record.php');
  
  class qcEvents_Stream_DNS_Record_SOA extends qcEvents_Stream_DNS_Record {
    const DEFAULT_TYPE = 0x06;
    
    private $Nameserver = '';
    private $Mailbox = '';
    private $Serial = 0;
    private $Refresh = 0;
    private $Retry = 0;
    private $Expire = 0;
    private $Minimum = 0;
    
    // {{{ __toString
    /**
     * Create a human-readable representation from this
     * 
     * @access friendly
     * @return string  
     **/
    function __toString () {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' SOA ' . $this->Nameserver . ' ' . $this->Mailbox . ' ' . $this->Serial . ' ' . $this->Refresh . ' ' . $this->Retry . ' ' . $this->Expire . ' ' . $this->Minimum;
    }
    // }}}
    
    public function setNameserver ($Nameserver) {
      $this->Nameserver = $Nameserver;
      
      return true;
    }
    
    public function setMailbox ($Mailbox) {
      $this->Mailbox = str_replace ('@', '.', $Mailbox);
      
      return true;
    }
    
    public function setSerial ($Serial) {
      $this->Serial = (int)$Serial;
      
      return true;
    }
    
    public function setRefresh ($Refresh) {
      $this->Refresh = (int)$Refresh;
      
      return true;
    }
    
    public function setRetry ($Retry) {
      $this->Retry = (int)$Retry;
      
      return true;
    }
    
    public function setExpire ($Expire) {
      $this->Expire = (int)$Expire;
      
      return true;
    }
    
    public function setMinimum ($Minimum) {
      $this->Minimum = $Minimum;
      
      return true;
    }
    
    
    // {{{ parsePayload
    /**
     * Parse a given payload
     * 
     * @param string $Data
     * @param int $Offset (optional)
     * @param int $Length (optional)
     * 
     * @access public
     * @return bool
     **/
    public function parsePayload ($Data, $Offset = 0, $Length = null) {
      if ($Length === null)
        $Length = strlen ($Data) - $Offset;
      
      $oOffset = $Offset;
      
      if (!($Nameserver = qcEvents_Stream_DNS_Message::getLabel ($Data, $Offset)))
        return false;
      
      if (!($Mailbox = qcEvents_Stream_DNS_Message::getLabel ($Data, $Offset)))
        return false;
      
      if (($Length - ($Offset - $oOffset)) != 20)
        return false;
      
      $this->Nameserver = $Nameserver;
      $this->Mailbox    = $Mailbox;
      $this->Serial     = self::parseInt32 ($Data, $Offset);
      $this->Refresh    = self::parseInt32 ($Data, $Offset);
      $this->Retry      = self::parseInt32 ($Data, $Offset);
      $this->Expire     = self::parseInt32 ($Data, $Offset);
      $this->Minimum    = self::parseInt32 ($Data, $Offset);
      
      return true;
    }
    // }}}
    
    // {{{ buildPayload
    /**
     * Retrive the payload of this record
     * 
     * @param int $Offset
     * @param array &$Labels
     * 
     * @access public
     * @return string
     **/
    public function buildPayload ($Offset, &$Labels) {
      $Nameserver = qcEvents_Stream_DNS_Message::setLabel ($this->Nameserver, $Offset, $Labels);
      $Mailbox = qcEvents_Stream_DNS_Message::setLabel ($this->Mailbox, $Offset + strlen ($Nameserver), $Labels);
      
      return
        $Nameserver .
        $Mailbox .
        self::buildInt32 ($this->Serial) .
        self::buildInt32 ($this->Refresh) .
        self::buildInt32 ($this->Retry) .
        self::buildInt32 ($this->Expire) .
        self::buildInt32 ($this->Minimum);
    }
    // }}}
  }

?>
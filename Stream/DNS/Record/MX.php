<?PHP

  /**
   * qcEvents - DNS MX Resource Record
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
  
  class qcEvents_Stream_DNS_Record_MX extends qcEvents_Stream_DNS_Record {
    const DEFAULT_TYPE = 0x0F;
    
    /* Priority of this record */
    private $Priority = null;
    
    /* The hostname assigned to this record */
    private $Hostname = null;
    
    // {{{ __toString
    /**
     * Create a human-readable representation from this
     * 
     * @access friendly
     * @return string  
     **/
    function __toString () {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' MX ' . $this->Priority . ' ' . $this->Hostname;
    }
    // }}}
    
    // {{{ getPriority
    /**
     * Retrive a priority assigned to this record
     * 
     * @access public
     * @return int   
     **/
    public function getPriority () {
      return $this->Priority;
    }
    // }}}
    
    // {{{ setPriority
    /**
     * Set the priority of this record
     * 
     * @param int $Priority
     * 
     * @access public
     * @return bool
     **/
    public function setPriority ($Priority) {
      if (($Priority < 1) || ($Priority > 0xFFFF))
        return false;
      
      $this->Priority = (int)$Priority;
      
      return true;
    }
    // }}}
    
    // {{{ getHostname
    /**
     * Retrive the hostname assigned to this record
     * 
     * @access public
     * @return string
     **/
    public function getHostname () {
      return $this->Hostname;
    }  
    // }}}
      
    // {{{ setHostname
    /**
     * Store a hostname on this record
     * 
     * @param string $Hostname 
     * 
     * @access public
     * @return bool  
     **/
    public function setHostname ($Hostname) {
      $this->Hostname = $Hostname;
      
      return true;
    }
    // }}}
    
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
      $Priority = self::parseInt16 ($Data, $Offset);
      
      if (!($Hostname = qcEvents_Stream_DNS_Message::getLabel ($Data, $Offset)))
        return false;
      
      $this->Priority = $Priority;
      $this->Hostname = $Hostname;
      
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
      return
        self::buildInt16 ($this->Priority) .
        qcEvents_Stream_DNS_Message::setLabel ($this->Hostname, $Offset + 2, $Labels);
    }
    // }}}
  }

?>
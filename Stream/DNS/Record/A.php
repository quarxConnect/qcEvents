<?PHP

  /**
   * qcEvents - DNS IPv4 Resource Record
   * Copyright (C) 2014-2020 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  class qcEvents_Stream_DNS_Record_A extends qcEvents_Stream_DNS_Record {
    const DEFAULT_TYPE = 0x01;
    
    private $Address = '0.0.0.0';
    
    // {{{ __toString
    /**
     * Create a human-readable representation from this
     * 
     * @access friendly
     * @return string  
     **/
    function __toString () {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' A ' . $this->Address;
    }
    // }}}
    
    // {{{ getAddress
    /**
     * Retrive any address assigned to this record
     * 
     * @access public
     * @return string
     **/
    public function getAddress () {
      return $this->Address;
    }  
    // }}}
    
    // {{{ setAddress
    /**
     * Store an address for this record
     * 
     * @param string $Address
     * 
     * @access public
     * @return bool  
     **/
    public function setAddress ($Address) {
      # TODO: Check the address
      
      $this->Address = $Address;
      
      return true;
    }
    // }}}
    
    // {{{ parsePayload
    /**
     * Parse a given payload
     * 
     * @param string $dnsData
     * @param int $dataOffset
     * @param int $dataLength (optional)
     * 
     * @access public
     * @return void
     * @throws LengthException
     **/  
    public function parsePayload (&$dnsData, &$dataOffset, $dataLength = null) {
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);
      
      if ($dataLength < $dataOffset + 4)
        throw new LengthException ('DNS-Record too short (A)');
      
      $this->Address = long2ip (self::parseInt32 ($dnsData, $dataOffset, $dataLength));
      $dataOffset += 4;
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
      return self::buildInt32 (ip2long ($this->Address));
    }
    // }}}
  }

?>
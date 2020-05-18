<?PHP

  /**
   * qcEvents - DNS IPv6 Resource Record
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
  
  class qcEvents_Stream_DNS_Record_AAAA extends qcEvents_Stream_DNS_Record {
    const DEFAULT_TYPE = 0x1C;
    
    private $Address = '[::0]';
    
    // {{{ __toString
    /**
     * Create a human-readable representation from this
     * 
     * @access friendly
     * @return string  
     **/
    function __toString () {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' AAAA ' . $this->Address;
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
      
      if ($dataLength < $dataOffset + 16)
        throw new LengthException ('DNS-Record too short (AAAA)');
      
      $this->Address = '[' . bin2hex (substr ($dnsData, $dataOffset,      2)) . ':' .
                             bin2hex (substr ($dnsData, $dataOffset +  2, 2)) . ':' .
                             bin2hex (substr ($dnsData, $dataOffset +  4, 2)) . ':' .
                             bin2hex (substr ($dnsData, $dataOffset +  6, 2)) . ':' .
                             bin2hex (substr ($dnsData, $dataOffset +  8, 2)) . ':' .
                             bin2hex (substr ($dnsData, $dataOffset + 10, 2)) . ':' .
                             bin2hex (substr ($dnsData, $dataOffset + 12, 2)) . ':' .
                             bin2hex (substr ($dnsData, $dataOffset + 14, 2)) . ']';
      
      $dataOffset += 16;
    }
    // }}}
  }

?>
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
  
  require_once ('qcEvents/Socket.php');
  require_once ('qcEvents/Stream/DNS/Record.php');
  
  class qcEvents_Stream_DNS_Record_AAAA extends qcEvents_Stream_DNS_Record {
    const DEFAULT_TYPE = 0x1C;
    
    private $ipAddress = null;
    
    // {{{ __toString
    /**
     * Create a human-readable representation from this
     * 
     * @access friendly
     * @return string  
     **/
    function __toString () {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' AAAA ' . $this->ipAddress;
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
      return $this->ipAddress ?? '[::0]';
    }  
    // }}}
    
    // {{{ setAddress
    /**
     * Store an address for this record
     * 
     * @param string $ipAddress
     * 
     * @access public
     * @return void
     * @throws InvalidArgumentException
     **/
    public function setAddress ($ipAddress) {
      if (!qcEvents_Socket::isIPv6 ($ipAddress))
        throw new InvalidArgumentException ('Invalid IPv6');
      
      if ($ipAddress [0] != '[')
        $ipAddress = '[' . $ipAddress . ']';
      
      $this->ipAddress = $ipAddress;
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
      // Make sure we know the length of our input-buffer
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);
      
      // Handle empty payload
      if ($dataLength - $dataOffset == 0)
        $this->ipAddress = null;
      
      // Make sure we have enough input
      if ($dataLength - $dataOffset == 16) {
        $this->ipAddress = qcEvents_Socket::ip6fromBinary (substr ($dnsData, $dataOffset, 16));
        $dataOffset += 16;
      
      // Handle empty payload
      } elseif ($dataLength - $dataOffset == 0)
        $this->ipAddress = null;
      
      // Raise an error for anything else
      else
        throw new LengthException ('DNS-Record of invalid size (AAAA)');
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
      if ($this->ipAddress === null)
        return '';
      
      return qcEvents_Socket::ip6toBinary ($this->ipAddress);
    }
    // }}}
  }

?>
<?PHP

  /**
   * qcEvents - DNS IPv6 Resource Record
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
      
      if ($Length != 16)
        return false;
      
      $this->Address = '[' . bin2hex (substr ($Data, $Offset,      2)) . ':' .
                             bin2hex (substr ($Data, $Offset +  2, 2)) . ':' .
                             bin2hex (substr ($Data, $Offset +  4, 2)) . ':' .
                             bin2hex (substr ($Data, $Offset +  6, 2)) . ':' .
                             bin2hex (substr ($Data, $Offset +  8, 2)) . ':' .
                             bin2hex (substr ($Data, $Offset + 10, 2)) . ':' .
                             bin2hex (substr ($Data, $Offset + 12, 2)) . ':' .
                             bin2hex (substr ($Data, $Offset + 14, 2)) . ']';
      
      return true;
    }
    // }}}
  }

?>
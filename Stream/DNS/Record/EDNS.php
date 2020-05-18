<?PHP

  /**
   * qcEvents - EDNS Resource Record
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
  
  class qcEvents_Stream_DNS_Record_EDNS extends qcEvents_Stream_DNS_Record {
    const DEFAULT_TYPE = 0x29;
    
    private $RCode   = 0x00;
    private $Version = 0x00;
    private $Flags   = 0x0000;
    private $Options = array ();
    
    // {{{ __toString
    /**
     * Create a human-readable representation from this
     * 
     * @access friendly
     * @return string  
     **/
    function __toString () {
      return 'EDNS Version ' . $this->Version . ', Flags ' . $this->Flags . ', UDP ' . $this->getDatagramSize ();
    }
    // }}}
    
    // {{{ getReturnCode
    /**
     * Retrive the extended Return-Code
     * 
     * @access public
     * @return int
     **/
    public function getReturnCode () {
      return $this->RCode;
    }
    // }}}
    
    // {{{ setReturnCode
    /**
     * Set the extended return-code
     * 
     * @param int $Code
     * 
     * @access public
     * @return bool
     **/
    public function setReturnCode ($Code) {
      $this->RCode = $Code;
      $this->setTTL (($Code << 24) | ($this->getTTL () & 0xFFFFFF));
      
      return true;
    }
    // }}}
    
    // {{{ getFlags
    /**
     * Retrive extended flags of this DNS-Message
     * 
     * @access public
     * @return int
     **/
    public function getFlags () {
      return $this->Flags;
    }
    // }}}
    
    // {{{ setFlags
    /**
     * Set the extended flags of this DNS-Message
     *    
     * @param int $Flags
     * 
     * @access public
     * @return bool
     **/
    public function setFlags ($Flags) {
      $this->Flags = $Flags;
      $this->setTTL (($this->getTTL () & 0xFFFF0000) | ($Flags & 0xFFFF));
      
      return true;
    }
    // }}}
    
    // {{{ getDatagramSize
    /**
     * Retrive the disired maximum size of datagrams
     * 
     * @access public
     * @return int   
     **/
    public function getDatagramSize () {
      return $this->getClass ();
    }
    // }}}
    
    // {{{ setDatagramSize
    /**
     * Set the maximum size of datagrams for this message
     * 
     * @param int $Size
     * 
     * @access public
     * @return bool
     **/
    public function setDatagramSize ($Size) {
      return $this->setClass ($Size);
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
     * @thows LengthException
     **/
    public function parsePayload (&$dnsData, &$dataOffset, $dataLength = null) {
      // Parse option-data
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);
      
      $Options = array ();
      
      while ($dataOffset + 4 <= $dataLength) {
        $Option = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
        $Length = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
        
        $Options [$Option] = substr ($dnsData, $dataOffset, $Length);
        $dataOffset += $Length;
      }
      
      if ($dataOffset != $dataLength)
        throw new LengthException ('Garbage data on DNS-Record (EDNS)');
      
      $this->Options = $Options;
      
      // Parse information from meta-data
      $TTL = $this->getTTL ();
      
      $this->RCode = (($TTL >> 24) & 0xFF);  
      $this->Version = (($TTL >> 16) & 0xFF);
      $this->Flags = ($TTL & 0xFFFF);
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
      $Payload = '';
      
      foreach ($this->Options as $ID=>$Data)
        $Payload .=
          self::buildInt16 ($ID) .
          self::buildInt16 (strlen ($Data)) .
          $Data;
      
      return $Payload;
    }
    // }}}
  }

?>
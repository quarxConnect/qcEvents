<?PHP

  /**
   * qcEvents - DNS NSEC Resource Record
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
  
  class qcEvents_Stream_DNS_Record_NSEC extends qcEvents_Stream_DNS_Record {
    const DEFAULT_TYPE = 0x2F;
    
    private $nextDomainname = '';
    private $Types = array ();
    
    // {{{ __toString
    /**
     * Create a human-readable representation from this
     * 
     * @access friendly
     * @return string
     **/
    function __toString () {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' NSEC ' . $this->nextDomainname . (count ($this->Types) > 0 ? ' TYPE' . implode (' TYPE', $this->Types) : '');
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
      
      $Stop = $Offset + $Length;
      
      if (($nextDomainname = qcEvents_Stream_DNS_Message::getLabel ($Data, $Offset, false)) === false)
        return false;
      
      while ($Offset < $Stop) {
        $Window = ord ($Data [$Offset++]) * 0x0100;
        $Length = ord ($Data [$Offset++]);
        
        for ($b = 0; $b < $Length; $b++) {
          $v = ord ($Data [$Offset++]);
          
          if ($v & 0x80) $Types [$Window]     = $Window;
          if ($v & 0x40) $Types [$Window + 1] = $Window + 1;
          if ($v & 0x20) $Types [$Window + 2] = $Window + 2;
          if ($v & 0x10) $Types [$Window + 3] = $Window + 3;
          if ($v & 0x08) $Types [$Window + 4] = $Window + 4;
          if ($v & 0x04) $Types [$Window + 5] = $Window + 5;
          if ($v & 0x02) $Types [$Window + 6] = $Window + 6;
          if ($v & 0x01) $Types [$Window + 7] = $Window + 7;
          
          $Window += 8;
        }
      }
      
      $this->nextDomainname = $nextDomainname;
      $this->Types = $Type;
      
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
      $Windows = array ();
      $Bitmask = '';
      
      foreach ($this->Types as $Type) {
        $Window = (($Type >> 8) & 0xFF);
        $Bit    = ($Type & 0xFF);
        $Byte   = floor ($Bit / 8);
        $Bit   -= ($Byte * 8);
        
        // Make sure the byte exists
        if (!isset ($Windows [$Window]))
          $Windows [$Window] = str_repeat ("\x00", $Byte + 1);
        elseif (strlen ($Windows [$Window]) < $Byte + 1)
          $Windows [$Window] .= str_repeat ("\x00", $Byte + 1 - strlen ($Windows [$Window]));
        
        // Update the byte
        # TODO: This looks bad
        $Windows [$Window][$Byte] = chr (ord ($Windows [$Window][$Byte]) | (1 << (7 - $Bit)));
      }
      
      foreach ($Windows as $Window=>$Bytes)
        $Bitmask .= chr ($Window & 0xFF) . chr (strlen ($Bytes) & 0xFF) . $Bytes;
      
      return
        qcEvents_Stream_DNS_Message::setLabel ($this->SignersName) .
        $Bitmask;
    }
    // }}}
  }

?>
<?PHP

  /**
   * qcEvents - MPEG TS Table
   * Copyright (C) 2015 Bernd Holzmueller <bernd@quarxconnect.de>
   * 
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   * 
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   * GNU General Public License for more details.
   * 
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/
  
  abstract class qcEvents_Stream_MPEG_TS_Table {
    const TABLE_ID = null;
    
    /* This table contains current information */
    private $isCurrent = false;
    
    /* Total number of sections on this table */
    private $sectionCount = null;
    
    /* Already parsed sections */
    private $Sections = array ();
    
    // {{{ peekTableID
    /**
     * Peek a table-id from a given data-segment
     * 
     * @param string $Data
     * 
     * @access public
     * @return int
     **/
    public static function peekTableID ($Data) {
      if (($l = strlen ($Data)) < 1)
        return false;
      
      if (($o = ord ($Data [0]) + 1) >= $l)
        return false;
      
      return ord ($Data [$o]);
    }
    // }}}
    
    // {{{ parse
    /**
     * Try to parse data for this table
     * 
     * @param string $Data
     * @param bool $Current (optional)
     * 
     * @access public
     * @return bool
     **/
    public function parse ($Data, $Current = false) {
      // Skip the pointer
      $Offset = ord ($Data [0]) + 1;
      
      // Read all flags of the table
      $Table = ord ($Data [$Offset++]);
      
      if (($this::TABLE_ID !== null) && ($Table != $this::TABLE_ID)) {
        trigger_error ('Table-ID mismatch: ' . $Table . ' != ' . $this::TABLE_ID);
        
        return false;
      }
      
      $Flags = (ord ($Data [$Offset++]) << 8) | (ord ($Data [$Offset++]));
      $sectionSyntaxIndicator = (($Flags & 0x8000) == 0x8000);
      $sectionLength = ($Flags & 0xFFF);
      $TableID = (ord ($Data [$Offset++]) << 8) | (ord ($Data [$Offset++]));
      
      $Flags = (ord ($Data [$Offset++]));
      $Version = (($Flags & 0x3E) >> 1);
      $currentNextIndicator = (($Flags & 0x01) == 0x01);
      $sectionNumber = (ord ($Data [$Offset++]));
      $lastSectionNumber = (ord ($Data [$Offset++]));
      
      if ($Current && !$currentNextIndicator)
        return;
      
      // Check the integrity
      $crc32 =
        (ord ($Data [$Offset + $sectionLength - 9]) << 24) |
        (ord ($Data [$Offset + $sectionLength - 8]) << 16) |
        (ord ($Data [$Offset + $sectionLength - 7]) <<  8) |
        (ord ($Data [$Offset + $sectionLength - 6]));
      
      // Try to parse the additional section
      if (($Section = $this->parseSection (substr ($Data, $Offset, $Offset + $sectionLength - 18))) === false)
        return false;
      
      if ($this->sectionCount === null)
        $this->sectionCount = $lastSectionNumber + 1;
      
      $this->isCurrent = $currentNextIndicator;
      $this->Sections [$sectionNumber] = $Section;
      
      return true;
    }
    // }}}
    
    // {{{ parseSection
    /**
     * Parse a section of this table
     * 
     * @param string $Data
     * 
     * @access protected
     * @return array
     **/
    abstract protected function parseSection ($Data);
    // }}}
    
    // {{{ isReady
    /**
     * Check if the table was parsed completely
     * 
     * @access public
     * @return bool
     **/
    public function isReady () {
      return (count ($this->Sections) == $this->sectionCount);
    }
    // }}}
    
    public function getItems () {
      $out = array ();
      
      foreach ($this->Sections as $Section)
        foreach ($Section as $Key=>$Item)
          $out [$Key] = $Item;
      
      return $out;
    }
  }

?>
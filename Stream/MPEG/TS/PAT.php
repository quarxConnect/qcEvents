<?PHP

  /**
   * qcEvents - MPEG TS Programme Address Table
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
  
  require_once ('qcEvents/Stream/MPEG/TS/Table.php');
  
  class qcEvents_Stream_MPEG_TS_PAT extends qcEvents_Stream_MPEG_TS_Table {
    const TABLE_ID = 0x00;
    
    /* PID of Network-Information-Table */
    private $NIT = null;
    
    
    // {{{ getNIT
    /**
     * Retrive PID of Network-Information-Table (if there is one)
     * 
     * @access public
     * @return int
     **/
    public function getNIT () {
      return $this->NIT;
    }
    // }}}
    
    // {{{ parseSection
    /**
     * Parse section of a PAT-Table
     * 
     * @param string $Data
     * 
     * @access protected
     * @return array
     **/
    protected function parseSection ($Data) {
      // Read programs
      $NIT = null;
      $PAT = array ();
      $Offset = 0;
      $Length = strlen ($Data);

      while ($Offset < $Length) {
        $programmNumber =
          (ord ($Data [$Offset++]) <<  8) |
          (ord ($Data [$Offset++]));
        
        $pid =
          ((ord ($Data [$Offset++]) & 0x1F) <<  8) |
          (ord ($Data [$Offset++]));
        
        if ($programmNumber == 0x0000)
          $NIT = $pid;
        else
          $PAT [$programmNumber] = $pid;
      }
      
      if ($NIT !== null)
        $this->NIT = $NIT;
      
      return $PAT;
    }
    // }}}
  }

?>
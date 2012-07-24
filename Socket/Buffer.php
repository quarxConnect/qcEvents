<?PHP

  /**
   * qcEvents - Buffered Socket
   * Copyright (C) 2012 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Socket.php');
  
  /**
   * Buffered socket
   * ---------------
   * 
   * @class qcEvents_Socket_Buffer
   * @extends qcEvents_Socket
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Socket_Buffer extends qcEvents_Socket {
    const METHOD_LINE = 0;
    
    /* Internal buffer */
    private $Buffer = '';
    
    /* Buffer-Method */
    private $BufferMethod = qcEvents_Socket_Buffer::METHOD_LINE;
    
    // {{{ receive
    /**   
     * Callback: Invoked whenever incoming data is received
     *  
     * @param string $Data  
     * 
     * @access protected
     * @return void   
     **/
    protected function receive ($Data) {
      // We expect lines
      if ($this->BufferMethod == self::METHOD_LINE)
        $this->receiveLine ($Data);
    }
    // }}}
    
    // {{{ receiveLine
    /**
     * Try to receive a line from our server
     * 
     * @access protected
     * @return string
     **/
    protected function receiveLine ($Data) {
      // Append the data to our internal buffer
      $this->Buffer .= $Data;
      unset ($Data);
      
      // Check if there are complete lines on the buffer
      while (($p = strpos ($this->Buffer, "\n")) !== false) {
        // Peek the line from buffer
        $Line = rtrim (substr ($this->Buffer, 0, $p));
        $this->Buffer = substr ($this->Buffer, $p + 1);
        
        // Fire the callback
        if ($this->___callback ('receivedLine', $Line) === false)
          break;
      }
    }
    // }}}
    
    // {{{ receivedLine
    /**
     * Callback: Invoked when receiveLine() finds a completed line on the buffer
     * 
     * @param string $Line
     * 
     * @access protected
     * @return bool
     **/
    protected function receivedLine ($Line) { }
    // }}}
  }

?>
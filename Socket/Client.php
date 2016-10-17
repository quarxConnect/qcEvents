<?PHP

  /**
   * qcEvents - Common Client Functions
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
   * Abstract Client
   * ---------------
   * Implementation of some generic client-functionality
   * 
   * @class qcEvents_Socket_Client
   * @extends qcEvents_Socket
   * @package qcEvents
   * @revision 01
   **/
  abstract class qcEvents_Socket_Client extends qcEvents_Socket {
    const USE_LINE_BUFFER = false;
    
    private $Buffer = '';
    
    // {{{ socketReceive
    /**
     * Internal Callback: Receive data from the wire
     * 
     * @param string $Data
     * 
     * @access protected
     * @return void
     **/
    protected function socketReceive ($Data) {
      // Check wheter to forward this data to the per-line-parser
      if ($this::USE_LINE_BUFFER)
        return $this->receiveLine ($Data);
      
      trigger_error ('Receiving Data on client without line-buffer enabled');
    }
    // }}}
    
    // {{{ socketReadable
    /**
     * Internal Callback: Data is available on the read-buffer
     * 
     * @access protected
     * @return void
     **/
    protected function socketReadable () {
      // Check wheter to forward this data to the per-line-parser
      if ($this::USE_LINE_BUFFER)
        return $this->receiveLine ($this->read ());
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
    
    // {{{ getLineBuffer
    /**
     * Retrive contents of our line-buffer
     * 
     * @access protected
     * @return string
     **/
    protected function getLineBuffer () {
      return $this->Buffer;
    }
    // }}}
    
    // {{{ getLineBufferClean
    /**
     * Retrive contents of our line-buffer and clean it
     * 
     * @access protected
     * @return string
     **/
    protected function getLineBufferClean () {
      $rc = $this->Buffer;
      $this->Buffer = '';
      
      return $rc;
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
<?PHP

  /**
   * qcEvents - HTTP-Stream Implementation
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
  require_once ('qcEvents/Socket/Stream/HTTP/Request.php');
  
  /**
   * HTTP-Stream
   * -----------
   * Abstract HTTP-Stream-Handler (common functions for both - client and server)
   * 
   * @class qcEvents_Socket_Stream_HTTP
   * @extends qcEvents_Socket
   * @package qcEvents
   * @revision 01
   **/
  abstract class qcEvents_Socket_Stream_HTTP extends qcEvents_Socket {
    private $requestBuffer = '';
    private $requestHandle = null;
    
    // {{{ reset
    /**
     * Reset internal variables   
     * 
     * @access protected
     * @return void      
     **/
    protected function reset () {
      $this->requestHandle = null;
    }
    // }}}
    
    // {{{ bufferRequest
    /**
     * Buffer data for a HTTP-Request
     * 
     * @param string $Data
     * 
     * @access protected
     * @return bool TRUE if a request was parsed completely, FALSE if this isn't a HTTP-Request, NULL if there is not enough data available
     **/
    protected function bufferRequest ($Data) {
      // Append the Data to our internal buffer
      $this->requestBuffer .= $Data;
      
      // Check if a full line was received
      if (!is_object ($this->requestHandle) || !$this->requestHandle->headerComplete ())
        while (true) {
          // Search the end-of-line
          if (($LE = strpos ($this->requestBuffer, "\n")) === false)
            return null;
          
          // Peek the line from buffer
          $Line = rtrim (substr ($this->requestBuffer, 0, $LE));
          $this->requestBuffer = substr ($this->requestBuffer, $LE + 1);
          
          // Check if the request was already started
          if (!is_object ($this->requestHandle)) {
            // Check for spaces on the line
            if ((($p1 = strpos ($Line, ' ')) === false) ||
                (($p2 = strrpos ($Line, ' ')) === false) || ($p1 == $p2))
              return false;
            
            // Parse the request line
            $Method = strtoupper (substr ($Line, 0, $p1));
            $Protocol = strtoupper (substr ($Line, $p2 + 1));
            $URI = substr ($Line, $p1 + 1, $p2 - $p1 - 1);
            
            if (substr ($Protocol, 0, 5) != 'HTTP/')
              return false;
            
            // Create a new request-object
            $this->requestHandle = new qcEvents_Socket_Stream_HTTP_Request ($Method, $URI, $Protocol);
            
            unset ($p1, $p2, $Method, $Protocol, $URI);
          
          // Check for end of request-header
          } elseif (strlen ($Line) == 0) {
            $this->requestHandle->headerComplete (true);
            
            break;
          
          // Check if this is a normal header
          } elseif (($p = strpos ($Line, ':')) === false)
            return false;
          
          // Append normal Header-data
          else
            $this->requestHandle->appendHeader (substr ($Line, 0, $p), ltrim (substr ($Line, $p + 1)));
        }
      
      // Check if we are expecting payload
      if ($this->requestHandle->expectPayload ()) {
        // Check if there is enough data available
        if (strlen ($this->requestBuffer) < ($l = $this->requestHandle->getPayloadLength ()))
          return null;
        
        // Truncate the payload
        $this->requestHandle->setPayload (substr ($this->requestBuffer, 0, $l));
        $this->requestBuffer = substr ($this->requestBuffer, $l + 1);
      }
      
      // Indicate a success
      return true;
    }
    // }}}
    
    // {{{ getRequest
    /**
     * Retrive the current request
     * 
     * @access public
     * @return object
     **/
    public function getRequest () {
      return $this->requestHandle;
    }
    // }}}
  }

?>
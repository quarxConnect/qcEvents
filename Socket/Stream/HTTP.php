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
  require_once ('qcEvents/Socket/Stream/HTTP/Response.php');
  
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
    
    private $Buffer = null;
    private $Request = null;
    private $Response = null;
    
    // {{{ reset
    /**
     * Reset internal variables   
     * 
     * @access protected
     * @return void      
     **/
    protected function reset () {
      $this->Request = null;
      $this->Response = null;
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
      $this->Buffer .= $Data;
      unset ($Data);
      
      // Check if we already received an initial line
      if (!is_object ($this->Request)) {
        // Search the end-of-line
        if (($p = strpos ($this->Buffer, "\n")) === false)
          return null;
        
        // Get the line from buffer
        $Line = rtrim (substr ($this->Buffer, 0, $p));
        $this->Buffer = substr ($this->Buffer, $p + 1);
        
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
        $this->Request = new qcEvents_Socket_Stream_HTTP_Request ($Method, $URI, $Protocol);
      }
      
      // Parse additional headers    
      if (($rc = $this->bufferHeader ($this->Request)) !== true)
        return $rc;
      
      // Check if we are expecting payload
      if ($this->Request->expectPayload ()) {
        // Check if there is enough data available
        if (strlen ($this->Buffer) < ($l = $this->Request->getPayloadLength ()))
          return null;
        
        // Truncate the payload
        $this->Request->setPayload (substr ($this->Buffer, 0, $l));
        $this->Buffer = substr ($this->Buffer, $l + 1);
      }
      
      // Indicate a success
      return true;
    }
    // }}}
    
    // {{{ bufferResponse
    /**
     * Buffer a HTTP-Response
     * 
     * @param string $Input
     * 
     * @access protected
     * @return bool
     **/
    protected function bufferResponse ($Input) {
      // Append to local buffer
      $this->Buffer .= $Input;
      unset ($Input);
      
      // Check if we have received an inital line
      if (!is_object ($this->Response)) {
        // Check if there is an initial line
        if (($p = strpos ($this->Buffer, "\n")) === false)
          return null;
        
        // Get the initial line from buffer
        $Line = rtrim (substr ($this->Buffer, 0, $p));
        $this->Buffer = substr ($this->Buffer, $p + 1);
        
        // Parse the line
        $Protocol = substr ($Line, 0, ($p = strpos ($Line, ' ')));
        $Code = intval (substr ($Line, $p + 1, 3));
        $Msg = substr ($Line, $p + 5);
        
        // Create a Response-object
        $this->Response = new qcEvents_Socket_Stream_HTTP_Response ($Protocol, $Code, $Msg);
      }
      
      // Parse additional headers
      return $this->bufferHeader ($this->Response);
    }
    // }}}
    
    // {{{ bufferHeader
    /**
     * Fetch HTTP-Headers from buffer
     * 
     * @param object $Header
     * 
     * @access private
     * @return bool
     **/
    private function bufferHeader ($Header) {
      // Check if the header is already received
      if ($Header->headerComplete ())
        return true;
      
      // Get new header-entries from the buffer
      while (($p = strpos ($this->Buffer, "\n")) !== false) {
        // Get the next line from Buffer
        $Line = rtrim (substr ($this->Buffer, 0, $p));
        $this->Buffer = substr ($this->Buffer, $p + 1);
        
        // Check for end-of-header
        if (strlen ($Line) == 0) {
          $Header->headerComplete (true);
          
          return true;
        }
        
        // Check if this is a valid header
        if (($p = strpos ($Line, ':')) === false)
          return false;
        
        $Header->appendHeader (substr ($Line, 0, $p), trim (substr ($Line, $p + 1)));
      }
      
      return null;
    }
    // }}}
    
    // {{{ bufferPayload
    /**
     * Buffer and receive Payload
     * 
     * @param string $Input
     * 
     * @access protected
     * @return bool
     **/
    protected function bufferPayload ($Input) {
      // Append to local buffer
      $this->Buffer .= $Input;
      unset ($Input);
      
      // Check if we are handling a response
      if (is_object ($this->Response))
        return $this->Response->receivePayload ($this->Buffer);
      
      // Check if we are handling a request
      elseif (is_object ($this->Request))
        return $this->Response->receivePayload ($this->Buffer);
      
      return false;
    }
    // }}}
    
    // {{{ writeRequest
    /**
     * Submit a request over the wire
     * 
     * @param object $Request
     * 
     * @access protected
     * @return void
     **/
    protected function writeRequest ($Request) {
      // Store the request
      $this->Request = $Request;
      
      // Write out the request-line
      $this->mwrite ($Request->getMethod (), ' ', $Request->getURI (), ' ', $Request->getProtocol (), "\r\n");
      
      // Write out all headers
      foreach ($Request->getHeaders () as $Key=>$Value)
        $this->mwrite ($Key, ': ', $Value, "\r\n");
      
      $this->mwrite ("\r\n");
      
      # TODO: What about payload?!
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
      return $this->Request;
    }
    // }}}
    
    // {{{ getResponse
    /**
     * Retrive the current response
     * 
     * @access public
     * @return object
     **/
    public function getResponse () {
      return $this->Response;
    }
    // }}}
  }

?>
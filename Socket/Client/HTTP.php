<?PHP

  /**
   * qcEvents - HTTP Client Implementation
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

  require_once ('qcEvents/Socket/Stream/HTTP.php');
  
  class qcEvents_Socket_Client_HTTP extends qcEvents_Socket_Stream_HTTP {
    private $Request = null;
    private $Requests = array ();
    private $Response = null;
    
    // {{{ addRequest
    /**
     * Append a request to our queue
     * 
     * @param string $Host
     * @param string $URL
     * @param enum $Method
     * 
     * @access public
     * @return void
     **/
    public function addRequest ($Host, $URL, $Method = qcEvents_Socket_Stream_HTTP_Request::METHOD_GET) {
      $R = new qcEvents_Socket_Stream_HTTP_Request ($Method, $URL, 'HTTP/1.1');
      $R->appendHeader ('host', $Host);
      
      $this->Requests [] = $R;
      $this->submitRequest ();
    }
    // }}}
    
    // {{{ connected
    /**
     * Internal Callback: Connection was established
     * 
     * @access protected
     * @return void
     **/
    protected function connected () {
      $this->submitRequest ();
    }
    // }}}
    
    // {{{ receive
    /**
     * Receive an incoming request
     * 
     * @param string $Data
     * 
     * @access protected
     * @return void
     **/
    protected function receive ($Data) {
      // Try to receive response-headers
      if (!is_object ($this->Response)) {
        if (($rc = $this->bufferResponse ($Data)) === false)
          return $this->handleReceiveError ();
        
        elseif ($rc === null)
          return null;
        
        $this->Response = $this->getResponse ();
      }
      
      // Receive payload from the response
      if (($rc = $this->bufferPayload ($Data)) === false)
        return $this->handleReceiveError ();
        
      elseif ($rc === null)
        return null;
      
      // Fire up the callback
      $this->___callback ('responseReceived', $this->Request, $this->Response);
      
      // Head over to the next request
      $this->Request = null;
      $this->submitRequest ();
    }
    // }}}
    
    // {{{ submitRequest
    /**
     * Submit a request
     *
     * @access protected
     * @return void
     **/
    protected function submitRequest () {
      // Check if we are connected
      if (!$this->isOnline ())
        return false;
      
      // Check if there is a request pending
      if (is_object ($this->Request))
        return false;
      
      // Check if there are queued events
      if (count ($this->Requests) == 0)
        return true;
      
      // Move the next request into the active queue
      $this->Request = array_shift ($this->Requests);
      
      // Write the request to the wire
      $this->writeRequest ($this->Request);
    }
    // }}}
    
    // {{{ handleReceiveError
    /**
     * An error occured upon receiving
     *
     * @access private
     * @return void
     **/
    private function handleReceiveError () {
      # TODO
    }
    // }}}
    
    // {{{ responseReceived
    /**
     * Callback: HTTP-Response was received
     * 
     * @param object $Request
     * @param object $Response
     * 
     * @access protected
     * @return void
     **/
    protected function responseReceived ($Request, $Response) { }
    // }}}
  }

?>
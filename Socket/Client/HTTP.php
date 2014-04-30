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

  require_once ('qcEvents/Stream/HTTP.php');
  require_once ('qcEvents/Socket/Client/HTTP/Request.php');
  
  class qcEvents_Socket_Client_HTTP extends qcEvents_Stream_HTTP {
    /* Current Request */
    private $Request = null;
    
    /* Pending requests */
    private $Requests = array ();
    
    // {{{ __construct   
    /**
     * Create a new server-client
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {   
      // Inherit to our parent
      call_user_func_array ('parent::__construct', func_get_args ());
      
      // Register hooks
      $this->addHook ('socketConnected', array ($this, 'httpSocketConnected'));
      $this->addHook ('socketDisconnected', array ($this, 'httpSocketDisconnected'));
      $this->addHook ('socketConnectionFailed', array ($this, 'httpSocketFailed'));
      $this->addHook ('httpFinished', array ($this, 'httpReqeustFinished'));
    }
    // }}}
    
    // {{{ addRequest
    /**
     * Enqueue an HTTP-Request
     * 
     * @param mixed $Request
     * @param enum $Method (optional)
     * @param array $Headers (optional)
     * @param string $Body (optional)
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function addRequest ($Request, $Method = null, $Headers = null, $Body = null, callable $Callback = null, $Private = null) {
      // Make sure we have a request-object
      if (!($Request instanceof qcEvents_Socket_Client_HTTP_Request))
        $Request = new qcEvents_Socket_Client_HTTP_Request ($Request);
      
      // Set additional properties of the request
      if ($Method !== null)
        $Request->setMethod ($Method);
      
      if (is_array ($Headers))
        foreach ($Headers as $Key=>$Value)
          $Request->setField ($Key, $Value);
      
      if ($Body !== null)
        $Request->setBody ($Body);
      
      // Enqueue the request
      $this->Requests [] = array ($Request, $Callback, $Private);
      
      // Try to submit it
      $this->submitPendingRequest ();
    }
    // }}}
    
    // {{{ submitPendingRequest
    /**
     * Submit a pending request over the wire
     * 
     * @access private
     * @return void
     **/
    private function submitPendingRequest () {
      // Check if there is an active request
      if ($this->Request !== null)
        return;
      
      // Check if there are no more events pending
      if (count ($this->Requests) == 0)
        return $this->disconnect ();
      
      // Reset the HTTP-Stream
      $this->reset ();
      
      // Retrive the next request
      $this->Request = array_shift ($this->Requests);
      
      $Host = $this->Request [0]->getHostname ();
      $Port = $this->Request [0]->getPort ();
      $TLS = $this->Request [0]->useTLS ();
      
      // Check if to connect to remote host
      if ($this->isConnected ()) {
        if (($this->getRemoteHost () == $Host) &&
            ($this->getRemotePort () == $Port) &&
            ($this->tlsEnable () == $TLS))
          return $this->httpSocketConnected ();
        
        // Close the current connection
        return $this->disconnect ();
      }
      
      // Connect to next host
      return $this->connect ($Host, $Port, self::TYPE_TCP, $TLS);
    }
    // }}}
    
    // {{{ httpSocketConnected
    /**
     * Internal Callback: Our underlying socket was connected
     * 
     * @access protected
     * @return void
     **/
    protected final function httpSocketConnected () {
      // Check if a request is pending
      if ($this->Request === null)
        return $this->submitPendingRequest ();
      
      // Write out the request
      $this->write (strval ($this->Request [0]));
    }
    // }}}
    
    // {{{ httpSocketDisconnected
    /**
     * Internal Callback: Our underlying socket was disconnected
     * 
     * @access protected
     * @return void
     **/
    protected final function httpSocketDisconnected () {
      // Check if a request is pending
      if ($this->Request === null)
        return $this->submitPendingRequest ();
      
      // Connect to next destination
      $this->connect ($this->Request [0]->getHostname (), $this->Request [0]->getPort (), self::TYPE_TCP, $this->Request [0]->useTLS ());
    }
    // }}}
    
    // {{{ httpSocketFailed
    /**
     * Internal Callback: HTTP-Connection failed at socket-level
     * 
     * @access protected
     * @return void
     **/
    protected final function httpSocketFailed () {
      // Check if there is a request pending
      if ($this->Request === null)
        return;
      
      // Release the current request
      $Request = $this->Request;
      $this->Request = null;
      
      // Fire callbacks
      $this->___raiseCallback ($Request [1], $Request [0], null, null, $Request [2]);
      $this->___callback ('httpRequestResult', $Request [0], null, null);
      
      // Move to next request
      $this->submitPendingRequest ();
    }
    // }}}
    
    // {{{ httpReqeustFinished
    /** 
     * Internal Callback: HTTP-Request was finished
     * 
     * @param qcEvents_Stream_HTTP_Header $Header
     * @param string $Body
     * 
     * @access protected
     * @return void
     **/
    protected final function httpReqeustFinished ($Header, $Body) {
      // Check if a request is pending
      if ($this->Request !== null) {
        $Request = $this->Request;
        $this->Request = null;
        
        // Fire callbacks
        $this->___raiseCallback ($Request [1], $Request [0], $Header, $Body, $Request [2]);
        $this->___callback ('httpRequestResult', $Request [0], $Header, $Body);
      }
      
      // Submit the next request
      if (strtolower ($Header->getField ('Connection')) != 'close')
        $this->submitPendingRequest ();
    }
    // }}}
    
    // {{{ httpRequestResult
    /**
     * Callback: HTTP-Request is finished
     * 
     * @param qcEvents_Socket_Client_HTTP_Request $Request
     * @param qcEvents_Stream_HTTP_Header $Header (optional)
     * @param string $Body (optional)
     * 
     * @access protected
     * @return void
     **/
    protected function httpRequestResult (qcEvents_Socket_Client_HTTP_Request $Request, qcEvents_Stream_HTTP_Header $Header = null, $Body = null) { }
    // }}}
  }

?>
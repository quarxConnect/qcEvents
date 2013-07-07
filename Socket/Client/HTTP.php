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
      $this->addHook ('httpFinished', array ($this, 'httpReqeustFinished'));
    }
    // }}}
    
    // {{{ addRequest
    /**
     * Enqueue an HTTP-Request
     * 
     * @param string $URL
     * @param enum $Method (optional)
     * @param array $Headers (optional)
     * @param string $Body (optional)
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function addRequest ($URL, $Method = 'GET', $Headers = array (), $Body = null, $Callback = null) {
      // Enqueue the request
      $this->Requests [] = array (
        0 => $URL,
        1 => parse_url ($URL),
        2 => $Method,
        3 => $Headers,
        4 => $Body,
        5 => $Callback
      );
      
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
      if (count ($this->Requests) == 0) {
        $this->disconnect ();
        
        return;
      }
      
      // Reset the HTTP-Stream
      $this->reset ();
      
      // Retrive the next request
      $this->Request = array_shift ($this->Requests);
      
      $Host = $this->Request [1]['host'];
      $Port = (isset ($this->Request [1]['port']) ? $this->Request [1]['port'] : ($this->Request [1]['scheme'] == 'https' ? 443 : 80));
      
      // Check if to connect to remote host
      if ($this->isConnected () &&
          ($this->getRemoteHost () == $Host) &&
          ($this->getRemotePort () == $Port))
        return $this->httpSocketConnected ();
      
      if ($this->isDisconnected ())
        $this->connect ($Host, $Port, self::TYPE_TCP, ($this->Request [1]['scheme'] == 'https'));
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
      
      if (!isset ($this->Request [3]['Host']))
        $this->Request [3]['Host'] = $this->Request [1]['host'];
      
      $this->mwrite ($this->Request [2], ' ', $this->Request [1]['path'], (isset ($this->Request [1]['query']) ? '?' . $this->Request [1]['query'] : ''), ' HTTP/1.1', "\r\n");
      
      foreach ($this->Request [3] as $Key=>$Value)
        $this->mwrite ($Key, ': ', $Value, "\r\n");
      
      $this->write ("\r\n");
    }
    // }}}
    
    // {{{ httpReqeustFinished
    /** 
     * Internal Callback: HTTP-Request was finished
     * 
     * @param qcEvents_Socket_Stream_HTTP_Header $Header
     * @param string $Body
     * 
     * @access protected
     * @return void
     **/
    protected final function httpReqeustFinished ($Header, $Body) {
      // Check if a request is pending
      if ($this->Request !== null) {
        // Fire callbacks
        if (is_callable ($this->Request [5]))
          call_user_func ($this->Request [5], $this, $this->Request [0], $Header, $Body);
        
        $this->___callback ('httpRequestResult', $this->Request [0], $Header, $Body);
        
        // Remove the current request
        $this->Request = null;
      }
      
      // Submit the next request
      $this->submitPendingRequest ();
    }
    // }}}
    
    // {{{ httpRequestResult
    /**
     * Callback: HTTP-Request is finished
     * 
     * @param string $URL
     * @param qcEvents_Socket_Stream_HTTP_Header $Header
     * @param string $Body
     * 
     * @access protected
     * @return void
     **/
    protected function httpRequestResult ($URL, qcEvents_Socket_Stream_HTTP_Header $Header, $Body) { }
    // }}}
  }

?>
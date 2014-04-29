<?PHP

  /**
   * qcEvents - HTTP-Server Implementation
   * Copyright (C) 2013 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Socket/Stream/HTTP.php');
  
  /**
   * HTTP-Server
   * -----------
   * HTTP-Request Handler (server)
   * 
   * @class qcEvents_Socket_Server_HTTP
   * @package qcEvents
   * @revision 03
   **/
  class qcEvents_Socket_Server_HTTP extends qcEvents_Socket_Stream_HTTP {
    /* Maximum numbers of requests for this connection */
    private $maxRequestCount = 50;
    
    /* Number of processed requests */
    private $RequestCount = 0;
    
    /* Active Request-Headers */
    private $Request = null;
    
    /* Body of active request */
    private $RequestBody = null;
    
    /* Queued requests */
    private $Requests = array ();
    
    /* Our Response-Headers */
    private $Response = null;
    
    /* Timeout of this connection */
    private $keepAliveTimeout = 5;
    
    // {{{ __construct   
    /**
     * Create a new HTTP-Stream   
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      // Inherit to our parent
      call_user_func_array ('parent::__construct', func_get_args ());
        
      // Register hooks
      $this->addHook ('httpFinished', array ($this, 'httpdRequestReady'));
    }
    // }}}
    
    // {{{ httpdSetResponse
    /**
     * Finish a request within a single transmission
     * 
     * @param qcEvents_Socket_Stream_HTTP_Header $Request
     * @param qcEvents_Socket_Stream_HTTP_Header $Response
     * @param string $Body (optional)
     * 
     * @access public
     * @return bool
     **/
    public function httpdSetResponse (qcEvents_Socket_Stream_HTTP_Header $Request, qcEvents_Socket_Stream_HTTP_Header $Response, $Body = null) {
      // Check if the given request matches the current one
      if ($Request !== $this->Request)
        return false;
      
      // Make sure there is a line-break at the end of body
      if (substr ($Body, -2, 2) != "\r\n")
        $Body .= "\r\n";
      
      // Set length of content
      $Response->setField ('Content-Length', strlen ($Body));
      
      // Write out the response
      $this->httpHeaderWrite ($Response);
      $this->write ($Body);
      
      // Reset the current state
      $this->httpdFinish ();
      
      return true;
    }
    // }}}
    
    // {{{ httpdStartResponse
    /**
     * Start a response for a given request
     * 
     * @param qcEvents_Socket_Stream_HTTP_Header $Request
     * @param qcEvents_Socket_Stream_HTTP_Header $Response
     * 
     * @access public
     * @return bool
     **/
    public function httpdStartResponse (qcEvents_Socket_Stream_HTTP_Header $Request, qcEvents_Socket_Stream_HTTP_Header $Response) {
      // Check if the given request matches the current one
      if ($Request !== $this->Request)
        return false;
      
      // Check if headers are already sent
      if ($this->Response)
        return false;
      
      // Make sure the response-header is correct for this response
      if ($Response->getVersion () < 1.1) {
        $Response->unsetField ('Transfer-Encoding');
        $Response->setField ('Connection', 'close');
      } else
        $Response->setField ('Transfer-Encoding', 'chunked');
      
      $Response->unsetField ('Content-Length');
      
      // Write out the response  
      $this->httpHeaderWrite ($Response);
      
      return true;
    }
    // }}}
    
    // {{{ httpdWriteResponse
    /**
     * Write futher data for a given request
     * 
     * @param qcEvents_Socket_Stream_HTTP_Header $Request
     * @param string $Body
     * 
     * @access public
     * @return bool
     **/
    public function httpdWriteResponse (qcEvents_Socket_Stream_HTTP_Header $Request, $Body) {
      // Check if the given request matches the current one
      if (($Request !== $this->Request) || !$this->Response)
        return false;
      
      // Write out the chunk
      if ($this->Response->getVersion () < 1.1)
        $this->write ($Body);
      else
        $this->mwrite (dechex (strlen ($Body)), "\r\n", $Body, "\r\n");
      
      return true;
    }
    // }}}
    
    // {{{ httpdFinishResponse
    /**
     * Finish a given response
     * 
     * @param qcEvents_Socket_Stream_HTTP_Header $Request
     * @param qcEvents_Socket_Stream_HTTP_Header $Trailer (optional)
     * 
     * @access public
     * @return bool
     **/
    public function httpdFinishResponse (qcEvents_Socket_Stream_HTTP_Header $Request, qcEvents_Socket_Stream_HTTP_Header $Trailer = null) {
      // Check if the given request matches the current one
      if (($Request !== $this->Request) || !$this->Response)
        return false;
      
      // Write out last chunk
      $this->write ("0\r\n");
      
      // Check for tailing header
      if (is_object ($Trailer)) {
        $Header = strval ($Trailer);
        $this->write (substr ($Header, strpos ($Header, "\r\n") + 2));
      } else
        $this->write ("\r\n");
      
      // Reset ourself
      $this->httpdFinish ();
      
      return true;
    }
    // }}}
    
    // {{{ httpdFinish
    /**
     * Finish the active HTTP-Request
     * 
     * @access private
     * @return void
     **/
    private function httpdFinish () {
      // Handle response-headers
      if ($this->Response && $this->Response->hasField ('Connection')) {
        if ($this->Response->getField ('Connection') == 'close')
          $this->disconnect ();
        else
          $this->addTimeout ($this->keepAliveTimeout, false, array ($this, 'httpdCheckKeepAlive'));
      }
      
      // Make sure pending requests are processed (with a cleaned up stack)
      $this->forceOnNextIteration (self::EVENT_TIMER);
      
      // Reset ourself
      $this->reset ();
    }
    // }}}
    
    // {{{ reset
    /**
     * Reset our internal state
     * 
     * @access protected
     * @return void
     **/
    protected function reset () {
      // Reset ourself
      $this->Request = null;
      $this->RequestBody = null;
      $this->Response = null;
      
      // Reset our parent, too
      parent::reset ();
    }
    // }}}
    
    // {{{ httpHeaderWrite
    /**
     * Write out a HTTP-Header
     * 
     * @param qcEvents_Socket_Stream_HTTP_Header $Header
     * @access protected
     * @return void
     **/
    protected function httpHeaderWrite (qcEvents_Socket_Stream_HTTP_Header $Header) {
      // Check if headers have been written already
      if ($this->Response)
        return false;
      
      // Check for some hard-coded headers
      if (!$Header->hasField ('Server'))
        $Header->setField ('Server', 'quarxConnect.de qcEvents/HTTPd');
      
      if (!$Header->hasField ('Date'))
        $Header->setField ('Date', date ('r'));
      
      // Check wheter to force a close on the connection
      if ($this->RequestCount >= $this->maxRequestCount)
        $Header->setField ('Connection', 'close');
      elseif (!$Header->hasField ('Connection')) {
        $Header->setField ('Connection', 'Keep-Alive');
        $Header->setField ('Keep-Alive', 'timeout=' . $this->keepAliveTimeout . ', max=' . ($this->maxRequestCount - $this->RequestCount));
      }
      
      // Write out the header
      $this->Response = $Header;
      
      return parent::httpHeaderWrite ($Header);
    }
    // }}}
    
    // {{{ httpdRequestReady
    /**
     * Internal Callback: HTTP-Request was received
     * 
     * @param qcEvents_Socket_Stream_HTTP_Header $Header
     * @param string $Body
     * 
     * @access protected
     * @return void
     **/
    protected final function httpdRequestReady (qcEvents_Socket_Stream_HTTP_Header $Header, $Body) {
      // Discard the header if it is not a request
      if (!$Header->isRequest ())
        return;
      
      // Enqueue the request
      $this->Requests [] = array ($Header, $Body);
      
      // Dispatch the queue
      $this->httpdDispatchQueue ();
    }
    // }}}
    
    // {{{ httpdDispatchQueue
    /**
     * Check if there are pending requests that want to be processed
     * 
     * @access private
     * @return void
     **/
    private function httpdDispatchQueue () {
      // Check if there are pending requests
      if (count ($this->Requests) == 0)
        return;
      
      // Check if there is an active request
      if ($this->Request !== null)
        return;
      
      // Activate the next request
      $Request = array_shift ($this->Requests);
      $this->Request = $Request [0];
      $this->RequestBody = $Request [1];
      $this->RequestCount++;
      
      unset ($Request);
      
      // Fire callback
      $this->___callback ('httpdRequestReceived', $this->Request, $this->RequestBody);
    }
    // }}}
    
    // {{{ httpdCheckKeepAlive
    /**
     * Check wheter to timeout this connection
     * 
     * @access protected
     * @return void
     **/
    public final function httpdCheckKeepAlive () {
      // Ignore the check if a request is beeing processed
      if ($this->Request)
        return;
      
      // Check if the timeout was reached
      $Duration = time () - $this->getLastEvent ();
      
      if ($Duration >= $this->keepAliveTimeout)
        return $this->disconnect ();
      
      // Requeue the timeout
      $this->addTimeout (max (1, $this->keepAliveTimeout - $Duration), false, array ($this, 'httpdCheckKeepAlive'));
    }
    // }}}
    
    // {{{ timerEvent
    /**
     * Internal Callback: Check for pending requests
     * 
     * @access public
     * @return void
     **/
    public final function timerEvent () {
      return $this->httpdDispatchQueue ();
    }
    // }}}
    
    
    // {{{ httpdRequestReceived
    /**
     * Callback: A HTTP-Request was received
     * 
     * @remark This is only called once, if another request wasn't finished yet new requests are queued
     * 
     * @param qcEvents_Socket_Stream_HTTP_Header $Header
     * @param string $Body
     * 
     * @access protected
     * @return void
     **/
    protected function httpdRequestReceived (qcEvents_Socket_Stream_HTTP_Header $Header, $Body = null) { }
    // }}}
  }

?>
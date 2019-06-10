<?PHP

  /**
   * qcEvents - HTTP-Server Implementation
   * Copyright (C) 2014 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Promise.php');
  require_once ('qcEvents/Stream/HTTP.php');
  require_once ('qcEvents/Stream/HTTP/Request.php');
  
  /**
   * HTTP-Server
   * -----------
   * HTTP-Request Handler (server)
   * 
   * @class qcEvents_Socket_Server_HTTP
   * @package qcEvents
   * @revision 03
   **/
  class qcEvents_Server_HTTP extends qcEvents_Stream_HTTP {
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
    
    /* Timer for keep-alive */
    private $keepAliveTimer = null;
    
    /* Time of last event */
    private $lastEvent = 0;
    
    // {{{ __construct   
    /**
     * Create a new HTTP-Stream   
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      // Set the header-class
      $this->setHeaderClass ('qcEvents_Stream_HTTP_Request');
    }
    // }}}
    
    // {{{ httpdSetResponse
    /**
     * Finish a request within a single transmission
     * 
     * @param qcEvents_Stream_HTTP_Header $Request
     * @param qcEvents_Stream_HTTP_Header $Response
     * @param string $Body (optional)
     * 
     * @access public
     * @return bool
     **/
    public function httpdSetResponse (qcEvents_Stream_HTTP_Header $Request, qcEvents_Stream_HTTP_Header $Response, $Body = null) {
      // Check if the given request matches the current one
      if ($Request !== $this->Request) {
        trigger_error ('Request is not active');
        
        return false;
      }
      
      // Set length of content
      $Response->setField ('Content-Length', strlen ($Body));
      
      // Write out the response
      $this->httpHeaderWrite ($Response);
      
      if (($Source = $this->getPipeSource ()) && ($Source instanceof qcEvents_Interface_Sink))
        $Source->write ($Body);
      
      // Reset the current state
      $this->httpdFinish ();
      
      return true;
    }
    // }}}
    
    // {{{ httpdStartResponse
    /**
     * Start a response for a given request
     * 
     * @param qcEvents_Stream_HTTP_Header $Request
     * @param qcEvents_Stream_HTTP_Header $Response
     * 
     * @access public
     * @return bool
     **/
    public function httpdStartResponse (qcEvents_Stream_HTTP_Header $Request, qcEvents_Stream_HTTP_Header $Response) {
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
     * @param qcEvents_Stream_HTTP_Header $Request
     * @param string $Body
     * 
     * @access public
     * @return bool
     **/
    public function httpdWriteResponse (qcEvents_Stream_HTTP_Header $Request, $Body) {
      // Check if the given request matches the current one
      if (($Request !== $this->Request) || !$this->Response)
        return false;
      
      // Write out the chunk
      if (!(($Source = $this->getPipeSource ()) instanceof qcEvents_Interface_Sink))
        return;
      
      if ($this->Response->getVersion () < 1.1)
        $Source->write ($Body);
      else
        $Source->mwrite (dechex (strlen ($Body)), "\r\n", $Body, "\r\n");
      
      return true;
    }
    // }}}
    
    // {{{ httpdFinishResponse
    /**
     * Finish a given response
     * 
     * @param qcEvents_Stream_HTTP_Header $Request
     * @param qcEvents_Stream_HTTP_Header $Trailer (optional)
     * 
     * @access public
     * @return bool
     **/
    public function httpdFinishResponse (qcEvents_Stream_HTTP_Header $Request, qcEvents_Stream_HTTP_Header $Trailer = null) {
      // Check if the given request matches the current one
      if (($Request !== $this->Request) || !$this->Response)
        return false;
      
      if (!(($Source = $this->getPipeSource ()) instanceof qcEvents_Interface_Sink))
        return;
      
      // Write out last chunk
      $Source->write ("0\r\n");
      
      // Check for tailing header
      if (is_object ($Trailer)) {
        $Header = strval ($Trailer);
        $Source->write (substr ($Header, strpos ($Header, "\r\n") + 2));
      } else
        $Source->write ("\r\n");
      
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
      if ($this->Response &&
          $this->Response->hasField ('Connection') &&
          ($this->Response->getField ('Connection') == 'close') &&
          ($Source = $this->getPipeSource ()))
        $Source->close ();
      
      // Reset ourself
      $this->reset ();
      
      // Process any waiting events
      $this->httpdDispatchQueue ();
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
      $this->lastEvent = time ();
      
      if ($this->keepAliveTimer)
        $this->keepAliveTimer->restart ();
      
      // Reset our parent, too
      parent::reset ();
    }
    // }}}
    
    // {{{ httpHeaderWrite
    /**
     * Write out a HTTP-Header
     * 
     * @param qcEvents_Stream_HTTP_Header $Header
     * 
     * @access protected
     * @return qcEvents_Promise
     **/
    protected function httpHeaderWrite (qcEvents_Stream_HTTP_Header $Header) : qcEvents_Promise {
      // Check if headers have been written already
      if ($this->Response)
        return qcEvents_Promise::reject ('Response-Header was already sent');
      
      // Check for some hard-coded headers
      if (!$Header->hasField ('Server'))
        $Header->setField ('Server', 'quarxConnect.de qcEvents/HTTPd');
      
      if (!$Header->hasField ('Date'))
        $Header->setField ('Date', date ('r'));
      
      // Check wheter to force a close on the connection
      if (($this->RequestCount >= $this->maxRequestCount) || ($Header->getVersion () < 1.1) || (strcasecmp ($this->Request->getField ('Connection'), 'close') == 0))
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
    
    // {{{ httpdHeaderReady
    /**
     * Internal Callback: HTTP-Header was received (body may follow)
     * 
     * @param qcEvents_Stream_HTTP_Header $Header
     * 
     * @access protected
     * @return void
     **/
    protected final function httpdHeaderReady (qcEvents_Stream_HTTP_Header $Header) {
      // Check if the client is expecting an early response
      if (!($Expect = $Header->getField ('Expect')) ||
          !(strcasecmp ($Expect, '100-continue') == 0) || # TODO: Add support for extensions
          !($Source = $this->getPipeSource ()) ||
          !($Source instanceof qcEvents_Interface_Sink))
        return;
      
      # TODO: Check here if the expection was met
      
      // Tell the client to proceed
      $Source->write ('HTTP/' . $Header->getVersion () . ' 100 Continue' . "\r\n\r\n");
    }
    // }}}
    
    // {{{ httpdRequestReady
    /**
     * Internal Callback: HTTP-Request was received
     * 
     * @param qcEvents_Stream_HTTP_Header $Header
     * @param string $Body
     * 
     * @access protected
     * @return void
     **/
    protected final function httpdRequestReady (qcEvents_Stream_HTTP_Header $Header, $Body) {
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
      
      // Stop keep-alive-timer for the moment
      if ($this->keepAliveTimer)
        $this->keepAliveTimer->cancel ();
      
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
    
    // {{{ httpdSetKeepAlive
    /**
     * Setup timer to check the liveness of our connection
     * 
     * @access private
     * @return void
     **/
    private function httpdSetKeepAlive ($Force = false) {
      // Just restart the timer if we already have one
      if ($this->keepAliveTimer) {
        if (!$Force)
          return $this->keepAliveTimer->restart ();
        
        $this->keepAliveTimer->cancel ();
      }
      
      // Make sure we have a source
      if (!(($Source = $this->getPipeSource ()) instanceof qcEvents_Interface_Common))
        return;
      
      // Make sure we have an event-base
      if (!($eventBase = $Source->getEventBase ()))
        return;
      
      // Setup the timer
      $this->keepAliveTimer = $eventBase->addTimeout ($this->keepAliveTimeout);
      $this->keepAliveTimer->then (
        function () use ($Source) {
          $Source->close ();
        }
      );
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function initStreamConsumer (qcEvents_Interface_Stream $Source) : qcEvents_Promise {
      // Setup our parent first
      return parent::initStreamConsumer ($Source)->then (
        function () use ($Source) {
          // Setup keep-alive
          $this->httpdSetKeepAlive (true);
          
          // Remember current time as last action
          $this->lastEvent = time ();
      
          // Register our hooks
          $this->addHook ('httpFinished', array ($this, 'httpdRequestReady'));
          $this->addHook ('httpHeaderReady', array ($this, 'httpdHeaderReady'));
          
          return new qcEvents_Promise_Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ initConsumer
    /**
     * Setup ourself to consume data from a source
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Interface_Consumer $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return callable
     **/
    public function initConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      // Make sure the source is a socket and close the connection if it misses to send the first request
      if (($rc = parent::initConsumer ($Source, $Callback, $Private)) && ($Source instanceof qcEvents_Socket))
        $this->httpdSetKeepAlive (true);
      
      $this->lastEvent = time ();
      
      // Register our hooks
      if ($rc) {
        $this->addHook ('httpFinished', array ($this, 'httpdRequestReady'));
        $this->addHook ('httpHeaderReady', array ($this, 'httpdHeaderReady'));
      }
      
      return $rc;
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return qcEvents_Promise
     **/  
    public function deinitConsumer (qcEvents_Interface_Source $Source) : qcEvents_Promise {
      // Remove our hooks again
      $this->removeHook ('httpFinished', array ($this, 'httpdRequestReady'));
      $this->removeHook ('httpHeaderReady', array ($this, 'httpdHeaderReady'));
      
      // Forward to our parent
      return parent::deinitConsumer ($Source);
    }
    // }}}
    
    
    // {{{ httpdRequestReceived
    /**
     * Callback: A HTTP-Request was received
     * 
     * @remark This is only called once, if another request wasn't finished yet new requests are queued
     * 
     * @param qcEvents_Stream_HTTP_Header $Header
     * @param string $Body
     * 
     * @access protected
     * @return void
     **/
    protected function httpdRequestReceived (qcEvents_Stream_HTTP_Header $Header, $Body = null) { }
    // }}}
  }

?>
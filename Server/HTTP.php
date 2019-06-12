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
    
    // {{{ serveFromFilesystem
    /**
     * Answer this request using a file from filesystem
     * 
     * @param qcEvents_Stream_HTTP_Request $Request Request to serve
     * @param string $Directory Document-Root-Directory to serve the file from
     * @param bool $allowSymlinks (optional) Allow symlinks to files outside the document-root
     * @param qcEvents_Stream_HTTP_Header $Response (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function serveFromFilesystem (qcEvents_Stream_HTTP_Request $Request, $Directory, $allowSymlinks = false, qcEvents_Stream_HTTP_Header $Response = null) : qcEvents_Promise {
      // Make sure we have a response-header
      if (!$Response)
        $Response = new qcEvents_Stream_HTTP_Header (array ('HTTP/1.1 500 Internal server error'));
      
      $Response->setVersion ($Request->getVersion (true));
      
      // Sanatize the Document-Root
      if (($Directory = realpath ($Directory)) === false) {
        $Response->setStatus (500);
        $Response->setMessage ('Internal server error');
        $Response->setField ('Content-Type', 'text/plain');
        
        return $this->httpdSetResponse ($Request, $Response, 'Invalid document-root.' . "\n");
      }
      
      $Directory .= '/';
      
      // Check the requested URI 
      $URI = $Request->getURI ();
      
      if (($p = strpos ($URI, '?')) !== false)
        $URI = substr ($URI, 0, $p);
      
      if ($URI [0] == '/')
        $URI = substr ($URI, 1);
      
      // Remove pseudo-elements from URL
      $Path = array ();
      
      foreach (explode ('/', $URI) as $Segment)
        if ($Segment == '.')
          continue;
        elseif ($Segment == '..')
          array_pop ($Path);
        else
          $Path [] = $Segment;
      
      $Path = implode ('/', $Path);
      
      // Create absolute path from request
      $Path = realpath ($Directory . $Path) . (strlen ($Path) == 0 ? '/' : '');
      
      // Check if the path exists and is valid
      if (($Path === false) || !file_exists ($Path) || (!$allowSymlinks && (substr ($Path, 0, strlen ($Directory)) != $Directory))) {
        $Response->setStatus (404);
        $Response->setMessage ('Not found');
        $Response->setField ('Content-Type', 'text/plain');
        
        return $this->httpdSetResponse ($Request, $Response, 'Not found ' . $Path . "\r\n");
      }
      
      // Handle directory-requests
      if (is_dir ($Path)) {
        // Check if it was requested as directory
        if ((strlen ($URI) > 0) && (substr ($URI, -1, 1) != '/')) {
          $Response->setStatus (302);
          $Response->setMessage ('This is a directory');
          $Response->setField ('Content-Type', 'text/plain');
          $Response->setField ('Location', '/' . $URI . '/');
          
          return $this->httpdSetResponse ($Request, $Response, 'This is a directory');
        } elseif (!is_file ($Path . 'index.html')) {
          $Response->setStatus (403);
          $Response->setMessage ('Forbidden');
          $Response->setField ('Content-Type', 'text/plain');
          
          return $this->httpdSetResponse ($Request, $Response, 'Directory-Listing not supported');
        } else
          $Path .= 'index.html';
      }
      
      // Try to read the file
      require_once ('qcEvents/File.php');
      
      return qcEvents_File::readFileContents (
        qcEvents_Base::singleton (),
        $Path
      )->then (
        function ($Content) use ($Path, $Request, $Response) {
          // Set a proper status
          $Response->setStatus (200);
          $Response->setMessage ('Ok');
          
          // Try to guess content-type
          if (function_exists ('mime_content_type')) {
            // Try mime-magic on the file
            $ContentType = mime_content_type ($Path);
            
            // Catch text/plain to cover some edge-cases
            if ($ContentType == 'text/plain') {
              // Handle some known special cases of text/plain
              switch (strtolower (substr ($Path, strrpos ($Path,'.')))) {
                case '.css':
                  $ContentType = 'text/css'; break;
                case '.js':
                  $ContentType = 'text/javascript'; break;
              }
              
              // Try to detect character-encoding
              if (function_exits ('mb_detect_encoding') &&
                  ($Encoding = mb_detect_encoding ($Content)))
                $ContentType .= '; charset="' . $Encoding . '"';
            }
            
            // Push to response
            $Response->setField ('Content-Type', $ContentType);
          }
          
          return $this->httpdSetResponse ($Request, $Response, $Content);
        },
        function () {
          // Push an error to the response
          $Response->setStatus (403);
          $Response->setMessage ('Forbidden');
          
          // Forward the result
          return $this->httpdSetResponse ($Request, $Response, 'File could not be read');
        }
      );
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
      if ($this->Response && $this->Response->hasField ('Connection')) {
        if ($this->Response->getField ('Connection') == 'close') {
          if ($Source = $this->getPipeSource ())
            $Source->close ();
        } elseif (($Source = $this->getPipeSource ()) instanceof qcEvents_Interface_Timer)
          $Source->addTimer ($this->keepAliveTimeout, false, array ($this, 'httpdCheckKeepAlive'));
      }
      
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
      #$t = debug_backtrace (DEBUG_BACKTRACE_IGNORE_ARGS, 1);
      #echo 'RESET from ', $t [0]['file'], ' ', $t [0]['line'], "\n";
      
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
     * @param qcEvents_Stream_HTTP_Header $Header
     * @access protected
     * @return void
     **/
    protected function httpHeaderWrite (qcEvents_Stream_HTTP_Header $Header) {
      // Check if headers have been written already
      if ($this->Response)
        return false;
      
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
      
      if (!(($Source = $this->getPipeSource ()) instanceof qcEvents_Socket))
        return;
      
      // Check if the timeout was reached
      $Duration = time () - $Source->getLastEvent ();
      
      if ($Duration >= $this->keepAliveTimeout)
        return $Source->close ();
      
      // Requeue the timeout
      $Source->addTimer (max (1, $this->keepAliveTimeout - $Duration), false, array ($this, 'httpdCheckKeepAlive'));
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
        $Source->addTimer ($this->keepAliveTimeout, false, array ($this, 'httpdCheckKeepAlive'));
      
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
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of 
     * 
     *   function (qcEvents_Interface_Consumer $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/  
    public function deinitConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      // Remove our hooks again
      $this->removeHook ('httpFinished', array ($this, 'httpdRequestReady'));
      $this->removeHook ('httpHeaderReady', array ($this, 'httpdHeaderReady'));
      
      // Forward to our parent
      return parent::deinitConsumer ($Source, $Callback, $Private);
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
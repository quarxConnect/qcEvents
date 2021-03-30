<?php

  /**
   * quarxConnect Events - HTTP-Server Implementation
   * Copyright (C) 2014-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);

  namespace quarxConnect\Events\Server;
  use \quarxConnect\Events;
  use \quarxConnect\Events\Stream;
  
  /**
   * HTTP-Server
   * -----------
   * HTTP-Request Handler (server)
   * 
   * @class HTTP
   * @extends Events\Stream\HTTP
   * @package \quarxConnect\Events
   * @revision 03
   **/
  class HTTP extends Stream\HTTP {
    /* Maximum numbers of requests for this connection */
    private $maxRequestCount = 50;
    
    /* Number of processed requests */
    private $RequestCount = 0;
    
    /* Active Request-Headers */
    private $Request = null;
    
    /* Body of active request */
    private $RequestBody = null;
    
    /* Queued requests */
    private $Requests = [ ];
    
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
      $this->setHeaderClass (Stream\HTTP\Request::class);
    }
    // }}}
    
    // {{{ serveFromFilesystem
    /**
     * Answer this request using a file from filesystem
     * 
     * @param Stream\HTTP\Request $Request Request to serve
     * @param string $Directory Document-Root-Directory to serve the file from
     * @param bool $allowSymlinks (optional) Allow symlinks to files outside the document-root
     * @param Stream\HTTP\Header $Response (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function serveFromFilesystem (Stream\HTTP\Request $Request, string $Directory, bool $allowSymlinks = false, Stream\HTTP\Header $Response = null) : Events\Promise {
      // Make sure we have a response-header
      if (!$Response)
        $Response = new Stream\HTTP\Header ([ 'HTTP/1.1 500 Internal server error' ]);
      
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
      $Path = [ ];
      
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
      
      // Try to find an event-base
      $Source = $this->getPipeSource ();
      
      if (!method_exists ($Source, 'getEventBase') ||
          !is_object ($Base = $Source->getEventBase ()))
        $Base = Events\Base::singleton ();
      
      // Try to read the file
      return Events\File::readFileContents ($Base, $Path)->then (
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
              if (function_exists ('mb_detect_encoding') &&
                  ($Encoding = mb_detect_encoding ($Content)))
                $ContentType .= '; charset="' . $Encoding . '"';
            }
            
            // Push to response
            $Response->setField ('Content-Type', $ContentType);
          }
          
          if (!$Response->hasField ('Date'))
            $Response->setField ('Date', date ('r'));
          
          if (!$Response->hasField ('Last-Modified'))
            $Response->setField ('Last-Modified', date ('r', filemtime ($Path)));
          
          if (!$Response->hasField ('Expires'))
            $Response->setField ('Expires', date ('r', time () + 3600));
          
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
    
    // {{{ upgradeToWebsocket
    /**
     * Upgrade a pending request to a websocket-connection
     * 
     * @param Stream\HTTP\Request $Request
     * 
     * @access public
     * @return Events\Promise
     **/
    public function upgradeToWebsocket (Stream\HTTP\Request $Request) : Events\Promise {
      // Make sure the request is valid for a Websocket-Upgrade
      if (!$Request->hasField ('Upgrade'))
        return Events\Promise::reject ('Missing Upgrade-Header');
      
      if ($Request->getField ('Upgrade') != 'websocket')
        return Events\Promise::reject ('Cannot upgrade to websocket');
      
      if (!$Request->hasField ('Connection') ||
          !in_array ('Upgrade', explode (',', str_replace (' ', '', $Request->getField ('Connection')))))
        return Events\Promise::reject ('Missing Upgrade-Token on Connection-Header');
      
      if (!($Nonce = $Request->getField ('Sec-WebSocket-Key')))
        return Events\Promise::reject ('Missing Sec-WebSocket-Key-Header');
      
      if ((int)$Request->getField ('Sec-WebSocket-Version') != 13)
        return Events\Promise::reject ('Invalud WebSocket-Version');
      
      // Preapre response-header
      $Response = new Stream\HTTP\Header ([
        'HTTP/' . $Request->getVersion (true) . ' 101 Switch protocols',
        'Upgrade: websocket',
        'Connection: Upgrade',
        'Sec-WebSocket-Accept: ' . base64_encode (sha1 ($Nonce . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)),
      ]);
      
      // Send out the response
      return $this->httpdSetResponse (
        $Request,
        $Response
      )->then (
        function () {
          // Retrive our source-stream
          $Stream = $this->getPipeSource ();
          
          // Remove the stream as source from ourself
          return $Stream->unpipe ($this)->catch (function () { })->then (
            function () use ($Stream) {
              // Create a new websocket
              $Websocket = new Stream\Websocket (Stream\Websocket::TYPE_SERVER);
              
              // Pipe the stream to the new socket
              return $Stream->pipeStream ($Websocket)->then (
                function () use ($Websocket) {
                  return $Websocket;
                }
              );
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ httpdSetResponse
    /**
     * Finish a request within a single transmission
     * 
     * @param Stream\HTTP\Header $Request
     * @param Stream\HTTP\Header $Response
     * @param string $responseBody (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function httpdSetResponse (Stream\HTTP\Header $Request, Stream\HTTP\Header $Response, string $responseBody = null) : Events\Promise {
      // Check if the given request matches the current one
      if ($Request !== $this->Request)
        return Events\Promise::reject ('Request is not active');
      
      // Set length of content
      $Response->setField ('Content-Length', strlen ($responseBody ?? ''));
      
      // Write out the response
      return $this->httpHeaderWrite ($Response)->then (
        function () use ($responseBody) {
          // Make sure we may write to our source (should never fail)
          if (!is_object ($Source = $this->getPipeSource ()) ||
              !($Source instanceof Events\ABI\Sink))
            throw new \exception ('Source is not writeable');
          
          // Write out the body
          if ($responseBody !== null)
            return $Source->write ($responseBody);
          
          return true;
        }
      )->then (
        function () {
          // Reset the current state
          $this->httpdFinish ();
          
          // Forward success
          return true;
        }
      );
    }
    // }}}
    
    // {{{ httpdStartResponse
    /**
     * Start a response for a given request
     * 
     * @param Stream\HTTP\Header $Request
     * @param Stream\HTTP\Header $Response
     * 
     * @access public
     * @return Events\Promise
     **/
    public function httpdStartResponse (Stream\HTTP\Header $Request, Stream\HTTP\Header $Response) : Events\Promise {
      // Check if the given request matches the current one
      if ($Request !== $this->Request)
        return Events\Promise::reject ('Request is not active');
      
      // Check if headers are already sent
      if ($this->Response)
        return Events\Promise::reject ('Response-Headers already been sent');
      
      // Make sure the response-header is correct for this response
      if ($Response->getVersion () < 1.1) {
        $Response->unsetField ('Transfer-Encoding');
        $Response->setField ('Connection', 'close');
      } else
        $Response->setField ('Transfer-Encoding', 'chunked');
      
      $Response->unsetField ('Content-Length');
      
      // Write out the response  
      return $this->httpHeaderWrite ($Response);
    }
    // }}}
    
    // {{{ httpdWriteResponse
    /**
     * Write futher data for a given request
     * 
     * @param Stream\HTTP\Header $Request
     * @param string $responseBody
     * 
     * @access public
     * @return Events\Promise
     **/
    public function httpdWriteResponse (Stream\HTTP\Header $Request, string $responseBody) : Events\Promise {
      // Check if the given request matches the current one
      if ($Request !== $this->Request)
        return Events\Promise::reject ('Request is not active');
      
      if (!$this->Response)
        return Events\Promise::reject ('Request-Headers have not been sent yet');
      
      // Write out the chunk
      if (!(($Source = $this->getPipeSource ()) instanceof Events\ABI\Sink))
        return Events\Promise::reject ('Source is not writable');
      
      if ($this->Response->getVersion () < 1.1)
        return $Source->write ($responseBody);
      else
        return $Source->write (sprintf ("%x\r\n%s\r\n", strlen ($responseBody), $responseBody));
    }
    // }}}
    
    // {{{ httpdFinishResponse
    /**
     * Finish a given response
     * 
     * @param Stream\HTTP\Header $Request
     * @param Stream\HTTP\Header $Trailer (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function httpdFinishResponse (Stream\HTTP\Header $Request, Stream\HTTP\Header $Trailer = null) : Events\Promise {
      // Check if the given request matches the current one
      if ($Request !== $this->Request)
        return Events\Promise::reject ('Request is not active');
      
      if (!$this->Response)
        return Events\Promise::reject ('Request-Headers have not been sent yet');
      
      if (!(($Source = $this->getPipeSource ()) instanceof Events\ABI\Sink))
        return Events\Promise::reject ('Source is not writable');
      
      // Check for tailing header
      if (is_object ($Trailer)) {
        // Make sure Trailer is a string
        $Trailer = strval ($Trailer);
        
        // Write out last chunk + trailer
        $Promise = $Source->write ("0\r\n" . substr ($Trailer, strpos ($Trailer, "\r\n") + 2));
      
      // Write out last chunk
      } else
        $Promise = $Source->write ("0\r\n\r\n");
      
      // Wait for the write() to finish
      return $Promise->then (
        function () {
          // Reset ourself
          $this->httpdFinish ();
          
          return true;
        }
      );
    }
    // }}}
    
    // {{{ httpdFinish
    /**
     * Finish the active HTTP-Request
     * 
     * @access private
     * @return void
     **/
    private function httpdFinish () : void {
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
    protected function reset () : void {
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
     * @param Stream\HTTP\Header $Header
     * 
     * @access protected
     * @return Events\Promise
     **/
    protected function httpHeaderWrite (Stream\HTTP\Header $Header) : Events\Promise {
      // Check if headers have been written already
      if ($this->Response)
        return Events\Promise::reject ('Response-Header was already sent');
      
      // Check for some hard-coded headers
      if (!$Header->hasField ('Server'))
        $Header->setField ('Server', 'quarxConnect.de qcEvents/HTTPd');
      
      if (!$Header->hasField ('Date'))
        $Header->setField ('Date', date ('r'));
      
      // Check wheter to force a close on the connection
      if (($this->RequestCount >= $this->maxRequestCount) ||
          ($Header->getVersion () < 1.1) ||
          ($this->Request->hasField ('Connection') && (strcasecmp ($this->Request->getField ('Connection'), 'close') == 0)))
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
     * @param Stream\HTTP\Header $Header
     * 
     * @access protected
     * @return void
     **/
    protected final function httpdHeaderReady (Stream\HTTP\Header $Header) : void {
      // Check if the client is expecting an early response
      if (!($Expect = $Header->getField ('Expect')) ||
          !(strcasecmp ($Expect, '100-continue') == 0) || # TODO: Add support for extensions
          !($Source = $this->getPipeSource ()) ||
          !($Source instanceof Events\ABI\Sink))
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
     * @param Stream\HTTP\Header $Header
     * @param string $requestBody (optional)
     * 
     * @access protected
     * @return void
     **/
    protected final function httpdRequestReady (Stream\HTTP\Header $Header, string $requestBody = null) : void {
      // Discard the header if it is not a request
      if (!$Header->isRequest ())
        return;
      
      // Enqueue the request
      $this->Requests [] = [ $Header, $requestBody ];
      
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
    private function httpdDispatchQueue () : void {
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
     * @param bool $Force (optional)
     * 
     * @access private
     * @return void
     **/
    private function httpdSetKeepAlive (bool $Force = false) : void {
      // Just restart the timer if we already have one
      if ($this->keepAliveTimer) {
        if (!$Force) {
          $this->keepAliveTimer->restart ();
          
          return;
        }
        
        $this->keepAliveTimer->cancel ();
      }
      
      // Make sure we have a source with getEventBase() and close()
      if (!(($Source = $this->getPipeSource ()) instanceof Events\ABI\Common))
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
     * @param Events\ABI\Source $Source
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initStreamConsumer (Events\ABI\Stream $Source) : Events\Promise {
      // Setup our parent first
      return parent::initStreamConsumer ($Source)->then (
        function () use ($Source) {
          // Setup keep-alive
          $this->httpdSetKeepAlive (true);
          
          // Remember current time as last action
          $this->lastEvent = time ();
      
          // Register our hooks
          $this->addHook ('httpFinished', [ $this, 'httpdRequestReady' ]);
          $this->addHook ('httpHeaderReady', [ $this, 'httpdHeaderReady' ]);
          
          return new Events\Promise\Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ initConsumer
    /**
     * Setup ourself to consume data from a source
     * 
     * @param Events\ABI\Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (Events\ABI\Consumer $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return callable
     **/
    public function initConsumer (Events\ABI\Source $Source, callable $Callback = null, $Private = null) {
      // Make sure the source is a socket and close the connection if it misses to send the first request
      if (($rc = parent::initConsumer ($Source, $Callback, $Private)) && ($Source instanceof Events\Socket))
        $this->httpdSetKeepAlive (true);
      
      $this->lastEvent = time ();
      
      // Register our hooks
      if ($rc) {
        $this->addHook ('httpFinished', [ $this, 'httpdRequestReady' ]);
        $this->addHook ('httpHeaderReady', [ $this, 'httpdHeaderReady' ]);
      }
      
      return $rc;
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param Events\ABI\Source $Source
     * 
     * @access public
     * @return Events\Promise
     **/  
    public function deinitConsumer (Events\ABI\Source $Source) : Events\Promise {
      // Remove our hooks again
      $this->removeHook ('httpFinished', [ $this, 'httpdRequestReady' ]);
      $this->removeHook ('httpHeaderReady', [ $this, 'httpdHeaderReady' ]);
      
      // Stop our timer
      if ($this->keepAliveTimer)
        $this->keepAliveTimer->cancel ();
      
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
     * @param Stream\HTTP\Header $Header
     * @param string $requestBody
     * 
     * @access protected
     * @return void
     **/
    protected function httpdRequestReceived (Stream\HTTP\Header $Header, string $requestBody = null) : void { }
    // }}}
  }

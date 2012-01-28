<?PHP

  /**
   * qcEvents - HTTP-Server Implementation
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
  
  /**
   * HTTP-Server
   * -----------
   * HTTP-Request Handler (server)
   * 
   * @class qcEvents_Socket_Server_HTTPRequest
   * @package qcEvents
   * @revision 02
   **/
  class qcEvents_Socket_Server_HTTPRequest extends qcEvents_Socket_Stream_HTTP {
    const REQUEST_OK = 200;
    const REQUEST_ERROR = 500;
    const REQUEST_DELAY = 99;
    
    private $Buffer = '';
    
    private $onRequest = false;
    private $responseCode = 200;
    private $responseHeaders = array ();
    private $responseBody = null;
    private $responseSent = false;
    private $headersSent = false;
    
    // Persistent Connection (Keep-Alive) settings
    private $Requests = 0;
    private $maxRequests = 10;
    private $keepAlive = 2;
    private $lastRequest = 0;
    
    // {{{ reset
    /**
     * Reset internal variables
     * 
     * @access protected
     * @return void
     **/
    protected function reset () {
      $this->responseCode = 200;
      $this->responseHeaders = array ();
      $this->responseBody = null;
      $this->responseSent = false;
      $this->headersSent = false;
      $this->onRequest = false;
      
      parent::reset ();
    }
    // }}}
    
    // {{{ connected
    /**
     * Reset upon a new connection-request
     * 
     * @access protected
     * @return void
     **/
    protected function connected () {
      $this->reset ();
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
      // Check if we are processing a request ATM
      if ($this->onRequest) {
        $this->Buffer .= $Data;
        
        return;
      } elseif (strlen ($this->Buffer) > 0) {
        $Data = $this->Buffer . $Data;
        $this->Buffer = '';
      }
      
      // Ask our handler to parse a request
      if (($rc = $this->bufferRequest ($Data)) === false)
        return $this->badRequest ('Malformed request');
      
      // Check if the request is still incomplete
      if ($rc === null)
        return;
      
      $this->onRequest = true;
      
      // Retrive the Request-Object
      if (!is_object ($Request = $this->getRequest ()))
        return $this->internalError ('Could not retrive final request-object');
      
      // Check for a known method
      if ($Request->getMethod () === null)
        return $this->badRequest ('Invalid HTTP-Method used');
      
      // Check if there is a POST without data
      if (($Request->expectPayload () || ($Request->getMethod () == qcEvents_Socket_Stream_HTTP_Request::METHOD_POST)) && !$Request->hasPayload ())
        return $this->badRequest ('Expected payload');
      
      // Increase the request-counter
      $this->Requests++;
      
      // Make sure not to disconnect an active connection
      $this->lastRequest = time () +  $this->keepAlive * 2;
      
      // Inherit to our handler
      if (($this->processRequest ($Request) !== self::REQUEST_DELAY) && !$this->responseSent)
        $this->writeResponse ();
    }
    // }}}
    
    // {{{ getRequestMethod
    /**
     * Retrive the HTTP-Method used for the request
     * 
     * @access public
     * @return enum
     **/
    public function getRequestMethod () {
      if (!is_object ($R = $this->getRequest ()))
        return false;
      
      return $R->getMethod ();
    }
    // }}}
    
    // {{{ getRequestBody
    /**
     * Load the body of the current request
     * 
     * @access public
     * @return string
     **/
    public function getRequestBody () {
      if (!is_object ($R = $this->getRequest ()))
        return false;
      
      return $R->getPayload ();
    }
    // }}}
    
    // {{{ setStatusCode
    /**
     * Set the status-code for the response
     * 
     * @param int $Code
     * 
     * @access public
     * @return void
     **/
    public function setStatusCode ($Code) {
      $this->responseCode = $Code;
    }
    // }}}
    
    // {{{ setHeader
    /**
     * Set a header for our response
     * 
     * @param string $Name
     * @param string $Content
     * 
     * @access public
     * @return void
     **/
    public function setHeader ($Name, $Content) {
      $this->responseHeaders [trim ($Name)] = trim ($Content);
    }
    // }}}
    
    // {{{ setContentType
    /**
     * Set the content-type of the result
     * 
     * @param string $ContentType
     * 
     * @access public
     * @return void
     **/
    public function setContentType ($ContentType) {
      return $this->setHeader ('Content-Type', $ContentType);
    }
    // }}}
    
    // {{{ setResponse
    /**
     * Set Data for the response
     * 
     * @param string $Data
     * @param int $Code (optional)
     * 
     * @access public
     * @return void
     **/
    public function setResponse ($Data, $Code = null) {
      if ($Code !== null)
        $this->setStatusCode ($Code);
      
      $this->responseBody = $Data;
    }
    // }}}
    
    // {{{ writeResponse
    /**
     * Write the whole response out to the client
     * 
     * @access protected
     * @return void
     **/
    protected function writeResponse () {
      if ($this->responseSent)
        return false;
      
      // Mark request as sent
      $this->responseSent = true;
      
      # TODO: Check Last-Modified and Etag against contentNewer()
      
      $Method = null;
      
      if (is_object ($Request = $this->getRequest ()) &&
          (($Method = $Request->getMethod ()) != qcEvents_Socket_Stream_HTTP_Request::METHOD_HEAD) &&
          ($this->responseBody !== null) &&
          (substr ($this->responseBody, -1, 1) != "\n"))
        $this->responseBody .= "\n";
      
      // Output headers
      $this->writeHeaders ();
      
      // Output body
      if (($Method != qcEvents_Socket_Stream_HTTP_Request::METHOD_HEAD) && ($this->responseBody !== null))
        $this->write ($this->responseBody);
      
      if ($this->closeConnection ())
        $this->disconnect ();
      else
        $this->reset ();
      
      if (strlen ($this->Buffer) > 0)
        $this->forceOnNextIteration (self::EVENT_READ);
      
      return true;
    }
    // }}}
    
    // {{{ writeHeaders
    /**
     * Write out our HTTP-Headers to the client
     * 
     * @access protected
     * @return void
     **/
    protected function writeHeaders () {
      // Check if headers have been sent already
      if ($this->headersSent)
        return false;
      
      $this->headersSent = true;
      
      // Write out the status-line
      # TODO: Append Human readable string
      $this->write ((is_object ($Request = $this->getRequest ()) ? $Request->getProtocol () : 'HTTP/0.9') . ' ' . $this->responseCode . "\n");
      
      unset ($this->responseHeaders ['Content-Length']);
      
      // Valid headers:
      // Allow, Authorization, Content-Encoding, Content-Length, Content-Type, Date
      // Expires, From, If-Modified-Since, Last-Modified, Location, Pragma, Referer
      // Server, User-Agent, WWW-Authenticate
      
      if (!isset ($this->responseHeaders ['Server']))
        $this->mwrite ('Server: quarxConnect.de qcEvents/HTTPd', "\n");
      
      if (!isset ($this->responseHeaders ['Date']))
        $this->mwrite ('Date: ', date ('r'), "\n");
      
      if (($l = strlen ($this->responseBody)) > 0)
        $this->mwrite ('Content-Length: ', $l, "\n");
      
      if ($this->closeConnection () || !$this->addTimeout ($this->keepAlive, false, array ($this, 'checkKeepAlive'))) {
        $this->mwrite ('Connection: close', "\n");
        $this->Requests = $this->maxRequests;
      } else {
        $this->mwrite ('Connection: Keep-Alive', "\n");
        $this->mwrite ('Keep-Alive: timeout=', $this->keepAlive, ', max=', ($this->maxRequests - $this->Requests), "\n");
        
        $this->lastRequest = time ();
      }
      
      foreach ($this->responseHeaders as $Name=>$Value)
        $this->mwrite ($Name, ': ', $Value, "\n");
      
      $this->write ("\n");
    }
    // }}}
    
    // {{{ checkKeepAlive
    /**
     * Check wheter to close a persistent connection
     * 
     * @access public
     * @return void
     **/
    public function checkKeepAlive () {
      // Check if the timeout was really reached or requeue
      if (time () - $this->lastRequest < $this->keepAlive)
        return $this->addTimeout ($this->keepAlive - (time () - $this->lastRequest), false, array ($this, 'checkKeepAlive'));
      
      // Close the connection if the timeout was reached
      $this->disconnect ();
    }
    // }}}
    
    // {{{ closeConnection
    /**
     * Check if the connection will be closed
     * 
     * @access protected
     * @return bool
     **/
    protected function closeConnection () {
      // Check if we can not determine the length of our response
      // or if max requests per class are reached
      if (($this->responseBody === null) || ($this->Requests == $this->maxRequests))
        return true;
      
      // Retrive the request-object
      if (!is_object ($Request = $this->getRequest ()))
        return false;
      
      // Check if the client forces us to close the connection
      return (($v = $Request->getHeader ('connection')) && ($v == 'close'));
    }
    // }}}
    
    // {{{ contentNewer
    /**
     * Check if our response is newer than a cached once at client-side
     * 
     * @param int $Date
     * @param string $Etag (optional)
     * 
     * @access protected
     * @return bool
     **/
    protected function contentNewer ($Date, $Etag = null) {
      // Retrive the request-object
      if (!is_object ($Request = $this->getRequest ()))
        return false;
      
      $dateMatch = false;
      $etagMatch = false;
      
      // Check the date
      if ($v = $Request->getHeader ('if-modified-since'))
        $dateMatch = ($Date <= strtotime ($v));
      
      // Check the etag
      if (($Etag !== null) && ($v = $Request->getHeader ('etag')))
        $etagMatch = ($Etag == $v);
      
      return !($dateMatch || $etagMatch);
    }
    // }}}
    
    // {{{ badRequest
    /**
     * Indicate a bad request from a client
     * 
     * @param string $Content (optional)
     * 
     * @access protected
     * @return enum
     **/
    protected function badRequest ($Content = '', $Delayed = false) {
      $this->setResponse ($Content, 400);
      
      if ($Delayed)
        $this->writeResponse ();
      
      return self::REQUEST_ERROR;
    }
    // }}}
    
    // {{{ internalError
    /**
     * Indicate an internal server error
     * 
     * @param string $Content (optional)
     * 
     * @access protected
     * @return enum
     **/
    protected function internalError ($Content = '', $Delayed = false) {
      $this->setResponse ($Content, 500);
      
      if ($this->Delayed)
        $this->writeResponse ();
      
      return self::REQUEST_ERROR;
    }
    // }}}
    
    // {{{ processRequest
    /**
     * Callback: Incoming request is ready
     * 
     * @param object $Request
     * 
     * @access protected
     * @return bool
     **/
    protected function processRequest ($Request) { }
    // }}}
  }

?>
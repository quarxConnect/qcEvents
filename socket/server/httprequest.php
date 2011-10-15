<?PHP

  require_once ('phpEvents/socket.php');
  
  class phpEvents_Socket_Server_HTTPRequest extends phpEvents_Socket {
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_HEAD = 'HEAD';
    const METHOD_OPTIONS= 'OPTIONS';
    
    const REQUEST_OK = 200;
    const REQUEST_ERROR = 500;
    const REQUEST_DELAY = 99;
    
    private $requestMethod = phpEvents_Socket_Server_HTTPRequest::METHOD_GET;
    private $requestURI = '';
    private $requestProtocol = 'HTTP/0.9';
    private $requestHeaders = array ();
    private $requestBody = null;
    
    private $responseCode = 200;
    private $responseHeaders = array ();
    private $responseBody = null;
    private $responseSent = false;
    private $headersSent = false;
    
    private $Buffer = '';
    private $haveRequestLine = false;
    private $expectBody = false;
    private $expectBodyLength = 0;
    
    // {{{ reset
    /**
     * Reset internal variables
     * 
     * @access protected
     * @return void
     **/
    protected function reset () {
      $this->haveRequestLine = false;
      $this->expectBody = false;
      $this->requestHeaders = array ();
      $this->requestBody = null;
      
      $this->responseCode = 200;
      $this->responseHeaders = array ();
      $this->responseBody = null;
      $this->responseSent = false;
      $this->headersSent = false;
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
      // Append Data to our internal buffer
      $this->Buffer .= $Data;
      
      if ($this->haveHeaders && !$this->expectBody && !$this->responseSent)
        return;
      
      // Parse headers
      if (!$this->haveHeaders) {
        while (($p = strpos ($this->Buffer, "\n")) !== false) {
          // Peek a line from buffer
          $Line = trim (substr ($this->Buffer, 0, $p));
          $this->Buffer = substr ($this->Buffer, $p + 1);
          
          // Check for end of headers
          if ($Line == '') {
            $this->haveHeaders = true;
            break;
          }
          
          // Parse request-line
          if (!$this->haveRequestLine) {
            $this->requestMethod = strtoupper ($this->getWord ($Line));
            $this->requestProtocol = $this->getLastWord ($Line);
            $this->requestURI = trim ($Line);
            $this->haveRequestLine = true;
            
            echo 'HTTP-Request STARTED ', $this->requestMethod, ' ', $this->requestURI, ' ', $this->requestProtocol, "\n";
            
          // Parse additional headers
          } else {
            $Header = substr ($this->getWord ($Line), 0, -1);
            $Value = trim ($Line);
            
            $this->requestHeaders [strtolower ($Header)] = $Value;
            
            echo 'HTTP-Request Header ', $Header, ' ', $Value, "\n";
          }
        }
        
        // Abort incomplete request here
        if (!$this->haveHeaders)
          return;
        
        // Prepare for processing
        if (isset ($this->requestHeaders ['content-length'])) {
          $this->expectBody = true;
          $this->expectBodyLength = intval ($this->requestHeaders ['content-length']);
        }
      }
      
      // Check if the body is ready
      if ($this->expectBody) {
        if (strlen ($this->Buffer) < $this->expectBodyLength)
          return;
        
        $this->requestBody = substr ($this->Buffer, 0, $this->expectBodyLength);
        $this->Buffer = ltrim (substr ($this->Buffer, $this->expectBodyLength + 1));
      
      // We have to expect a body on POST-requests, otherwise its a bad request
      } elseif ($this->requestMethod == self::METHOD_POST)
        return $this->badRequest ();
      
      echo 'HTTP-Request DISPATCH', "\n\n";
      
      // Inherit to our handler
      if ($this->processRequest ($this->requestURI) !== self::REQUEST_DELAY)
        $this->writeResponse ();
    }
    // }}}
    
    // {{{ getWord
    /**
     * Retrive a single word and truncate it from input
     * 
     * @param string $Data
     * 
     * @access private
     * @return string
     **/
    private function getWord (&$Data) {
      if (($p = strpos ($Data, ' ')) !== false) {
        $Word = substr ($Data, 0, $p);
        $Data = trim (substr ($Data, $p + 1));
      } else {
        $Word = $Data;
        $Data = '';
      }
      
      return $Word;
    }
    // }}}
    
    // {{{ getLastWord
    /**
     * Retrive the last single word and truncate it from input
     * 
     * @param string $Data
     * 
     * @access private
     * @return string
     **/
    private function getLastWord (&$Data) {
      if (($p = strrpos ($Data, ' ')) !== false) {
        $Word = substr ($Data, $p + 1);
        $Data = substr ($Data, 0, $p);
      } else {
        $Word = $Data;
        $Data = '';
      }
      
      return $Word;
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
      return $this->requestMethod;
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
      return $this->requestBody;
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
      
      // Output headers
      $this->writeHeaders ();
      
      // Output body
      if (($this->requestMethod != self::METHOD_HEAD) && ($this->responseBody !== null))
        $this->write ($this->responseBody);
      
      if ($this->closeConnection ())
        $this->disconnect ();
      else
        $this->reset ();
      
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
      
      echo "\n";
      
      // Write out the status-line
      $this->write ($this->requestProtocol . ' ' . $this->responseCode . "\n");
      
      unset ($this->responseHeaders ['Content-Length']);
      
      // Valid headers:
      // Allow, Authorization, Content-Encoding, Content-Length, Content-Type, Date
      // Expires, From, If-Modified-Since, Last-Modified, Location, Pragma, Referer
      // Server, User-Agent, WWW-Authenticate
      
      if (!isset ($this->responseHeaders ['Server']))
        $this->write ('Server: tiggersWelt.net phpEvents/HTTPd' . "\n");
      
      if (!isset ($this->responseHeaders ['Date']))
        $this->write ('Date: ' . date ('r') . "\n");
      
      if (($l = strlen ($this->responseBody)) > 0)
        $this->write ('Content-Length: ' . $l . "\n");
      
      if ($this->closeConnection ())
        $this->write ('Connection: close' . "\n");
      else
        $this->write ('Connection: Keep-Alive' . "\n");
      
      # Keep-Alive: timeout=2, max=10
      
      foreach ($this->responseHeaders as $Name=>$Value)
        $this->write ($Name . ': ' . $Value . "\n");
      
      $this->write ("\n");
    }
    // }}}
    
    public function write ($x) {
      echo '< ', trim ($x), "\n";
      return parent::write ($x);
    }
    
    // {{{ closeConnection
    /**
     * Check if the connection will be closed
     * 
     * @access protected
     * @return bool
     **/
    protected function closeConnection () {
      return true;
      return (
        // Check if the client forces us to close the connection
        (isset ($this->requestHeaders ['connection']) && ($this->requestHeaders ['connection'] == 'close')) ||
        
        // Check if we can not determine the length of our response
        ($this->responseBody === null)
      );
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
      $dateMatch = false;
      $etagMatch = false;
      
      // Check the date
      if (isset ($this->requestHeaders ['if-modified-since']))
        $dateMatch = ($Date <= strtotime ($this->requestHeaders ['if-modified-since']));
      
      // Check the etag
      if (($Etag !== null) && isset ($this->requestHeaders ['etag']))
        $etagMatch = ($Etag == $this->requestHeaders ['etag']);
      
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
    protected function badRequest ($Content = '') {
      $this->setResponse ($Content, 400);
      $this->writeResponse ();
      
      return self::REQUEST_ERROR;
    }
    // }}}
    
    // {{{ processRequest
    /**
     * Callback: Incoming request is ready
     * 
     * @param string $URI
     * 
     * @access protected
     * @return bool
     **/
    protected function processRequest ($URI) { }
    // }}}
  }

?>
<?PHP

  /**
   * qcEvents - HTTP Client Request
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
  
  class qcEvents_Stream_HTTP_Request extends qcEvents_Stream_HTTP {
    /* Use TLS for this request */
    private $useTLS = false;
    
    /* Authentication-Information */
    private static $preferedAuthenticationMethods = array (
      'Digest',
      'Basic'
    );
    
    private $authenticationMethods = array ();
    
    private $Username = null;
    private $Password = null;
    
    /* Values to upload with this request */
    private $Values = array ();
    
    /* Files to upload with this request */
    private $Files = array ();
    
    /* User-defined body for this request */
    private $Body = null;
    
    /* Maximum number of redirects */
    private $maxRedirects = 20;
    
    // {{{ __construct
    /**
     * Create a new HTTP-Request
     * 
     * @param mixed $Parameter (optional) Initialize the request with this headers or URL
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Parameter = null) {
      // Handle the parameter
      $Header = array ('GET / HTTP/1.0', 'Connection: keep-alive');
      $URL = null;
      
      if (is_array ($Parameter))
        $Header = $Parameter;
      elseif (is_string ($Parameter))
        $URL = $Parameter;
      
      // Setup the header using a dummy
      parent::__construct ($Header);
      
      // Store the requested URL
      if ($URL !== null)
        $this->setURL ($URL);
    }
    // }}}
    
    // {{{ __toString
    /**
     * Convert the header into a string
     * 
     * @access friendly
     * @return string
     **/
    public function __toString () {
      return $this->toString (false);
    }
    // }}}
    
    // {{{ toString
    /**
     * Convert the header into a string
     * 
     * @param bool $Human (optional) Generate human readable output
     * 
     * @access public
     * @return string
     **/
    public function toString ($Human = false) {
      // Make sure that files and values are transfered properly
      $haveFiles = (count ($this->Files) > 0);
      $haveValues = (count ($this->Values) > 0);
      $Body = null;
      
      if ($haveFiles || $haveValues) {
        // Generate a boundary for formdata
        $Boundary = '----qcEvents-' . md5 (time ());
        
        // Always transfer files and values using POST
        $this->setMethod ('POST');
        $this->setField ('Content-Type', 'multipart/form-data; boundary="' . $Boundary . '"');
        
        // Generate body of request
        $Body = '';
        
        foreach ($this->Values as $Name=>$Value)
           $Body .=
             '--' . $Boundary . "\r\n" .
             'Content-Disposition: form-data; name="' . $Name . '"' . "\r\n" .
             ($Value [1] !== null ? 'Content-Type: ' . $Value [1] . "\r\n" : '') .
             'Content-Transfer-Encoding: binary' . "\r\n\r\n" .
             $Value [0] . "\r\n";
        
        foreach ($this->Files as $Name=>$Fileinfo)
          $Body .=
            '--' . $Boundary . "\r\n" .
            'Content-Disposition: form-data; name="' . $Name . '"; filename="' . $Fileinfo [1] . '"' . "\r\n" .
            'Content-Type: ' . $Fileinfo [2] . "\r\n" .
            'Content-Transfer-Encoding: binary' . "\r\n\r\n" .
            ($Human ? '[' . filesize ($Fileinfo [0]) . ' binary octets]' : file_get_contents ($Fileinfo [0])) . "\r\n";
        
        $Body .= '--' . $Boundary . '--' . "\r\n";
        
        // Store length of body
        $this->setField ('Content-Length', strlen ($Body));
        
      // Use a user-defined body
      } elseif ($this->Body !== null) {
        # $this->setMethod ('POST');
        
        $Body =& $this->Body;
      
      // Make sure we are not in POST-Mode if no body is present
      } elseif ($this->getMethod () == 'POST')
        $this->setMethod ('GET');
      
      // Let our parent create the header
      $buf = parent::__toString ();
      
      // Append the body
      if ($Body !== null)
        $buf .= $Body . "\r\n";
      
      return $buf;
    }
    // }}}
    
    // {{{ getHostname
    /**
     * Retrive the hostname for this request
     * 
     * @access public
     * @return string
     **/
    public function getHostname () {
      // Retrive the hostname from headers
      $Host = $this->getField ('Host');
      
      // Check if there is a port
      if (($p = strrpos ($Host, ':')) === false)
        return $Host;
      
      // Truncate the port
      $Host = substr ($Host, 0, $p);
      
      // Check for IPv6
      if (($Host [0] == '[') && ($Host [strlen ($Host) - 1] == ']'))
        $Host = substr ($Host, 1, -1);
      
      return $Host;
    }
    // }}}
    
    // {{{ getPort
    /**
     * Retrive the port for this request
     * 
     * @access public
     * @return int
     **/
    public function getPort () {
      // Check if there is a port given on header
      if (($Host = $this->getField ('Host')) && (($p = strrpos ($Host, ':')) !== false))
        return intval (substr ($Host, $p + 1));
      
      // Return port based on TLS-Status
      return ($this->useTLS () ? 443 : 80);
    }
    // }}}
    
    // {{{ useTLS
    /**
     * Check wheter to use TLS for this request
     * 
     * @access public
     * @return bool
     **/
    public function useTLS () {
      return $this->useTLS;
    }
    // }}}
    
    // {{{ setURL
    /**
     * Set a URL for this request
     * 
     * @param string $URL
     * 
     * @access public
     * @return bool
     **/
    public function setURL ($URL) {
      // Make sure we have a parsed URL
      if (!is_array ($URL) && !($URL = parse_url ($URL)))
        return false;
      
      // Store the TLS-Status
      $this->useTLS = (isset ($URL ['scheme']) && ($URL ['scheme'] == 'https'));
      
      // Forward to our parent
      return parent::setURL ($URL);
    }
    // }}}
    
    // {{{ hasCredentials
    /**
     * Check if this request has credentials assigned
     * 
     * @access public
     * @return bool
     **/
    public function hasCredentials () {
      return (($this->Username !== null) || ($this->Password !== null));
    }
    // }}}
    
    // {{{ setCredentials
    /**
     * Store HTTP-Credentials
     * 
     * @param string $Username
     * @param string $Password
     * 
     * @access public
     * @return bool
     **/
    public function setCredentials ($Username, $Password) {
      // Store the new credentials
      $this->Username = $Username;
      $this->Password = $Password;
      
      // Try to apply credentials to this request
      $this->applyCredentials ();
    }
    // }}}
    
    // {{{ addAuthenticationMethod
    /**
     * Register a server-supported authentication-method
     * 
     * @param string $Method
     * @param array $Parameters (optional)
     * 
     * @access public
     * @return void
     **/
    public function addAuthenticationMethod ($Method, array $Parameters = null) {
      // Register the method
      $this->authenticationMethods [$Method] = $Parameters;
      
      // Try to apply credentials to this request
      $this->applyCredentials ();
    }
    // }}}
    
    // {{{ getUsername
    /**
     * Retrive a username assigned to this request
     * 
     * @access public
     * @return string
     **/
    public function getUsername () {
      return $this->Username;
    }
    // }}}
    
    // {{{ getPassword
    /**
     * Retrive a password assigned to this request
     * 
     * @access public
     * @return string
     **/
    public function getPassword () {
      return $this->Password;
    }
    // }}}
    
    // {{{ applyCredentials
    /**
     * Try to add credentials to this request
     * 
     * @access private
     * @return void
     **/
    private function applyCredentials () {
      // Check wheter to remove an authentication-information
      if (($this->Username === null) && ($this->Password === null))
        return $this->unsetField ('Authorization');
      
      // Try supported authentication-methods in prefered order
      foreach ($this::$preferedAuthenticationMethods as $Method)
        if (array_key_exists ($Method, $this->authenticationMethods))
          switch ($Method) {
            // Digest requires parameters and is therefore processed by ourself
            case 'Digest':
              $P = $this->authenticationMethods ['Digest'];
              
              $A1 = $this->Username . ':' . $P ['realm'] . ':' . $this->Password;
              $A2 = $this->getMethod () . ':' . $this->getURI ();
              $NC = sprintf ('%06d', (isset ($P ['nc']) ? $P ['nc'] : 0) + 1);
              $CNonce = sprintf ('%08x%08x', time (), rand (0, 0xFFFFFFFF));
              
              $R = md5 (
                md5 ($A1) . ':' .
                $P ['nonce'] . ':' .
                $NC . ':' .
                $CNonce . ':' .
                $P ['qop'] . ':' .
                md5 ($A2)
              );
              
              return $this->setField (
                'Authorization',
                'Digest ' .
                  'username="' . $this->Username . '",' .
                  'realm="' . $P ['realm'] . '",' .
                  'uri="' . $this->getURI () . '",' .
                  'algorithm=MD5,' .
                  'nonce="' . $P ['nonce'] . '",' .
                  'nc=' . $NC . ',' .
                  'cnonce="' . $CNonce . '",' .
                  'qop=' . $P ['qop'] . ',' .
                  'response="' . $R . '",' .
                  'opaque="' . $P ['opaque'] . '",' .
                  'userhash=false'
              );
            // Basic-Authentication is processed by our parent
            case 'Basic':
              return parent::setCredentials ($this->Username, $this->Password);
          }
    }
    // }}}
    
    // {{{ hasBody
    /**
     * Check if there is a stored body for this request
     * 
     * @access public
     * @return bool
     **/
    public function hasBody () {
      return ((strlen ($this->Body) > 0) || parent::hasBody ());
    }
    // }}}
    
    // {{{ setBody
    /**
     * Store a body for this request
     * 
     * @param string $Body
     * @param string $Mime (optional)
     * 
     * @access public
     * @return bool
     **/
    public function setBody ($Body, $Mime = null) {
      // Store the body
      $this->Body = $Body;
      
      // Set fields on header
      if ($Body === null) {
        $this->unsetField ('Content-Length');
        $this->unsetField ('Content-Type');
        
        return;
      }
      
      $this->setField ('Content-Length', strlen ($Body));
      
      if ($Mime !== null)
        $this->setField ('Content-Type', $Mime);
      elseif (!$this->hasField ('Content-Type'))
        $this->setField ('Content-Type', 'application/octet-stream');
      
      return true;
    }
    // }}}
    
    // {{{ getMaxRedirects
    /**
     * Retrive the maximum number of redirects
     * 
     * @access public
     * @return int
     **/
    public function getMaxRedirects () {
      return $this->maxRedirects;
    }
    // }}}
    
    // {{{ setMaxRedirects
    /**
     * Set the maximum number of redirects
     * 
     * @param int $Redirects
     * 
     * @access public
     * @return void
     **/
    public function setMaxRedirects ($Redirects) {
      $this->maxRedirects = max (0, (int)$Redirects);
    }
    // }}}
    
    // {{{ attachValue
    /**
     * Attach a simple value to this request
     * 
     * @param string $Name
     * @param string $Value
     * @param string $Mime (optional) MIME-Type to send to the server
     * 
     * @access public
     * @return bool
     **/
    public function attachValue ($Name, $Value, $Mime = null) {
      // Check if there is already a file by this name
      if (isset ($this->Files [$Name]))
        return false;
      
      // Attach the value
      $this->Values [$Name] = array ($Value, $Mime);
      
      return true;
    }
    // }}}
    
    // {{{ attachFile
    /**
     * Attach a file to this request
     * 
     * @param string $Path Path to file
     * @param string $Name (optional) Name of the file
     * @param string $Filename (optional) Filename to send to the server
     * @param string $Mime (optional) MIME-Type to send to the server
     * 
     * @access public
     * @return bool
     **/
    public function attachFile ($Path, $Name = null, $Filename = null, $Mime = null) {
      // Make sure the file exists
      if (!is_file ($Path))
        return false;
      
      // Bail out a warning if there is a body
      if ($this->Body !== null)
        trigger_error ('Uploading a file will override any user-defined body of the request', E_USER_WARNING);
      
      // Retrive the basename of the file
      $Basename = basename ($Path);
      
      // Check which name to use
      if ($Name === null)
        $Name = $Basename;
      
      if ($Filename === null)
        $Filename = $Basename;
      
      // Check if there is a mime-type given
      if ($Mime === null)
        # TODO: Auto-detect?
        $Mime = 'application/octet-stream';
      
      // Enqueue the file
      $this->Files [$Name] = array ($Path, $Filename, $Mime);
      unset ($this->Values [$Name]);
      
      return true;
    }
    // }}}
    
    // {{{ serveFromFilesystem
    /**
     * Answer this request using a file from filesystem
     * 
     * @param qcEvents_Server_HTTP $Server HTTP-Server-Instance that received this request
     * @param string $Directory Document-Root-Directory to serve the file from
     * @param bool $allowSymlinks (optional) Allow symlinks to files outside the document-root
     * @param qcEvents_Stream_HTTP_Header $Response (optional)
     * 
     * @access public
     * @return void
     **/
    public function serveFromFilesystem (qcEvents_Server_HTTP $Server, $Directory, $allowSymlinks = false, qcEvents_Stream_HTTP_Header $Response = null) {
      // Make sure we have a response-header
      if (!$Response)
        $Response = new qcEvents_Stream_HTTP_Header (array ('HTTP/1.1 500 Internal server error'));
      
      $Response->setVersion ($this->getVersion (true));
      
      // Sanatize the Document-Root
      if (($Directory = realpath ($Directory)) === false) {
        $Response->setStatus (500);
        $Response->setMessage ('Internal server error');
        $Response->setField ('Content-Type', 'text/plain');
        
        return $Server->httpdSetResponse ($this, $Response, 'Invalid document-root.' . "\n");
      }
      
      $Directory .= '/';
      
      // Check the requested URI 
      $URI = $this->getURI ();
      
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
        
        return $Server->httpdSetResponse ($this, $Response, 'Not found ' . $Path . "\r\n");
      }
      
      // Handle directory-requests
      if (is_dir ($Path)) {
        // Check if it was requested as directory
        if ((strlen ($URI) > 0) && (substr ($URI, -1, 1) != '/')) {
          $Response->setStatus (302);
          $Response->setMessage ('This is a directory');
          $Response->setField ('Content-Type', 'text/plain');
          $Response->setField ('Location', '/' . $URI . '/');
          
          return $Server->httpdSetResponse ($this, $Response, 'This is a directory');
        } elseif (!is_file ($Path . 'index.html')) {
          $Response->setStatus (403);
          $Response->setMessage ('Forbidden');
          $Response->setField ('Content-Type', 'text/plain');
             
          return $Server->httpdSetResponse ($this, $Response, 'Directory-Listing not supported');
        } else
          $Path .= 'index.html';
      }
      
      // Try to read the file
      require_once ('qcEvents/File.php');
      
      qcEvents_File::readFileContents (qcEvents_Base::singleton (), $Path, function ($Content) use ($Path, $Server, $Response) {
        // Check if the file could be read
        if ($Content !== null) {
          // Set a proper 
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
        
        // Push an error to the response
        } else {
          $Response->setStatus (403);
          $Response->setMessage ('Forbidden');
          
          $Content = 'File could not be read';
        }
        
        // Forward the result
        return $Server->httpdSetResponse ($this, $Response, $Content);
      });
    }
    // }}}
    
    
    // {{{ httpFinished
    /**
     * Internal Callback: Single HTTP-Request/Response was finished
     * 
     * @param qcEvents_Stream_HTTP_Header $Header
     * @param string $Body
     * 
     * @access protected
     * @return void
     **/
    protected final function httpFinished (qcEvents_Stream_HTTP_Header $Header, $Body) {
      // Make sure the header is a response
      if ($Header->getType () != qcEvents_Stream_HTTP_Header::TYPE_RESPONSE)
        return;
     
      // Raise the callback
      $this->___callback ('httpRequestResult', $Header, $Body);
    }
    // }}}
    
    // {{{ httpFailed
    /**
     * Internal Callback: Sinlge HTTP-Request/Response was not finished properly
     * 
     * @param qcEvents_Stream_HTTP_Header $Header (optional)
     * @param string $Body (optional)
     * 
     * @access protected
     * @return void
     **/
    protected final function httpFailed (qcEvents_Stream_HTTP_Header $Header = null, $Body = null) {
      // Make sure the header is a response
      if ($Header && ($Header->getType () != qcEvents_Stream_HTTP_Header::TYPE_RESPONSE))
        return;
      
      // Raise the callback
      $this->___callback ('httpRequestResult', $Header, $Body);
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
      // Inherit to our parent
      if (($rc = parent::initConsumer ($Source, $Callback, $Private)) && ($Source instanceof qcEvents_Socket)) {
        # TODO: This is Sockets-API!
        if (!$Source->isConnected ())
          return $Source->addHook ('socketConnected', function ($Socket) {
            // Write out the request
            $this->httpHeaderWrite ($this);
          }, null, true);
        
        // Write out the request
        $this->httpHeaderWrite ($this);
      }
      
      return $rc;
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
    protected function httpRequestResult (qcEvents_Stream_HTTP_Header $Header = null, $Body = null) { }
    // }}}
  }

?>
<?php

  /**
   * quarxConnect Events - HTTP Client Request
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

  namespace quarxConnect\Events\Stream\HTTP;
  use quarxConnect\Events;
  
  class Request extends Events\Stream\HTTP {
    /* Use TLS for this request */
    private $useTLS = false;
    
    /* Authentication-Information */
    private static $preferedAuthenticationMethods = [
      'Digest',
      'Basic'
    ];
    
    private $authenticationMethods = [ ];
    
    private $authUsername = null;
    private $authPassword = null;
    
    /* Values to upload with this request */
    private $requestValues = [ ];
    
    /* Files to upload with this request */
    private $requestFiles = [ ];
    
    /* User-defined body for this request */
    private $requestBody = null;
    
    /* Maximum number of redirects */
    private $maxRedirects = 20;
    
    // {{{ __construct
    /**
     * Create a new HTTP-Request
     * 
     * @param mixed $requestParameter (optional) Initialize the request with this headers or URL
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($requestParameter = null) {
      // Handle the parameter
      $requestHeader = [ 'GET / HTTP/1.0', 'Connection: keep-alive' ];
      $requestURL = null;
      
      if (is_array ($requestParameter))
        $requestHeader = $requestParameter;
      elseif (is_string ($requestParameter))
        $requestURL = $requestParameter;
      
      // Setup the header using a dummy
      parent::__construct ($requestHeader);
      
      // Store the requested URL
      if ($requestURL !== null)
        $this->setURL ($requestURL);
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
     * @param bool $humanReadable (optional) Generate human readable output
     * 
     * @access public
     * @return string
     **/
    public function toString (bool $humanReadable = false) : string {
      // Make sure that files and values are transfered properly
      $haveFiles = (count ($this->requestFiles) > 0);
      $haveValues = (count ($this->requestValues) > 0);
      $requestBody = null;
      
      if ($haveFiles || $haveValues) {
        // Generate a boundary for formdata
        $bodyBoundary = '----qcEvents-' . md5 (time ());
        
        // Always transfer files and values using POST
        $this->setMethod ('POST');
        $this->setField ('Content-Type', 'multipart/form-data; boundary="' . $bodyBoundary . '"');
        
        // Generate body of request
        $requestBody = '';
        
        foreach ($this->requestValues as $valueName=>$valueData)
           $requestBody .=
             '--' . $bodyBoundary . "\r\n" .
             'Content-Disposition: form-data; name="' . $valueName . '"' . "\r\n" .
             ($valueData [1] !== null ? 'Content-Type: ' . $valueData [1] . "\r\n" : '') .
             'Content-Transfer-Encoding: binary' . "\r\n\r\n" .
             $valueData [0] . "\r\n";
        
        foreach ($this->requestFiles as $fileName=>$fileInfo)
          $requestBody .=
            '--' . $bodyBoundary . "\r\n" .
            'Content-Disposition: form-data; name="' . $fileName . '"; filename="' . $fileInfo [1] . '"' . "\r\n" .
            'Content-Type: ' . $fileInfo [2] . "\r\n" .
            'Content-Transfer-Encoding: binary' . "\r\n\r\n" .
            ($humanReadable ? '[' . filesize ($fileInfo [0]) . ' binary octets]' : file_get_contents ($fileInfo [0])) . "\r\n";
        
        $requestBody .= '--' . $bodyBoundary . '--' . "\r\n";
        
        // Store length of body
        $this->setField ('Content-Length', strlen ($requestBody));
        
      // Use a user-defined body
      } elseif ($this->requestBody !== null) {
        # $this->setMethod ('POST');
        
        $requestBody =& $this->requestBody;
      
      // Make sure we are not in POST-Mode if no body is present
      } elseif ($this->getMethod () == 'POST')
        $this->setMethod ('GET');
      
      // Let our parent create the header
      $httpRequest = parent::__toString ();
      
      // Append the body
      if ($requestBody !== null)
        $httpRequest .= $requestBody . "\r\n";
      
      return $httpRequest;
    }
    // }}}
    
    // {{{ getHostname
    /**
     * Retrive the hostname for this request
     * 
     * @access public
     * @return string
     **/
    public function getHostname () : ?string {
      // Retrive the hostname from headers
      if (($targetHostname = $this->getField ('Host')) === null)
        return $targetHostname;
      
      // Check if there is a port
      if (($p = strrpos ($targetHostname, ':')) === false)
        return $targetHostname;
      
      // Truncate the port
      $targetHostname = substr ($targetHostname, 0, $p);
      
      // Check for IPv6
      if (($targetHostname [0] == '[') && ($targetHostname [strlen ($targetHostname) - 1] == ']'))
        $targetHostname = substr ($targetHostname, 1, -1);
      
      return $targetHostname;
    }
    // }}}
    
    // {{{ getPort
    /**
     * Retrive the port for this request
     * 
     * @access public
     * @return int
     **/
    public function getPort () : int {
      // Check if there is a port given on header
      if (($targetHostname = $this->getField ('Host')) && (($p = strrpos ($targetHostname, ':')) !== false))
        return (int)substr ($targetHostname, $p + 1);
      
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
    public function useTLS () : bool {
      return $this->useTLS;
    }
    // }}}
    
    // {{{ getURL
    /**
     * Retrive the URL from this header (only if it is a request)
     * 
     * @access public
     * @return string
     **/
    public function getURL () : string {
      return 'http' . ($this->useTLS ? 's' : '') . '://' . $this->getField ('Host') . $this->getURI ();
    }
    // }}}
    
    // {{{ setURL
    /**
     * Set a URL for this request
     * 
     * @param string|array $URL
     * 
     * @access public
     * @return void
     **/
    public function setURL ($requestURL) : void {
      // Make sure we have a parsed URL
      if (!is_array ($requestURL) && !($requestURL = parse_url ($requestURL)))
        throw new \Exception ('Malformed URL');
      
      // Store the TLS-Status
      $this->useTLS = (isset ($requestURL ['scheme']) && ($requestURL ['scheme'] == 'https'));
      
      // Forward to our parent
      parent::setURL ($requestURL);
    }
    // }}}
    
    // {{{ hasCredentials
    /**
     * Check if this request has credentials assigned
     * 
     * @access public
     * @return bool
     **/
    public function hasCredentials () : bool {
      return (($this->authUsername !== null) || ($this->authPassword !== null));
    }
    // }}}
    
    // {{{ setCredentials
    /**
     * Store HTTP-Credentials
     * 
     * @param string $authUsername (optional)
     * @param string $authPassword (optional)
     * 
     * @access public
     * @return void
     **/
    public function setCredentials (string $authUsername = null, string $authPassword = null) : void {
      // Store the new credentials
      $this->authUsername = $authUsername;
      $this->authPassword = $authPassword;
      
      // Try to apply credentials to this request
      $this->applyCredentials ();
    }
    // }}}
    
    // {{{ addAuthenticationMethod
    /**
     * Register a server-supported authentication-method
     * 
     * @param string $authMethod
     * @param array $authParameters (optional)
     * 
     * @access public
     * @return void
     **/
    public function addAuthenticationMethod (string $authMethod, array $authParameters = null) : void {
      // Register the method
      $this->authenticationMethods [$authMethod] = $authParameters;
      
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
    public function getUsername () : ?string {
      return $this->authUsername;
    }
    // }}}
    
    // {{{ getPassword
    /**
     * Retrive a password assigned to this request
     * 
     * @access public
     * @return string
     **/
    public function getPassword () : ?string {
      return $this->authPassword;
    }
    // }}}
    
    // {{{ applyCredentials
    /**
     * Try to add credentials to this request
     * 
     * @access private
     * @return void
     **/
    private function applyCredentials () : void {
      // Check wheter to remove an authentication-information
      if (($this->authUsername === null) && ($this->authPassword === null)) {
        $this->unsetField ('Authorization');
        
        return;
      }
      
      // Try supported authentication-methods in prefered order
      foreach ($this::$preferedAuthenticationMethods as $authMethod)
        if (array_key_exists ($authMethod, $this->authenticationMethods))
          switch ($authMethod) {
            // Digest requires parameters and is therefore processed by ourself
            case 'Digest':
              $digestParameters = $this->authenticationMethods ['Digest'];
              
              $nonceCount = sprintf ('%06d', (int)($digestParameters ['nc'] ?? 0) + 1);
              $clientNonce = sprintf ('%08x%08x', time (), rand (0, 0xFFFFFFFF));
              
              $digestResponse = md5 (
                md5 ($this->authUsername . ':' . $digestParameters ['realm'] . ':' . $this->authPassword) . ':' .
                $digestParameters ['nonce'] . ':' .
                $nonceCount . ':' .
                $clientNonce . ':' .
                ($digestParameters ['qop'] ?? '') . ':' .
                md5 ($this->getMethod () . ':' . $this->getURI ())
              );
              
              $this->setField (
                'Authorization',
                'Digest ' .
                  'username="' . $this->authUsername . '",' .
                  'realm="' . $digestParameters ['realm'] . '",' .
                  'uri="' . $this->getURI () . '",' .
                  'algorithm=MD5,' .
                  'nonce="' . $digestParameters ['nonce'] . '",' .
                  'nc=' . $nonceCount . ',' .
                  'cnonce="' . $clientNonce . '",' .
                  'qop=' . ($digestParameters ['qop'] ?? '') . ',' .
                  'response="' . $digestResponse . '",' .
                  'opaque="' . ($digestParameters ['opaque'] ?? '') . '",' .
                  'userhash=false'
              );
              
              return;
            
            // Basic-Authentication is processed by our parent
            case 'Basic':
              parent::setCredentials ($this->authUsername, $this->authPassword);
              
              return;
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
    public function hasBody () : bool {
      return ((strlen ($this->requestBody ?? '') > 0) || parent::hasBody ());
    }
    // }}}
    
    // {{{ setBody
    /**
     * Store a body for this request
     * 
     * @param string $requestBody
     * @param string $mimeType (optional)
     * 
     * @access public
     * @return void
     **/
    public function setBody (string $requestBody = null, string $mimeType = null) : void {
      // Check wheter to remove the body from request
      if ($requestBody === null) {
        $this->unsetField ('Content-Length');
        $this->unsetField ('Content-Type');
        $this->requestBody = null;
        
        return;
      }
      
      // Set the body
      $this->requestBody = $requestBody;
      
      // Set headers
      $this->setField ('Content-Length', strlen ($this->requestBody));
      
      if ($mimeType !== null)
        $this->setField ('Content-Type', $mimeType);
      elseif (!$this->hasField ('Content-Type'))
        $this->setField ('Content-Type', 'application/octet-stream');
    }
    // }}}
    
    // {{{ getMaxRedirects
    /**
     * Retrive the maximum number of redirects
     * 
     * @access public
     * @return int
     **/
    public function getMaxRedirects () : int {
      return $this->maxRedirects;
    }
    // }}}
    
    // {{{ setMaxRedirects
    /**
     * Set the maximum number of redirects
     * 
     * @param int $maxRedirects
     * 
     * @access public
     * @return void
     **/
    public function setMaxRedirects (int $maxRedirects) : void {
      if ($maxRedirects < 0)
        throw new \Exception ('Maximum redirects must not be less than zero');
      
      $this->maxRedirects = $maxRedirects;
    }
    // }}}
    
    // {{{ attachValue
    /**
     * Attach a simple value to this request
     * 
     * @param string $valueName
     * @param string $valueData
     * @param string $mimeType (optional) MIME-Type to send to the server
     * 
     * @access public
     * @return void
     **/
    public function attachValue (string $valeName, string $valueData, string $mimeType = null) : void {
      // Check if there is already a file by this name
      if (isset ($this->requestFiles [$valueName]))
        throw new \Exception ('Name already taken by a file');
      
      // Attach the value
      $this->requestValues [$valueName] = [ $valueData, $mimeType ];
    }
    // }}}
    
    // {{{ attachFile
    /**
     * Attach a file to this request
     * 
     * @param string $sourcePath Path to file
     * @param string $fielTitle (optional) Name of the file
     * @param string $fileName (optional) Filename to send to the server
     * @param string $mimeType (optional) MIME-Type to send to the server
     * 
     * @access public
     * @return void
     **/
    public function attachFile (string $sourcePath, string $fileTitle = null, string $fileName = null, string $mimeType = null) : void {
      // Make sure the file exists
      if (!is_file ($sourcePath))
        throw new \Exception ('File does not exist');
      
      // Bail out a warning if there is a body
      if ($this->requestBody !== null)
        trigger_error ('Uploading a file will override any user-defined body of the request', E_USER_WARNING);
      
      // Retrive the basename of the file
      $originalName = basename ($sourcePath);
      
      // Check which name to use
      if ($fileTitle === null)
        $fileTitle = $originalName;
      
      if ($fileName === null)
        $fileName = $originalName;
      
      // Check if there is a mime-type given
      if ($mimeType === null)
        # TODO: Auto-detect?
        $mimeType = 'application/octet-stream';
      
      // Enqueue the file
      $this->requestFiles [$fileTitle] = [ $sourcePath, $fileName, $mimeType ];
      unset ($this->requestValues [$fileTitle]);
    }
    // }}}
    
    // {{{ httpFinished
    /**
     * Internal Callback: Single HTTP-Request/Response was finished
     * 
     * @param Header $responseHeader
     * @param string $responseBody (optional)
     * 
     * @access protected
     * @return void
     **/
    protected final function httpFinished (Header $responseHeader, string $responseBody = null) : void {
      // Make sure the header is a response
      if ($responseHeader->getType () != Header::TYPE_RESPONSE)
        return;
     
      // Raise the callback
      $this->___callback ('httpRequestResult', $responseHeader, $responseBody);
    }
    // }}}
    
    // {{{ httpFailed
    /**
     * Internal Callback: Sinlge HTTP-Request/Response was not finished properly
     * 
     * @param Header $responseHeader (optional)
     * @param string $responseBody (optional)
     * 
     * @access protected
     * @return void
     **/
    protected final function httpFailed (Header $responseHeader = null, string $responseBody = null) : void {
      // Make sure the header is a response
      if ($responseHeader && ($responseHeader->getType () != Header::TYPE_RESPONSE))
        return;
      
      // Raise the callback
      $this->___callback ('httpRequestResult', $responseHeader, $responseBody);
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param Events\ABI\Source $streamSource
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initStreamConsumer (Events\ABI\Stream $streamSource) : Events\Promise {
      // Inherit to our parent
      return parent::initStreamConsumer ($streamSource)->then (
        function () use ($streamSource) {
          $parentResult = func_get_args ();
          
          // Make sure source-socket is connected
          if (
            ($streamSource instanceof Events\Socket) &&
            !$streamSource->isConnected ()
          )
            return Events\Promise::race ([
              $streamSource->once ('socketConnected')->then (
                function () {
                  // Write out the request
                  $this->httpHeaderWrite ($this);
                }
              ),
              $streamSource->once ('socketDisconnected')->then (
                function () {
                  throw new \Exception ('Source-Socket was disconnected');
                }
              )
            ])->then (
              function () use ($parentResult) {
                return new Events\Promise\Solution ($parentResult);
              }
            );
          
          // Write out the request
          $this->httpHeaderWrite ($this);
          
          return new Events\Promise\Solution ($parentResult);
        }
      );
    }
    // }}}
    
    // {{{ initConsumer
    /**
     * Setup ourself to consume data from a source
     * 
     * @param Events\ABI\Source $dataSource
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initConsumer (Events\ABI\Source $dataSource) : Events\Promise {
      // Inherit to our parent
      return parent::initConsumer ($dataSource)->then (
        function () use ($dataSource) {
          if ($dataSource instanceof Events\Socket) {
            # TODO: This is Sockets-API!
            if (!$dataSource->isConnected ())
              $dataSource->addHook (
                'socketConnected',
                function ($Socket) {
                  // Write out the request
                  $this->httpHeaderWrite ($this);
                },
                true
              );
            else
              // Write out the request
              $this->httpHeaderWrite ($this);
          }
          
          return new Events\Promise\Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ httpRequestResult
    /**
     * Callback: HTTP-Request is finished
     *    
     * @param Header $responseHeader (optional)
     * @param string $responseBody (optional)
     * 
     * @access protected
     * @return void
     **/
    protected function httpRequestResult (Header $responseHeader = null, $responseBody = null) : void { }
    // }}}
  }

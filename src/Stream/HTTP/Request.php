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
    
    private $Username = null;
    private $Password = null;
    
    /* Values to upload with this request */
    private $Values = [ ];
    
    /* Files to upload with this request */
    private $Files = [ ];
    
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
      $Header = [ 'GET / HTTP/1.0', 'Connection: keep-alive' ];
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
      if (($Host = $this->getField ('Host')) === null)
        return $Host;
      
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
    
    // {{{ getURL
    /**
     * Retrive the URL from this header (only if it is a request)
     * 
     * @access public
     * @return string
     **/
    public function getURL () {
      return 'http' . ($this->useTLS ? 's' : '') . '://' . $this->getField ('Host') . $this->getURI ();
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
      return ((strlen ($this->Body ?? '') > 0) || parent::hasBody ());
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
      // Check wheter to remove the body from request
      if ($Body === null) {
        $this->unsetField ('Content-Length');
        $this->unsetField ('Content-Type');
        $this->Body = null;
        
        return true;
      }
      
      // Set the body
      $this->Body = strval ($Body);
      
      // Set headers
      $this->setField ('Content-Length', strlen ($this->Body));
      
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
      $this->Values [$Name] = [ $Value, $Mime ];
      
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
      $this->Files [$Name] = [ $Path, $Filename, $Mime ];
      unset ($this->Values [$Name]);
      
      return true;
    }
    // }}}
    
    
    // {{{ httpFinished
    /**
     * Internal Callback: Single HTTP-Request/Response was finished
     * 
     * @param Header $Header
     * @param string $Body
     * 
     * @access protected
     * @return void
     **/
    protected final function httpFinished (Header $Header, $Body) {
      // Make sure the header is a response
      if ($Header->getType () != Header::TYPE_RESPONSE)
        return;
     
      // Raise the callback
      $this->___callback ('httpRequestResult', $Header, $Body);
    }
    // }}}
    
    // {{{ httpFailed
    /**
     * Internal Callback: Sinlge HTTP-Request/Response was not finished properly
     * 
     * @param Header $Header (optional)
     * @param string $Body (optional)
     * 
     * @access protected
     * @return void
     **/
    protected final function httpFailed (Header $Header = null, $Body = null) {
      // Make sure the header is a response
      if ($Header && ($Header->getType () != Header::TYPE_RESPONSE))
        return;
      
      // Raise the callback
      $this->___callback ('httpRequestResult', $Header, $Body);
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
      // Inherit to our parent
      return parent::initStreamConsumer ($Source)->then (
        function () use ($Source) {
          $parentResult = func_get_args ();
          
          // Make sure source-socket is connected
          if (($Source instanceof Events\Socket) &&
              !$Source->isConnected ())
            return Events\Promise::race ([
              $Source->once ('socketConnected')->then (
                function () {
                  // Write out the request
                  $this->httpHeaderWrite ($this);
                }
              ),
              $Source->once ('socketDisconnected')->then (
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
     * @param Header $Header (optional)
     * @param string $Body (optional)
     * 
     * @access protected
     * @return void
     **/
    protected function httpRequestResult (Header $Header = null, $Body = null) { }
    // }}}
  }

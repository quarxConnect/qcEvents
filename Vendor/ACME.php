<?PHP

  /**
   * qcEvents - ACME Client
   * Copyright (C) 2019 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Promise.php');
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Vendor/ACME/Order.php');
  
  class qcEvents_Vendor_ACME extends qcEvents_Hookable {
    /* Instance of HTTP-pool to use */
    private $httpPool = null;
    
    /* Directory-Entity */
    private $Directiory = null;
    
    /* Key for our service */
    private $Key = null;
    
    /* Prepared JWK-Header */
    private $jwkHeader = null;
    
    /* Prepared JOSE-Header */
    private $joseHeader = null;
    
    /* Next usable replay-nonce */
    private $replayNonce = null;
    
    /* Registration-Status */
    private $registrationStatus = null;
    
    // {{{ base64u
    /**
     * Apply base64-url encoding to a string
     * 
     * @param string $Data
     * 
     * @access public
     * @return string
     **/
    public static function base64u ($Data) {
      return rtrim (str_replace (array ('+', '/'), array ('-', '_'), base64_encode ($Data)), '=');
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create new ACME-Client
     * 
     * @param qcEvents_Client_HTTP $httpPool
     * @param string $directoryURL
     * @param mixed $Key
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Client_HTTP $httpPool, $directoryURL, $Key = null) {
      // Prepare the key
      if ($Key === null) {
        if (function_exists ('posix_getpwuid')) {
          // Retrive info about current user
          $pw = posix_getpwuid (posix_geteuid ());
          $path = $pw ['dir'] . '/.qcEvents';
          
          // Make sure our path exists
          if (is_dir ($path) || mkdir ($path, 0700)) {
            $Key = $path . '/acme-' . md5 ($directoryURL) . '.key';
            
            if (is_file ($Key))
              $Key = 'file://' . $Key;
            else
              $Key = null;
          } else
            $path = null;
        } else
          $path = null;
        
        // Check wheter to create a new privatekey
        if ($Key === null) {
          $Key = openssl_pkey_new (array (
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
          ));
          
          // Try to store on disk
          if ($path !== null)
            openssl_pkey_export_to_file ($Key, $path . '/acme-' . md5 ($directoryURL) . '.key');
        }
        
        unset ($path);
      }
      
      if (!is_resource ($Key) &&
          !is_resource ($Key = openssl_pkey_get_private ($Key)))
        throw new Exception ('Invalid Key');
      
      if (!is_array ($KeyDetails = openssl_pkey_get_details ($Key)))
        throw new Exception ('Could not fetch Key-Details');

      $this->Key = $Key;
      
      // Assign variables
      $this->httpPool = $httpPool;
      $this->Key = $Key;
      
      // Prepare headers
      $this->jwkHeader = array (
        'kty' => 'RSA',
        'e' => self::base64u ($KeyDetails ['rsa']['e']),
        'n' => self::base64u ($KeyDetails ['rsa']['n']),
      );
      
      $this->joseHeader = array (
        'alg' => 'RS256',
        'jwk' => $this->jwkHeader,
      );
      
      // Request the directory
      $this->Directory = $directoryURL;
      
      $this->getDirectory ();
    }
    // }}}
    
    // {{{ getThumbprint
    /**
     * Retrive the thumbprint of this ACME-Client
     * 
     * @access public
     * @return string
     **/
    public function getThumbprint () {
      ksort ($this->jwkHeader);
      
      return self::base64u (hash ('sha256', json_encode ($this->jwkHeader), true));
    }
    // }}}
    
    // {{{ getKeyAuthorization
    /**
     * Derive a key-authorization from a given token
     * 
     * @param string $Token
     *
     * @access public
     * @return string
     **/
    public function getKeyAuthorization ($Token) {
      return self::base64u (hash ('sha256', $Token . '.' . $this->getThumbprint (), true));
    }
    // }}}
    
    // {{{ getDirectory
    /**
     * Retrive the service-directory
     * 
     * @access public
     * @return qcEvents_Promise Resolved to an associative array of service-urls
     **/
    public function getDirectory () : qcEvents_Promise {
      // Check if we are still requesting the directory
      if ($this->Directory instanceof qcEvents_Promise)
        return $this->Directory;
      
      // Check if the directory is ready
      if (is_object ($this->Directory))
        return qcEvents_Promise::resolve ($this->Directory);
      
      // Request the directory again
      $URL = $this->Directory;
      
      return $this->Directory = $this->request ($URL, false)->then (
        function ($Directory) {
          // Store the directory
          $this->Directory = $Directory;
          
          // Forward the directory
          return $Directory;
        },
        function ($Error) use ($URL) {
          // Bail out an error
          trigger_error ('Failed to fetch directory: ' . $Error);
          
          // Reset directory
          $this->Directory = $URL;
          
          // Push the error forward
          throw new qcEvents_Promise_Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ getNonce
    /**
     * Retrive a nonce for the next request
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getNonce () : qcEvents_Promise {
      // Check wheter to use a cached nonce
      if ($this->replayNonce !== null) {
        $Nonce = $this->replayNonce;
        $this->replayNonce = null;
        
        return qcEvents_Promise::resolve ($Nonce);
      }
      
      // Request a new nonce from service
      return $this->getDirectory ()->then (
        function ($Directory) {
          // Make sure there is a newNonce-URL on directory
          if (!isset ($Directory->newNonce))
            throw new exception ('Missing URL for newNonce on directory');
          
          // Request a new nonce
          return $this->request ($Directory->newNonce, false, null, 'HEAD')->then (
            function () {
              // Make sure we found a new nonce
              if ($this->replayNonce === null)
                throw new exception ('Failed to get a new nonce');
              
              // Return the result
              $Nonce = $this->replayNonce;
              $this->replayNonce = null;
              
              return $Nonce;
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ checkRegistration
    /**
     * Check registration-status of our key
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function checkRegistration () : qcEvents_Promise {
      // Check for cached registration-status
      if ($this->registrationStatus === true)
        return qcEvents_Promise::resolve (true);
      elseif ($this->registrationStatus === false)
        return qcEvents_Promise::reject ('Not registered');
      
      // Retrive directory first
      return $this->getDirectory ()->then (
        function ($Directory) {
          // Make sure there is a newAccount-URL on directory
          if (!isset ($Directory->newAccount))
            throw new exception ('Missing URL for newAccount on directory');
          
          return $this->request ($Directory->newAccount, true, array ('onlyReturnExisting' => true), null, false)->then (
            function ($Response, qcEvents_Stream_HTTP_Header $Header) {
              // Check if the registration is valid
              if (!isset ($Response->status)) {
                $this->registrationStatus = null;
                
                throw new exception ('Registration-Status not found');
              }
              
              if ($Response->status != 'valid') {
                if (isset ($Response->type) && ($Response->type == 'urn:ietf:params:acme:error:accountDoesNotExist'))
                  $this->registrationStatus = false;
                else
                  $this->registrationStatus = null;
                
                throw new exception ('Registration not valid');
              }
              
              // Check for an account-url
              if ($Header->hasField ('Location') && ($URL = $Header->getField ('Location'))) {
                $this->joseHeader ['kid'] = $URL;
                unset ($this->joseHeader ['jwk']);
              } else {
                $this->joseHeader ['jwk'] = $this->jwkHeader;
                unset ($this->joseHeader ['kid']);
              }
              
              // Forward a positive state
              $this->registrationStatus = true;
              
              return true;
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ register
    /**
     * Try to register our key at the provider
     * 
     * @param array $Contacts (optional)
     * @param bool $acceptTOS (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function register (array $Contacts = null, $acceptTOS = false) : qcEvents_Promise {
      return $this->getDirectory ()->then (
        function ($Directory) use ($Contacts, $acceptTOS) {
          // Make sure there is a newAccount-URL on directory
          if (!isset ($Directory->newAccount))
            throw new exception ('Missing URL for newAccount on directory');
          
          // Prepare parameters
          $Params = array ();
          
          if ($Contacts !== null)
            $Params ['contact'] = $Contacts;
          
          if (isset ($Directory->meta->termsOfService)) {
            $Params ['termsOfServiceAgreed'] = ($acceptTOS === true);
            
            if ($acceptTOS !== true)
              trigger_error ('Service signals terms-of-service, but agreement was not given', E_USER_WARNING);
          }
          
          // Do the request
          return $this->request ($Directory->newAccount, true, $Params)->then (
            function ($Response, qcEvents_Stream_HTTP_Header $Header) {
              // Check status
              if (isset ($Response->status) && ($Response->status != 'valid'))
                throw new exception ('Registration-Status is not valid');
              
              // Check for an account-url
              if ($Header->hasField ('Location') && ($URL = $Header->getField ('Location'))) {
                $this->joseHeader ['kid'] = $URL;
                unset ($this->joseHeader ['jwk']);
              } else {
                $this->joseHeader ['jwk'] = $this->jwkHeader;
                unset ($this->joseHeader ['kid']);
              }
              
              // Just forward a positive value
              $this->registrationStatus = true;
              
              return true;
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ unregister
    /**
     * Deactivate this account at the service
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function unregister () : qcEvents_Promise {
      // Check if we already know our account-url
      if (isset ($this->joseHeader ['kid']))
        return $this->request ($this->joseHeader ['kid'], true, array ('status' => 'deactivated'))->then (
          function () {
            $this->registrationStatus = false;
            
            return true;
          }
        );
      
      // Try to detect our accont-url
      return $this->checkRegistration ()->then (
        function () {
          // Make sure something was detected
          if (!isset ($this->joseHeader ['kid']))
            throw new exception ('Failed to detect account-url');
          
          // Try to deactivate account
          return $this->request ($this->joseHeader ['kid'], true, array ('status' => 'deactivated'))->then (
            function () {
              $this->registrationStatus = false;
              
              return true;
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ createOrder
    /**
     * Create a new ACME-Order
     * 
     * @param array $dnsNames
     * @param int $validFrom (optional)
     * @param int $validUntil (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function createOrder (array $dnsNames, $validFrom = null, $validUntil = null) : qcEvents_Promise {
      return $this->checkRegistration ()->then (
        function () {
          return $this->getDirectory ();
        }
      )->then (
        function ($Directory) use ($dnsNames, $validFrom, $validUntil) {
          // Make sure there is a newOrder-URL on directory
          if (!isset ($Directory->newOrder))
            throw new exception ('Missing URL for newOrder on directory');
          
          // Prepare dns-names
          foreach ($dnsNames as $i=>$dnsName)
            $dnsNames [$i] = array (
              'type' => 'dns',
              'value' => (string)$dnsName
            );
          
          // Prepare parameters for the request
          $Params = array (
            'identifiers' => $dnsNames,
          );
          
          if ($validFrom !== null)
            $Params ['notBefore'] = date ('c', $validFrom);
          
          if ($validUntil !== null)
            $Params ['notAfter'] = date ('c', $validUntil);
          
          // Issue the request
          return $this->request ($Directory->newOrder, true, $Params)->then (
            function ($Response, qcEvents_Stream_HTTP_Header $Header) {
              // Check for a location
              if (!$Header->hasField ('Location'))
                throw new exception ('Missing Location on response');
              
              // Create an order from the response
              return qcEvents_Vendor_ACME_Order::fromJSON ($this, $Header->getField ('Location'), $Response);
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ getOrder
    /**
     * Retrive an existing order by its URI
     * 
     * @param string $URI
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getOrder ($URI) : qcEvents_Promise {
      return $this->checkRegistration ()->then (
        function () use ($URI) {
          return $this->request ($URI, false);
        }
      )->then (
        function ($Response) use ($URI) {
          // Create an order from the response
          return qcEvents_Vendor_ACME_Order::fromJSON ($this, $URI, $Response);
        }
      );
    }
    // }}}
    
    // {{{ request
    /**
     * Perform an ACME-Request
     * 
     * @param string $URL
     * @param bool $Sign (optional)
     * @param mixed $Payload (optional)
     * @param string $Method (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function request ($URL, $Sign = true, $Payload = null, $Method = null, $rejectError = true) : qcEvents_Promise {
      // Prepare request-method
      if ($Method === null)
        $Method = (($Payload === null) && !$Sign ? 'GET' : 'POST');
      
      // Prepare the request
      $Headers = array (
        'User-Agent' => 'qcEvents-ACME/0.1',
      );
      
      $Request = function ($resolve, $reject) use ($URL, $Method, $rejectError, &$Headers, &$Payload) {
        $this->httpPool->addNewRequest (
          $URL,
          $Method,
          $Headers,
          $Payload,
          function (qcEvents_Client_HTTP $httpPool, qcEvents_Stream_HTTP_Request $Request, qcEvents_Stream_HTTP_Header $Header = null, $Body = null) use ($rejectError, $resolve, $reject) {
            // Check for errors on the response
            if (!$Header)
              return $reject ('No header received');
            
            // Look for a nonce
            if ($Header->hasField ('Replay-Nonce'))
              $this->replayNonce = $Header->getField ('Replay-Nonce');
            
            // Check for an error-response
            if ($rejectError && $Header->isError ())
              return $reject ('Errornous response received', $Header, $Body);
            
            // Forward the result
            if (($Header->getField ('Content-Type') == 'application/json') ||
                ($Header->getField ('Content-Type') == 'application/problem+json'))
              return $resolve (json_decode ($Body), $Header);
            
            return $resolve ($Body, $Header);
          }
        );
      };
      
      // Check wheter to sign payload
      if (!$Sign)
        return new qcEvents_Promise ($Request);
      
      return $this->getNonce ()->then (
        function ($Nonce) use ($URL, $Request, &$Headers, &$Payload) {
          // Create header
          $header = $this->joseHeader;
          $header ['nonce'] = $Nonce;
          $header ['url'] = $URL;
          
          $payload = array (
            'protected' => self::base64u (json_encode ($header)),
          );
          
          // Create Payload
          $payload ['payload'] = ($Payload === false ? '' : self::base64u (json_encode ($Payload === null ? new stdClass : $Payload)));
          
          // Create signature
          if (openssl_sign ($payload ['protected'] . '.' . $payload ['payload'], $signature, $this->Key, OPENSSL_ALGO_SHA256) === false)
            throw new exception ('Failed to create a signature');
           
          // Setup payload
          $Headers ['Content-Type'] = 'application/jose+json';
          
          $payload ['signature'] = self::base64u ($signature);
          $Payload = json_encode ($payload);
          
          // Run the request
          return new qcEvents_Promise ($Request);
        }
      );
    }
    // }}}
  }

?>
<?php

  /**
   * qcEvents - ACME Client
   * Copyright (C) 2019-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);
  
  namespace quarxConnect\Events\Vendor;
  use \quarxConnect\Events;
  
  class ACME extends Events\Hookable {
    /* Instance of HTTP-pool to use */
    private $httpPool = null;
    
    /* Directory-Entity */
    private $serviceDirectory = null;
    
    /* Key for our service */
    private $clientKey = null;
    
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
     * @param string $inputData
     * 
     * @access public
     * @return string
     **/
    public static function base64u (string $inputData) : string {
      return rtrim (str_replace ([ '+', '/' ], [ '-', '_' ], base64_encode ($inputData)), '=');
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create new ACME-Client
     * 
     * @param Events\Base $eventBase
     * @param string $directoryURL
     * @param mixed $clientKey (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Events\Base $eventBase, string $directoryURL, $clientKey = null) {
      // Prepare the client-key
      if ($clientKey === null) {
        // Construct path to our key
        if ($dataPath = $eventBase->getDataPath ()) {
          $clientKey = $dataPath . '/acme-' . md5 ($directoryURL) . '.key';
          
          if (is_file ($clientKey))
            $clientKey = 'file://' . $clientKey;
          else
            $clientKey = null;
        }
        
        // Check wheter to create a new privatekey
        if ($clientKey === null) {
          $clientKey = openssl_pkey_new ([
            'private_key_type' => \OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
          ]);
          
          // Try to store on disk
          if ($dataPath !== null)
            openssl_pkey_export_to_file ($clientKey, $dataPath . '/acme-' . md5 ($directoryURL) . '.key');
        }
        
        unset ($dataPath);
      }
      
      if (
        !is_resource ($clientKey) &&
        !is_resource ($clientKey = openssl_pkey_get_private ($clientKey))
      )
        throw new \Exception ('Invalid Key');
      
      if (!is_array ($keyDetails = openssl_pkey_get_details ($clientKey)))
        throw new \Exception ('Could not fetch Key-Details');
      
      if (!isset ($keyDetails ['rsa']))
        throw new \Exception ('Only RSA-Keys are supported');
      
      $this->clientKey = $clientKey;
      
      // Assign variables
      $this->httpPool = new Events\Client\HTTP ($eventBase);
      $this->httpPool->setMaxRequests (1);
      
      // Prepare headers
      $this->jwkHeader = [
        'kty' => 'RSA',
        'e' => self::base64u ($keyDetails ['rsa']['e']),
        'n' => self::base64u ($keyDetails ['rsa']['n']),
      ];
      
      $this->joseHeader = [
        'alg' => 'RS256',
        'jwk' => $this->jwkHeader,
      ];
      
      // Request the directory
      $this->serviceDirectory = $directoryURL;
      
      $this->getDirectory ();
    }
    // }}}
    
    // {{{ getEventBase
    /**
     * Retrive the handle of the current event-loop-handler
     * 
     * @access public
     * @return Events\Base
     **/
    public function getEventBase () : ?Events\Base {
      return $this->httpPool->getEventBase ();
    }
    // }}}
    
    // {{{ getThumbprint
    /**
     * Retrive the thumbprint of this ACME-Client
     * 
     * @access public
     * @return string
     **/
    public function getThumbprint () : string {
      ksort ($this->jwkHeader);
      
      return self::base64u (hash ('sha256', json_encode ($this->jwkHeader), true));
    }
    // }}}
    
    // {{{ getKeyAuthorization
    /**
     * Derive a key-authorization from a given token
     * 
     * @param string $authToken
     *
     * @access public
     * @return string
     **/
    public function getKeyAuthorization (string $authToken) : string {
      return self::base64u (hash ('sha256', $authToken . '.' . $this->getThumbprint (), true));
    }
    // }}}
    
    // {{{ getDirectory
    /**
     * Retrive the service-directory
     * 
     * @access public
     * @return Events\Promise Resolves to an associative array of service-urls
     **/
    public function getDirectory () : Events\Promise {
      // Check if we are still requesting the directory
      if ($this->serviceDirectory instanceof Events\Promise)
        return $this->serviceDirectory;
      
      // Check if the directory is ready
      if (is_object ($this->serviceDirectory))
        return Events\Promise::resolve ($this->serviceDirectory);
      
      // Request the directory again
      $URL = $this->serviceDirectory;
      
      return $this->serviceDirectory = $this->request ($URL, false)->then (
        function ($serviceDirectory) {
          // Store the directory
          $this->serviceDirectory = $serviceDirectory;
          
          // Forward the directory
          return $serviceDirectory;
        },
        function (\Throwable $requestError) use ($URL) {
          // Bail out an error
          trigger_error ('Failed to fetch directory: ' . $requestError->getMessage ());
          
          // Reset directory
          $this->serviceDirectory = $URL;
          
          // Push the error forward
          throw new Events\Promise\Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ getNonce
    /**
     * Retrive a nonce for the next request
     * 
     * @access public
     * @return Events\Promise
     **/
    public function getNonce () : Events\Promise {
      // Check wheter to use a cached nonce
      if ($this->replayNonce !== null) {
        $storedNonce = $this->replayNonce;
        $this->replayNonce = null;
        
        return Events\Promise::resolve ($storedNonce);
      }
      
      // Request a new nonce from service
      return $this->getDirectory ()->then (
        function ($serviceDirectory) {
          // Make sure there is a newNonce-URL on directory
          if (!isset ($serviceDirectory->newNonce))
            throw new \Exception ('Missing URL for newNonce on directory');
          
          // Request a new nonce
          return $this->request ($Directory->newNonce, false, null, 'HEAD')->then (
            function () {
              // Make sure we found a new nonce
              if ($this->replayNonce === null)
                throw new \Exception ('Failed to get a new nonce');
              
              // Return the result
              $storedNonce = $this->replayNonce;
              $this->replayNonce = null;
              
              return $storedNonce;
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
     * @return Events\Promise
     **/
    public function checkRegistration () : Events\Promise {
      // Check for cached registration-status
      if ($this->registrationStatus === true)
        return Events\Promise::resolve (true);
      elseif ($this->registrationStatus === false)
        return Events\Promise::reject ('Not registered');
      elseif ($this->registrationStatus !== null)
        return $this->registrationStatus;
      
      // Retrive directory first
      return $this->registrationStatus = $this->getDirectory ()->then (
        function ($serviceDirectory) {
          // Make sure there is a newAccount-URL on directory
          if (!isset ($serviceDirectory->newAccount))
            throw new \Exception ('Missing URL for newAccount on directory');
          
          return $this->request ($serviceDirectory->newAccount, true, [ 'onlyReturnExisting' => true ], null, false)->then (
            function ($responseBody, Events\Stream\HTTP\Header $responseHeader) {
              // Check if the registration is valid
              if (!isset ($responseBody->status)) {
                $this->registrationStatus = null;
                
                throw new \Exception ('Registration-Status not found');
              }
              
              if ($responseBody->status != 'valid') {
                if (
                  isset ($responseBody->type) &&
                  ($responseBody->type == 'urn:ietf:params:acme:error:accountDoesNotExist')
                )
                  $this->registrationStatus = false;
                else
                  $this->registrationStatus = null;
                
                throw new \Exception ('Registration not valid');
              }
              
              // Check for an account-url
              if (
                $responseHeader->hasField ('Location') &&
                ($URL = $responseHeader->getField ('Location'))
              ) {
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
     * @param array $clientContacts (optional)
     * @param bool $acceptTOS (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function register (array $clientContacts = null, bool $acceptTOS = false) : Events\Promise {
      return $this->getDirectory ()->then (
        function ($serviceDirectory) use ($clientContacts, $acceptTOS) {
          // Make sure there is a newAccount-URL on directory
          if (!isset ($serviceDirectory->newAccount))
            throw new \Exception ('Missing URL for newAccount on directory');
          
          // Prepare parameters
          $registrationParams = [ ];
          
          if ($clientContacts !== null)
            $registrationParams ['contact'] = $clientContacts;
          
          if (isset ($serviceDirectory->meta->termsOfService)) {
            $registrationParams ['termsOfServiceAgreed'] = ($acceptTOS === true);
            
            if ($acceptTOS !== true)
              trigger_error ('Service signals terms-of-service, but agreement was not given', E_USER_WARNING);
          }
          
          // Do the request
          return $this->request ($serviceDirectory->newAccount, true, $registrationParams)->then (
            function ($responseBody, Events\Stream\HTTP\Header $responseHeader) {
              // Check status
              if (
                isset ($responseBody->status) &&
                ($responseBody->status != 'valid')
              )
                throw new \Exception ('Registration-Status is not valid');
              
              // Check for an account-url
              if (
                $responseHeader->hasField ('Location') &&
                ($URL = $responseHeader->getField ('Location'))
              ) {
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
     * @return Events\Promise
     **/
    public function unregister () : Events\Promise {
      // Check if we already know our account-url
      if (isset ($this->joseHeader ['kid']))
        return $this->request ($this->joseHeader ['kid'], true, [ 'status' => 'deactivated' ])->then (
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
            throw new \Exception ('Failed to detect account-url');
          
          // Try to deactivate account
          return $this->request ($this->joseHeader ['kid'], true, [ 'status' => 'deactivated' ])->then (
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
     * @return Events\Promise
     **/
    public function createOrder (array $dnsNames, int $validFrom = null, int $validUntil = null) : Events\Promise {
      return $this->checkRegistration ()->then (
        function () {
          return $this->getDirectory ();
        }
      )->then (
        function ($serviceDirectory) use ($dnsNames, $validFrom, $validUntil) {
          // Make sure there is a newOrder-URL on directory
          if (!isset ($serviceDirectory->newOrder))
            throw new \Exception ('Missing URL for newOrder on directory');
          
          // Prepare dns-names
          foreach ($dnsNames as $i=>$dnsName)
            $dnsNames [$i] = [
              'type' => 'dns',
              'value' => (string)$dnsName
            ];
          
          // Prepare parameters for the request
          $orderParams = [
            'identifiers' => $dnsNames,
          ];
          
          if ($validFrom !== null)
            $orderParams ['notBefore'] = date ('c', $validFrom);
          
          if ($validUntil !== null)
            $orderParams ['notAfter'] = date ('c', $validUntil);
          
          // Issue the request
          return $this->request ($serviceDirectory->newOrder, true, $orderParams)->then (
            function ($responseBody, Events\Stream\HTTP\Header $responseHeader) {
              // Check for a location
              if (!$responseHeader->hasField ('Location'))
                throw new \Exception ('Missing Location on response');
              
              // Create an order from the response
              return ACME\Order::fromJSON ($this, $responseHeader->getField ('Location'), $responseBody);
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
     * @return Events\Promise
     **/
    public function getOrder (string $URI) : Events\Promise {
      return $this->checkRegistration ()->then (
        function () use ($URI) {
          $acmeOrder = new ACME\Order ($this, $URI);
          
          return $acmeOrder->fetch ();
        }
      );
    }
    // }}}
    
    // {{{ request
    /**
     * Perform an ACME-Request
     * 
     * @param string $URL
     * @param bool $signRequest (optional)
     * @param mixed $requestPayload (optional)
     * @param string $requestMethod (optional)
     * @param bool $rejectError (optional) Create a rejection on error (default)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function request (string $URL, bool $signRequest = true, $requestPayload = null, string $requestMethod = null, bool $rejectError = true) : Events\Promise {
      // Prepare request-method
      if ($requestMethod === null)
        $requestMethod = (($requestPayload === null) && !$signRequest ? 'GET' : 'POST');
      
      // Prepare the request
      $requestHeaders = [
        'User-Agent' => 'qcEvents-ACME/0.1',
      ];
      
      if ($signRequest)
        $requestPromise = $this->getNonce ()->then (
          function ($acmeNonce) use ($URL, $requestMethod, $requestHeaders, $requestPayload) {
            // Create header
            $signedHeader = $this->joseHeader;
            $signedHeader ['nonce'] = $acmeNonce;
            $signedHeader ['url'] = $URL;
            
            $signedPayload = [
              'protected' => self::base64u (json_encode ($signedHeader)),
            ];
            
            // Create Payload
            $signedPayload ['payload'] = ($requestPayload === false ? '' : self::base64u (json_encode ($requestPayload ?? new \stdClass ())));
            
            // Create signature
            if (openssl_sign ($signedPayload ['protected'] . '.' . $signedPayload ['payload'], $payloadSignature, $this->clientKey, OPENSSL_ALGO_SHA256) === false)
              throw new \Exception ('Failed to create a signature');
            
            // Setup payload
            $requestHeaders ['Content-Type'] = 'application/jose+json';
            
            $signedPayload ['signature'] = self::base64u ($payloadSignature);
            $requestPayload = json_encode ($signedPayload);
            
            // Run the request
            return $this->httpPool->request (
              $URL,
              $requestMethod,
              $requestHeaders,
              $requestPayload
            );
        }
        );
      else
        $requestPromise = $this->httpPool->request (
          $URL,
          $requestMethod,
          $requestHeaders,
          $requestPayload
        );
      
      return $requestPromise->then (
        function ($responseBody, Events\Stream\HTTP\Header $responseHeader = null) use ($rejectError, $URL) {
          // Check for errors on the response
          if (!$responseHeader)
            throw new \Exception ('No header received');
          
          // Look for a nonce
          if ($responseHeader->hasField ('Replay-Nonce'))
            $this->replayNonce = $responseHeader->getField ('Replay-Nonce');
          
          // Try to parse JSON on the result
          if (
            ($responseHeader->getField ('Content-Type') == 'application/json') ||
            ($responseHeader->getField ('Content-Type') == 'application/problem+json')
          )
            $responseBody = json_decode ($responseBody);
          
          // Check for an error-response
          if ($rejectError && $responseHeader->isError ())
            throw new \Exception ('Errornous response received' . (is_object ($responseBody) && isset ($responseBody->detail) ? ': ' . $Body->detail : ''));
          
          // Forward the result
          return new Events\Promise\Solution ([ $responseBody, $responseHeader ]);
        }
      );
    }
    // }}}
  }

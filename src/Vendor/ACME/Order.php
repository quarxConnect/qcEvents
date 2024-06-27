<?php

  /**
   * qcEvents - Representation of an ACME Order
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
  
  namespace quarxConnect\Events\Vendor\ACME;
  use \quarxConnect\Events;
  
  class Order {
    /* ACME-Instance for this order */
    private $ACME = null;
    
    /* URI of this order */
    private $URI = null;
    
    /* Status of this order */
    public const STATUS_PENDING = 'pending'; // Order was newly created
    public const STATUS_READY = 'ready'; // Order was authorized and is ready for processing
    public const STATUS_PROCESSING = 'processing'; // Order is being processed
    public const STATUS_VALID = 'valid'; // Order was authorized and processed successfully
    public const STATUS_INVALID = 'invalid'; // Order could not be authorized or processed
    
    private $Status = Order::STATUS_INVALID;
    
    /* Timestamp when this order expires */
    private $Expires = null;
    
    /* Identifiers for this order */
    private $Identifiers = [ ];
    
    /* Requested notBefore-Timestamp */
    private $notBefore = null;
    
    /* Requested notAfter-Timestamp */
    private $notAfter = null;
    
    /* Authorizations for this order */
    private $Authorizations = [ ];
    
    /* Finalize-URI for this order */
    private $finalizeURI = null;
    
    /* Certificate-URI for this order */
    private $certificateURI = null;
    
    /* Error-Information that raised while processing */
    private $Error = null;
    
    
    // {{{ fromJSON
    /**
     * Create/Restore an ACME-Order from JSON
     * 
     * @param Events\Vendor\ACME $ACME
     * @param string $URI
     * @param object $JSON
     * 
     * @access public
     * @return Order
     **/
    public static function fromJSON (Events\Vendor\ACME $ACME, string $URI, object $JSON): Order
    {
      $orderInstance = new Order ($ACME, $URI);
      $orderInstance->updateFromJSON ($JSON);
      
      return $orderInstance;
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create a new ACME-Order-Instance
     * 
     * @param Events\Vendor\ACME $ACME
     * @param string $URI
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Events\Vendor\ACME $ACME, string $URI) {
      $this->ACME = $ACME;
      $this->URI = $URI;
    }
    // }}}
    
    // {{{ __debugInfo
    /**
     * Prepare output of this order for var_dump()
     * 
     * @access friendly
     * @return array
     **/
    function __debugInfo () : array {
      return [
        'URI' => $this->URI,
        'Status' => $this->Status,
        'Expires' => $this->Expires,
        'notBefore' => $this->notBefore,
        'notAfter' => $this->notAfter,
        'Identifiers' => array_map (
          function ($orderIdentifier) {
            return strtoupper ($orderIdentifier->type) . ':' . $orderIdentifier->value;
          },
          $this->Identifiers
        ),
        'Authorizations' => $this->Authorizations,
        'finalizeURI' => $this->finalizeURI,
        'certificateURI' => $this->certificateURI,
        'Error' => $this->Error,
      ];
    }
    // }}}
    
    // {{{ isPending
    /**
     * Check if the order is waiting for authorization
     * 
     * @access public
     * @return bool
     **/
    public function isPending () : bool {
      return ($this->Status == self::STATUS_PENDING);
    }
    // }}}
    
    // {{{ isReady
    /**
     * Check if the order is ready to be processed
     * 
     * @access public
     * @return bool
     **/
    public function isReady () : bool {
      return ($this->Status == self::STATUS_READY);
    }
    // }}}
    
    // {{{ isProcessing
    /**
     * Check if the order is in processing state
     * 
     * @access public
     * @return bool
     **/
    public function isProcessing () : bool {
      return ($this->Status == self::STATUS_PROCESSING);
    }
    // }}}
    
    // {{{ isValid
    /**
     * Check if the order is valid and was processed
     * 
     * @access public
     * @return bool
     **/
    public function isValid () : bool {
      return ($this->Status == self::STATUS_VALID);
    }
    // }}}
    
    // {{{ isInvalid
    /**
     * Check if the order is invalid
     * 
     * @access public
     * @return bool
     **/
    public function isInvalid () {
      return ($this->Status == $this::STATUS_INVALID);
    }
    // }}}
    
    // {{{ getIdentifiers
    /**
     * Retrive all identifiers of this order
     * 
     * @access public
     * @return array
     **/
    public function getIdentifiers () : array {
      return $this->Identifiers;
    }
    // }}}
    
    // {{{ getAuthorizations
    /**
     * Retrive all authorizations for this order
     * 
     * @access public
     * @return Events\Promise
     **/
    public function getAuthorizations () : Events\Promise {
      $Result = [ ];
      
      foreach ($this->Authorizations as $Key=>$Value)
        if (is_string ($Value))
          $Result [] = $this->Authorizations [$Key] = $this->ACME->request ($Value, false)->then (
            function ($Result) use ($Key, $Value) {
              return $this->Authorizations [$Key] = Authorization::fromJSON ($this->ACME, $Value, $Result);
            }
          );
        else
          $Result [] = $Value;
      
      return Events\Promise::all ($Result);
    }
    // }}}
    
    // {{{ getCertificate
    /**
     * Retrive the issued certificate
     * 
     * @access public
     * @return Events\Promise
     **/
    public function getCertificate () : Events\Promise {
      // Retrive the full chain
      return $this->getCertificateChain (true)->then (
        function (array $certificateChain) {
          return array_shift ($certificateChain);
        }
      );
    }
    // }}}
    
    // {{{ getCertificateChain
    /**
     * Retrive the chain of the issued certfiicate
     * 
     * @param bool $fullChain (optional) Include the end-entity-certificate itself as well
     * 
     * @access public
     * @return Events\Promise
     **/
    public function getCertificateChain (bool $fullChain = false) : Events\Promise {
      // Check our state first
      if (!$this->isValid ())
        return Events\Promise::reject ('Order is not in valid state');
      
      // Make sure we have a certificate-URI
      if ($this->certificateURI === null)
        return Events\Promise::reject ('Missing certificate-URI');
      
      // Issue the request
      return $this->ACME->request ($this->certificateURI, true, false)->then (
        function ($responseBody, Events\Stream\HTTP\Header $responseHeader) use ($fullChain) {
          // Check content-type of response
          if ($responseHeader->getField ('Content-Type') != 'application/pem-certificate-chain')
            throw new \Exception ('Invalid content-type on response');
          
          // Explode the chain
          $certificateChain = explode ("\n\n", trim ($responseBody));
          
          // Check wheter to remove our own certificate from chain
          if (!$fullChain)
            array_shift ($certificateChain);
          
          // Forward the result
          return $certificateChain;
        }
      );
    }
    // }}}
    
    // {{{ createKey
    /**
     * Create a private key for a certificate
     * 
     * @param int $keySize (optional)
     * 
     * @access public
     * @return string
     **/
    public function createKey (int $keySize = 2048) : string {
      // Check size of the key
      if ($keySize % 1024 != 0)
        throw new \Exception ('Size must be a multiple of 1024');
      
      if ($keySize < 2048)
        throw new \Exception ('Size is too small (must be at least 2048)');
      
      // Create the key
      $newKey = openssl_pkey_new ([
        'private_key_bits' => $keySize,
        'private_key_type' => \OPENSSL_KEYTYPE_RSA,
      ]);
      
      // Export key to string
      if (!openssl_pkey_export ($newKey, $pemKey))
        throw new \Exception ('Failed to export new key');
      
      return $pemKey;
    }
    // }}}
    
    // {{{ createCSR
    /**
     * Create a Certificate-Signing-Request for this order
     * 
     * @param mixed $requestKey
     * @param array $requestSubject (optional)
     * 
     * @access public
     * @return string
     **/
    public function createCSR ($requestKey, array $requestSubject = [ ]) : string {
      // Make sure there is a commonName on the subject
      if (!isset ($requestSubject ['commonName']))
        foreach ($this->Identifiers as $ID) {
          $requestSubject ['commonName'] = $ID->value;
          
          break;
        }
      
      // Create temporary configuration for OpenSSL
      $tmpConfig = tempnam (sys_get_temp_dir (), 'openssl');
      
      file_put_contents (
        $tmpConfig,
        '[req]' . "\n" .
        'distinguished_name=v3_req' . "\n" .
        '[v3_req]' . "\n" .
        '[v3_ca]' . "\n" .
        '[san]' . "\n" .
        'subjectAltName=' . implode (
          ',',
          array_map (
            function ($requestIdentifier) {
              return strtoupper ($requestIdentifier->type) . ':' . $requestIdentifier->value;
            },
            $this->Identifiers
          )
        ) . "\n"
      );
      
      // Generate the CSR
      $certificateRequest = openssl_csr_new (
        $requestSubject,
        $requestKey,
        [
          'digest_alg' => 'sha256',
          'config' => $tmpConfig,
          'req_extensions' => 'san',
        ]
      );
      
      // Remove temporary configuration again
      unlink ($tmpConfig);
      
      // Convert CSR to string
      if (!openssl_csr_export ($certificateRequest, $pemRequest))
        throw new \Exception ('Failed to export certificate request');
      
      return $pemRequest;
    }
    // }}}
    
    // {{{ fetch
    /**
     * Fetch this order from server
     * 
     * @access public
     * @return Events\Promise
     **/
    public function fetch () : Events\Promise {
      return $this->ACME->request ($this->URI, false)->then (
        function ($responseBody) {
          // Push response to our attributes
          $this->updateFromJSON ($responseBody);
          
          // Return ourself
          return $this;
        }
      );
    }
    // }}}
    
    // {{{ finalize
    /**
     * Try to finalize this order
     * 
     * @param mixed $signingReqeust
     * 
     * @access public
     * @return Events\Promise
     **/
    public function finalize ($signingRequest) : Events\Promise {
      // Check our state first
      if (!$this->isReady ())
        return Events\Promise::reject ('Order must be in ready-state');
      
      if ($this->finalizeURI === null)
        return Events\Promise::reject ('No finalizeURI assigned');
      
      // Make sure the CSR is in the right format
      if (is_resource ($signingRequest) && !openssl_csr_export ($signingRequest, $signingRequest))
        return Events\Promise::reject ('Failed to export CSR from OpenSSL');
      
      if (substr ($signingRequest, 0, 2) == '--')
        $signingRequest = base64_decode (substr ($signingRequest, strpos ($signingRequest, "\n") + 1, strpos ($signingRequest, "\n--") - strpos ($signingRequest, "\n")));
      
      // Run the request
      return $this->ACME->request ($this->finalizeURI, true, [ 'csr' => $this->ACME::base64u ($signingRequest) ])->then (
        function ($responseJSON) {
          // Push the lastest JSON to our self
          $this->updateFromJSON ($responseJSON);
          
          // Indicate success
          return true;
        }
      );
    }
    // }}}
    
    // {{{ updateFromJSON
    /**
     * Update this order from a JSON-Object
     * 
     * @param object $JSON
     * 
     * @access private
     * @return void
     **/
    private function updateFromJSON (object $JSON) : void {
      $this->Status = $JSON->status;
      $this->Identifiers = $JSON->identifiers;
      $this->Authorizations = $JSON->authorizations;
      $this->finalizeURI = $JSON->finalize;
    
      if (isset ($JSON->expires))
        $this->Expires = strtotime ($JSON->expires);
      
      if (isset ($JSON->notBefore))
        $this->notBefore = strtotime ($JSON->notBefore);
      
      if (isset ($JSON->notAfter))
        $this->notAfter = strtotime ($JSON->notAfter);
      
      if (isset ($JSON->certificate))
        $this->certificateURI = $JSON->certificate;
      
      if (isset ($JSON->error))
        $this->Error = $JSON->error;
    }
    // }}}
  }

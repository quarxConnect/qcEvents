<?PHP

  /**
   * qcEvents - Representation of an ACME Order
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
  require_once ('qcEvents/Vendor/ACME/Authorization.php');
  
  class qcEvents_Vendor_ACME_Order {
    /* ACME-Instance for this order */
    private $ACME = null;
    
    /* URI of this order */
    private $URI = null;
    
    /* Status of this order */
    const STATUS_PENDING = 'pending'; // Order was newly created
    const STATUS_READY = 'ready'; // Order was authorized and is ready for processing
    const STATUS_PROCESSING = 'processing'; // Order is being processed
    const STATUS_VALID = 'valid'; // Order was authorized and processed successfully
    const STATUS_INVALID = 'invalid'; // Order could not be authorized or processed
    
    private $Status = qcEvents_Vendor_ACME_Order::STATUS_INVALID;
    
    /* Timestamp when this order expires */
    private $Expires = null;
    
    /* Identifiers for this order */
    private $Identifiers = array ();
    
    /* Requested notBefore-Timestamp */
    private $notBefore = null;
    
    /* Requested notAfter-Timestamp */
    private $notAfter = null;
    
    /* Authorizations for this order */
    private $Authorizations = array ();
    
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
     * @param qcEvents_Vendor_ACME $ACME
     * @param string $URI
     * @param object $JSON
     * 
     * @access public
     * @return qcEvents_Vendor_ACME_Order
     **/
    public static function fromJSON (qcEvents_Vendor_ACME $ACME, $URI, $JSON) {
      $Instance = new static ($ACME, $URI);
      $Instance->updateFromJSON ($JSON);
      
      return $Instance;
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create a new ACME-Order-Instance
     * 
     * @param qcEvents_Vendor_ACME $ACME
     * @param string $URI
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Vendor_ACME $ACME, $URI) {
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
    function __debugInfo () {
      return array (
        'URI' => $this->URI,
        'Status' => $this->Status,
        'Expires' => $this->Expires,
        'notBefore' => $this->notBefore,
        'notAfter' => $this->notAfter,
        'Identifiers' => array_map (function ($e) { return strtoupper ($e->type) . ':' . $e->value; }, $this->Identifiers),
        'Authorizations' => $this->Authorizations,
        'finalizeURI' => $this->finalizeURI,
        'certificateURI' => $this->certificateURI,
        'Error' => $this->Error,
      );
    }
    // }}}
    
    // {{{ isPending
    /**
     * Check if the order is waiting for authorization
     * 
     * @access public
     * @return bool
     **/
    public function isPending () {
      return ($this->Status == $this::STATUS_PENDING);
    }
    // }}}
    
    // {{{ isReady
    /**
     * Check if the order is ready to be processed
     * 
     * @access public
     * @return bool
     **/
    public function isReady () {
      return ($this->Status == $this::STATUS_READY);
    }
    // }}}
    
    // {{{ isValid
    /**
     * Check if the order is valid and was processed
     * 
     * @access public
     * @return bool
     **/
    public function isValid () {
      return ($this->Status == $this::STATUS_VALID);
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
    public function getIdentifiers () {
      return $this->Identifiers;
    }
    // }}}
    
    // {{{ getAuthorizations
    /**
     * Retrive all authorizations for this order
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getAuthorizations () : qcEvents_Promise {
      $Result = array ();
      
      foreach ($this->Authorizations as $Key=>$Value)
        if (is_string ($Value))
          $Result [] = $this->Authorizations [$Key] = $this->ACME->request ($Value, false)->then (
            function ($Result) use ($Key, $Value) {
              return $this->Authorizations [$Key] = qcEvents_Vendor_ACME_Authorization::fromJSON ($this->ACME, $Value, $Result);
            }
          );
        else
          $Result [] = $Value;
      
      return qcEvents_Promise::all ($Result);
    }
    // }}}
    
    // {{{ getCertificate
    /**
     * Retrive the issued certificate
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getCertificate () : qcEvents_Promise {
      // Retrive the full chain
      return $this->getCertificateChain (true)->then (
        function (array $Chain) {
          return array_shift ($Chain);
        }
      );
    }
    // }}}
    
    // {{{ getCertificateChain
    /**
     * Retrive the chain of the issued certfiicate
     * 
     * @param bool $Full (optional) Include the end-entity-certificate itself as well
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getCertificateChain ($Full = false) : qcEvents_Promise {
      // Check our state first
      if (!$this->isValid ())
        return qcEvents_Promise::reject ('Order is not in valid state');
      
      // Make sure we have a certificate-URI
      if ($this->certificateURI === null)
        return qcEvents_Promise::reject ('Missing certificate-URI');
      
      // Issue the request
      return $this->ACME->request ($this->certificateURI, true, false)->then (
        function ($Response, qcEvents_Stream_HTTP_Header $Header) use ($Full) {
          // Check content-type of response
          if ($Header->getField ('Content-Type') != 'application/pem-certificate-chain')
            throw new exception ('Invalid content-type on response');
          
          // Explode the chain
          $Chain = explode ("\n\n", trim ($Response));
          
          // Check wheter to remove our own certificate from chain
          if (!$Full)
            array_shift ($Chain);
          
          // Forward the result
          return $Chain;
        }
      );
    }
    // }}}
    
    // {{{ createKey
    /**
     * Create a private key for a certificate
     * 
     * @param int $Size (optional)
     * 
     * @access public
     * @return stirng
     **/
    public function createKey ($Size = 2048) {
      // Check size of the key
      if ($Size % 1024 != 0) {
        trigger_error ('Size must be a multiple of 1024');
        
        return false;
      }
      
      if ($Size < 2048) {
        trigger_error ('Size is too small (must be at least 2048)');
        
        return false;
      }
      
      // Create the key
      $Key = openssl_pkey_new (array (
        'private_key_bits' => $Size,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
      ));
      
      // Export key to string
      if (!openssl_pkey_export ($Key, $pemKey))
        return false;
      
      return $pemKey;
    }
    // }}}
    
    // {{{ createCSR
    /**
     * Create a Certificate-Signing-Request for this order
     * 
     * @param mixed $Key
     * @param array $Subject (optional)
     * 
     * @access public
     * @return string
     **/
    public function createCSR ($Key, array $Subject = array ()) {
      // Make sure there is a commonName on the subject
      if (!isset ($Subject ['commonName']))
        foreach ($this->Identifiers as $ID) {
          $Subject ['commonName'] = $ID->value;
          
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
        'subjectAltName=' . implode (',', array_map (function ($e) { return strtoupper ($e->type) . ':' . $e->value; }, $this->Identifiers)) . "\n"
      );
      
      // Generate the CSR
      $Request = openssl_csr_new (
        $Subject,
        $Key,
        array (
          'digest_alg' => 'sha256',
          'config' => $tmpConfig,
          'req_extensions' => 'san',
        )
      );
      
      // Remove temporary configuration again
      unlink ($tmpConfig);
      
      // Convert CSR to string
      if (!openssl_csr_export ($Request, $pemRequest))
        return false;
      
      return $pemRequest;
    }
    // }}}
    
    // {{{ fetch
    /**
     * Fetch this order from server
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function fetch () : qcEvents_Promise {
      return $this->ACME->request ($this->URI, false)->then (
        function ($Response) {
          // Push response to our attributes
          $this->updateFromJSON ($Response);
          
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
     * @param mixed $CSR
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function finalize ($CSR) : qcEvents_Promise {
      // Check our state first
      if (!$this->isReady ())
        return qcEvents_Promise::reject ('Order must be in ready-state');
      
      if ($this->finalizeURI === null)
        return qcEvents_Promise::reject ('No finalizeURI assigned');
      
      // Make sure the CSR is in the right format
      if (is_resource ($CSR) && !openssl_csr_export ($CSR, $CSR))
        return qcEvents_Promise::reject ('Failed to export CSR from OpenSSL');
      
      if (substr ($CSR, 0, 2) == '--')
        $CSR = base64_decode (substr ($CSR, strpos ($CSR, "\n") + 1, strpos ($CSR, "\n--") - strpos ($CSR, "\n")));
      
      // Run the request
      return $this->ACME->request ($this->finalizeURI, true, array ('csr' => $this->ACME::base64u ($CSR)))->then (
        function ($JSON) {
          // Push the lastest JSON to our self
          $this->updateFromJSON ($JSON);
          
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
    private function updateFromJSON ($JSON) {
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

?>
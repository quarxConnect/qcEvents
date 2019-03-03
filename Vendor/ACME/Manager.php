<?PHP

  /**
   * qcEvents - ACME Certificate Manager
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
  
  require_once ('qcEvents/Vendor/ACME.php');
  
  class qcEvents_Vendor_ACME_Manager extends qcEvents_Vendor_ACME {
    /* Timeout to retry creating an order */
    const ORDER_CREATE_TIMEOUT = 60;
    
    /* timeout to refetch status of an pending order */
    const ORDER_PENDING_TIMEOUT = 30;
    
    /* Timeout to refetch status of an order in processing-state */
    const ORDER_PROCESSING_TIMEOUT = 15;
    
    /* All certificates for this manager */
    private $Certificates = array ();
    
    /* Pending challenges */
    private $Challenges = array ();
    
    // {{{ addCertificate
    /**
     * Add a certificate to this manager
     * 
     * @param array $Names
     * @param qcEvents_Socket_Server $Server (optional) Register certificate at the server when deployed
     * @param bool $Default (optional) Register as default certificate
     * 
     * @access public
     * @return void
     **/
    public function addCertificate (array $Names, qcEvents_Socket_Server $Server = null, $Default = false) {
      // Create unique key for that certificate
      $Key = $Names;
      sort ($Key);
      $Key = hash ('sha256', implode ('@', $Key));
      
      // Check if there is already an order for this
      if (isset ($this->Certificates [$Key])) {
        if ($Server) {
          if (!in_array ($Server, $this->Certificates [$Key]['Servers'], true))
            $this->Certificates [$Key]['Servers'][] = $Server;
          
          if ($Default && !in_array ($Server, $this->Certificates [$Key]['Defaults'], true))
            $this->Certificates [$Key]['Defaults'][] = $Server;
        }
        
        return;
      }
      
      // Create the certificate-order
      $this->Certificates [$Key] = array (
        'Names' => $Names,
        'Servers' => ($Server ? array ($Server) : array ()),
        'Defaults' => ($Server && $Default ? array ($Server) : array ()),
        'Order' => null,
      );
      
      // Check if we know the certificate already
      if (($Path = $this::getDataPath ()) && is_file ($Path . '/acme-' . $Key . '.crt'))
        return $this->activateCertificate ($Key);
      
      if (!$Path) {
        trigger_error ('Failed to get Data-Path', E_USER_WARNING);
        
        return false;
      }
      
      // Place a new order via ACME
      $this->startOrder ($Key);
    }
    // }}}
    
    // {{{ startOrder
    /**
     * Start create/renew-process for a given certificate
     * 
     * @param string $Key
     * 
     * @access private
     * @return void
     **/
    private function startOrder ($Key) {
      // Try to create the order
      $this->createOrder ($this->Certificates [$Key]['Names'])->then (
        function (qcEvents_Vendor_ACME_Order $Order) use ($Key) {
          // Store the order
          $this->Certificates [$Key]['Order'] = $Order;
          
          // Try to process order
          $this->processOrder ($Order);
        },
        function ($e) use ($Key) {
          trigger_error ('Could not create order: ' . $e);
          
          $this->getEventBase ()->addTimeout ($this::ORDER_CREATE_TIMEOUT)->then (
            function () use ($Key) {
              $this->startOrder ($Key);
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ processOrder
    /**
     * Try to process an order
     * 
     * @param qcEvents_Vendor_ACME_Order $Order
     * 
     * @access private
     * @return void
     **/
    private function processOrder (qcEvents_Vendor_ACME_Order $Order) {
      // Check for pending authorizations
      if ($Order->isPending ())
        return $Order->getAuthorizations ()->then (
          function (array $Authorizations) use ($Order) {
            // Try to process each authorization
            $Promises = array ();
            
            foreach ($Authorizations as $Authorization) {
              foreach ($Authorization->getChallenges () as $Challenge) {
                // We only support HTTP-01 at the moment
                if (!$Challenge->isType ('http-01'))
                  continue;
                
                // Check if the challenge is already active
                if (isset ($this->Challenges [$Challenge->getToken ()]))
                  continue (2);
                
                // Activate the challenge
                $this->Challenges [$Challenge->getToken ()] = $Order;
                $Promises [] = $Challenge->activate ();
                
                // Proceed to net authorization
                continue (2);
              }
              
              // Bail out a warning if we get here
              trigger_error ('No challenge for authorization found', E_USER_WARNING);
            }
            
            // Wait for pending challenges
            return qcEvents_Promise::all ($Promises)->then (
              function () {
                return $this->getEventBase ()->addTimeout ($this::ORDER_PENDING_TIMEOUT);
              }
            )->then (
              // Refresh the order after timeout
              function () use ($Order) {
                return $Order->refresh ();
              }
            )->then (
              // Try to process again
              function (qcEvents_Vendor_ACME_Order $Order) {
                $this->processOrder ($Order);
              }
            );
          },
          function ($e) {
            trigger_error ('Could not fetch authorizations: ' . $e);
          }
        );
      
      // Try to issue the certificate
      if ($Order->isReady ()) {
        // Remove the challenges from queue
        foreach (array_keys ($this->Challenges, $Order, true) as $Key)
          unset ($this->Challenges [$Key]);
        
        // Create a new key
        $Order->Key = $Order->createKey ();
        
        // Create a CSR for this order
        $CSR = $Order->createCSR ($Order->Key);
        
        // Try to finalize the order
        return $Order->finalize ($CSR)->then (
          function () use ($Order) {
            // Try to process a new state
            if (!$Order->isReady ())
              return $this->processOrder ($Order);
            
            # TODO: Set timeout to check order again
            trigger_error ('TODO: Order still ready after finalize');
          },
          function ($e) {
            trigger_error ('Could not finalize order: ' . $e);
          }
        );
      }
      
      // Try to retrive the certificate
      if ($Order->isValid ())
        return $Order->getCertificateChain (true)->then (
          function (array $Chain) use ($Order) {
            // Try to get out data-path
            if (!($Path = $this::getDataPath ())) {
              trigger_error ('Failed to get data-path', E_USER_WARNING);
              
              return;
            }
            
            // Find key for this order
            $Found = false;
            
            foreach ($this->Certificates as $Key=>$Info)
              if ($Found = ($Info ['Order'] === $Order))
                break;
            
            if (!$Found) {
              trigger_error ('Failed to find certificate-descriptor for order', E_USER_WARNING);
              
              return;
            }
            
            // Try to write out the certificate
            $Chain [] = $Order->Key;
            $PEM = implode ("\n", $Chain);
            
            if (file_put_contents ($Path . '/acme-' . $Key . '.crt.new', $PEM) != strlen ($PEM)) {
              trigger_error ('Failed to write new certificate', E_USER_WARNING);
              @unlink ($Path . '/acme-' . $Key . '.crt.new');
              
              return;
            }
            
            if (!rename ($Path . '/acme-' . $Key . '.crt.new', $Path . '/acme-' . $Key . '.crt')) {
              trigger_error ('Failed to activate new certificate', E_USER_WARNING);
              
              return;
            }
            
            // Remove the order from queue
            $this->Certificates [$Key]['Order'] = null;
            
            // Activate the certificate
            $this->activateCertificate ($Key);
          },
          function ($e) {
            trigger_error ('Could not load certificates: ' . $e);
          }
        );
      
      if ($Order->isInvalid ()) {
        // Remove the challenges from queue
        foreach (array_keys ($this->Challenges, $Order, true) as $Key)
          unset ($this->Challenges [$Key]);
        
        # TODO: Enqueue order again
        trigger_error ('TODO: Order became invalid', E_USER_WARNING);
        
        return;
      }
      
      if ($Order->isProcessing ())
        $this->getEventBase ()->addTimeout ($this::ORDER_PROCESSING_TIMEOUT)->then (
          function () use ($Order) {
            return $Order->refresh ();
          }
        )->then (
          function ($Order) {
            return $this->processOrder ($Order);
          }
        );
    }
    // }}}
        
    // {{{ processRequest
    /**
     * Try to intercept a HTTP-Request
     * 
     * @access public
     * @return bool Returns true if the request was intercepted
     **/
    public function processRequest (qcEvents_Server_HTTP $Server, qcEvents_Stream_HTTP_Header $Header, $Body = null) {
      // Retrive URI for the request
      $URI = $Header->getURI ();
      
      // Check for an ACME-Request
      if (substr ($URI, 0, 28) != '/.well-known/acme-challenge/')
        return false;
      
      // Check the challenge
      $Challenge = substr ($URI, 28);
      
      if (!isset ($this->Challenges [$Challenge]))
        return false;
      
      // Write out the response
      $Server->httpdSetResponse (
        $Header,
        new qcEvents_Stream_HTTP_Header (array (
          'HTTP/1.1 200 OK',
          'Server: quarxConnect httpd/0.1',
          'Content-Type: text/plain'
        )),
        $Challenge . '.' . $this->getThumbprint ()
      );
      
      // Check the order again
      $this->Challenges [$Challenge]->fetch ()->then (function ($Order) {
        if (!$Order->isPending ())
          $this->processOrder ($Order);
      });
      
      return true;
    }
    // }}}
    
    // {{{ activateCertificate
    /**
     * Activate a certificate
     * 
     * @param string $Key
     * 
     * @access private
     * @return void
     **/
    private function activateCertificate ($Key) {
      // Try to get out data-path
      if (!($Path = $this::getDataPath ())) {
        trigger_error ('Failed to get data-path', E_USER_WARNING);
        
        return;
      }
      
      // Sanity-Check the key
      if (!isset ($this->Certificates [$Key])) {
        trigger_error ('No such key: ' . $Key, E_USER_WARNING);
        
        return;
      }
      
      // Sanity-Check if our certificate is in place
      $Filename = $Path . '/acme-' . $Key . '.crt';
      
      if (!is_file ($Filename)) {
        trigger_error ('Missing certificate', E_USER_WARNING);
        
        return;
      }
      
      // Activate servers
      foreach ($this->Certificates [$Key]['Servers'] as $Server) {
        // Check wheter to set as default
        $Default = in_array ($Server, $this->Certificates [$Key]['Defaults'], true);
        
        // Retrive TLS-Options
        $Options = $Server->tlsOptions ();
        
        // Patch the options
        $Certificate = (!isset ($Options ['local_cert']) || $Default ? $Filename : $Options ['local_cert']);
        $Certificates = (isset ($Options ['SNI_server_certs']) ? $Options ['SNI_server_certs'] : array ());
        
        foreach ($this->Certificates [$Key]['Names'] as $Name)
          $Certificates [$Name] = $Filename;
        
        // Push back
        $Server->tlsCertificate ($Certificate, $Certificates);
      }
      
      // Check when to renew the certificate
      $Info = openssl_x509_parse (file_get_contents ($Filename));
      $Time = time ();
      $ValidTime = $Info ['validTo_time_t'] - $Info ['validFrom_time_t'];
      $RenewAfter = $Info ['validFrom_time_t'] + (int)($ValidTime * 0.75);
      $RenewIn = $RenewAfter - $Time;
      
      // Enqueue the renew
      $this->getEventBase ()->addTimeout ($RenewIn)->then (
        function () use ($Key) {
          return $this->startOrder ($Key);
        }
      );
    }
    // }}}
  }

?>
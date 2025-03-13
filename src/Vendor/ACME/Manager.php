<?php

  /**
   * qcEvents - ACME Certificate Manager
   * Copyright (C) 2019-2022 Bernd Holzmueller <bernd@quarxconnect.de>
   * Copyright (C) 2023-2025 Bernd Holzmueller <bernd@innorize.gmbh>
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

  namespace quarxConnect\Events\Vendor\ACME;

  use quarxConnect\Events;
  use Throwable;

  class Manager extends Events\Vendor\ACME {
    /* Timeout to retry creating an order */
    private const ORDER_CREATE_TIMEOUT = 60;
    
    /* timeout to refetch status of an pending order */
    private const ORDER_PENDING_TIMEOUT = 30;
    
    /* Timeout to refetch status of an order in processing-state */
    private const ORDER_PROCESSING_TIMEOUT = 15;
    
    /* All certificates for this manager */
    private array $Certificates = [];
    
    /* Pending challenges */
    private array $Challenges = [];
    
    // {{{ addCertificate
    /**
     * Add a certificate to this manager
     *
     * @param array $domainNames
     * @param Events\Socket\Server|null $socketServer (optional) Register certificate at the server when deployed
     * @param bool $defaultCertificate (optional) Register as default certificate
     *
     * @return void
     **/
    public function addCertificate (array $domainNames, Events\Socket\Server $socketServer = null, bool $defaultCertificate = false): void
    {
      // Create unique key for that certificate
      $certificateKey = $domainNames;
      sort ($certificateKey);
      $certificateKey = hash ('sha256', implode ('@', $certificateKey));

      // Check if there is already an order for this
      if (isset ($this->Certificates [$certificateKey])) {
        if ($socketServer) {
          if (!in_array ($socketServer, $this->Certificates [$certificateKey]['Servers'], true))
            $this->Certificates [$certificateKey]['Servers'][] = $socketServer;

          if (
            $defaultCertificate &&
            !in_array ($socketServer, $this->Certificates [$certificateKey]['Defaults'], true)
          )
            $this->Certificates [$certificateKey]['Defaults'][] = $socketServer;
        }

        return;
      }

      // Create the certificate-order
      $this->Certificates [$certificateKey] = [
        'Names' => $domainNames,
        'Servers' => ($socketServer ? [ $socketServer ] : [ ]),
        'Defaults' => ($socketServer && $defaultCertificate ? [ $socketServer ] : [ ]),
        'Order' => null,
      ];

      // Check if we know the certificate already
      $dataPath = $this->getEventBase ()->getDataPath ();

      if (is_file ($dataPath . '/acme-' . $certificateKey . '.crt')) {
        $this->activateCertificate ($certificateKey);

        return;
      }

      // Place a new order via ACME
      $this->startOrder ($certificateKey);
    }
    // }}}

    // {{{ startOrder
    /**
     * Start create/renew-process for a given certificate
     *
     * @param string $certificateKey
     *
     * @return void
     **/
    private function startOrder (string $certificateKey): void
    {
      // Try to create the order
      $this->createOrder ($this->Certificates [$certificateKey]['Names'])->then (
        function (Order $acmeOrder) use ($certificateKey): void {
          // Store the order
          $this->Certificates [$certificateKey]['Order'] = $acmeOrder;

          // Try to process order
          $this->processOrder ($acmeOrder);
        },
        function (Throwable $orderError) use ($certificateKey): void {
          trigger_error ('Could not create order: ' . $orderError->getMessage ());

          $this->getEventBase ()->addTimeout ($this::ORDER_CREATE_TIMEOUT)->then (
            function () use ($certificateKey): void {
              $this->startOrder ($certificateKey);
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
     * @param Order $acmeOrder
     * 
     * @access private
     * @return void
     **/
    private function processOrder (Order $acmeOrder) : void
    {
      // Check for pending authorizations
      if ($acmeOrder->isPending ()) {
        $acmeOrder->getAuthorizations ()->then (
          function (array $acmeAuthorizations) use ($acmeOrder): Events\Promise {
            // Try to process each authorization
            $authPromises = [];

            foreach ($acmeAuthorizations as $acmeAuthorization) {
              foreach ($acmeAuthorization->getChallenges () as $authChallenge) {
                // We only support HTTP-01 at the moment
                if (!$authChallenge->isType ('http-01'))
                  continue;

                // Check if the challenge is already active
                if (isset ($this->Challenges [$authChallenge->getToken ()]))
                  continue (2);

                // Activate the challenge
                $this->Challenges [$authChallenge->getToken ()] = $acmeOrder;
                $authPromises [] = $authChallenge->activate ();

                // Proceed to net authorization
                continue (2);
              }

              // Bail out a warning if we get here
              trigger_error ('No challenge for authorization found', E_USER_WARNING);
            }

            // Wait for pending challenges
            return Events\Promise::all ($authPromises)->then (
              function () {
                return $this->getEventBase ()->addTimeout ($this::ORDER_PENDING_TIMEOUT);
              }
            )->then (
            // Refresh the order after timeout
              function () use ($acmeOrder) {
                return $acmeOrder->fetch ();
              }
            )->then (
            // Try to process again
              function (Order $acmeOrder) {
                $this->processOrder ($acmeOrder);
              }
            );
          },
          function (Throwable $orderError): void {
            trigger_error ('Could not fetch authorizations: ' . $orderError->getMessage ());
          }
        );
      // Try to issue the certificate
      } elseif ($acmeOrder->isReady ()) {
        // Remove the challenges from queue
        foreach (array_keys ($this->Challenges, $acmeOrder, true) as $orderKey)
          unset ($this->Challenges [$orderKey]);

        // Create a CSR for this order
        $signingRequest = $acmeOrder->createCSR ();

        // Try to finalize the order
        $acmeOrder->finalize ($signingRequest)->then (
          function () use ($acmeOrder): void {
            // Try to process a new state
            if (!$acmeOrder->isReady ()) {
              $this->processOrder ($acmeOrder);

              return;
            }

            # TODO: Set timeout to check order again
            trigger_error ('TODO: Order still ready after finalize');
          },
          function (Throwable $orderError): void {
            trigger_error ('Could not finalize order: ' . $orderError->getMessage ());
          }
        );

      // Try to retrieve the certificate
      } elseif ($acmeOrder->isValid ()) {
        $acmeOrder->getCertificateChain (true)->then (
          function (array $certificateChain) use ($acmeOrder): void {
            // Try to get out data-path
            $dataPath = $this->getEventBase ()->getDataPath ();

            // Find key for this order
            $orderFound = false;
            $certificateKey = null;

            foreach ($this->Certificates as $certificateKey => $orderInfo) {
              $orderFound = ($orderInfo ['Order'] === $acmeOrder);

              if ($orderFound)
                break;
            }

            if (!$orderFound) {
              trigger_error ('Failed to find certificate-descriptor for order', E_USER_WARNING);

              return;
            }

            // Try to write out the certificate
            $certificateChain [] = $acmeOrder->getPrivateKeyAsPEM ();
            $chainPEM = implode (PHP_EOL, $certificateChain);
            $certificatePath = $dataPath . '/acme-' . $certificateKey . '.crt';

            if (file_put_contents ($certificatePath . '.new', $chainPEM) !== strlen ($chainPEM)) {
              trigger_error ('Failed to write new certificate to disk', E_USER_WARNING);
              unlink ($dataPath . '/acme-' . $certificateKey . '.crt.new');

              return;
            }

            if (!rename ($certificatePath . '.new', $certificatePath)) {
              trigger_error ('Failed to activate (rename) new certificate', E_USER_WARNING);
              unlink ($dataPath . '/acme-' . $certificateKey . '.crt.new');

              return;
            }

            // Remove the order from queue
            $this->Certificates [$certificateKey]['Order'] = null;

            // Activate the certificate
            $this->activateCertificate ($certificateKey);
          },
          function (Throwable $orderError): void {
            trigger_error ('Could not load certificates: ' . $orderError->getMessage ());
          }
        );
      } elseif ($acmeOrder->isInvalid ()) {
        // Remove the challenges from queue
        foreach (array_keys ($this->Challenges, $acmeOrder, true) as $orderKey)
          unset ($this->Challenges [$orderKey]);
        
        # TODO: Enqueue order again
        trigger_error ('TODO: Order became invalid', E_USER_WARNING);
      } elseif ($acmeOrder->isProcessing ()) {
        $this->getEventBase ()->addTimeout ($this::ORDER_PROCESSING_TIMEOUT)->then (
          fn (): Events\Promise  => $acmeOrder->fetch ()
        )->then (
          function ($acmeOrder): void {
            $this->processOrder ($acmeOrder);
          }
        );
      } else {
        trigger_error ('ACME-Order in unhandled state, please FIXME');
      }
    }
    // }}}
        
    // {{{ processRequest
    /**
     * Try to intercept a HTTP-Request
     * 
     * @param Events\Server\HTTP $httpServer
     * @param Events\Stream\HTTP\Header $requestHeader
     * @param string $requestBody (optional)
     * 
     * @access public
     * @return bool Returns true if the request was intercepted
     **/
    public function processRequest (Events\Server\HTTP $httpServer, Events\Stream\HTTP\Header $requestHeader, string $requestBody = null) : bool {
      // Retrive URI for the request
      $URI = $requestHeader->getURI ();
      
      // Check for an ACME-Request
      if (substr ($URI, 0, 28) != '/.well-known/acme-challenge/')
        return false;
      
      // Check the challenge
      $Challenge = substr ($URI, 28);
      
      if (!isset ($this->Challenges [$Challenge]))
        return false;
      
      // Write out the response
      $httpServer->httpdSetResponse (
        $requestHeader,
        new Events\Stream\HTTP\Header ([
          'HTTP/1.1 200 OK',
          'Server: quarxConnect httpd/0.1',
          'Content-Type: text/plain'
        ]),
        $Challenge . '.' . $this->getThumbprint ()
      );
      
      // Check the order again
      $this->Challenges [$Challenge]->fetch ()->then (
        function ($acmeOrder) {
          if (!$acmeOrder->isPending ())
            $this->processOrder ($acmeOrder);
        }
      );
      
      return true;
    }
    // }}}
    
    // {{{ activateCertificate
    /**
     * Activate a certificate
     * 
     * @param string $certificateKey
     * 
     * @access private
     * @return void
     **/
    private function activateCertificate (string $certificateKey) : void {
      // Try to get out data-path
      if (!($dataPath = $this->getEventBase ()->getDataPath ())) {
        trigger_error ('Failed to get data-path', E_USER_WARNING);
        
        return;
      }
      
      // Sanity-Check the key
      if (!isset ($this->Certificates [$certificateKey])) {
        trigger_error ('No such key: ' . $certificateKey, E_USER_WARNING);
        
        return;
      }
      
      // Sanity-Check if our certificate is in place
      $Filename = $dataPath . '/acme-' . $certificateKey . '.crt';
      
      if (!is_file ($Filename)) {
        trigger_error ('Missing certificate', E_USER_WARNING);
        
        return;
      }
      
      // Activate servers
      foreach ($this->Certificates [$certificateKey]['Servers'] as $socketServer) {
        // Check wheter to set as default
        $defaultCertificate = in_array ($socketServer, $this->Certificates [$certificateKey]['Defaults'], true);
        
        // Retrive TLS-Options
        $tlsOptions = $socketServer->tlsOptions ();
        
        // Patch the options
        $tlsCertificate = (!isset ($tlsOptions ['local_cert']) || $defaultCertificate ? $Filename : $tlsOptions ['local_cert']);
        $tlsCertificates = (isset ($tlsOptions ['SNI_server_certs']) ? $tlsOptions ['SNI_server_certs'] : [ ]);
        
        foreach ($this->Certificates [$certificateKey]['Names'] as $domainName)
          $tlsCertificates [$domainName] = $Filename;
        
        // Push back
        $socketServer->tlsCertificate ($tlsCertificate, $tlsCertificates);
      }
      
      // Check when to renew the certificate
      $certificateInfo = openssl_x509_parse (file_get_contents ($Filename));
      $timeNow = time ();
      $validTime = $certificateInfo ['validTo_time_t'] - $certificateInfo ['validFrom_time_t'];
      $renewAfter = $certificateInfo ['validFrom_time_t'] + (int)($validTime * 0.75);
      $renewIn = $renewAfter - $timeNow;
      
      // Enqueue the renew
      $this->getEventBase ()->addTimeout ($renewIn)->then (
        function () use ($certificateKey) {
          $this->startOrder ($certificateKey);
        }
      );
    }
    // }}}
  }

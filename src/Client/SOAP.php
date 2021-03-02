<?php

  /**
   * quarxConnect Events - Dumb SOAP Client Implementation
   * Copyright (C) 2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  namespace quarxConnect\Events\Client;
  use quarxConnect\Events;
  
  if (!extension_loaded ('soap') && (!function_exists ('dl') || !dl ('soap.so'))) {
    trigger_error ('SOAP-Extension not present', \E_USER_ERROR);
    
    return;
  }
  
  class SOAP {
    /* Instance of our http-client */
    private $httpClient = null;
    
    /* Instance of our soap-client */
    private $soapClient = null;
    
    private $lastSoapCall = null;
    
    // {{{ __construct
    /**
     * Create a new half-asynchronour SOAP-Client
     * 
     * @param Events\Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Events\Base $eventBase) {
      // Create a new http-client
      $this->httpClient = new HTTP ($eventBase);
      
      // Create a dummy-promise
      $this->lastSoapCall = Events\Promise::resolve ($eventBase);
    }
    // }}}
    
    // {{{ __call
    /**
     * Invoke a SOAP-Function
     * 
     * @param string $functionName
     * @param array $functionArguments
     * 
     * @access friendly
     * @return Events\Promise
     **/
    function __call ($functionName, array $functionArguments) : Events\Promise {
      $this->lastSoapCall = $this->lastSoapCall->catch (
        function () { }
      )->then (
        function () use ($functionName, $functionArguments) {
          return call_user_func_array ([ $this->soapClient, $functionName ], $functionArguments);
        }
      );
      
      return $this->lastSoapCall;
    }
    // }}}
    
    // {{{ getEventBase
    /**
     * Retrive the Event-Base our HTTP-Client was assigned to
     * 
     * @access public
     * @return Events\Base
     **/
    public function getEventBase () : Events\Base {
      return $this->httpClient->getEventBase ();
    }
    // }}}
    
    // {{{ loadWSDL
    /**
     * Load a WSDL for this client from a remote source
     * 
     * @param string $wsdlURL
     * @param array $soapOptions (otpional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function loadWSDL ($wsdlURL, array $soapOptions = null) : Events\Promise {
      $wsdlFile = sys_get_temp_dir () . '/qcevents-soap-' . sha1 ($wsdlURL) . '.xml';
      
      if (is_file ($wsdlFile))
        return $this->loadWSDLFile ($wsdlFile);
      
      return $this->httpClient->request ($wsdlURL)->then (
        function ($responseBody) use ($wsdlFile) {
          return Events\File::writeFileContents (
            $this->httpClient->getEventBase (), 
            $wsdlFile,
            $responseBody
          );
        }
      )->then (
        function () use ($wsdlFile, $soapOptions) {
          return $this->loadWSDLFile ($wsdlFile, $soapOptions);
        }
      );
    }
    // }}}
    
    // {{{ loadWSDLFile
    /**
     * Load a WSDL from a local file
     * 
     * @param string $wsdlFile
     * 
     * @access public
     * @return Events\Promise
     **/
    public function loadWSDLFile ($wsdlFile, array $soapOptions = null) : Events\Promise {
      // Make sure the file is there
      if (!is_file ($wsdlFile))
        return Events\Promise::reject ('File not found');
      
      // Create a new SOAP-Client
      $this->soapClient = new class ($this->httpClient, $wsdlFile, $soapOptions) extends \SoapClient {
        /* Set of last request-headers */
        private $lastRequestHeaders = [ ];
        
        /* Instance of our http-client */
        private $httpClient = null;
        
        /* SOAP-Options */
        private $soapOptions = null;
        
        // {{{ __construct
        /**
         * Create new SOAP-Client
         * 
         * @param \qcEvents_Client_HTTP $httpClient
         * @param string $wsdlFile
         * 
         * @access friendly
         * @return void
         **/
        function __construct (HTTP $httpClient, $wsdlFile, array $soapOptions = null) {
          $this->httpClient = $httpClient;
          $this->soapOptions = $soapOptions ?? [ ];
          
          parent::__construct ($wsdlFile, $this->soapOptions);
        }
        // }}}
        
        // {{{ __doRequest
        /**
         * Issue the SOAP-Request to the remote party
         * 
         * @param string $soapRequest
         * @param string $soapLocation
         * @param string $soapAction
         * 
         * @access public
         * @return string
         **/
        public function __doRequest ($soapRequest, $soapLocation, $soapAction, $soapVersion, $soapOneWay = false) {
          // Store last used headers
          $this->lastRequestHeaders = [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . $soapAction . '"',
          ];
          
          // Check wheter to modify the URL
          if (isset ($this->soapOptions ['login'])) {
            // Parse the URL
            $soapURL = parse_url ($soapLocation);
            
            // Rewrite required parts
            $soapURL ['user'] = $this->soapOptions ['login'];
            
            if (isset ($this->soapOptions ['password']))
              $soapURL ['pass'] = $this->soapOptions ['password'];
            elseif (isset ($soapURL ['pass']))
              $soapURL ['pass'] = rawurldecode ($soapURL ['pass']);
            
            // Rebuild the URL
            $soapLocation =
              ($soapURL ['scheme'] ?? 'http') . '://' .
              (isset ($soapURL ['user']) ? rawurlencode ($soapURL ['user']) . (isset ($soapURL ['pass']) ? ':' . rawurlencode ($soapURL ['pass']) . '@' : '') : '') .
              $soapURL ['host'] . (isset ($soapURL ['port']) ? ':' . $soapURL ['port'] : '') .
              ($soapURL ['path'] ?? '/') .
              (isset ($soapURL ['query']) ? '?' . $soapURL ['query'] : '');
          }
          
          // Do the http-request in some synchronized style
          $responseBody = Events\Synchronizer::do (
            $this->httpClient,
            'request',
            $soapLocation,
            'POST',
            [
              'Content-Type' => 'text/xml; charset=utf-8',
              'SOAPAction' => '"' . $soapAction . '"',
            ],
            $soapRequest
          );
          
          // Forward the result
          return $responseBody;
        }
        // }}}
        
        // {{{ __getLastRequestHeaders
        /**
         * Retrive last used request-headers
         * 
         * @access public
         * @return string
         **/
        public function __getLastRequestHeaders () {
          return implode ("\n", $this->lastRequestHeaders) . "\n";
        }
        // }}}
      };
      
      return Events\Promise::resolve ();
    }
    // }}}
  }

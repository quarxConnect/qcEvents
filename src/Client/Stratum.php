<?php

  /**
   * qcEvents - Stratum-Client
   * Copyright (C) 2018-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  namespace quarxConnect\Events\Client;
  use \quarxConnect\Events;
  
  class Stratum extends Events\Stream\Stratum {
    /* Mining-Difficulty */
    private $Difficulty = 1;
    
    /* Version-String of this client (set via subscribe()) */
    private $Version = null;
    
    /* Authenticated username */
    private $Username = null;
    
    // {{{ subscribe
    /**
     * Try to subscribe to service
     * 
     * @param string $clientVersion (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function subscribe (string $Version = 'qcEvents/Stratum') : Events\Promise {
      switch ($this->getProtocolVersion ()) {
        // Ethereum-GetWork does not support this and negotiates version later
        case $this::PROTOCOL_ETH_GETWORK:
          $this->Version = $Version;
          
          return Events\Promise::resolve ([ ], null, null);
        // Ethereum-Stratum has empty parameters?!
        case $this::PROTOCOL_ETH_STRATUM:
          $Params = [ ];
          
          break;
        // Ethereum-Nicehash has protocol-version right after client-version
        case $this::PROTOCOL_ETH_STRATUM_NICEHASH:
          $Params = [ $Version, 'EthereumStratum/1.0.0' ];
          
          break;
        default:
          $Params = [ $Version ];
      }
      
      return $this->sendRequest (
        'mining.subscribe',
        $Params
      )->then (
        function ($Result)
        use ($Version) {
          // Sanatize the result
          if (!$Result || !is_array ($Result) || (count ($Result) <= 2))
            throw new \Exception ('Invalid result received');
          
          // Store the version
          $this->Version = $Version;
          
          // Transform the result
          $Result = [
            'Services' => $Result [0],
            'ExtraNonce1' => hexdec ($Result [1]),
            'ExtraNonce2Length' => $Result [2],
          ];
          
          // Raise the callback
          $this->___callback ('stratumSubscribed', $Result ['Services'], $Result ['ExtraNonce1'], $Result ['ExtraNonce2Length']);
          
          // Forward the result
          return new Events\Promise\Solution ([ $Result ['Services'], $Result ['ExtraNonce1'], $Result ['ExtraNonce2Length'] ]);
        }
      );
    }
    // }}}
    
    // {{{ configure
    /**
     * Try to negotiate protocol-extensions with out server
     * 
     * @param array $Extensions
     * 
     * @access public
     * @return Events\Promise
     **/
    public function configure (array $Extensions) : Events\Promise {
      // Check if this call is supported
      if ($this->getProtocolVersion () == $this::PROTOCOL_ETH_GETWORK)
        return Events\Promise::reject ('Unsupported on eth-getwork');
      
      // Convert extensions
      $Parameters = [ ];
      
      foreach ($Extensions as $Extension=>$eParameters)
        foreach ($eParameters as $Key=>$Value)
          $Parameters [$Extension . '.' . $Key] = $Value;
      
      // Forward the request
      return $this->sendRequest (
        'mining.configure',
        [ array_keys ($Extensions), $Parameters ]
      );
    }
    // }}}
    
    // {{ authenticate
    /**
     * Try to authenticate at the pool
     * 
     * @param string $Username
     * @param string $Password (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function authenticate (string $Username, string $Password = null) : Events\Promise {
      return $this->sendRequest (
        ($this->getProtocolVersion () == $this::PROTOCOL_ETH_GETWORK ? 'eth_submitLogin' : 'mining.authorize'),
        ($Password !== null ? [ $Username, $Password ] : [ $Username ]),
        ($this->getProtocolVersion () == $this::PROTOCOL_ETH_GETWORK ? [ 'worker' => $this->Version ] : null)
      )->then (
        function ($Result)
        use ($Username) {
          // Make sure its a bool
          $Result = !!$Result;
          
          if ($Result) {
            $this->Username = $Username;
            $this->___callback ('stratumAuthenticated', $Username);
            
            if ($this->getProtocolVersion () == $this::PROTOCOL_ETH_GETWORK)
              $this->sendMessage ([
                'id' => 0,
                'method' => 'eth_getWork',
                'params' => [ ],
              ]);
          }
          
          // Forward the result
          return $Result;
        }
      );
    }
    // }}}
    
    // {{{ getUsername
    /**
     * Retrive the last authenticated username
     * 
     * @access public
     * @return string
     **/
    public function getUsername () : string {
      return $this->Username;
    }
    // }}}
    
    // {{{ submitWork
    /**
     * Submit work
     * 
     * @param array $Work
     * 
     * @access public
     * @return Events\Promise
     **/
    public function submitWork (array $Work) : Events\Promise {
      // Preprocess Work for ethereum
      if ($this->getAlgorithm () == $this::ALGORITHM_ETH) {
        # GetWork:
        #   Result
        #   Headerhash
        #   MixHash
        # Stratum:
        #   Username
        #   JobID
        #   Result
        #   Headerhash
        #   MixHash
        # Nicehash
        #   Username
        #   JobID
        #   Result
      }
      
      // Send out the message
      return $this->sendRequest (
        ($this->getProtocolVersion () == $this::PROTOCOL_ETH_GETWORK ? 'eth_submitWork' : 'mining.submit'),
        $Work
      );
    }
    // }}}
    
    // {{{ processRequest
    /**
     * Process a Stratum-Request
     * 
     * @param object $Message
     * 
     * @access protected
     * @return void
     **/
    protected function processRequest (object $Message) : void {
      // Pool sets a new difficulty
      if ($Message->method == 'mining.set_difficulty') {
        $this->Difficulty = $Message->params [0];
        $this->___callback ('stratumSetDifficulty', $this->Difficulty);
        
        return;
      
      // Pool sends new job
      } elseif ($Message->method == 'mining.notify') {
        if ($Job = Events\Stream\Stratum\Job::fromArray ($this, $Message->params))
          $this->___callback ('stratumNewJob', $Job);
        
        return;
      }
      
      // Inherit to our parent if request wasn't handled
      parent::processRequest ($Message);
    }
    // }}}
    
    // {{{ processNotify
    /**
     * Process a Stratum-Notify
     * 
     * @param object $Message
     * 
     * @access protected
     * @return void
     **/
    protected function processNotify (object $Message) : void {
      // Check if this might be a job or inherit to our parent
      if (($this->getProtocolVersion () != $this::PROTOCOL_ETH_GETWORK) ||
          !($Job = Events\Stream\Stratum\Job::fromArray ($this, $Message->result)))
        parent::processNotify ($Message);
      else
        $this->___callback ('stratumNewJob', $Job);
    }
    // }}}
    
    
    // {{{ stratumSubscribed
    /**
     * Callback: We are subscribed to events at our server
     * 
     * @param array $Services
     * @param string $ExtraNonce1
     * @param int $ExtraNonce2Length
     * 
     * @access protected
     * @return void
     **/
    protected function stratumSubscribed ($Services, $ExtraNonce1, $ExtraNonce2Length) { }
    // }}}
    
    // {{{ stratumAuthenticated
    /**
     * Callback: We were authenticated at our server
     * 
     * @param string $Username
     * 
     * @access protected
     * @return void
     **/
    protected function stratumAuthenticated ($Username) { }
    // }}}
    
    // {{{ stratumSetDifficulty
    /**
     * Callback: Difficulty was changed
     * 
     * @param float $Difficulty
     * 
     * @access protected
     * @return void
     **/
    protected function stratumSetDifficulty ($Difficulty) { }
    // }}}
    
    // {{{ stratumNewJob
    /**
     * Callback: A new Job was received from our server
     * 
     * @param Events\Stream\Stratum\Job $Job
     * 
     * @access protected
     * @return void
     **/
    protected function stratumNewJob (Events\Stream\Stratum\Job $Job) { }
    // }}}
  }

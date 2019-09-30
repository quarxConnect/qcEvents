<?PHP

  /**
   * qcEvents - Validating Stratum-Server
   * Copyright (C) 2018 Bernd Holzmueller <bernd@quarxconnect.de>
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

  // Make sure GMP-Extension is available
  if (!extension_loaded ('gmp') && (!function_exists ('dl') || !dl ('gmp.so'))) {
    trigger_error ('Missing GMP-Extension, stratum-validation not working');

    return;
  }
  
  require_once ('qcEvents/Stream/Stratum.php');
  require_once ('qcEvents/Stream/Stratum/Job.php');
  
  class qcEvents_Server_Stratum extends qcEvents_Stream_Stratum {
    // Used nonces
    private static $Nonces = array ();
    private static $nextNonce = 0;
    
    /* Version-Identifier of our client */
    private $Version = null;
    
    /* Client is capable of changing the extranonce */
    private $canChangeExtranonce = false;
    
    /* Client is capable of Multi-Version-Mining (aka ASIC Boost) */
    private $canMineMultiVersion = false;
    
    /* Client supports changing the version-mask */
    private $canChangeVersionMask = false;
    
    /* Minimum difficulty requested by the client */
    private $minDifficulty = null;
    
    /* Mininig-Difficulty */
    private $Difficulty = 1;
    
    /* Allowed bits for version-mask */
    private $allowedVersionMask = 0x1fffe000;
    
    /* Version-Mask for Multi-Version-Mining */
    private $versionMask = null;
    
    // Extra-Nonce for this client
    private $ExtraNonce1 = null;
    
    // Length of Extra-Nonce-2 for this client
    private $ExtraNonce2Length = 4;
    
    // Active mining-jobs
    private $Jobs = array ();
    
    // Latest mining-job
    private $jobLatest = null;
    
    /* Pending work-requests */
    private $workRequests = array ();
    
    // Work done for unknown jobs
    private $workUnknown = 0;
    
    // Malformed work done
    private $workMalformed = 0;
    
    // Invalid work done
    private $workInvalid = 0;
    
    // Work done
    private $workDone = 0;
    
    // {{{ __destruct
    /**
     * Cleanup nonce-registry
     * 
     * @access friendly
     * @return void
     **/
    function __destruct () {
      if ($this->ExtraNonce1 === null)
        return;
      
      unset (self::$Nonces [$this->ExtraNonce1]);
      
      if (self::$nextNonce > $this->ExtraNonce1)
        self::$nextNonce = $this->ExtraNonce1;
    }
    // }}}
    
    // {{{ sendSubscribtion
    /**
     * Send positive response to a subscribtion-request
     * 
     * @param int $ID
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function sendSubscribtion ($ID) : qcEvents_Promise {
      // Respond depending on protocol-version
      $Version = $this->getProtocolVersion ();
      
      if ($Version == $this::PROTOCOL_ETH_STRATUM_NICEHASH) // Nicehash-Variant of stream for ethereum
        $Result = array (
          0 => array (
            0 => array (
              0 => 'mining.notify',
              1 => time (),
              2 => 'EthereumStratum/1.0.0',
            ),
          ),
          1 => sprintf ('%06x', $this->ExtraNonce1),
        );
      elseif ($Version != $this::PROTOCOL_ETH_STRATUM) // Anything that's not stratum for ethereum
        $Result = array (
          0 => array (
            0 => array (
              0 => 'mining.notify',
              1 => time (),
            ),
          ),
          1 => sprintf ('%08x', $this->ExtraNonce1),
          2 => $this->ExtraNonce2Length,
        );
      else // Stratum for ethereum
        $Result = true;
      
      // Send out the response
      return $this->sendMessage (array (
        'id' => $ID,
        'error' => null,
        'result' => $Result,
      ));
    }
    // }}}
    
    // {{{ getVersion
    /**
     * Retrive the client-identifier
     * 
     * @access public
     * @return string
     **/
    public function getVersion () {
      return $this->Version;
    }
    // }}}
    
    // {{{ setAlgorithm
    /**
     * Set mining-algorithm of our client
     * 
     * @param enum $Algorithm
     * 
     * @access public
     * @return bool
     **/
    public function setAlgorithm ($Algorithm) {
      // Set the algorithm
      if (!parent::setAlgorithm ($Algorithm))
        return false;
      
      // Indicate success
      return true;
    }
    // }}}
    
    // {{{ getDifficulty
    /**
     * Retrive the assigned mininig-difficulty
     * 
     * @access public
     * @return float
     **/
    public function getDifficulty () {
      return max ($this->minDifficulty, $this->Difficulty);
    }
    // }}}
    
    // {{{ setDifficulty
    /**
     * Set a new mining difficulty
     * 
     * @param float $Difficulty
     * 
     * @access public
     * @return void
     **/
    public function setDifficulty ($Difficulty) {
      // Store the new difficulty
      $this->Difficulty = (float)$Difficulty;
      
      // Forward the message
      if (($this->getProtocolVersion () != $this::PROTOCOL_ETH_GETWORK) &&
          ($this->getProtocolVersion () != $this::PROTOCOL_ETH_STRATUM)) // TODO: Check if eth-stratum really don't support this
        return $this->sendNotify (
          'mining.set_difficulty',
          array ($this->getDifficulty ())
        );
    }
    // }}}
    
    // {{{ addJob
    /**
     * Register a mining-job
     * 
     * @param qcEvents_Stream_Stratum_Job $Job
     * 
     * @access public
     * @return void
     **/
    public function addJob (qcEvents_Stream_Stratum_Job $Job) {
      // Reset jobs if needed
      if ($Job->isReset ())
        $this->Jobs = array ();
      
      // Register the job
      $this->Jobs [$this->jobLatest = $Job->getID ()] = $Job;
      
      // Raise a callback
      $this->___callback ('stratumWorkNew', $Job);
      
      // Forward the job
      foreach ($this->workRequests as $Key=>$Message)
        $this->sendMessage (array (
          'id' => $Message->id,
          'error' => null,
          'result' => $Job->toArray ($this::PROTOCOL_ETH_GETWORK),
        ));
      
      $this->workRequests = array ();
      
      if ($this->getProtocolVersion () == $this::PROTOCOL_ETH_GETWORK)
        return $this->sendMessage (array (
          'id' => 0,
          'result' => $Job->toArray ($this::PROTOCOL_ETH_GETWORK),
        ));
      
      return $this->sendNotify (
        'mining.notify',
        $Job->toArray ($this->getProtocolVersion ())
      );
    }
    // }}}
    
    // {{{ getExtraNonce1
    /**
     * Retrive the extra-nonce-1 for this client as binary string
     * 
     * @access public
     * @return int
     **/
    public function getExtraNonce1 () {
      // Assign an extra-nonce if we don't have one
      if ($this->ExtraNonce1 === null) {
        $maxNonce = ($this->getAlgorithm () == $this::ALGORITHM_ETH ? 0xffffff : (PHP_INT_SIZE > 4 ? 0xffffffff : 0x7fffffff));
        
        // Set the nonce
        if (defined ('QCEVENTS_STRAUM_NONCE_RANDOM') && QCEVENTS_STRAUM_NONCE_RANDOM) {
          do {
            $this->ExtraNonce1 = rand (0, $maxNonce);
          } while (isset (self::$Nonces [$this->ExtraNonce1]));
        } else {
          $this->ExtraNonce1 = self::$nextNonce;
          
          // Find next free nonce
          for ($i = self::$nextNonce + 1; $i <= $maxNonce; $i++)
            if (!isset (self::$Nonces [$i])) {
              self::$nextNonce = $i;
              break;
            }
          
          if (self::$nextNonce == $this->ExtraNonce1)
            for ($i = 0; $i < $this->ExtraNonce1; $i++)
              if (!isset (self::$Nonces [$i])) {
                self::$nextNonce = $i;
                
                break;
              }
        }
        
        // Claim the nonce
        self::$Nonces [$this->ExtraNonce1] = true;
      }
      
      // May only be 3 bytes in etherum/nicehash
      if ($this->getAlgorithm () == $this::ALGORITHM_ETH)
        return ($this->ExtraNonce1 & 0xffffff);
      
      return $this->ExtraNonce1;
    }
    // }}}
    
    // {{{ getExtraNonce2Length
    /**
     * Retrive the length of the client's extra-nonce-2
     * 
     * @access public
     * @return int
     **/
    public function getExtraNonce2Length () {
      return $this->ExtraNonce2Length;
    }
    // }}}
    
    // {{{ setExtraNonce
    /**
     * Change extra-nonces for this client
     * 
     * @param int $ExtraNonce1 (optional)
     * @param int $ExtraNonce2Length (optional)
     * @param bool $Notify (optional) Send notification to our client (default)
     * 
     * @access public
     * @return void
     **/
    public function setExtraNonce ($ExtraNonce1 = null, $ExtraNonce2Length = null, $Notify = true) {
      // Make sure any change is desired
      if (($ExtraNonce1 === null) && ($ExtraNonce2Length === null))
        return;
      
      // Change the values
      if ($ExtraNonce1 !== null) {
        unset (self::$Nonces [$this->ExtraNonce1]);
        
        $this->ExtraNonce1 = ($this->getProtocolVersion () == $this::PROTOCOL_ETH_STRATUM_NICEHASH ? (int)$ExtraNonce1 & 0xffffff : (int)$ExtraNonce1);
        
        self::$Nonces [$this->ExtraNonce1] = true;
      }
      
      if ($this->getProtocolVersion () == $this::PROTOCOL_ETH_STRATUM_NICEHASH)
        $this->ExtraNonce2Length = 5;
      elseif ($ExtraNonce2Length !== null)
        $this->ExtraNonce2Length = (int)$ExtraNonce2Length;
      
      // Raise a callback
      $this->___callback ('stratumExtraNonceChanged', $this->ExtraNonce1, $this->ExtraNonce2Length);
      
      // Notify the client
      if (!$Notify)
        return;
      
      if ($this->getProtocolVersion () == $this::PROTOCOL_ETH_STRATUM_NICEHASH)
        $this->sendNotify (
          'mining.set_extranonce',
          array (sprintf ('%06x', $this->ExtraNonce1))
        );
      elseif (($this->getProtocolVersion () != $this::PROTOCOL_ETH_GETWORK) &&
              ($this->getProtocolVersion () != $this::PROTOCOL_ETH_STRATUM))
        $this->sendNotify (
          'mining.set_extranonce',
          array (sprintf ('%08x', $this->ExtraNonce1), $this->ExtraNonce2Length)
        );
    }
    // }}}
    
    // {{{ setVersionMask
    /**
     * Try to change the version-mask for this miner
     * 
     * @param int $VersionMask
     * 
     * @access public
     * @return void
     **/
    public function setVersionMask ($VersionMask) {
      // Check if the miner is capable of this
      if (!$this->canChangeVersionMask)
        return;
      
      // Store the change locally
      $this->versionMask = $VersionMask;
      
      // Notify the client
      $this->sendNotify (
        'mining.set_version_mask',
        array (sprintf ('%08x', $this->versionMask))
      );
    }
    // }}}
    
    // {{{ bailOut
    /**
     * Verbosely print some information
     * 
     * @param string $Message
     * @param ... (optional)
     * 
     * @access public
     * @return void
     **/
    public function bailOut ($Msg) {
      // Make sure we have a stream assinged
      if (!is_object ($Stream = $this->getStream ()))
        return;
      
      // Patch in our information
      $Args = func_get_args ();
      $Args [0] = $Stream->getRemoteName ();
      array_unshift ($Args, '[%-6s] %-28s ' . $Msg . "\n", $this->getAlgorithm ());
      
      // Bail out
      call_user_func_array ('printf', $Args);
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
    protected function processRequest ($Message) {
      // Ethereum works sometimes differently
      if ($isEthereum = ($this->getAlgorithm () == $this::ALGORITHM_ETH)) {
        // Try to process a login
        if (($Message->method == 'eth_submitLogin') ||
            ($Message->method == 'eth_login')) {
          // Signal getwork-based implementation
          $this->setProtocolVersion ($this::PROTOCOL_ETH_GETWORK);
          
          // Check number of parameters
          if ((count ($Message->params) < 1) || (count ($Message->params) > 2))
            // Push back an error
            return $this->sendMessage (array (
              'id' => $Message->id,
              'error' => array (
                24,
                'Not authorized / message malformed'
              ),
              'result' => null,
            ));
          
          // Raise a callback for this
          return $this->___callback ('stratumAuthorize', $Message->id, $Message->params [0], (isset ($Message->params [1]) ? $Message->params [1] : null));
        } elseif ($Message->method == 'eth_getWork') {
          // Signal getwork-based implementation
          $this->setProtocolVersion ($this::PROTOCOL_ETH_GETWORK);
          
          // Check if we have a job ready
          if (($this->jobLatest === null) || !isset ($this->Jobs [$this->jobLatest])) {
            // Push to queue
            $this->workRequests [] = $Message;
            
            // Notify upper layer
            $this->___callback ('stratumWorkRequest');
            
            // Enqueue timeout
            return $this->getStream ()->getEventBase ()->addTimeout (20)->then (
              function () use ($Message) {
                // Check if the job was already done
                $Key = array_search ($Message, $this->workRequests, true);
                
                if ($Key === false)
                  return;
                
                unset ($this->workRequests [$Key]);
                
                // Check if we have work available now
                if (($this->jobLatest === null) || !isset ($this->Jobs [$this->jobLatest])) {
                  $this->bailOut (':: Rejecting getWork due to timeout');
                  
                  return $this->sendMessage (array (
                    'id' => $Message->id,
                    'error' => array (
                      0,
                      'Work not ready',
                    ),
                    'result' => null,
                  ));
                }
                
                // Forward the work
                return $this->sendMessage (array (
                  'id' => $Message->id,
                  'error' => null,
                  'result' => $this->Jobs [$this->jobLatest]->toArray ($this::PROTOCOL_ETH_GETWORK),
                ));
              }
            );
          }
          
          // Push back the latest job
          return $this->sendMessage (array (
            'id' => $Message->id,
            'error' => null,
            'result' => $this->Jobs [$this->jobLatest]->toArray ($this::PROTOCOL_ETH_GETWORK),
          ));
        } elseif ($Message->method == 'eth_submitWork') {
          // Signal getwork-based implementation
          $this->setProtocolVersion ($this::PROTOCOL_ETH_GETWORK);
          
          // Check if the job is known
          $JobID = $this->jobLatest;
          
          if (!isset ($this->Jobs [$JobID])) {
            // Increase a counter
            $this->workUnknown++;
            
            // Raise a callback for this
            $this->___callback ('stratumWorkUnknownJob', $JobID, $Message->params);
            
            // Push back a non-sense-message
            return $this->sendMessage (array (
              'id' => $Message->id,
              'error' => null,
              'result' => true,
            ));
          }
          
          // Check the input
          if ((count ($Message->params) != 3) ||
              (strlen ($Message->params [0]) != 18) ||
              (strlen ($Message->params [1]) != 66) ||
              (strlen ($Message->params [2]) != 66)) {
            // Increase a counter
            $this->workMalformed++;
            
            // Raise a callback for this
            $this->___callback ('stratumWorkMalformedJob', $this->Jobs [$JobID], $Message->params);
            
            // Push back a non-sense-message
            return $this->sendMessage (array (
              'id' => $Message->id,
              'error' => null,
              'result' => true,
            ));
          }
          
          # TODO: Validate the result here
          
          // Increase work-done-counter
          $this->workDone++;
          
          // Raise a callback
          return $this->___callback ('stratumWork', $Message->id, null, null, $this->Jobs [$JobID], $Message->params);
        } elseif ($Message->method == 'eth_submitHashrate') {
          // Signal getwork-based implementation
          $this->setProtocolVersion ($this::PROTOCOL_ETH_GETWORK);
          
          // Check the input
          if ((count ($Message->params) != 2) ||
              (strlen ($Message->params [0]) > 66) ||
              (strlen ($Message->params [1]) > 66))
            return $this->sendMessage (array (
              'id' => $Message->id,
              'error' => array (
                24,
                'Message malformed'
              ),
              'result' => null,
            ));
          
          // Raise callback for this
          $this->___callback ('stratumHashrate', $Message->params [0], $Message->params [1]);
          
          // Just ACK this
          return $this->sendMessage (array (
            'id' => $Message->id,
            'error' => null,
            'result' => true,
          ));
        }
        
        // Check if we were nailed to get-work-based protocol
        if ($this->getProtocolVersion () == $this::PROTOCOL_ETH_GETWORK) {
          $this->bailOut ('>> Unknown method %s', $Message->method);
          var_dump ($Message);
          
          return parent::processRequest ($Message);
        }
      }
      
      // Try to run the method
      if ($Message->method == 'mining.subscribe') {
        // Check if this was done before
        if ($this->Version !== null)
          return $this->sendMessage (array (
            'id' => $Message->id,
            'error' => array (
              25,
              'Duplicate subscribtion',
            ),
            'result' => null,
          ));
        
        // Treat ethereum special
        if ($isEthereum) {
          // Ethereum/NiceHash Stratum requires exactly 2 parameters (miner-version and protocol-version)
          if (count ($Message->params) == 2) {
            // Normal stratum treats the second parameter as session-resumption, we skip this
            $this->setProtocolVersion ($this::PROTOCOL_ETH_STRATUM_NICEHASH);
            $this->ExtraNonce2Length = 5;
            
            unset ($Message->params [1]);
          } else
            $this->setProtocolVersion ($this::PROTOCOL_ETH_STRATUM);
        }
        
        // Store client-UA if available
        if (count ($Message->params) > 0)
          $this->Version = $Message->params [0];
        
        // Raise a callback for this
        return $this->___callback ('stratumSubscribe', $Message->id, $this->Version, (count ($Message->params) > 1 ? $Message->params [1] : null));
      } elseif ($Message->method == 'mining.authorize') {
        // Check number of parameters
        if (count ($Message->params) < 2)
          // Push back an error
          return $this->sendMessage (array (
            'id' => $Message->id,
            'error' => array (
              24,
              'Not authorized / message malformed'
            ),
            'result' => null,
          ));
        
        // Raise a callback for this
        return $this->___callback ('stratumAuthorize', $Message->id, $Message->params [0], $Message->params [1]);
      } elseif ($Message->method == 'mining.submit') {
        // Check if the job is known
        $JobID = $Message->params [1];
        
        if (!isset ($this->Jobs [$JobID])) {
          // Increase a counter
          $this->workUnknown++;
          
          // Raise a callback for this
          $this->___callback ('stratumWorkUnknownJob', $JobID, $Message->params);
          
          // Push back an error
          return $this->sendMessage (array (
            'id' => $Message->id,
            'error' => array (
              21,
              'Job not found',
            ),
            'result' => null,
          ));
        }
        
        // Treat ethereum special
        if ($isEthereum) {
          // Convert work to Eth-Submit-Work-Format
          if ($this->getProtocolVersion () == $this::PROTOCOL_ETH_STRATUM_NICEHASH) {
            if ($Wellformed = (count ($Message->params) == 3))
              $Message->params = array (
                '0x' . $Message->params [2],
                '0x' . $this->Jobs [$JobID]->getHeaderHash (),
                '0x' // TODO!!!
              );
          } elseif ($this->getProtocolVersion () == $this::PROTOCOL_ETH_STRATUM) {
            if ($Wellformed = (count ($Message->params) == 5))
              $Message->params = array (
                '0x' . $Message->params [2],
                '0x' . $Message->params [3],
                '0x' . $Message->params [4],
              );
          } else
            $Wellformed = (count ($Message->params) == 3);
          
        // Check the input
        } else
          $Wellformed = (strlen ($Message->params [2]) == $this->ExtraNonce2Length * 2);
        
        if (!$Wellformed) {
          // Increase a counter
          $this->workMalformed++;
          
          // Raise a callback for this
          $this->___callback ('stratumWorkMalformedJob', $this->Jobs [$JobID], $Message->params);
          
          // Push back an error
          return $this->sendMessage (array (
            'id' => $Message->id,
            'error' => array (
              20,
              'Message malformed',
            ),
            'result' => null,
          ));
        }
        
        // Validate the work
        if (extension_loaded ('gmp'))
          switch ($this->getAlgorithm ()) {
            case $this::ALGORITHM_SHA256:
              $Valid = $this->validateSHA256 ($this->Jobs [$JobID], $Message, $Block); break;
            case $this::ALGORITHM_SCRYPT:
              $Valid = $this->validateScrypt ($this->Jobs [$JobID], $Message, $Block); break;
            case $this::ALGORITHM_X11: # TODO
            case $this::ALGORITHM_ETH: # TODO
            default:
              $Valid = $Block = null;
          }
        else
          $Valid = null;
        
        // Increase a counter
        if ($Valid !== false)
          $this->workDone++;
        else
          $this->workInvalid++;
        
        // Raise a callback
        return $this->___callback ('stratumWork', $Message->id, $Valid, $Block, $this->Jobs [$JobID], $Message->params);
      // Check for Miner-Configuration (BIP 310)
      } elseif ($Message->method == 'mining.configure') {
        $Params = array ();
        
        foreach ($Message->params [0] as $Extension)
          if ($Extension == 'version-rolling') {
            // Client is capable of multi-version-mining
            $this->canMineMultiVersion = true;
            $this->canChangeVersionMask = true;
            
            // Store and return the mask
            $Params ['version-rolling.mask'] = sprintf ('%08x', $this->versionMask = (hexdec ($Message->params [1]->{'version-rolling.mask'}) & $this->allowedVersionMask));
            
            // Mark the extension as processed
            $Params [$Extension] = true;
          } elseif ($Extension == 'minimum-difficulty') {
            // Check if the extension is negotiated correct
            if ($Params [$Extension] =
                (isset ($Message->params [1]->{'minimum-difficulty.value'}) &&
                 ($Message->params [1]->{'minimum-difficulty.value'} >= 0)))
              // Store the miners preference
              $this->minDifficulty = $Message->params [1]->{'minimum-difficulty.value'};
          
          } elseif ($Extension == 'subscribe-extranonce') {
            // Store the support for mining.set_extranonce
            $this->canChangeExtranonce = true;
            
            // Mark the extension as processed
            $Params [$Extension] = true;
          } else
            $Params [$Extension] = false;
        
        $this->sendMessage (array (
          'id' => $Message->id,
          'error' => null,
          'result' => $Params,
        ));
      } elseif ($Message->method == 'mining.multi_version') {
        // Client is capable of multi-version-mining
        $this->canMineMultiVersion = true;
        
        // Set some hard-coded version-mask for Bitmain miners
        if ($this->versionMask === null)
          $this->versionMask = 0x00c00000;
        
        // Inidcate success
        $this->sendMessage (array (
          'id' => $Message->id,
          'error' => null,
          'result' => array (4),
        ));
      } elseif ($Message->method == 'mining.extranonce.subscribe') {
        // Store the support for mining.set_extranonce
        $this->canChangeExtranonce = true;
        
        // Indicate success
        $this->sendMessage (array (
          'id' => $Message->id,
          'error' => null,
          'result' => true,
        ));
      } elseif ($Message->method == 'miner_file') {
        $this->sendMessage (array (
          'id' => $Message->id,
          'error' => array (
            42,
            'G0 aw4y 1337 haXXor :-P'
          ),
          'result' => null,
        ));
      } else {
        $this->bailOut ('>> Unknown method %s', $Message->method);
        var_dump ($Message);
      }

      parent::processRequest ($Message);
    }
    // }}}
    
    // {{{ rebuildBlockHeader
    /**
     * Rebuild block-header for a given job and mining-result
     * 
     * @param qcEvents_Stream_Stratum_Job $Job
     * @param object $Message
     * 
     * @access public
     * @return string
     **/
    public function rebuildBlockHeader (qcEvents_Stream_Stratum_Job $Job, $Message) {
      // Reassemle the coinbase
      $Coinbase = $this->rebuildCoinbase ($Job, $Message);
      
      // Generate Merkle-Root
      $Merkle = $Job->getMerkleTree ();
      
      foreach ($Merkle as $k=>$Hash)
        $Merkle [$k] = hex2bin ($Hash);
      
      array_unshift ($Merkle, hash ('sha256', hash ('sha256', $Coinbase, true), true));
      
      $Count = count ($Merkle);
      
      while ($Count-- > 1) {
        $Tx1 = array_shift ($Merkle);
        $Tx2 = array_shift ($Merkle);
        
        array_unshift ($Merkle, hash ('sha256', hash ('sha256', $Tx1 . $Tx2, true), true));
      }
      
      $Merkle = $Merkle [0];
      
      // Reassemble the version
      $Version = hexdec ($Job->getVersion ());
      
      if (($this->versionMask !== null) && (count ($Message->params) > 5)) {
        $versionBits = hexdec ($Message->params [5]);
        
        if (($versionBits & ~$this->versionMask) == 0)
          $Version = ($Version & ~$this->versionMask) | ($versionBits & $this->versionMask);
      }
      
      // Reassemble the header
      $Prev = hex2bin ($Job->getHeaderHash ());
      $Prev =
        substr ($Prev, 28, 4) . substr ($Prev, 24, 4) . substr ($Prev, 20, 4) . substr ($Prev, 16, 4) .
        substr ($Prev, 12, 4) . substr ($Prev, 8, 4) . substr ($Prev, 4, 4) . substr ($Prev, 0, 4);
      
      return pack (
        'Va32a32VVV',
        $Version,                         // Version
        strrev ($Prev),                   // Previous block hash
        $Merkle,                          // Merkle root hash
        hexdec ($Message->params [3]),    // Time of block
        hexdec ($Job->getDifficulty ()),  // Difficulty
        hexdec ($Message->params [4])     // Nonce
      );
    }
    // }}}
    
    // {{{ rebuildCoinbase
    /**
     * Rebuild a coinbase-transaction from a job and a work-result
     * 
     * @param qcEvents_Stream_Stratum_Job $Job
     * @param array $Message
     * 
     * @access public
     * @return string
     **/
    public function rebuildCoinbase (qcEvents_Stream_Stratum_Job $Job, $Message) {
      return hex2bin (sprintf (
        '%s%08x%0' . ($this->ExtraNonce2Length * 2) . 's%s',
        $Job->getCoinbaseStart (),
        $this->ExtraNonce1,
        $Message->params [2],
        $Job->getCoinbaseEnd ()
      ));
    }
    // }}}
    
    // {{{ validateSHA256
    /**
     * Validate an sha256-mining-job
     * 
     * @param qcEvents_Stream_Stratum_Job $Job
     * @param object $Message
     * @param bool $Block (optional)
     * 
     * @access private
     * @return bool
     **/
    private function validateSHA256 (qcEvents_Stream_Stratum_Job $Job, $Message, &$Block = false) {
      // Rebuild the block-header
      $Header = $this->rebuildBlockHeader ($Job, $Message);
      
      // Check difficulty
      static $diffBase = null;
      
      if ($diffBase === null)
        $diffBase = gmp_init ('0x00000000ffff0000000000000000000000000000000000000000000000000000');
      
      $Hash = gmp_import (strrev (hash ('sha256', hash ('sha256', $Header, true), true)));
      $Diff = gmp_div ($diffBase, gmp_init ((int)ceil ($this->getDifficulty ())));
      $Valid = (gmp_cmp ($Hash, $Diff) <= 0);
      
      // Check if a block was found
      $Max = hexdec ($Job->getDifficulty ());
      
      $Block = (gmp_cmp ($Hash, gmp_mul (gmp_init ($Max & 0xFFFFFF), gmp_pow (gmp_init (256), ((($Max >> 24) & 0xFF) - 3)))) <= 0);
      
      // Return the result
      return $Valid;
    }
    
    // {{{ validateScrypt
    /**
     * Validate an scrypt-mining-job
     * 
     * @param qcEvents_Stream_Stratum_Job $Job
     * @param object $Message
     * 
     * @access private
     * @return bool
     **/
    private function validateScrypt (qcEvents_Stream_Stratum_Job $Job, $Message, &$Block = false) {
      // Make sure we have Scrypt available
      require_once ('Scrypt/Scrypt.php');
      
      static $Scrypt  = null;
      
      if ($Scrypt === null)
        $Scrypt = new Scrypt (1024, 1, 1, 32);
      
      // Rebuild the block-header
      $Header = $this->rebuildBlockHeader ($Job, $Message);
      
      // Check difficulty
      static $diffBase = null;
      
      if ($diffBase === null)
        $diffBase = gmp_init ('0x0000ffff00000000000000000000000000000000000000000000000000000000'); // NOTE: This is for LTC, others may differ
      
      $Hash = gmp_import (strrev ($Scrypt->nosalt ($Header, 80)));
      $Diff = gmp_div ($diffBase, gmp_init ((int)ceil ($this->getDifficulty ())));
      $Valid = (gmp_cmp ($Hash, $Diff) <= 0);
      
      // Check if a block was found
      $Max = hexdec ($Job->getDifficulty ());
      $Block = (gmp_cmp ($Hash, gmp_mul (gmp_init ($Max & 0xFFFFFF), gmp_pow (gmp_init (256), ((($Max >> 24) & 0xFF) - 3)))) <= 0);
      
      // Return the result
      return $Valid;
    }
    // }}}
    
    
    // {{{ stratumSubscribe
    /**
     * Callback: Stratum-Client wants to subscribe
     * 
     * @param int $ID ID of JSON-RPC-Request
     * @param string $ClientVersion (optional) Client-Version-Identifier
     * @param string $ExtraNone (optional) ExtraNonce-Session requested by client
     * 
     * @access protected
     * @return void
     **/
    protected function stratumSubscribe ($ID, $ClientVersion = null, $ExtraNonce = null) { }
    // }}}
    
    // {{{ stratumAuthorize
    /**
     * Callback: Authorize-Request was received
     * 
     * @param int $ID Message-ID of the request
     * @param string $Username
     * @param string $Password
     * 
     * @access protected
     * @return void
     **/
    protected function stratumAuthorize ($ID, $Username, $Password) { }
    // }}}
    
    // {{{ stratumExtraNonceChanged
    /**
     * Callback: Extra-Nonces of the client were changed
     * 
     * @param int $ExtraNonce1
     * @param int $ExtraNonce2Length
     * 
     * @access protected
     * @return void
     **/
    protected function stratumExtraNonceChanged ($ExtraNonce1, $ExtraNonce2Length) { }
    // }}}
    
    // {{{ stratumWorkRequest
    /**
     * Callback: A request for work was received
     * 
     * @access protected
     * @returtn void
     **/
    protected function stratumWorkRequest () { }
    // }}}
    
    // {{{ stratumWork
    /**
     * Callback: Mining-Result was received
     * 
     * @param int $ID Message-ID of the request
     * @param bool $Valid Validation-Result (NULL if unsure)
     * @param bool $Block A block was found (NULL if unsure)
     * @param qcEvents_Stream_Stratum_Job $Job
     * @param array $Work
     * 
     * @access protected
     * @return void
     **/
    protected function stratumWork ($ID, $Valid, $Block, qcEvents_Stream_Stratum_Job $Job, array $Work) { }
    // }}}
    
    // {{{ stratumWorkUnknownJob
    /**
     * Callback: Work for an unknown job was received
     * 
     * @param int $JobID
     * @param array $Work
     * 
     * @access protected
     * @return void
     **/
    protected function stratumWorkUnknownJob ($JobID, $Work) { }
    // }}}
    
    // {{{ stratumWorkMalformedJob
    /**
     * Callback: A malformed work was received
     * 
     * @param qcEvents_Stream_Stratum_Job $Job
     * @param array $Work
     * 
     * @access protected
     * @return void
     **/
    protected function stratumWorkMalformedJob (qcEvents_Stream_Stratum_Job $Job, $Work) { }
    // }}}
    
    // {{{ stratumWorkNew
    /**
     * Callback: A new job was added
     * 
     * @param qcEvents_Stream_Stratum_Job $Job
     * 
     * @access protected
     * @return void
     **/
    protected function stratumWorkNew (qcEvents_Stream_Stratum_Job $Job) { }
    // }}}
    
    // {{{ stratumHashrate
    /**
     * Callback: Client notifies us about his hashrate
     * 
     * @param string $Hashrate
     * @param string $ClientID
     * 
     * @access protected
     * @return void
     **/
    protected function stratumHashrate ($Hashrate, $ClientID) { }
    // }}}
  }

?>
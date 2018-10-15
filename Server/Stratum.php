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
  require_once ('qcEvents/Interface/Timer.php');
  require_once ('qcEvents/Trait/Timer.php');
  
  class qcEvents_Server_Stratum extends qcEvents_Stream_Stratum implements qcEvents_Interface_Timer {
    use qcEvents_Trait_Timer;
    
    // Used nonces
    private static $Nonces = array ();
    private static $nextNonce = 0;
    
    // Version-Identifier of our client
    private $Version = null;
    
    // Mininig-Difficulty
    private $Difficulty = 1;
    
    // Extra-Nonce for this client
    private $ExtraNonce1 = null;
    
    // Length of Extra-Nonce-2 for this client
    private $ExtraNonce2Length = 4;
    
    // Active mining-jobs
    private $Jobs = array ();
    
    // Latest mining-job
    private $jobLatest = null;
    
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
      return $this->Difficulty;
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
          array ($this->Difficulty)
        );
    }
    // }}}
    
    // {{{ addJob
    /**
     * Register a mining-job
     * 
     * @param array $Job
     * 
     * @access public
     * @return void
     **/
    public function addJob ($Job) {
      // Preprocess ethereum-stuff
      if ($this->getAlgorithm () == $this::ALGORITHM_ETH) {
        /**
         * Ethereum-GetWork has 3 Elements (Headerhash, Seedhash, Target)
         * Ethereum-Stratum has 5 Elements (Job-ID, Headerhash, Seedhash, Target, Reset)
         * Ethereum-Stratum-Nicehash has 4 Elements (Job-ID, Seedhash, Headerhash, Reset)
         **/
        static $jobTypes = array (
          3 => self::PROTOCOL_ETH_GETWORK,
          4 => self::PROTOCOL_ETH_STRATUM_NICEHASH,
          5 => self::PROTOCOL_ETH_STRATUM,
        );
        
        if (!isset ($jobTypes [count ($Job)])) {
          trigger_error ('Invlaid Job-Format');
          
          return false;
        }
        
        $inFormat = $jobTypes [count ($Job)];
        
        // Check wheter to convert the job
        if ($inFormat != $this->getProtocolVersion ()) {
          // Convert Job to eth-stratum
          if ($inFormat == $this::PROTOCOL_ETH_STRATUM_NICEHASH)
            $Job = array (
              $Job [0],
              $Job [2],
              $Job [1],
              gmp_strval (gmp_div (gmp_init ('00000000ffff0000000000000000000000000000000000000000000000000000', 16), gmp_init ($this->Difficulty)), 16),
              $Job [3],
            );
          elseif ($inFormat == $this::PROTOCOL_ETH_GETWORK)
            $Job = array (
              substr ($Job [0], 2),
              substr ($Job [0], 2),
              substr ($Job [1], 2),
              substr ($Job [2], 2),
              true, // TODO: How to detect if we have to reset?!
            );
          
          // Convert back to desired format
          if ($this->getProtocolVersion () == $this::PROTOCOL_ETH_STRATUM_NICEHASH)
            $Job = array (
              $Job [0],
              $Job [2],
              $Job [1],
              $Job [4],
            );
            // TODO: Retarget difficulty here
          elseif ($this->getProtocolVersion () == $this::PROTOCOL_ETH_GETWORK)
            $Job = array (
              '0x' . $Job [1],
              '0x' . $Job [2],
              '0x' . $Job [3],
            );
        }
      }
      
      // Check wheter to reset
      if ($isEthereumGetWork = ($this->getProtocolVersion () == $this::PROTOCOL_ETH_GETWORK))
        $Reset = true;
      elseif ($this->getProtocolVersion () == $this::PROTOCOL_ETH_STRATUM)
        $Reset = (isset ($Job [4]) && $Job [4]);
      elseif ($this->getProtocolVersion () == $this::PROTOCOL_ETH_STRATUM_NICEHASH)
        $Reset = (isset ($Job [3]) && $Job [3]);
      elseif (isset ($Job [8]))
        $Reset = $Job [8];
      else
        $Reset = false;
      
      // Reset jobs if needed
      if ($Reset)
        $this->Jobs = array ();
      
      // Register the job
      $this->Jobs [$Job [0]] = $Job;
      $this->jobLatest = $Job [0];
      
      // Raise a callback
      $this->___callback ('stratumWorkNew', $Job, $Reset);
      
      // Forward the job
      if ($isEthereumGetWork)
        return $this->sendMessage (array (
          'id' => 0,
          'result' => $Job,
        ));
      
      return $this->sendNotify (
        'mining.notify',
        $Job
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
     * 
     * @access public
     * @return void
     **/
    public function setExtraNonce ($ExtraNonce1 = null, $ExtraNonce2Length = null) {
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
    
    public function sendMessage ($Message, array $Extra = null) {
      return parent::sendMessage ($Message, $Extra);
    }
    
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
          if (($this->jobLatest === null) || !isset ($this->Jobs [$this->jobLatest]))
            return $this->addTimer (3, false, function () use ($Message) {
              if (($this->jobLatest === null) || !isset ($this->Jobs [$this->jobLatest]))
                return $this->sendMessage (array (
                  'id' => $Message->id,
                  'error' => array (
                    0,
                    'Work not ready',
                  ),
                  'result' => null,
                ));
              
              return $this->sendMessage (array (
                'id' => $Message->id,
                'error' => null,
                'result' => array (
                  $this->Jobs [$this->jobLatest][0],
                  $this->Jobs [$this->jobLatest][1],
                  $this->Jobs [$this->jobLatest][2],
                ),
              ));
            });
          
          // Push back the latest job
          return $this->sendMessage (array (
            'id' => $Message->id,
            'error' => null,
            'result' => array (
              $this->Jobs [$this->jobLatest][0],
              $this->Jobs [$this->jobLatest][1],
              $this->Jobs [$this->jobLatest][2],
            ),
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
          // Convert work to  Eth-Submit-Work-Format
          if ($this->getProtocolVersion () == $this::PROTOCOL_ETH_STRATUM_NICEHASH) {
            if ($Wellformed = (count ($Message->params) == 3))
              $Message->params = array (
                '0x' . $Message->params [2],
                '0x' . $this->Jobs [$JobID][2],
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
      } elseif (($Message->method == 'mining.extranonce.subscribe') || ($Message->method == 'mining.multi_version')) {
        # TODO
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
     * @param array $Job
     * @param object $Message
     * 
     * @access private
     * @return string
     **/
    private function rebuildBlockHeader ($Job, $Message) {
      // Reassemle the coinbase
      $Coinbase = hex2bin (sprintf (
        '%s%08x%0' . ($this->ExtraNonce2Length * 2) . 's%s',
        $Job [2],
        $this->ExtraNonce1,
        $Message->params [2],
        $Job [3]
      ));
      
      // Generate Merkle-Root
      $Merkle = $Job [4];
      
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
      
      // Reassemble the header
      $Prev = hex2bin ($Job [1]);
      $Prev =
        substr ($Prev, 28, 4) . substr ($Prev, 24, 4) . substr ($Prev, 20, 4) . substr ($Prev, 16, 4) .
        substr ($Prev, 12, 4) . substr ($Prev, 8, 4) . substr ($Prev, 4, 4) . substr ($Prev, 0, 4);
      
      return pack (
        'Va32a32VVV',
        hexdec ($Job [5]),                // Version
        strrev ($Prev),                   // Previous block hash
        $Merkle,                          // Merkle root hash
        hexdec ($Message->params [3]),    // Time of block
        hexdec ($Job [6]),                // Difficulty
        hexdec ($Message->params [4])     // Nonce
      );
    }
    // }}}
    
    // {{{ validateSHA256
    /**
     * Validate an sha256-mining-job
     * 
     * @param array $Job
     * @param object $Message
     * @param bool $Block (optional)
     * 
     * @access private
     * @return bool
     **/
    private function validateSHA256 ($Job, $Message, &$Block = false) {
      // Rebuild the block-header
      $Header = $this->rebuildBlockHeader ($Job, $Message);
      
      // Check difficulty
      static $diffBase = null;
      
      if ($diffBase === null)
        $diffBase = gmp_init ('0x00000000ffff0000000000000000000000000000000000000000000000000000');
      
      $Hash = gmp_import (strrev (hash ('sha256', hash ('sha256', $Header, true), true)));
      $Diff = gmp_div ($diffBase, gmp_init ((int)ceil ($this->Difficulty)));
      $Valid = (gmp_cmp ($Hash, $Diff) <= 0);
      
      // Check if a block was found
      $Max = hexdec ($Job [6]);
      
      $Block = (gmp_cmp ($Hash, gmp_mul (gmp_init ($Max & 0xFFFFFF), gmp_pow (gmp_init (256), ((($Max >> 24) & 0xFF) - 3)))) <= 0);
      
      if ($Block)
        printf ("%s\n%064s\n%064s\n", $Job [6], gmp_strval ($Hash, 16), gmp_strval (gmp_mul (gmp_init ($Max & 0xFFFFFF), gmp_pow (gmp_init (256), ((($Max >> 24) & 0xFF) - 3))), 16));
      
      // Return the result
      return $Valid;
    }
    
    // {{{ validateScrypt
    /**
     * Validate an scrypt-mining-job
     * 
     * @param array $Job
     * @param object $Message
     * 
     * @access private
     * @return bool
     **/
    private function validateScrypt ($Job, $Message, &$Block = false) {
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
      $Diff = gmp_div ($diffBase, gmp_init ((int)ceil ($this->Difficulty)));
      $Valid = (gmp_cmp ($Hash, $Diff) <= 0);
      
      // Check if a block was found
      $Max = hexdec ($Job [6]);
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
    
    // {{{ stratumWork
    /**
     * Callback: Mining-Result was received
     * 
     * @param int $ID Message-ID of the request
     * @param bool $Valid Validation-Result (NULL if unsure)
     * @param bool $Block A block was found (NULL if unsure)
     * @param array $Job
     * @param array $Work
     * 
     * @access protected
     * @return void
     **/
    protected function stratumWork ($ID, $Valid, $Block, array $Job, array $Work) { }
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
     * @param array $Job
     * @param array $Work
     * 
     * @access protected
     * @return void
     **/
    protected function stratumWorkMalformedJob ($Job, $Work) { }
    // }}}
    
    // {{{ stratumWorkNew
    /**
     * Callback: A new job was added
     * 
     * @param array $Job
     * @param bool $Reset (optional)
     * 
     * @access protected
     * @return void
     **/
    protected function stratumWorkNew ($Job, $Reset = false) { }
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
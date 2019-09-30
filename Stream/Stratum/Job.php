<?PHP

  /**
   * qcEvents - Stratum Job
   * Copyright (C) 2019 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  class qcEvents_Stream_Stratum_Job {
    /* The ID of tis job */
    private $ID = null;
    
    /* Hash of previous header */
    private $Headerhash = null;
    
    /* First part of coinbase */
    private $CoinbaseStart = null;
    
    /* Last part of coinbase */
    private $CoinbaseEnd = null;
    
    /* Seedhash for ethereum */
    private $Seedhash = null;
    
    /* Partly generated merkle-tree */
    private $MerkleTree = null;
    
    /* Version of block-header */
    private $Version = null;
    
    /* Target-Difficulty */
    private $Difficulty = null;
    
    /* Current time */
    private $Time = null;
    
    /* This job invalidates any previous jobs */
    private $Reset = false;
    
    // {{{ fromArray
    /**
     * Create a new Stratum-Job from a given array
     * 
     * @param qcEvents_Stream_Stratum $Stratum Instance of the stratum-stream the job is for
     * @param array $Job All informations regarding the job
     * @param enum $Format (optional) Format of the job, if not given informations from stream will be used
     * 
     * @access public
     * @return qcEvents_Stream_Stratum_Job
     **/
    public static function fromArray (qcEvents_Stream_Stratum $Stratum, array $Job, $Format = null) : ?qcEvents_Stream_Stratum_Job {
      // Prepare the job
      $Instance = new static ();
      
      // Check wheter to pre-process a Job for ethereum
      if ($Stratum->getAlgorithm () == $Stratum::ALGORITHM_ETH) {
        /**
         * Ethereum-GetWork has 3 Elements          (        Headerhash, Seedhash, Target       )
         * Ethereum-Stratum has 5 Elements          (Job-ID, Headerhash, Seedhash, Target, Reset)
         * Ethereum-Stratum-Nicehash has 4 Elements (Job-ID, Seedhash, Headerhash,         Reset)
         **/
        static $jobTypes = array (
          3 => qcEvents_Stream_Stratum::PROTOCOL_ETH_GETWORK,
          5 => qcEvents_Stream_Stratum::PROTOCOL_ETH_STRATUM,
          4 => qcEvents_Stream_Stratum::PROTOCOL_ETH_STRATUM_NICEHASH,
        );
        
        // Make sure we know how to process this job
        if (!isset ($jobTypes [count ($Job)])) {
          trigger_error ('Invalid Job-Format');
          
          return null;
        }
        
        // Check wheter to convert the job
        $inFormat = $jobTypes [count ($Job)];
        
        if ($inFormat == qcEvents_Stream_Stratum::PROTOCOL_ETH_GETWORK) {
          $Instance->ID = $Job [0];
          $Instance->Headerhash = $Job [0];
          $Instance->Seedhash = $Job [1];
          $Instance->Difficulty = $Job [2];
          $Instance->Reset = true;
        } elseif ($inFormat == qcEvents_Stream_Stratum::PROTOCOL_ETH_STRATUM) {
          $Instance->ID = $Job [0];
          $Instance->Headerhash = $Job [1];
          $Instance->Seedhash = $Job [2];
          $Instance->Difficulty = $Job [3];
          $Instance->Reset = $Job [4];
        } elseif ($inFormat == qcEvents_Stream_Stratum::PROTOCOL_ETH_STRATUM_NICEHASH) {
          $Instance->ID = $Job [0];
          $Instance->Headerhash = $Job [2];
          $Instance->Seedhash = $Job [1];
          $Instance->Difficulty = gmp_strval (gmp_div (gmp_init ('00000000ffff0000000000000000000000000000000000000000000000000000', 16), gmp_init ($Stratum->getDifficulty ())), 16);
          $Instance->Reset = $Job [3];
        } else
          trigger_error ('You should never see this hint!');
        
        return $Instance;
      }
      
      $Instance->ID = $Job [0];
      $Instance->Headerhash = $Job [1];
      $Instance->CoinbaseStart = $Job [2];
      $Instance->CoinbaseEnd = $Job [3];
      $Instance->MerkleTree = $Job [4];
      $Instance->Version = $Job [5];
      $Instance->Difficulty = $Job [6];
      $Instance->Time = $Job [7];
      $Instance->Reset = (isset ($Job [8]) && !!$Job [8]);
      
      return $Instance;
    }
    // }}}
    
    
    // {{{ isReset
    /**
     * Check if this job resets/invalidates any previous job
     * 
     * @access public
     * @return bool
     **/
    public function isReset () {
      return $this->Reset;
    }
    // }}}
    
    // {{{ getID
    /**
     * Retrive the ID of this job
     * 
     * @access public
     * @return string
     **/
    public function getID () {
      return $this->ID;
    }
    // }}}
    
    // {{{ getHeaderHash
    /**
     * Retrive the hash of the previous header
     * 
     * @access public
     * @return string
     **/
    public function getHeaderHash () {
      return $this->Headerhash;
    }
    // }}}
    
    // {{{ getCoinbaseStart
    /**
     * Retrive the first bytes of the coinbase
     * 
     * @access public
     * @return string
     **/
    public function getCoinbaseStart () {
      return $this->CoinbaseStart;
    }
    // }}}
    
    // {{{ getCoinbaseEnd
    /**
     * Retrive the last bytes of the coinbase
     * 
     * @access public
     * @return string
     **/
    public function getCoinbaseEnd () {
      return $this->CoinbaseEnd;
    }
    // }}}
    
    // {{{ getMerkleTree
    /**
     * Retrive the partly build merke-tree
     * 
     * @access public
     * @return array
     **/
    public function getMerkleTree () {
      return $this->MerkleTree;
    }
    // }}}
    
    // {{{ getVersion
    /**
     * Retrive the block-version to build
     * 
     * @access public
     * @return int
     **/
    public function getVersion () {
      return $this->Version;
    }
    // }}}
    
    // {{{ getDifficulty
    /**
     * Retrive the target-difficulty of this job
     * 
     * @ccess public
     * @return string
     **/
    public function getDifficulty () {
      return $this->Difficulty;
    }
    // }}}
    
    // {{{ toArray
    /**
     * Convert this job to an arrray
     * 
     * @param enum $Protocol (optional) Define the protocol to use
     * 
     * @access public
     * @return array
     **/
    public function toArray ($Protocol = null) {
      // Return a GetWork-Job for ethereum
      if ($Protocol == qcEvents_Stream_Stratum::PROTOCOL_ETH_GETWORK)
        return array (
          $this->Headerhash,
          $this->Seedhash,
          $this->Difficulty,
        );
      
      // Return Stratum for ethereum
      if ($Protocol == qcEvents_Stream_Stratum::PROTOCOL_ETH_STRATUM)
        return array (
          $this->ID,
          $this->Headerhash,
          $this->Seedhash,
          $this->Difficulty,
          $this->Reset
        );
      
      // Return Stratum for Nicehash-Ethereum
      if ($Protocol == qcEvents_Stream_Stratum::PROTOCOL_ETH_STRATUM_NICEHASH)
        return array (
          $this->ID,
          $this->Seedhash,
          $this->Headerhash,
          $this->Reset,
        );
      
      return array (
        $this->ID,
        $this->Headerhash,
        $this->CoinbaseStart,
        $this->CoinbaseEnd,
        $this->MerkleTree,
        $this->Version,
        $this->Difficulty,
        $this->Time,
        $this->Reset,
      );
    }
    // }}}
  }

?>
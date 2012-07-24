<?PHP

  /**
   * qcEvents - Runtime Cache
   * Copyright (C) 2012 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Timer.php');
  
  /**
   * Cache
   * -----
   * Simple Key-Value-Cache with TTL
   * 
   * @class qcEvents_Cache
   * @extends qcEvents_Timer
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Cache extends qcEvents_Timer {
    private $defaultTTL = 60;
    
    private $Values = array ();
    private $TTLs = array ();
    private $Timers = array ();
    
    // {{{ addValue
    /**
     * Store an item on the cache
     * 
     * @param string $Key
     * @param mixed $Value
     * @param int $TTL (optional)
     * @param bool $dontOverwrite (optional) Do not overwrite if Key is already present
     * 
     * @access public
     * @return bool
     **/
    public function addValue ($Key, $Value, $TTL = null, $dontOverwrite = false) {
      // Check if the value is already present and an overwrite isn't wanted
      if ($dontOverwrite && isset ($this->Values [$Key]))
        return false;
      
      if ($TTL < 1)
        $TTL = $this->defaultTTL;
      
      // Store the item
      $this->Values [$Key] = $Value;
      $this->TTLs [$Key] = $TTL;
      
      // Setup the timer
      $this->addKeyTimer ($Key, $TTL);
      
      // Fire a callback
      $this->___callback ('addedValue', $Key, $Value, $TTL);
      
      return true;
    }
    // }}}
    
    // {{{ addKeyTimer
    /**
     * 
     **/
    private function addKeyTimer ($Key, $TTL) {
      $Timer = time () + $TTL;
      
      if (!isset ($this->Timers [$Timer])) {  
        $this->Timers [$Timer] = array ($Key);
        $this->addTimeout ($TTL, false);
        
        ksort ($this->Timers);
      } else
        $this->Timers [$Timer][] = $Key;
    }
    // }}}
    
    // {{{ haveKey
    /**
     * Check if we know a value by key
     * 
     * @param string $Key
     * 
     * @access public
     * @return bool
     **/
    public function haveKey ($Key) {
      return isset ($this->Values [$Key]);
    }
    // }}}
    
    // {{{ haveValue
    /**
     * Check if we know a value
     * 
     * @param mixed $Value
     * @param bool $Strict (optional)
     * 
     * @access public
     * @return bool
     **/
    public function haveValue ($Value, $Strict = false) {
      return in_array ($Value, $this->Values, $Strict);
    }
    // }}}
    
    // {{{ getValue
    /**
     * Retrive a value for a key
     * 
     * @param string $Key
     * 
     * @access public
     * @return mixed
     **/
    public function getValue ($Key) {
      if (isset ($this->Values [$Key]))
        return $this->Values [$Key];
      
      return null;
    }
    // }}}
    
    // {{{ timerEvent
    /**
     * Handle a timer-event
     * 
     * @access public
     * @return void
     **/
    public function timerEvent () {
      // Retrive the current time
      $T = time ();
      
      // Check for expired keys
      foreach ($this->Timers  as $Time=>$Keys) {
        if ($Time > $T)
          break;
        
        foreach ($Keys as $Key) {
          $nTTL = $this->___callback ('renewTTL', $Key, $this->Values [$Key], $this->TTLs [$Key]);
          
          if ($nTTL > 0) {
            $this->TTLs [$Key] = $nTTL;
            $this->addKeyTimer ($Key, $nTTL);
          } else {
            $this->___callback ('removedValue', $Key, $this->Values [$Key], $this->TTLs [$Key]);
            unset ($this->Values [$Key], $this->TTLs [$Key]);
          }
        }
        
        unset ($this->Timers [$Time]);
      }
    }
    // }}}
    
    // {{{ renewTTL
    /**
     * Callback: Check wheter to renew the TTL for a given key-value-pair
     * 
     * @param string $Key
     * @param mixed $Value
     * @param int $oldTTL
     * 
     * @access protected
     * @return int If n>0 ask again in n seconds
     **/
    protected function renewTTL ($Key, $Value, $TTL) { }
    // }}}
    
    // {{{ addedValue
    /**
     * Callback: A Key-Value-Pair was added to the cache
     * 
     * @param string $Key
     * @param mixed $Value
     * @param int $TTL
     * 
     * @access protected
     * @return void
     **/
    protected function addedValue ($Key, $Value, $TTL) { }
    // }}}
    
    // {{{ removedValue
    /**
     * Callback: A Key-Value-Pair was removed from the cache
     * 
     * @param string $Key
     * @param mixed $Value
     * @param int $TTL
     * 
     * @access protected
     * @return void
     **/
    protected function removedValue ($Key, $Value, $TTL) { }
    // }}}
  }

?>
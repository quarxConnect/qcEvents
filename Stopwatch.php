<?PHP

  /**
   * qcEvents - Stopwatch
   * Copyright (C) 2014-2020 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  class qcEvents_Stopwatch {
    /* Overall generations */
    private static $generations = 0;
    
    /* Generation of this stop-watch */
    private $generation = 0;
    
    /* Time when this stopwatch was started */
    private $startTime = 0;
    
    /* Time when last round was finished */
    private $roundTime = 0;
    
    // {{{ __construct
    /**
     * Create a new stop-watch
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      $this->generation = ++self::$generations;
      $this->reset ();
    }
    // }}}
    
    // {{{ __destruct
    /**
     * Decrese number of generations
     * 
     * @access friendly
     * @return void
     **/
    function __destruct () {
      self::$generations--;
    }
    // }}}
    
    // {{{ __toString
    /**
     * Convert this stop-watch into a user-friendly string
     * 
     * @access friendly
     * @return string
     **/
    function __toString () {
      return sprintf ('%10.5f ms / %10.5f ms', $this->getTotalTime () * 1000, $this->getRoundTime () * 1000);
    }
    // }}}
    
    // {{{ __invoke
    /**
     * Output stop-watch-times with an explaining message
     * 
     * @param string $strMessage (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __invoke ($strMessage = 'stopWatch()') {
      printf ('%-16s %s' . "\n", str_repeat ('  ', $this->generation - 1) . $strMessage, $this->__toString ());
    }
    // }}}
    
    // {{{ getTotalTime
    /**
     * Retrive the total time this stop-watch is running
     * 
     * @access public
     * @return float
     **/
    public function getTotalTime () {
      return microtime (true) - $this->startTime;
    }
    // }}}
    
    // {{{ getRoundTime
    /**
     * Get time of current round and start a new one
     * 
     * @access public
     * @return float
     **/
    public function getRoundTime () {
      $now = microtime (true);
      $result = $now - $this->roundTime;
      $this->roundTime = $now;
      
      return $result;
    }
    // }}}
    
    // {{{ reset
    /**
     * Reset the stopwatch
     * 
     * @access public
     * @return void
     **/
    public function reset () {
      $this->startTime = $this->roundTime = microtime (true);
    }
    // }}}
  }

?>
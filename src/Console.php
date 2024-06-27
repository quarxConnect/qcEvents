<?php

  /**
   * qcEvents - I/O-Stream for Console
   * Copyright (C) 2020-2022 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  namespace quarxConnect\Events;
  
  class Console extends IOStream {
    // {{{ __construct
    /**
     * Create a new console-reader/writer
     * 
     * @param Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Base $eventBase) {
      // Inherit to our parent
      parent::__construct ($eventBase);
      
      // Set stream-FDs
      $this->setStreamFDs (STDIN, STDOUT);
    }
    // }}}
    
    // {{{ ___read
    /**
     * Read from the underlying stream
     * 
     * @param int $readLength (optional)
     * 
     * @access protected
     * @return string   
     **/
    protected function ___read (int $readLength = null) : ?string {
      return $this->___readGeneric ($readLength);
    }
    // }}}

    // {{{ ___write
    /**
     * Write to the underlying stream 
     * 
     * @param string $writeData
     * 
     * @access protected
     * @return int The number of bytes that have been written
     **/
    protected function ___write (string $writeData) : ?int {
      return $this->___writeGeneric ($writeData);
    }
    // }}}
    
    // {{{ ___close
    /**
     * Close the stream at the handler
     * 
     * @param mixed $closeFD (optional)
     * 
     * @access protected
     * @return bool
     **/
    protected function ___close ($closeFD = null) : bool {
      if ($closeFD === null)
        return false;
      
      return $this->___closeGeneric ($closeFD);
    }
    // }}}
  }

<?PHP

  /**
   * qcEvents - CSV-Stream
   * Copyright (C) 2016 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Interface/Consumer.php');
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Promise.php');
  
  /**
   * CSV-Stream
   * ----------
   * Read Comma-Separated Values from a stream and/or write them back
   * 
   * @class qcEvents_Stream_CSV
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Stream_CSV extends qcEvents_Hookable implements qcEvents_Interface_Consumer, qcEvents_Interface_Stream_Consumer {
    /* Separator of fields on CSV-Stream */
    private $csvSeparator = ',';
    
    /* Enclosure for field-values */
    private $csvEnclosure = '"';
    
    /* End-of-Record-marker */
    private $csvLineEnding = "\r\n";
    
    /* Use CSV-Header */
    private $csvHeader = true;
    
    /* Internal buffer */
    private $csvBuffer = '';
    private $csvBufferLength = 0;
    
    /* Currently parsed record */
    private $csvRecord = array ();
    
    // {{{ __construct
    /**
     * Create a new CSV-stream
     * 
     * @param string $Separator (optional) Separator-Character on stream
     * @param string $Enclosure (optional) Enclosure-Character on stream
     * @param string $LineEnd (optional) Line-Ending-Character on stream
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Separator = null, $Enclosure = null, $LineEnd = null) {
      if ($Separator !== null)
        $this->csvSeparator = $Separator;
      
      if ($Enclosure !== null)
        $this->csvEnclosure = $Enclosure;
      
      if ($LineEnd !== null)
        $this->csvLineEnding = $LineEnd;
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $Data
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return void
     **/
    public function consume ($Data, qcEvents_Interface_Source $Source) {
      // Append to internal buffer
      $this->csvBuffer .= $Data;
      $this->csvBufferLength += strlen ($Data);
      unset ($Data);
      
      $csvOffset = 0;
      $csvSeparatorLength = strlen ($this->csvSeparator);
      $csvLineEndLength = strlen ($this->csvLineEnding);
      $csvEnclosureLength = strlen ($this->csvEnclosure);
      
      while ($csvOffset < $this->csvBufferLength) {
        // Check if the field is escaped
        if (substr ($this->csvBuffer, $csvOffset, $csvEnclosureLength) == $this->csvEnclosure) {
          // Find next occurence of enclosure
          $csvEnclosureOffset = $csvOffset + $csvEnclosureLength;
          
          do {
            // Find the next enclosure (if none was found, skip here)
            if (($csvEnclosureOffset = strpos ($this->csvBuffer, $this->csvEnclosure, $csvEnclosureOffset)) === false)
              break (2);
            
            // Check if its just an escaped enclosure
            if (substr ($this->csvBuffer, $csvEnclosureOffset + $csvEnclosureLength, $csvEnclosureLength) == $this->csvEnclosure) {
              $csvEnclosureOffset += $csvEnclosureLength + $csvEnclosureLength;
              
              continue;
            }
            
            break;
          } while ($csvEnclosureOffset < $this->csvBufferLength);
          
          // Read the entire field
          $this->csvRecord [] = str_replace ($this->csvEnclosure . $this->csvEnclosure, $this->csvEnclosure, substr ($this->csvBuffer, $csvOffset + $csvEnclosureLength, $csvEnclosureOffset - $csvOffset - $csvEnclosureLength));
          
          // Move the pointer
          $csvOffset = $csvEnclosureOffset + $csvEnclosureLength;
          
          unset ($csvEnclosureOffset);
          
          // Check for line-ending
          if (substr ($this->csvBuffer, $csvOffset, $csvLineEndLength) == $this->csvLineEnding) {
            $csvOffset += $csvLineEndLength;
            
            $this->csvPushRecord ();
          } elseif (substr ($this->csvBuffer, $csvOffset, $csvSeparatorLength) != $this->csvSeparator)
            # TODO: How to handle this?
            trigger_error ('No separator next to enclosure');
            
          else
            $csvOffset += $csvSeparatorLength;
          
        // Check if there is the end of a normal field on the buffer
        } else {
          // Find relevant positions
          $p1 = strpos ($this->csvBuffer, $this->csvSeparator, $csvOffset);
          $p2 = strpos ($this->csvBuffer, $this->csvLineEnding, $csvOffset);
          
          // A separator was found before line-ending
          if (($p1 !== false) && (($p2 === false) || ($p1 < $p2))) {
            $this->csvRecord [] = substr ($this->csvBuffer, $csvOffset, $p1 - $csvOffset);
            $csvOffset = $p1 + $csvSeparatorLength;
            
          // A line-ending was found
          } elseif ($p2 !== false) {
            $this->csvRecord [] = substr ($this->csvBuffer, $csvOffset, $p2 - $csvOffset);
            $csvOffset = $p2 + $csvLineEndLength;
            
            // Run callback and reset
            $this->csvPushRecord ();
          
          // Nothing relevant was found
          } else
            break;
        }
      }
      
      // Truncate the buffer
      $this->csvBuffer = substr ($this->csvBuffer, $csvOffset);
      $this->csvBufferLength -= $csvOffset;
    }
    // }}}
    
    // {{{ csvPushRecord
    /**
     * Push the currently cached record to the callback
     * 
     * @access private
     * @return void
     **/
    private function csvPushRecord () {
      // Peek the record and reset
      $Record = $this->csvRecord; 
      $this->csvRecord = array ();
      
      // Ignore empty records
      if (($Length = count ($Record)) == 0)
        return;
      
      // Check wheter to use this as a header
      if ($this->csvHeader === true)
        return $this->___callback ('csvHeaderRecevied', $this->csvHeader = $Record);
      
      // Check wheter to apply a header
      if ($this->csvHeader !== false) {
        $hLength = count ($this->csvHeader);
        
        // Add empty columns to record
        if ($Length < $hLength) {
          for (; $Length < $hLength; $Length++)
            $Record [] = null;
        
        // Truncate columns from record
        } elseif ($Length > $hLength) {
          trigger_error ('Truncating record to match headers length');
          
          $Record = array_slice ($Record, 0, $hLength);
        }
        
        $Record = array_combine ($this->csvHeader, $Record);
      }
      
      // Run the callback
      $this->___callback ('csvRecordReceived', $Record);
    }
    // }}}
    
    // {{{ initConsumer
    /**
     * Setup ourself to consume data from a source
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     *  
     *   function (qcEvents_Interface_Consumer $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return callable
     **/
    public function initConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      // Assign the source
      $this->Source = $Source;
      
      // Run the callback
      $this->___raiseCallback ($Callback, true, $Private);
    }
    // }}}
    
    // {{{ initStreamConsumer
    /** 
     * Setup ourself to consume data from a stream
     *    
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function initStreamConsumer (qcEvents_Interface_Stream $Source) : qcEvents_Promise {
      // Assign the source
      $this->Source = $Source;
      
      // Return solved promise
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function deinitConsumer (qcEvents_Interface_Source $Source) : qcEvents_Promise {
      // Remove the source
      $this->Source = null;
      
      $this->csvRecord = array ();
      $this->csvBuffer = '';
      $this->csvBufferLength = 0;
      
      if (is_array ($this->csvHeader))
        $this->csvHeader = true;
      
      // Run the callback
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    // {{{ close
    /**   
     * Close this event-interface
     * 
     * @access public
     * @return qcEvents_Promise
     **/  
    public function close () : qcEvents_Promise {
      if ($this->csvBufferLength > 0)
        $this->consume ($this->csvLineEnding, $this->Source);
      
      $this->___callback ('eventClosed');
      
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    
    // {{{ eventClosed
    /**
     * Callback: We were closed
     * 
     * @access protected
     * @return void
     **/
    protected function eventClosed () { }
    // }}}
    
    // {{{ csvHeaderRecevied
    /**
     * The header (first record) of a csv was received from stream
     * 
     * @param array $Header Indexed array with header
     * 
     * @access protected
     *  @return void
     **/
    protected function csvHeaderRecevied (array $Header) { }
    // }}}
    
    // {{{ csvRecordReceived
    /**
     * A record was received from stream
     * 
     * @param array $Record Associative array with csv-values
     * 
     * @access protected
     * @return void
     **/
    protected function csvRecordReceived (array $Record) { }
    // }}}
  }

?>
<?PHP

  /**
   * qcEvents - Character-Stream
   * Copyright (C) 2015 Bernd Holzmueller <bernd@quarxconnect.de>
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
  require_once ('qcEvents/Abstract/Source.php');
  require_once ('qcEvents/Promise.php');
  
  // Make sure MBString is available
  if (!extension_loaded ('mbstring') && (!function_exists ('dl') || !dl ('mbstring.so')))
    return trigger_error ('No mbstring-extension loaded');
  
  /**
   * Character-Stream
   * ----------------
   * Convert Character-Encoding of a piped stream
   * 
   * @class qcEvents_Stream_Character
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Stream_Character extends qcEvents_Abstract_Source implements qcEvents_Interface_Consumer {
    /* Internal buffer */
    private $bufferData = '';
    private $bufferLength = 0;
    
    /* Output charset */
    private $charsetOut = 'UTF-8';
    
    /* (Detected) Input charset */
    private $charsetIn = null;
    
    /* Fixed character-length */
    private $charsetLength = null;
    
    /* Check Input-Stream for encoding-errors */
    private $debugCharset = true;
    
    // {{{ __construct
    /**
     * Create a new character-stream
     * 
     * @param string $charsetOut (optional) Set output-characterset
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($charsetOut = null) {
      if ($charsetOut !== null)
        $this->charsetOut = $charsetOut;
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
      // Append data to text-buffer
      $this->bufferData .= $Data;
      $this->bufferLength += strlen ($Data);
      unset ($Data);
      
      // Check wheter to detect charset
      if ($this->charsetIn === null) {
        // Wait until there is enough data on the buffer
        if ($this->bufferLength < 4)
          return;
        
        // Read the major BOM Bytes
        $Major = (ord ($this->bufferData [0]) << 8) | ord ($this->bufferData [1]);
        $Minor = (ord ($this->bufferData [2]) << 8) | ord ($this->bufferData [3]);
        $lBOM = 2;
        
        switch ($Major) {
          // Detect UTF-32 (big endian)
          case 0x0000:
            if ($Minor == 0xFEFF) {
              $this->charsetIn = 'UTF-32BE';
              $this->charsetLength = 4;
              $lBOM = 4;
            }
             
            break;
          # SCSU-Detection - not supported by MBString at the moment
          # case 0x0EFE:
          #   if ($Minor >> 8) == 0xFF) {
          #     $this->charsetIn = '';
          #     $lBOM = 3;
          #   }
          #    
          #   break;
          // Detect UTF-7
          case 0x2B2F:
            if ((($Minor >> 8) == 0x76) &&
                ((($Minor & 0xFF) == 0x38) || (($Minor & 0xFF) == 0x39) || (($Minor & 0xFF) == 0x2B) || (($Minor & 0xFF) == 0x2F))) {
              // Encoded characters are 3 octets long but are mixed with normal ASCII, we can not determine a generic length here
              $this->charsetIn = 'UTF-7';
              $lBOM = 4;
            }
             
            break;
          // Detect GB-18030 encoding
          case 0x8431:
            if ($Minor == 0x9533) {
              // This is similar to UTF-8, so no generic length here
              $this->charsetIn = 'GB18030';
              $lBOM = 4;
            }
             
            break;
          # UTF-EBCDIC-Detection - not supported by MBString at the moment
          # case 0xDD73:
          #   if ($Minor == 0x6673) {
          #     $this->charsetIn = ''; 
          #     $lBOM = 4;
          #   }
          #    
          #   break;
          // Detect UTF-8-encoding
          case 0xEFBB:
            if (($Minor >> 8) == 0xBF) {
              // No generic length
              $this->charsetIn = 'UTF-8'; 
              $lBOM = 3;
            }
             
            break;
          # Detect UTF-1-encoding
          # case 0xF764:
          #   if (($Minor >> 8) == 0x4C) {
          #     $this->charsetIn = 'UTF-8'; 
          #     $lBOM = 3;
          #   }
          #    
          #   break;
          # Detect BOCU-1-encoding
          # case 0xFBEE:
          #   if (($Minor >> 8) == 0x28) {
          #     $this->charsetIn = ''; 
          #     $lBOM = 3;
          #   }
          #    
          #   break;
          // Detect UTF-16-encoding
          case 0xFEFF:
            $this->charsetIn = 'UTF-16BE';
            $this->charsetLength = 2;
            
            break;
          // Detecht UTF-16/32 little endian encoding 
          case 0xFFFE:
            if ($Minor == 0x0000) {
              $this->charsetIn = 'UTF-32LE';
              $this->charsetLength = 4;
              $lBOM = 4;
            } else {
              $this->charsetIn = 'UTF-16LE';
              $this->charsetLength = 2;
            }
            
            break;
          // Let mbstring probe the character-set
          default:
            $lBOM = 0;
            
            if (($this->charsetIn = mb_detect_encoding ($this->bufferData)) === false) {
              trigger_error ('No valid BOM was found and mbstring had problems to detect the encoding of input-stream');
              
              $this->charsetIn = 'auto';
            }
        }
        
        // Truncate the BOM from buffer
        if ($lBOM > 0) {
          $this->bufferData = substr ($this->bufferData, $lBOM);
          $this->bufferLength -= $lBOM;
        }
        
        // Raise callback
        if ($this->charsetIn != 'auto')
          $this->___callback ('characterDetected', $this->charsetIn, $this->charsetLength);
      }
      
      // Convert characters of fixed width on the buffer
      if ($this->charsetLength !== null)
        return $this->sourceInsertLength ($this->bufferLength - ($this->bufferLength % $this->charsetLength));
      
      // Detect last full UTF-8-Character
      elseif ($this->charsetIn == 'UTF-8') {
        $Length = 0;
        
        for ($i = $this->bufferLength - 1; $i >= 0; $i--) {
          $c = ord ($this->bufferData [$i]);
          
          // Check for an ASCII-Character
          if (($c & 0x80) == 0x00) {
            $Length = $i;
            
            break;
          
          // Check for an UTF-8-Lenth
          } elseif (($c & 0xC0) == 0xC0) {
            $uLen = 2;
            $uPos = 0x20;
            
            while (($uPos > 0) && (($c & $uPos) != 0)) {
              $uPos >>= 1;
              $uLen++;
            }
            
            // Check if there are enough bytes on the buffer for this to be valid
            if ($this->bufferLength < $i + $uLen)
              continue;
            
            $Length = $i + $uLen;
          }
        }
        
        if ($Length == 0)
          return;
        
        return $this->sourceInsertLength ($Length);
      }
      
      // Validate the data
      if ($this->debugCharset && !mb_check_encoding ($this->bufferData, $this->charsetIn)) {
        trigger_error ('Encoding-error on input');
        
        return;
      }
      
      // Forward to buffer
      $this->sourceInsert (mb_convert_encoding ($this->bufferData, $this->charsetOut, $this->charsetIn));
      
      // Empty our own buffer
      $this->bufferData = '';
      $this->bufferLength = 0;
    }
    // }}}
    
    // {{{ sourceInsertLength
    /**
     * Forward a chunk of a given length from our own buffer
     * 
     * @param string $Length
     * 
     * @access private
     * @return void
     **/
    private function sourceInsertLength ($Length) {
      // Extract from buffer
      $Data = substr ($this->bufferData, 0, $Length);
      
      // Validate the data
      if ($this->debugCharset && !mb_check_encoding ($Data, $this->charsetIn)) {
        trigger_error ('Encoding-error on input');
        
        return;
      }
      
      // Forward to buffer
      $this->sourceInsert (mb_convert_encoding ($Data, $this->charsetOut, $this->charsetIn));
      unset ($Data);
      
      // Truncate our own buffer
      if ($this->bufferLength > $Length) {
        $this->bufferData = substr ($this->bufferData, $Length);
        $this->bufferLength -= $Length;
      } else {
        $this->bufferData = '';
        $this->bufferLength = 0;
      }
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
      # TODO
      $this->___raiseCallback ($Callback, true, $Private);
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
      # TODO
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    protected function characterDetected ($Charset, $Length = null) { }
  }

?>
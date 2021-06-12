<?php

  /**
   * qcEvents - Character-Stream
   * Copyright (C) 2015-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  namespace quarxConnect\Events\Stream;
  use \quarxConnect\Events;
  use \quarxConnect\Events\ABI;
  
  // Make sure MBString is available
  if (!extension_loaded ('mbstring') && (!function_exists ('dl') || !dl ('mbstring.so')))
    return trigger_error ('No mbstring-extension loaded');
  
  /**
   * Character-Stream
   * ----------------
   * Convert Character-Encoding of a piped stream
   * 
   * @class quarxConnect\Events\Stream\Character
   * @package qcEvents
   * @revision 01
   **/
  class Character extends Events\Virtual\Source implements ABI\Consumer {
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
    function __construct (string $charsetOut = null) {
      if ($charsetOut !== null)
        $this->charsetOut = $charsetOut;
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $soruceData
     * @param ABI\Source $dataSource
     * 
     * @access public
     * @return void
     **/
    public function consume ($sourceData, ABI\Source $dataSource) : void {
      // Append data to text-buffer
      $this->bufferData .= $sourceData;
      $this->bufferLength += strlen ($sourceData);
      unset ($sourceData);
      
      // Check wheter to detect charset
      if ($this->charsetIn === null) {
        // Wait until there is enough data on the buffer
        if ($this->bufferLength < 4)
          return;
        
        // Read the major BOM Bytes
        $bomMajor = (ord ($this->bufferData [0]) << 8) | ord ($this->bufferData [1]);
        $bomMinor = (ord ($this->bufferData [2]) << 8) | ord ($this->bufferData [3]);
        $bomLength = 2;
        
        switch ($bomMajor) {
          // Detect UTF-32 (big endian)
          case 0x0000:
            if ($bomMinor == 0xFEFF) {
              $this->charsetIn = 'UTF-32BE';
              $this->charsetLength = 4;
              $bomLength = 4;
            }
            
            break;
          # SCSU-Detection - not supported by MBString at the moment
          # case 0x0EFE:
          #   if ($bomMinor >> 8) == 0xFF) {
          #     $this->charsetIn = '';
          #     $bomLength = 3;
          #   }
          #    
          #   break;
          // Detect UTF-7
          case 0x2B2F:
            if ((($bomMinor >> 8) == 0x76) &&
                ((($bomMinor & 0xFF) == 0x38) || (($bomMinor & 0xFF) == 0x39) || (($bomMinor & 0xFF) == 0x2B) || (($bomMinor & 0xFF) == 0x2F))) {
              // Encoded characters are 3 octets long but are mixed with normal ASCII, we can not determine a generic length here
              $this->charsetIn = 'UTF-7';
              $bomLength = 4;
            }
             
            break;
          // Detect GB-18030 encoding
          case 0x8431:
            if ($bomMinor == 0x9533) {
              // This is similar to UTF-8, so no generic length here
              $this->charsetIn = 'GB18030';
              $bomLength = 4;
            }
             
            break;
          # UTF-EBCDIC-Detection - not supported by MBString at the moment
          # case 0xDD73:
          #   if ($bomMinor == 0x6673) {
          #     $this->charsetIn = ''; 
          #     $bomLength = 4;
          #   }
          #    
          #   break;
          // Detect UTF-8-encoding
          case 0xEFBB:
            if (($bomMinor >> 8) == 0xBF) {
              // No generic length
              $this->charsetIn = 'UTF-8'; 
              $bomLength = 3;
            }
             
            break;
          # Detect UTF-1-encoding
          # case 0xF764:
          #   if (($bomMinor >> 8) == 0x4C) {
          #     $this->charsetIn = 'UTF-8'; 
          #     $bomLength = 3;
          #   }
          #    
          #   break;
          # Detect BOCU-1-encoding
          # case 0xFBEE:
          #   if (($bomMinor >> 8) == 0x28) {
          #     $this->charsetIn = ''; 
          #     $bomLength = 3;
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
            if ($bomMinor == 0x0000) {
              $this->charsetIn = 'UTF-32LE';
              $this->charsetLength = 4;
              $bomLength = 4;
            } else {
              $this->charsetIn = 'UTF-16LE';
              $this->charsetLength = 2;
            }
            
            break;
          // Let mbstring probe the character-set
          default:
            $bomLength = 0;
            
            if (($this->charsetIn = mb_detect_encoding ($this->bufferData)) === false) {
              trigger_error ('No valid BOM was found and mbstring had problems to detect the encoding of input-stream');
              
              $this->charsetIn = 'auto';
            }
        }
        
        // Truncate the BOM from buffer
        if ($bomLength > 0) {
          $this->bufferData = substr ($this->bufferData, $bomLength);
          $this->bufferLength -= $bomLength;
        }
        
        // Raise callback
        if ($this->charsetIn != 'auto')
          $this->___callback ('charactersetDetected', $this->charsetIn, $this->charsetLength);
      }
      
      // Convert characters of fixed width on the buffer
      if ($this->charsetLength !== null) {
        $this->sourceInsertLength ($this->bufferLength - ($this->bufferLength % $this->charsetLength));
        
        return;
      }
      
      // Detect last full UTF-8-Character
      elseif ($this->charsetIn == 'UTF-8') {
        $utfLength = 0;
        
        for ($i = $this->bufferLength - 1; $i >= 0; $i--) {
          $c = ord ($this->bufferData [$i]);
          
          // Check for an ASCII-Character
          if (($c & 0x80) == 0x00) {
            $utfLength = $i;
            
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
            
            $utfLength = $i + $uLen;
          }
        }
        
        if ($utfLength > 0)
          $this->sourceInsertLength ($utfLength);
        
        return;
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
     * @param int $sourceLength
     * 
     * @access private
     * @return void
     **/
    private function sourceInsertLength (int $sourceLength) : void {
      // Extract from buffer
      $sourceData = substr ($this->bufferData, 0, $sourceLength);
      
      // Validate the data
      if ($this->debugCharset && !mb_check_encoding ($sourceData, $this->charsetIn)) {
        trigger_error ('Encoding-error on input');
        
        return;
      }
      
      // Forward to buffer
      $this->sourceInsert (mb_convert_encoding ($sourceData, $this->charsetOut, $this->charsetIn));
      unset ($sourceData);
      
      // Truncate our own buffer
      if ($this->bufferLength > $sourceLength) {
        $this->bufferData = substr ($this->bufferData, $sourceLength);
        $this->bufferLength -= $sourceLength;
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
     * @param ABI\Source $dataSource
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initConsumer (ABI\Source $dataSource) : Events\Promise {
      return Events\Promise::resolve ();
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param ABI\Source $dataSource
     * 
     * @access public
     * @return Events\Promise 
     **/
    public function deinitConsumer (ABI\Source $dataSource) : Events\Promise {
      return Events\Promise::resolve ();
    }
    // }}}
    
    
    // {{{ charactersetDetected
    /**
     * Callback: Input-Characterset was detected
     * 
     * @param string $detectedCharset
     * @param int $characterLength (optional)
     * 
     * @access protected
     * @return void
     **/
    protected function charactersetDetected (string $detectedCharset, int $characterLength = null) : void { }
    // }}}
  }

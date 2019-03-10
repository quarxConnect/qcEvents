<?PHP

  /**
   * qcEvents - Compressed Stream
   * Copyright (C) 2019 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Abstract/Pipe.php');
  require_once ('qcEvents/Interface/Source.php');
  require_once ('qcEvents/Interface/Consumer.php');
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  require_once ('qcEvents/Promise.php');

  class qcEvents_Stream_Compressed extends qcEvents_Abstract_Pipe implements qcEvents_Interface_Consumer, qcEvents_Interface_Stream_Consumer, qcEvents_Interface_Source {
    /* Our internal stream-state */
    const STATE_DETECT     = 0x00;
    const STATE_HEADER     = 0x01;
    const STATE_COMPRESSED = 0x02;
    const STATE_FOOTER     = 0x03;
    
    private $State = qcEvents_Stream_Compressed::STATE_DETECT;
    
    /* Detected/Used Container */
    const CONTAINER_NONE = 0x00;
    const CONTAINER_GZIP = 0x01;
    const CONTAINER_ZLIB = 0x02;
    
    private $Container = qcEvents_Stream_Compressed::CONTAINER_NONE;
    
    /* Compression-Method */
    const COMPRESSION_DEFLATE = 0x08;
    
    private $Compression = qcEvents_Stream_Compressed::COMPRESSION_DEFLATE;
    
    /* Compression-State */
    const COMPRESSION_STATE_HEADER = 0x00;
    const COMPRESSION_STATE_TABLE  = 0x01;
    const COMPRESSION_STATE_TREE   = 0x02;
    const COMPRESSION_STATE_DATA   = 0x03;
    const COMPRESSION_STATE_DATA2  = 0x04;
    const COMPRESSION_STATE_IMAGE  = 0x05;
    
    private $CompressionState = qcEvents_Stream_Compressed::COMPRESSION_STATE_HEADER;
    
    /* Internal compressor-informations */
    const COMPRESSION_DEFLATE_MAX_BITS = 15;
    const COMPRESSION_DEFLATE_TABLE_SIZE = 1440;
    
    private static $cplens = array (3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 15, 17, 19, 23, 27, 31, 35, 43, 51, 59, 67, 83, 99, 115, 131, 163, 195, 227, 258, 0, 0);
    private static $cplext = array (0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 4, 5, 5, 5, 5, 0, 112, 112);
    private static $cpdist = array (1, 2, 3, 4, 5, 7, 9, 13, 17, 25, 33, 49, 65, 97, 129, 193, 257, 385, 513, 769, 1025, 1537, 2049, 3073, 4097, 6145, 8193, 12289, 16385, 24577);
    private static $cpdext = array (0, 0, 0, 0, 1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8, 8, 9, 9, 10, 10, 11, 11, 12, 12, 13, 13);
    
    private $compressedWindowSize = 32768;
    private $compressedDynamicLiterals = null;
    private $compressedDynamicDistances = null;
    private $compressedDynamicLengths = null;
    private $compressedCodeLength = null;
    private $compressedCodeLengths = null;
    private $compressedTreeIndex = null;
    
    private $deflateLast = false;
    private $deflateTable = null;
    private $deflateTableOffset = null;
    private $deflateCodeLength = null;
    private $deflateLiteralBits = null;
    private $deflateLiteralOffset = null;
    private $deflateDistanceBits = null;
    private $deflateDistanceOffset = null;
    private $deflateLength = null;
    
    /* Flags */
    const FLAG_GZIP_IS_ASCII     = 0x0001;
    const FLAG_GZIP_HEADER_CRC   = 0x0002;
    const FLAG_GZIP_EXTRA_FIELDS = 0x0004;
    const FLAG_GZIP_FILENAME     = 0x0008;
    const FLAG_GZIP_COMMENT      = 0x0010;
    const FLAG_GZIP_DEFLATE_MAX  = 0x0200;
    const FLAG_GZIP_DEFLATE_MIN  = 0x0400;
    
    const FLAG_ZLIB_DICT         = 0x20;
    
    private $gzipFlags = null;
    private $gzipModified = null;
    private $gzipOS = null;
    private $gzipFilename = null;
    private $gzipComment = null;
    
    private $zlibFlags = null;
    private $zlibCompressionLevel = null;
    private $zlibDictionary = null;
    
    /* Our local byte-buffer */
    private $compressedBuffer = '';
    private $compressedBufferLength = 0x00;
    
    /* Our local bit-buffer */
    private $bitBuffer = 0x00;
    
    /* Length of our bit-buffer */
    private $bitBufferLength = 0x00;
    
    /* bit-buffer-Offset on compressed buffer */
    private $bitBufferOffset = 0x00;
    
    /* Uncompressed buffer */
    const UNCOMPRESSED_FLUSH_WATERMARK = 40960;
    
    private $uncompressedBuffer = '';
    private $uncompressedBufferLength = 0x00;
    private $uncompressedBufferOffset = 0x00;
    
    /* Raise events if data is available on the buffer */
    private $raiseEvents = true;
    private $Source = null;
    
    private $haveRead = false;
    
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
    public function consume ($Data, qcEvents_Interface_Source $Source = null) {
      // Copy the data to our buffer
      $this->compressedBuffer .= $Data;
      $this->compressedBufferLength += strlen ($Data);
      unset ($Data);
      
      // Try to process the buffer
      $this->processBuffer ();
    }
    // }}}
    
    // {{{ processBuffer
    /**
     * Try to process bytes from the buffer
     * 
     * @access private
     * @return void
     **/
    private function processBuffer () {
      // Check wheter we need to detect contents of our buffer
      if ($this->State == $this::STATE_DETECT) {
        // We need at least two bytes to work
        if ($this->compressedBufferLength < 2)
          return null;
        
        // Generate magic from buffer
        $Magic = (ord ($this->compressedBuffer [0]) << 8) | ord ($this->compressedBuffer [1]);
        
        // Check if the stream uses GZIP-Encoding (RFC 1952)
        if ($Magic == 0x1F8B) {
          $this->Container = $this::CONTAINER_GZIP;
          $this->State = $this::STATE_HEADER;
        
        // Check if the stream uses ZLIB-Encoding (RFC 1950)
        // We detect this by looking if DEFLATE is set and the magic is a multiple of 31
        } elseif ((($Magic & 0x0F00) == ($this::COMPRESSION_DEFLATE << 8)) && ($Magic % 31 == 0)) {
          $this->Container = $this::CONTAINER_ZLIB;
          $this->State = $this::STATE_HEADER;
        
        // Nothing that we know was detected
        } else
          return $this->raiseCompressionError ('Unable to detect compression-container');
        
        // Raise a callback for this
        $this->___callback ('compressedContainerDetected', $this->Container);
      }
      
      // Check wheter to process the header
      if ($this->State == $this::STATE_HEADER) {
        // Process GZIP-Header (RFC 1952)
        if ($this->Container == $this::CONTAINER_GZIP) {
          // Check if there is enough data on the buffer
          if ($this->compressedBufferLength < 10)
            return;
          
          // Check flags to see if we are looking for additional headers
          $this->gzipFlags = ord ($this->compressedBuffer [3]);
          $p = 10;
          
          // Check if extra-fields were signaled and received
          if ($this->gzipFlags & $this::FLAG_GZIP_EXTRA_FIELDS) {
            // Check if we have the length-field
            if ($this->compressedBufferLength < 12)
              return null;
            
            $exLength = (ord ($this->compressedBuffer [$p++]) << 8) | ord ($this->compressedBuffer [$p++]);
            
            // Check if there is enough data on the buffer
            if ($this->compressedBufferLength < ($p += $exLength))
              return null;
          }
          
          // Check how many zeros we need
          $Zeros = ($this->gzipFlags & $this::FLAG_GZIP_FILENAME ? 1 : 0) + ($this->gzipFlags & $this::FLAG_GZIP_COMMENT ? 1 : 0);
          
          if ($Zeros > 0) {
            for (; $p < $this->compressedBufferLength; $p++)
              if ((ord ($this->compressedBuffer [$p]) == 0x00) && (--$Zeros == 0))
                break;
            
            if ($Zeros > 0)
              return null;
          }
          
          // Check if the header has crc16-protection
          if (($this->gzipFlags & $this::FLAG_GZIP_HEADER_CRC) && ($this->compressedBufferLength < $p + 2))
            return null;
          
          // Parse the header
          $this->Compression = ord ($this->compressedBuffer [2]);
          $this->gzipModified = ord ($this->compressedBuffer [4]) | (ord ($this->compressedBuffer [5]) << 8) | (ord ($this->compressedBuffer [6]) << 16) | (ord ($this->compressedBuffer [7]) << 24);
          $this->gzipFlags = $this->gzipFlags | (ord ($this->compressedBuffer [8]) << 8);
          $this->gzipOS = ord ($this->compressedBuffer [9]);
          
          $hl = 10;
          
          // Parse extra fields
          if ($this->gzipFlags & $this::FLAG_GZIP_EXTRA_FIELDS) {
            // Skip the length-bits
            $hl += 2;
            
            // Extract the fields
            $exStop = $hl + $exLength;
            $exFields = array ();
            
            while ($hl + 4 < $xStop) {
              $FieldID = ord ($this->compressedBuffer [$hl++]) | (ord ($this->compressedBuffer [$hl++]) << 8);
              $exLength = min (ord ($this->compressedBuffer [$hl++]) | (ord ($this->compressedBuffer [$hl++]) << 8), $exStop - $hl);
              
              $exFields [$FieldID] = substr ($this->compressedBuffer, $hl, $exLength);
              $hl += $exLength;
            }
            
            // Skip the extra-fields
            $hl = $exStop;
          } else
            $exFields = null;
          
          // Parse filename
          if ($this->gzipFlags & $this::FLAG_GZIP_FILENAME) {
            $s = $hl;
            
            while ($hl < $this->compressedBufferLength)
              if (ord ($this->compressedBuffer [$hl++]) == 0x00)
                break;
            
            $this->gzipFilename = substr ($this->compressedBuffer, $s, $hl - $s - 1);
          } else
            $this->gzipFilename = null;
          
          if ($this->gzipFlags & $this::FLAG_GZIP_COMMENT) {
            $s = $hl;
            
            while ($hl < $this->compressedBufferLength)
              if (ord ($this->compressedBuffer [$hl++]) == 0x00)
                break;
            
            $this->gzipComment = substr ($this->compressedBuffer, $s, $hl - $s - 1);
          } else
            $this->gzipComment = null;
          
          // Check if the header has crc16
          if ($this->gzipFlags & $this::FLAG_GZIP_HEADER_CRC) {
            # TODO: We just skip this at the moment
            $hl += 2;
          }
          
          // Truncate the header from the stream
          $this->compressedBuffer = substr ($this->compressedBuffer, $hl);
          $this->compressedBufferLength -= $hl;
          
          // Move to COMPRESSED-State
          $this->State = $this::STATE_COMPRESSED;
          
          // Raise a callback for GZIP
          $this->___callback ('compressedGZIPHeader', $this->Compression, $this->gzipFilename, $this->gzipModified, $this->gzipOS, $this->gzipComment, $exFields);
          
        // Process ZLIB-Header (RFC 1950)
        } elseif ($this->Container == $this::CONTAINER_ZLIB) {
          // Make sure that the buffer is big enough to read; all headers
          if ($this->compressedBufferLength < 2)
            return;
          
          $hl = 2;
          
          // Read zlib-flags first
          $this->zlibFlags = ord ($this->compressedBuffer [1]);
          
          // Check if the entire header is on the buffer
          if (($this->zlibFlags & $this::FLAG_ZLIB_DICT) && ($this->compressedBufferLength < 6))
            return;
          
          // Parse the header
          $CMF = ord ($this->compressedBuffer [0]);
          $hl = 2;
          
          $this->Compression = ($CMF & 0x0F);
          
          if ($this->Compression == $this::COMPRESSION_DEFLATE)
            $this->compressedWindowSize = (1 << ((($CMF & 0xF0) >> 4) + 8));
          
          $this->zlibCompressionLevel = (($this->zlibFlags & 0xC0) >> 6);
          
          if ($this->zlibFlags & $this::FLAG_ZLIB_DICT) {
            $this->zlibDictionary = ord ($this->compressedBuffer [2]) | (ord ($this->compressedBuffer [3]) << 8) | (ord ($this->compressedBuffer [4]) << 16) | (ord ($this->compressedBuffer [5]) << 24);
            $hl += 4;
          }
          
          // Truncate the header
          $this->compressedBuffer = substr ($this->compressedBuffer, $hl);
          $this->compressedBufferLength -= $hl;
          
          // Move to COMPRESSED-State
          $this->State = $this::STATE_COMPRESSED;
          
          // Raise a callback for ZLIB
          $this->___callback ('compressedZLIBHeader', $this->Compression, $this->zlibCompressionLevel, $this->compressedWindowSize, $this->zlibDictionary);
          
        // We should never get here:
        } else
          return $this->raiseCompressionError ('Header on unhandled container-type ' . $this->Container);
        
        // Raise general callback
        $this->___callback ('compressedContainerReady', $this->Container, $this->Compression);
      }
      
      // Check if we reached COMPRESSED-State
      if ($this->State == $this::STATE_COMPRESSED)
        $this->processCompressed ();
      
      // Check if we reached FOOTER-State
      if ($this->State == $this::STATE_FOOTER) {
        if ($this->Container == $this::CONTAINER_GZIP) {
          # 4 Bytes CRC32
          # 4 Bytes ISIZE
          
          if ($this->compressedBufferLength < 8)
            return;
          
          $this->compressedBuffer = substr ($this->compressedBuffer, 8);
          $this->compressedBufferLength -= 8;
          
          $this->State = $this::STATE_DETECT;
        } elseif ($this->Container == $this::CONTAINER_ZLIB) {
          # 4 Bytes Adler32
          
          if ($this->compressedBufferLength < 4)
            return;
          
          $this->compressedBuffer = substr ($this->compressedBuffer, 4);
          $this->compressedBufferLength -= 4;
          
          $this->State = $this::STATE_DETECT;
        } else
          return $this->raiseCompressionError ('Footer on unhabled container-type');
        
        // Raise a callback for this
        $this->___callback ('compressedContainerFinished');
      }
    }
    // }}}
    
    // {{{ processCompressed
    /**
     * Try to process compressed payload on the buffer
     * 
     * @access private
     * @return void
     **/
    private function processCompressed () {
      // Check if the compression-method is deflate
      if ($this->Compression != $this::COMPRESSION_DEFLATE)
        return $this->raiseCompressionError ('Unsupported compression-mechanism');
      
      // Process our current state while we have bits
      $l = $this->getAvailableBits ();
      
      while ($l) {
        switch ($this->CompressionState) {
          // Read header of compressed block
          case $this::COMPRESSION_STATE_HEADER:
            // Make sure we have enough bits available
            if ($l < 3)
              break (2);
            
            // Read the header
            if (($this->bitBufferLength < 3) && !$this->fillBufferedBits (3))
              break (2);
            
            $Header = $this->bitBuffer & 0x07;
            $this->bitBuffer >>= 3;
            $this->bitBufferLength -= 3;
            $l -= 3;
            
            // Check if this is the last block
            $this->deflateLast = (($Header & 0x01) == 0x01);
            
            // Check type of next block
            $Type = (($Header & 0x06) >> 1);
            
            // Not compressed at all
            if ($Type == 0x00) {
              // Update the state
              $this->CompressionState = $this::COMPRESSION_STATE_IMAGE;
              
              // Clear the bit-buffer
              $this->cleanBufferedBits ();
              
              continue;
            // Compressed using fixed codes
            } elseif ($Type == 0x01) {
              // Prepare static table
              static $fixedTable = null;
              static $fixedLiteralOffset = 0;
              static $fixedLiteralBits = 9;
              static $fixedDistanceOffset = 0;
              static $fixedDistanceBits = 5;
              
              if (!$fixedTable) {
                $codeLengths = array_fill (0, 288 + 30, 8);
                
                for ($i = 144; $i < 256; $i++)
                  $codeLengths [$i] = 9;
                
                for ($i = 256; $i < 280; $i++)
                  $codeLengths [$i] = 7;
                
                for ($i = 288; $i < 288 + 30; $i++)
                  $codeLengths [$i] = 5;
                
                $hufts = 0;
                $fixedTable = array_fill (0, $this::COMPRESSION_DEFLATE_TABLE_SIZE * 3, 0);
                $this->buildHuffmanTable (288, $codeLengths, 0, 288, 257, self::$cplens, self::$cplext, $fixedLiteralOffset, $fixedLiteralBits, $fixedTable, $hufts, true);
                $this->buildHuffmanTable (30, $codeLengths, 288, 30, 0, self::$cpdist, self::$cpdext, $fixedDistanceOffset, $fixedDistanceBits, $fixedTable, $hufts);
              }
              
              // Set deflate-state to fixed table
              $this->deflateTable = $fixedTable;
              $this->deflateTableOffset = $this->deflateLiteralOffset = $fixedLiteralOffset;
              $this->deflateCodeLength = $this->deflateLiteralBits = $fixedLiteralBits;
              $this->deflateDistanceOffset = $fixedDistanceOffset;
              $this->deflateDistanceBits = $fixedDistanceBits;
              
              // Update the state
              $this->CompressionState = $this::COMPRESSION_STATE_DATA;
              
              continue;
            // Compressed using dynamic codes
            } elseif ($Type == 0x02) {
              // Update the state
              $this->CompressionState = $this::COMPRESSION_STATE_TABLE;
              
              // Reset just to be sure
              $this->compressedDynamicLengths = null;
            
            // Invalid type
            } else
              return $this->raiseCompressionError ('Invalid block-type on deflate');
            
          // Read dynamic huffman table
          case $this::COMPRESSION_STATE_TABLE:
            if ($this->compressedDynamicLengths === null) {
              // Check if there are enough bits
              if ($l < 14)
                break (2);
              
              // Retrive the table
              if (($this->bitBufferLength < 14) && !$this->fillBufferedBits (14))
                return $this->raiseCompressionError ('This should not happen at all');
              
              if (($this->compressedDynamicLiterals = ($this->bitBuffer & 0x1F)) > 29)
                return $this->raiseCompressionError ('Too many length symbols');
              
              $this->bitBuffer >>= 5;
              
              if (($this->compressedDynamicDistances = ($this->bitBuffer & 0x1F)) > 31)
                return $this->raiseCompressionError ('Too many distance symbols');
              
              $this->bitBuffer >>= 5;
              $this->compressedDynamicLengths = ($this->bitBuffer & 0x0F);
              $this->bitBuffer >>= 4;
              $this->bitBufferLength -= 14;
              $l -= 14;
              
              // Decrease number of available bits
              # $l -= 14;
            }
            
            // Check if we may read dynamic code-lengths
            if ($l < (($this->compressedDynamicLengths + 4) * 3))
              break (2);
            
            // Read dynamic code-lengths
            static $map = array (16, 17, 18, 0, 8, 7, 9, 6, 10, 5, 11, 4, 12, 3, 13, 2, 14, 1, 15);
            
            $this->compressedCodeLengths = array_fill (0, 258 + $this->compressedDynamicLiterals + $this->compressedDynamicDistances, 0);
            
            for ($i = 0; $i < $this->compressedDynamicLengths + 4; $i++) {
              if (($this->bitBufferLength < 3) && !$this->fillBufferedBits (3))
                return $this->raiseCompressionError ('This should not happen at all');
              
              $this->compressedCodeLengths [$map [$i]] = ($this->bitBuffer & 7);
              $this->bitBuffer >>= 3;
              $this->bitBufferLength -= 3;
            }
            
            for ($i = $this->compressedDynamicLengths + 4; $i < 19; $i++)
              $this->compressedCodeLengths [$map [$i]] = 0;
            
            $l -= (($this->compressedDynamicLengths + 4) * 3);
            
            $this->compressedCodeLength = 7;
            $this->deflateTable = array_fill (0, $this::COMPRESSION_DEFLATE_TABLE_SIZE * 3, 0);
            $this->deflateTableOffset = 0;
            $hufts = 0;
            
            if (!$this->buildHuffmanTable (19, $this->compressedCodeLengths, 0, 19, 19, null, null, $this->deflateTableOffset, $this->compressedCodeLength, $this->deflateTable, $hufts))
              return $this->raiseCompressionError ('Could not generate table');
            
            // Update the state
            $this->CompressionState = $this::COMPRESSION_STATE_TREE;
            $this->compressedTreeIndex = 0;
            
          // Read huffman-tree from buffer
          case $this::COMPRESSION_STATE_TREE:
            if ($this->compressedTreeIndex < count ($this->compressedCodeLengths))
              for (; $this->compressedTreeIndex < count ($this->compressedCodeLengths); $this->compressedTreeIndex++) {
                // Check if there are enough bits on the buffer
                if ($l < $this->compressedCodeLength)
                  break (3);
                
                // Read the actual length of the next item
                if (($this->bitBufferLength < $this->compressedCodeLength) && !$this->fillBufferedBits ($this->compressedCodeLength))
                  return $this->raiseCompressionError ('This should not happen at all');
                
                $actualLength = $this->deflateTable [$this->deflateTableOffset + ($this->bitBuffer & ((1 << $this->compressedCodeLength) - 1)) * 3 + 1];
                
                if ($l < $actualLength)
                  break (3);
                
                // Read the code-length
                if (($this->bitBufferLength < $actualLength) && !$this->fillBufferedBits ($actualLength))
                  return $this->raiseCompressionError ('This should not happen at all');
                
                $CodeLength = $this->deflateTable [$this->deflateTableOffset + ($this->bitBuffer & ((1 << $actualLength) - 1)) * 3 + 2];
                
                // Post-Process the code-length
                if ($CodeLength < 16) {
                  // Store the code
                  $this->compressedCodeLengths [$this->compressedTreeIndex] = $CodeLength;
                  
                  // Make sure the bit-buffer is cleared
                  $this->bitBuffer >>= $actualLength;
                  $this->bitBufferLength -= $actualLength;
                  $l -= $actualLength;
                  
                  // Continue with next one
                  continue;
                }
                
                // Determine how many bits we need
                $i = ($CodeLength == 18 ? 7 : $CodeLength - 14);
                
                if ($l < $actualLength + $i)
                  break (3);
                
                // Make sure the bit-buffer is cleared 
                $this->bitBuffer >>= $actualLength;
                $this->bitBufferLength -= $actualLength;
                $l -= $actualLength;
                
                // Retrive the additional bits
                if (($this->bitBufferLength < $i) && !$this->fillBufferedBits ($i))
                  return $this->raiseCompressionError ('This should not happen at all');
                
                $j = ($CodeLength == 18 ? 11 : 3) + ($this->bitBuffer & ((1 << $i) - 1));
                $this->bitBuffer >>= $i;
                $this->bitBufferLength -= $i;
                $l -= $i;
                
                if (($this->compressedTreeIndex + $j > count ($this->compressedCodeLengths)) || (($CodeLength == 16) && ($this->compressedTreeIndex < 1)))
                  return $this->raiseCompressionError ('Invalid bit-length repeat');
                
                // Determine the new code-length
                $CodeLength = ($CodeLength == 16 ? $this->compressedCodeLengths [$this->compressedTreeIndex - 1] : 0);
                
                do {
                  $this->compressedCodeLengths [$this->compressedTreeIndex++] = $CodeLength;
                } while (--$j > 0);
                
                // Don't jump out of the loop too early
                $this->compressedTreeIndex--;
              }
            
            $hufts = 0;
            $this->deflateLiteralBits = 9;
            $this->deflateLiteralOffset = 0;
            $this->deflateDistanceBits = 6;
            $this->deflateDistanceOffset = 0;
            
            if (!$this->buildHuffmanTable (288, $this->compressedCodeLengths, 0, 257 + $this->compressedDynamicLiterals, 257, self::$cplens, self::$cplext, $this->deflateLiteralOffset, $this->deflateLiteralBits, $this->deflateTable, $hufts, true))
              return $this->raiseCompressionError ('Could not build literal/length tree');
            
            if (!$this->buildHuffmanTable (288, $this->compressedCodeLengths, 257 + $this->compressedDynamicLiterals, 1 + $this->compressedDynamicDistances, 0, self::$cpdist, self::$cpdext, $this->deflateDistanceOffset, $this->deflateDistanceBits, $this->deflateTable, $hufts))
              return $this->raiseCompressionError ('Could not build distance tree');
            
            // Move to DATA-state
            $this->deflateTableOffset = $this->deflateLiteralOffset;
            $this->deflateCodeLength = $this->deflateLiteralBits;
            $this->CompressionState = $this::COMPRESSION_STATE_DATA;
            
          // Read compressed data from buffer
          case $this::COMPRESSION_STATE_DATA:
            // Check if there are enough bits on the buffer
            while ($l >= $this->deflateCodeLength) {
              // Read bits from buffer
              if (($this->bitBufferLength < $this->deflateCodeLength) && !$this->fillBufferedBits ($this->deflateCodeLength))
                return $this->raiseCompressionError ('This should not happen at all');
              
              $Index = ($this->deflateTableOffset + ($this->bitBuffer & ((1 << $this->deflateCodeLength) - 1))) * 3;
              
              // Check type of next element
              $Type = $this->deflateTable [$Index];
              
              // Peek a literal from the table
              if ($Type == 0) {
                // Append to uncompressed buffer
                if (!$this->raiseEvents || ($this->uncompressedBufferLength - $this->uncompressedBufferOffset > $this::UNCOMPRESSED_FLUSH_WATERMARK)) {
                  $this->uncompressedBuffer .= $this->deflateTable [$Index + 2];
                  $this->uncompressedBufferLength++;
                } else
                  $this->processUncompressed ($this->deflateTable [$Index + 2], 1);
                
                // Remove the bits from buffer
                $this->bitBuffer >>= $this->deflateTable [$Index + 1];
                $this->bitBufferLength -= $this->deflateTable [$Index + 1];
                $l -= $this->deflateTable [$Index + 1];
                
                // Reset to start again
                $this->deflateTableOffset = $this->deflateLiteralOffset;
                $this->deflateCodeLength = $this->deflateLiteralBits;
                
                continue;
              // Read length and distance
              } elseif (($Type & 0x10) == 0x10) {
                // Check if there are enough bits on the buffer
                if ($l < $this->deflateTable [$Index + 1] + ($Type & 0x0F))
                  break (3);
                
                // Remove the bits from buffer
                $this->bitBuffer >>= $this->deflateTable [$Index + 1];
                $this->bitBufferLength -= $this->deflateTable [$Index + 1];
                $l -= $this->deflateTable [$Index + 1];
                
                // Retrive the length
                if (($this->bitBufferLength < ($Type & 0x0F)) && !$this->fillBufferedBits ($Type & 0x0F))
                  return $this->raiseCompressionError ('This should not happen at all');
                
                $this->deflateLength = $this->deflateTable [$Index + 2] + ($this->bitBuffer & ((1 << ($Type & 0x0F)) - 1));
                $this->bitBuffer >>= ($Type & 0x0F);
                $this->bitBufferLength -= ($Type & 0x0F);
                $l -= ($Type & 0x0F);
                
                // Move to distance-state
                $this->deflateTableOffset = $this->deflateDistanceOffset;
                $this->deflateCodeLength = $this->deflateDistanceBits;
                $this->CompressionState = $this::COMPRESSION_STATE_DATA2;
                
                break;
              // Switch over to next table
              } elseif (($Type & 0x40) == 0x00) {
                // Remove the bits from buffer
                $this->bitBuffer >>= $this->deflateTable [$Index + 1];
                $this->bitBufferLength -= $this->deflateTable [$Index + 1];
                $l -= $this->deflateTable [$Index + 1];
                
                // Set new table-offset
                $this->deflateCodeLength = $Type;
                $this->deflateTableOffset = ($Index / 3) + $this->deflateTable [$Index + 2];
                
                continue;
              // Check if the end of block was reached
              } elseif (($Type & 0x20) == 0x20) {
                // Remove the bits from buffer
                $this->bitBuffer >>= $this->deflateTable [$Index + 1];
                $this->bitBufferLength -= $this->deflateTable [$Index + 1];
                $l -= $this->deflateTable [$Index + 1];
                
                // Flush the uncompressed buffer
                $this->flushUncompressed ();
                
                // Raise a callback for this
                $this->___callback ('compressedBlockReady', $this->deflateLast);
                
                // Leave if inflate was finished
                if ($this->deflateLast) {
                  // Clean up bit-buffer
                  $this->cleanBufferedBits ();
                  
                  return ($this->State = $this::STATE_FOOTER);
                }
                
                // Proceed to next block
                $this->CompressionState = $this::COMPRESSION_STATE_HEADER;
                
                continue (2);
              // Invalid compressed data
              } else
                return $this->raiseCompressionError ('Invalid literal or code-length');
            }
          
          // Retrive distance-literals for deflate
          case $this::COMPRESSION_STATE_DATA2:
            // Make sure there are enough bits on the buffer
            if ($l < $this->deflateCodeLength)
              break (2);
            
            // Retrive the distance
            if (($this->bitBufferLength < $this->deflateCodeLength) && !$this->fillBufferedBits ($this->deflateCodeLength))
              return $this->raiseCompressionError ('This should not happen at all');
            
            $Index = ($this->deflateTableOffset + ($this->bitBuffer & ((1 << $this->deflateCodeLength) - 1))) * 3;
            
            // Check what to do next
            $Type = $this->deflateTable [$Index];
            
            if (($Type & 0x10) == 0x10) {
              // Check if we have enough bits available
              if ($l < $this->deflateTable [$Index + 1] + ($Type & 0x1F))
                break (2);
              
              // Truncate the buffer
              $this->bitBuffer >>= $this->deflateTable [$Index + 1];
              $this->bitBufferLength -= $this->deflateTable [$Index + 1];
              $l -= $this->deflateTable [$Index + 1];
              
              // Read the whole distance
              if (($this->bitBufferLength < ($Type & 0x0F)) && !$this->fillBufferedBits ($Type & 0x0F))
                return $this->raiseCompressionError ('This should not happen at all');
              
              $Distance = $this->deflateTable [$Index + 2] + ($this->bitBuffer & ((1 << ($Type & 0x0F)) - 1));
              $this->bitBuffer >>= ($Type & 0x0F);
              $this->bitBufferLength -= ($Type & 0x0F);
              $l -= ($Type & 0x0F);
              
              // Copy from window to uncompressed
              $Chunk = substr ($this->uncompressedBuffer, -$Distance, $this->deflateLength);
              
              if ($this->deflateLength > $Distance) {
                $Chunk = str_repeat ($Chunk, (int)($this->deflateLength / $Distance));
                
                if ($this->deflateLength % $Distance != 0)
                  $Chunk .= substr ($this->uncompressedBuffer, -$Distance, $this->deflateLength % $Distance);
              }
              
              // Append to uncompressed buffer
              if (!$this->raiseEvents || ($this->uncompressedBufferLength + $this->deflateLength - $this->uncompressedBufferOffset > $this::UNCOMPRESSED_FLUSH_WATERMARK)) {
                $this->uncompressedBuffer .= $Chunk;
                $this->uncompressedBufferLength += $this->deflateLength;
              } else
                $this->processUncompressed ($Chunk, $this->deflateLength);
              
              unset ($Chunk);
              
              // Go back to start
              $this->deflateTableOffset = $this->deflateLiteralOffset;
              $this->deflateCodeLength = $this->deflateLiteralBits;
              $this->CompressionState = $this::COMPRESSION_STATE_DATA;
              
              continue;
            // Move to next table
            } elseif (($Type & 0x40) == 0x00) {
              // Truncate the bit-buffer
              $this->bitBuffer >>= $this->deflateTable [$Index + 1];
              $this->bitBufferLength -= $this->deflateTable [$Index + 1];
              $l -= $this->deflateTable [$Index + 1];
              
              // Set new table offset
              $this->deflateTableOffset = ($Index / 3) + $this->deflateTable [$Index + 2];
              $this->deflateCodeLength = $Type;
              
              continue;
            }
            
            // Raise an error if we get here
            return $this->raiseCompressionError ('Invalid Distance-Code');
          
          // Read uncompressed data from buffer
          case $this::COMPRESSION_STATE_IMAGE:
            // Peek the length of uncompressed payload
            $imageLength = ord ($this->compressedBuffer [0]) | (ord ($this->compressedBuffer [1]) << 8);
            
            // Check if the buffer is big enough
            if ($this->compressedBufferLength < $imageLength + 4)
              return;
            
            // Validate the length
            $imageLengthComplement = ord ($this->compressedBuffer [2]) | (ord ($this->compressedBuffer [3]) << 8);
            
            if ($imageLength != ~$imageLengthComplement)
              return $this->raiseCompressionError ('Malformed image-block on deflate');
            
            // Get the uncompressed data
            $Uncompressed = substr ($this->compressedBuffer, 4, $imageLength);
            $this->compressedBuffer = substr ($this->compressedBuffer, $imageLength + 4);
            $this->compressedBufferLength -= $imageLength + 4;
            
            // Update our state
            $this->CompressionState = $this::COMPRESSION_STATE_HEADER;
            
            // Push further
            $this->processUncompressed ($Uncompressed, $imageLength);
            unset ($Uncompressed);
            
            // Try to flush the uncompressed
            $this->flushUncompressed ();
            
            // Raise a callback for this
            $this->___raiseCallback ('compressedBlockReady', $this->deflateLast);
            
            break;
        }
      }
      
      $this->cleanBufferedBits (true);
    }
    // }}}
    
    // {{{ buildHuffmanTable
    /**
     * Construct a huffman-table
     * 
     * @param int $i (optional) Initialize context this big
     * @param array $b Array of code-lengths
     * @param int $o Offset in code-lengths to use
     * @param int $n Number of codes
     * @param int $s Number of simple-valued codes
     * @param array $d Array of base values for codes
     * @param array $e Array of extra-bits for codes
     * @param int $t Starting-Offset in generated table
     * @param int $m Number of maximum bits to lookup
     * @param array $hp Resulting table
     * @param int $hn Number of items used
     * @param bool $lit (optional) Process literals
     * 
     * @access private
     * @return bool
     **/
    private function buildHuffmanTable ($i = null, $b, $o, $n, $s, $d, $e, &$t, &$m, &$hp, &$hn, $lit = false) {
      // Check wheter to reset our state
      if ($i !== null)
        $huffmanContext = array_fill (0, $i, 0);
      else
        $huffmanContext = array ();
      
      // Count each bitlength
      $x = $c = $u = array_fill (0, $this::COMPRESSION_DEFLATE_MAX_BITS + 1, 0);
      $l = $k = $j = $m;
      $g = $i = 0;
      
      unset ($u [$this::COMPRESSION_DEFLATE_MAX_BITS]);
      
      for ($p = 0; $p < $n; $p++)
        if ((($blen = $b [$p + $o]) >= 0) && ($blen <= $this::COMPRESSION_DEFLATE_MAX_BITS)) {
          // Increase the counter
          $c [$blen]++;
          
          // Check if this might be a minimum code-length
          if (($blen < $k) && ($blen > 0))
            $k = $j = $blen;
          
          // Check if this might be a maxium code-length
          if ($blen > $g)
            $g = $i = $blen;
        } else
          return $this->raiseCompressionError ('Invalid bit-length on table');
      
      // Check if all codes have zero length
      if ($c [0] == count ($c) - $o) {
        $t = -1;
        $m =  0;
        
        return true;
      }
      
      // Adjuist maximum values
      if ($l > $i)
        $l = $i;
      
      $m = $l;
      
      // Adjust last length count to fill out codes, if needed
      for ($y = 1 << $j; $j < $i; $j++, $y <<= 1)
        if (($y -= $c [$j]) < 0)
          return $this->raiseCompressionError ('');
      
      if (($y -= $c [$i]) < 0)
        return $this->raiseCompressionError ('');
      
      $c [$i] += $y;
      
      // Generate starting offsets into the value table for each length
      $x [1] = $j = 0;
      $p = 1;
      $xp = 2;
      
      while (--$i > 0)
        $x [$xp++] = ($j += $c [$p++]);
      
      // Make a table of values in order of bit lengths
      $i = 0;
      $p = 0;
      
      while ($i < $n)
        if (($j = $b [$o + $p++]) !== 0)
          $huffmanContext [$x [$j]++] = $i++;
        else
          $i++;
      
      $n = $x [$g];
      
      // Generate the Huffman codes and for each, make the table entries
      $x [0] = $i = $p = 0;
      $h = -1;
      $w = -$l;
      $u [0] = 0;
      $q = 0;
      $z = 0;
      $r = array (0, 0, 0);
      
      // go through the bit lengths (k already is bits in shortest code)
      for (; $k <= $g; $k++) {
        $a = $c [$k];
        
        while ($a-- > 0) {
          while ($k > $w + $l) {
            $h++;
            $w += $l;
            
            // compute minimum size table less than or equal to l bits
            $z = $g - $w;
            $z = ($z > $l) ? $l : $z;
            
            if (($f = 1 << ($j = $k - $w)) > $a + 1) {
              $f -= $a + 1;
              $xp = $k;
              
              if ($j < $z) {
                while (++$j < $z) {
                  if (($f <<= 1) <= $c [++$xp])
                    break;
                  
                  $f -= $c [$xp];
                }
              }
            }
            
            $z = (1 << $j);
            
            // allocate new table
            if ($hn + $z > $this::COMPRESSION_DEFLATE_TABLE_SIZE)
              return $this->raiseCompressionError ('Table-Offset too large');
            
            $u [$h] = $q = $hn;
            $hn += $z;
            
            // connect to last table, if there is one
            if ($h > 0) {
              $x [$h] = $i;
              $r [0] = $j;
              $r [1] = $l;
              $j = $i >> ($w - $l);
              $r [2] = ($q - $u [$h - 1] - $j);
              
              for ($o = ($u [$h - 1] + $j) * 3, $P = 0; $P < 3; $P++)
                $hp [$o + $P] = $r [$P];
            } else
              $t = $q;
          }
          
          // set up table entry in r
          $r [1] = ($k - $w);
          
          if ($p >= $n)
            $r [0] = 192;
          elseif ($huffmanContext [$p] < $s) {
            $r [0] = ($huffmanContext [$p] < 256 ? 0 : 96);
            $r [2] = ($lit ? chr ($huffmanContext [$p++]) : $huffmanContext [$p++]);
          } else {
            $r [0] = ($e [$huffmanContext [$p] - $s] + 80);
            $r [2] = $d [$huffmanContext [$p++] - $s];
          }
          
          // fill code-like entries with r
          $f = 1 << ($k - $w);
          
          for ($j = $i >> $w; $j < $z; $j += $f)
            for ($o = ($q + $j) * 3, $P = 0; $P < 3; $P++)
              $hp [$o + $P] = $r [$P];
          
          // backwards increment the k-bit code i
          for ($j = 1 << ($k - 1); ($i & $j) != 0; $j >>= 1)
            $i ^= $j;
          
          $i ^= $j;
          
          // backup over finished tables
          $mask = (1 << $w) - 1;
          
          while (($i & $mask) != $x [$h]) {
            $h--;
            $w -= $l;
            $mask = (1 << $w) - 1;
          }
        }
      }
      
      return !(($y != 0) && ($g != 1));
    }
    // }}}
    
    // {{{ getAvailableBits
    /**
     * Retrive the number of bits we have available on the buffer
     * 
     * @access private
     * @return int
     **/
    private function getAvailableBits () {
      return ($this->bitBufferLength + (($this->compressedBufferLength - $this->bitBufferOffset) * 8));
    }
    // }}}
    
    private function fillBufferedBits ($Count) {
      if ($Count > 24) {
        throw new exception ('too large: ' . $Count);
        
        return null;
      }
      
      // Check if there are enough bits available
      if (($this->bitBufferLength + (($this->compressedBufferLength - $this->bitBufferOffset) * 8)) < $Count)
        return null;
      
      // Check how many bytes we may add
      $Bytes = (int)((24 - $this->bitBufferLength) / 8);
      
      if ($this->bitBufferOffset + $Bytes >= $this->compressedBufferLength)
        $Bytes = $this->compressedBufferLength - $this->bitBufferOffset;
      
      if ($Bytes == 0)
        return null;
      
      switch ($Bytes) {
        case 1:
          $this->bitBuffer |=
            (ord ($this->compressedBuffer [$this->bitBufferOffset++]) << $this->bitBufferLength);
          $this->bitBufferLength += 0x08;
          
          break;
        case 2:
          $this->bitBuffer |=
            (ord ($this->compressedBuffer [$this->bitBufferOffset++]) << $this->bitBufferLength) |
            (ord ($this->compressedBuffer [$this->bitBufferOffset++]) << ($this->bitBufferLength + 8));
          $this->bitBufferLength += 0x10;
          
          break;
        default:
          $this->bitBuffer |=
            (ord ($this->compressedBuffer [$this->bitBufferOffset++]) << $this->bitBufferLength) |
            (ord ($this->compressedBuffer [$this->bitBufferOffset++]) << ($this->bitBufferLength + 8)) |
            (ord ($this->compressedBuffer [$this->bitBufferOffset++]) << ($this->bitBufferLength + 16));
          $this->bitBufferLength += 0x18;
          
          break;
      }
      
      return $Bytes;
    }
    
    // {{{ cleanBufferedBits
    /**
     * Empty the internal bit-buffer
     * 
     * @param bool $onlyByteBuffer Just remove used bytes from byte-buffer
     * 
     * @access private
     * @return void
     **/
    private function cleanBufferedBits ($onlyByteBuffer = false) {
      // Clear bit-buffer
      if (!$onlyByteBuffer) {   
        $this->bitBuffer = 0x00;
        $this->bitBufferLength = 0x00;
        $this->bitBufferOffset = 0x00;
        $bytesOnBuffer = 0;
      } else
        $bytesOnBuffer = (int)floor ($this->bitBufferLength / 0x08);
      
      // Flush compressed buffer
      $this->compressedBuffer = substr ($this->compressedBuffer, $this->bitBufferOffset - $bytesOnBuffer);
      $this->compressedBufferLength -= $this->bitBufferOffset - $bytesOnBuffer;
      $this->bitBufferOffset = $bytesOnBuffer;
    }
    // }}}
    
    // {{{ processUncompressed
    /**
     * Process received uncompressed payload
     * 
     * @param string $Data
     * 
     * @access private
     * @return void
     **/
    private function processUncompressed ($Data, $l = null) {
      // Get the length of new data
      if ((($l === null) && (($l = strlen ($Data)) == 0)) || ($l == 0))
        return;
      
      // Check wheter to truncate the buffer
      # We just let the buffer fill here, read() will cleanup things
      #if ($this->uncompressedBufferLength + $l > $this->compressedWindowSize) {
      #  $Offset = min ($this->uncompressedBufferOffset, $this->uncompressedBufferLength + $l - $this->compressedWindowSize);
      #  
      #  if ($Offset > 0) {
      #    $this->uncompressedBuffer = substr ($this->uncompressedBuffer, $Offset);
      #    $this->uncompressedBufferLength -= $Offset;
      #    $this->uncompressedBufferOffset -= $Offset;
      #  }
      #}
      
      // Append to internal buffer
      $this->uncompressedBuffer .= $Data;
      $this->uncompressedBufferLength += $l;
      
      // Check wheter to raise an event
      if ($this->raiseEvents && ($this->uncompressedBufferLength - $this->uncompressedBufferOffset > $this::UNCOMPRESSED_FLUSH_WATERMARK))
        $this->flushReadable ();
    }
    // }}}
    
    // {{{ flushUncompressed
    /**
     * Explicitly notify available data on the uncompressed buffer
     * 
     * @access private
     * @return void
     **/
    private function flushUncompressed () {
      // Check if we should raise events and if there is data pending
      if (!$this->raiseEvents || ($this->uncompressedBufferLength <= $this->uncompressedBufferOffset))
        return;
      
      // Raise the event
      $this->flushReadable ();
    }
    // }}}
    
    private function flushReadable () {
      do {
        $this->haveRead = false;
        $this->___callback ('eventReadable');
      } while ($this->haveRead && ($this->uncompressedBufferOffset < $this->uncompressedBufferLength));
    }
    
    // {{{ raiseCompressionError
    /**
     * Handle a compression-error on the stream
     * 
     * @param string $Reason
     * 
     * @access private
     * @return void
     **/
    private function raiseCompressionError ($Reason) {
      $this->close ();
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
      $this->State = self::STATE_DETECT;
      $this->compressedBuffer = '';
      $this->compressedBufferLength = 0;
      $this->cleanBufferedBits ();
      
      $this->___callback ('eventClosed');
      
      return qcEvents_Promise::resolve ();
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
      
      // Raise the callback
      $this->___raiseCallback ($Callback, true, $Private);
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Interface_Stream_Consumer $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return callable
     **/
    public function initStreamConsumer (qcEvents_Interface_Stream $Source, callable $Callback = null, $Private = null) {
      // Store the source
      $this->Source = $Source;
      
      // Raise the callback
      $this->___raiseCallback ($Callback, true, $Private);
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
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
     * @return void
     **/
    public function deinitConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      $this->___raiseCallback ($Callback, true, $Private);
    }
    // }}}
    
    // {{{ read
    /**
     * Try to read pending data from this source
     * 
     * @param int $Size (optional)
     * 
     * @access public
     * @return string
     **/
    public function read ($Size = null) {
      // Check wheter size is unbound
      if ($Size === null)
        $Size = $this->uncompressedBufferLength - $this->uncompressedBufferOffset;
      
      // Peek the data from the buffer
      $buf = substr ($this->uncompressedBuffer, $this->uncompressedBufferOffset, $Size);
      $this->uncompressedBufferOffset += $Size;
      
      // We cannot read further than the entire buffer
      if ($this->uncompressedBufferOffset > $this->uncompressedBufferLength)
        $this->uncompressedBufferOffset = $this->uncompressedBufferLength;
      
      // Check wheter to truncate the buffer
      if (($this->uncompressedBufferLength > $this->compressedWindowSize) && (($offset = min ($this->uncompressedBufferOffset, $this->uncompressedBufferLength - $this->compressedWindowSize)) > 0)) {
        $this->uncompressedBuffer = substr ($this->uncompressedBuffer, $offset);
        $this->uncompressedBufferLength -= $offset;
        $this->uncompressedBufferOffset -= $offset;
      }
      
      // Return the peeked data
      $this->haveRead = true;
      
      if ($Size < 1)
        return null;
      
      return $buf;
    }
    // }}}
    
    // {{{ watchRead
    /**
     * Set/Retrive the current event-watching status
     * 
     * @param bool $Set (optional) Set the status
     * 
     * @access public
     * @return bool
     **/
    public function watchRead ($Set = null) {
      if ($Set !== null) {
        if ($this->raiseEvents = !!$Set)
          $this->flushReadable ();
        
        return true;
      }
      
      return $this->raiseEvents;
    }
    // }}}
    
    // {{{ setEventBase
    /**
     * Set the Event-Base of this source
     * 
     * @param qcEvents_Base $Base
     * 
     * @access public
     * @return void
     **/
    public function setEventBase (qcEvents_Base $Base) {
      # Unused
    }
    // }}}
    
    // {{{ isWatching
    /**
     * Check if we are registered on the assigned Event-Base and watching for events
     * 
     * @param bool $Set (optional) Toogle the state
     * 
     * @access public
     * @return bool
     **/
    public function isWatching ($Set = null) {
      return $this->watchRead ($Set);
    }
    // }}}
    
    
    // {{{ eventRead
    /**
     * Callback: A readable-event was received for this handler on the event-loop
     * 
     * @access protected
     * @return void
     **/
    protected function eventReadable () { }
    // }}}
    
    // {{{ eventClosed
    /**
     * Callback: This stream was closed
     * 
     * @access protected
     * @return void
     **/
    protected function eventClosed () { }
    // }}}
    
    protected function compressedContainerDetected ($Format) { }
    protected function compressedContainerReady ($Container, $Compression) { }
    protected function compressedGZIPHeader ($Compression, $Filename, $Modified, $OS, $Comment, $exFields) { }
    protected function compressedZLIBHeader ($Compression, $CompressionLevel, $WindowSize, $Dictionary) { }
    protected function compressedBlockReady ($isLastBlock) { }
    protected function compressedContainerFinished () { }
  }

?>
<?php

  /**
   * qcEvents - Compressed Stream
   * Copyright (C) 2019-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  class Compressed extends Events\Virtual\Pipe implements ABI\Consumer, ABI\Stream\Consumer, ABI\Source {
    use Events\Feature\Based;
    
    /* Our internal stream-state */
    private const STATE_DETECT     = 0x00;
    private const STATE_HEADER     = 0x01;
    private const STATE_COMPRESSED = 0x02;
    private const STATE_FOOTER     = 0x03;
    
    private $decompressState = Compressed::STATE_DETECT;
    
    /* Detected/Used Container */
    public const CONTAINER_NONE = 0x00;
    public const CONTAINER_GZIP = 0x01;
    public const CONTAINER_ZLIB = 0x02;
    
    private $containerType = Compressed::CONTAINER_NONE;
    
    /* Compression-Method */
    public const COMPRESSION_DEFLATE = 0x08;
    
    private $compressionType = Compressed::COMPRESSION_DEFLATE;
    
    /* Compression-State */
    private const COMPRESSION_STATE_HEADER = 0x00;
    private const COMPRESSION_STATE_TABLE  = 0x01;
    private const COMPRESSION_STATE_TREE   = 0x02;
    private const COMPRESSION_STATE_DATA   = 0x03;
    private const COMPRESSION_STATE_DATA2  = 0x04;
    private const COMPRESSION_STATE_IMAGE  = 0x05;
    
    private $compressionState = Compressed::COMPRESSION_STATE_HEADER;
    
    /* Internal compressor-informations */
    private const COMPRESSION_DEFLATE_MAX_BITS = 15;
    private const COMPRESSION_DEFLATE_TABLE_SIZE = 1440;
    
    private static $cplens = [ 3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 15, 17, 19, 23, 27, 31, 35, 43, 51, 59, 67, 83, 99, 115, 131, 163, 195, 227, 258, 0, 0 ];
    private static $cplext = [ 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 4, 5, 5, 5, 5, 0, 112, 112 ];
    private static $cpdist = [ 1, 2, 3, 4, 5, 7, 9, 13, 17, 25, 33, 49, 65, 97, 129, 193, 257, 385, 513, 769, 1025, 1537, 2049, 3073, 4097, 6145, 8193, 12289, 16385, 24577 ];
    private static $cpdext = [ 0, 0, 0, 0, 1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8, 8, 9, 9, 10, 10, 11, 11, 12, 12, 13, 13 ];
    
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
    private const FLAG_GZIP_IS_ASCII     = 0x0001;
    private const FLAG_GZIP_HEADER_CRC   = 0x0002;
    private const FLAG_GZIP_EXTRA_FIELDS = 0x0004;
    private const FLAG_GZIP_FILENAME     = 0x0008;
    private const FLAG_GZIP_COMMENT      = 0x0010;
    private const FLAG_GZIP_DEFLATE_MAX  = 0x0200;
    private const FLAG_GZIP_DEFLATE_MIN  = 0x0400;
    
    private const FLAG_ZLIB_DICT         = 0x20;
    
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
    private const UNCOMPRESSED_FLUSH_WATERMARK = 40960;
    
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
     * @param mixed $sourceData
     * @param ABI\Source $dataSource
     * 
     * @access public
     * @return void
     **/
    public function consume ($sourceData, ABI\Source $dataSource = null) : void {
      // Copy the data to our buffer
      $this->compressedBuffer .= $sourceData;
      $this->compressedBufferLength += strlen ($sourceData);
      unset ($sourceData);
      
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
    private function processBuffer () : void {
      // Check wheter we need to detect contents of our buffer
      if ($this->decompressState == self::STATE_DETECT) {
        // We need at least two bytes to work
        if ($this->compressedBufferLength < 2)
          return;
        
        // Generate magic from buffer
        $inputMagic = (ord ($this->compressedBuffer [0]) << 8) | ord ($this->compressedBuffer [1]);
        
        // Check if the stream uses GZIP-Encoding (RFC 1952)
        if ($inputMagic == 0x1F8B) {
          $this->containerType = $this::CONTAINER_GZIP;
          $this->decompressState = self::STATE_HEADER;
        
        // Check if the stream uses ZLIB-Encoding (RFC 1950)
        // We detect this by looking if DEFLATE is set and the magic is a multiple of 31
        } elseif ((($inputMagic & 0x0F00) == (self::COMPRESSION_DEFLATE << 8)) && ($inputMagic % 31 == 0)) {
          $this->containerType = $this::CONTAINER_ZLIB;
          $this->decompressState = self::STATE_HEADER;
        
        // Nothing that we know was detected
        } else
          $this->raiseCompressionError ('Unable to detect compression-container');
        
        // Raise a callback for this
        $this->___callback ('compressedContainerDetected', $this->containerType);
      }
      
      // Check wheter to process the header
      if ($this->decompressState == self::STATE_HEADER) {
        // Process GZIP-Header (RFC 1952)
        if ($this->containerType == $this::CONTAINER_GZIP) {
          // Check if there is enough data on the buffer
          if ($this->compressedBufferLength < 10)
            return;
          
          // Check flags to see if we are looking for additional headers
          $this->gzipFlags = ord ($this->compressedBuffer [3]);
          $p = 10;
          
          // Check if extra-fields were signaled and received
          if ($this->gzipFlags & self::FLAG_GZIP_EXTRA_FIELDS) {
            // Check if we have the length-field
            if ($this->compressedBufferLength < 12)
              return;
            
            $exLength = (ord ($this->compressedBuffer [$p++]) << 8) | ord ($this->compressedBuffer [$p++]);
            
            // Check if there is enough data on the buffer
            if ($this->compressedBufferLength < ($p += $exLength))
              return;
          }
          
          // Check how many zeros we need
          $expectedZeros = ($this->gzipFlags & self::FLAG_GZIP_FILENAME ? 1 : 0) + ($this->gzipFlags & self::FLAG_GZIP_COMMENT ? 1 : 0);
          
          if ($expectedZeros > 0) {
            for (; $p < $this->compressedBufferLength; $p++)
              if ((ord ($this->compressedBuffer [$p]) == 0x00) && (--$expectedZeros == 0))
                break;
            
            if ($expectedZeros > 0)
              return;
          }
          
          // Check if the header has crc16-protection
          if (($this->gzipFlags & self::FLAG_GZIP_HEADER_CRC) && ($this->compressedBufferLength < $p + 2))
            return;
          
          // Parse the header
          $this->compressionType = ord ($this->compressedBuffer [2]);
          $this->gzipModified = ord ($this->compressedBuffer [4]) | (ord ($this->compressedBuffer [5]) << 8) | (ord ($this->compressedBuffer [6]) << 16) | (ord ($this->compressedBuffer [7]) << 24);
          $this->gzipFlags = $this->gzipFlags | (ord ($this->compressedBuffer [8]) << 8);
          $this->gzipOS = ord ($this->compressedBuffer [9]);
          
          $headerLength = 10;
          
          // Parse extra fields
          if ($this->gzipFlags & self::FLAG_GZIP_EXTRA_FIELDS) {
            // Skip the length-bits
            $headerLength += 2;
            
            // Extract the fields
            $exStop = $headerLength + $exLength;
            $exFields = [ ];
            
            while ($headerLength + 4 < $xStop) {
              $fieldID = ord ($this->compressedBuffer [$headerLength++]) | (ord ($this->compressedBuffer [$headerLength++]) << 8);
              $exLength = min (ord ($this->compressedBuffer [$headerLength++]) | (ord ($this->compressedBuffer [$headerLength++]) << 8), $exStop - $headerLength);
              
              $exFields [$fieldID] = substr ($this->compressedBuffer, $headerLength, $exLength);
              $headerLength += $exLength;
            }
            
            // Skip the extra-fields
            $headerLength = $exStop;
          } else
            $exFields = null;
          
          // Parse filename
          if ($this->gzipFlags & self::FLAG_GZIP_FILENAME) {
            $s = $headerLength;
            
            while ($headerLength < $this->compressedBufferLength)
              if (ord ($this->compressedBuffer [$headerLength++]) == 0x00)
                break;
            
            $this->gzipFilename = substr ($this->compressedBuffer, $s, $headerLength - $s - 1);
          } else
            $this->gzipFilename = null;
          
          if ($this->gzipFlags & self::FLAG_GZIP_COMMENT) {
            $s = $headerLength;
            
            while ($headerLength < $this->compressedBufferLength)
              if (ord ($this->compressedBuffer [$headerLength++]) == 0x00)
                break;
            
            $this->gzipComment = substr ($this->compressedBuffer, $s, $headerLength - $s - 1);
          } else
            $this->gzipComment = null;
          
          // Check if the header has crc16
          if ($this->gzipFlags & self::FLAG_GZIP_HEADER_CRC) {
            # TODO: We just skip this at the moment
            $headerLength += 2;
          }
          
          // Truncate the header from the stream
          $this->compressedBuffer = substr ($this->compressedBuffer, $headerLength);
          $this->compressedBufferLength -= $headerLength;
          
          // Move to COMPRESSED-State
          $this->decompressState = self::STATE_COMPRESSED;
          
          // Raise a callback for GZIP
          $this->___callback ('compressedGZIPHeader', $this->compressionType, $this->gzipFilename, $this->gzipModified, $this->gzipOS, $this->gzipComment, $exFields);
          
        // Process ZLIB-Header (RFC 1950)
        } elseif ($this->containerType == $this::CONTAINER_ZLIB) {
          // Make sure that the buffer is big enough to read; all headers
          if ($this->compressedBufferLength < 2)
            return;
          
          $headerLength = 2;
          
          // Read zlib-flags first
          $this->zlibFlags = ord ($this->compressedBuffer [1]);
          
          // Check if the entire header is on the buffer
          if (($this->zlibFlags & self::FLAG_ZLIB_DICT) && ($this->compressedBufferLength < 6))
            return;
          
          // Parse the header
          $CMF = ord ($this->compressedBuffer [0]);
          $headerLength = 2;
          
          $this->compressionType = ($CMF & 0x0F);
          
          if ($this->compressionType == $this::COMPRESSION_DEFLATE)
            $this->compressedWindowSize = (1 << ((($CMF & 0xF0) >> 4) + 8));
          
          $this->zlibCompressionLevel = (($this->zlibFlags & 0xC0) >> 6);
          
          if ($this->zlibFlags & self::FLAG_ZLIB_DICT) {
            $this->zlibDictionary = ord ($this->compressedBuffer [2]) | (ord ($this->compressedBuffer [3]) << 8) | (ord ($this->compressedBuffer [4]) << 16) | (ord ($this->compressedBuffer [5]) << 24);
            $headerLength += 4;
          }
          
          // Truncate the header
          $this->compressedBuffer = substr ($this->compressedBuffer, $headerLength);
          $this->compressedBufferLength -= $headerLength;
          
          // Move to COMPRESSED-State
          $this->decompressState = self::STATE_COMPRESSED;
          
          // Raise a callback for ZLIB
          $this->___callback ('compressedZLIBHeader', $this->compressionType, $this->zlibCompressionLevel, $this->compressedWindowSize, $this->zlibDictionary);
          
        // We should never get here:
        } else
          $this->raiseCompressionError ('Header on unhandled container-type ' . $this->containerType);
        
        // Raise general callback
        $this->___callback ('compressedContainerReady', $this->containerType, $this->compressionType);
      }
      
      // Check if we reached COMPRESSED-State
      if ($this->decompressState == self::STATE_COMPRESSED)
        $this->processCompressed ();
      
      // Check if we reached FOOTER-State
      if ($this->decompressState == self::STATE_FOOTER) {
        if ($this->containerType == $this::CONTAINER_GZIP) {
          # 4 Bytes CRC32
          # 4 Bytes ISIZE
          
          if ($this->compressedBufferLength < 8)
            return;
          
          $this->compressedBuffer = substr ($this->compressedBuffer, 8);
          $this->compressedBufferLength -= 8;
          
          $this->decompressState = self::STATE_DETECT;
        } elseif ($this->containerType == $this::CONTAINER_ZLIB) {
          # 4 Bytes Adler32
          
          if ($this->compressedBufferLength < 4)
            return;
          
          $this->compressedBuffer = substr ($this->compressedBuffer, 4);
          $this->compressedBufferLength -= 4;
          
          $this->decompressState = self::STATE_DETECT;
        } else
          $this->raiseCompressionError ('Footer on unhabled container-type');
        
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
    private function processCompressed () : void {
      // Check if the compression-method is deflate
      if ($this->compressionType != $this::COMPRESSION_DEFLATE)
        $this->raiseCompressionError ('Unsupported compression-mechanism');
      
      // Process our current state while we have bits
      $availableBits = $this->getAvailableBits ();
      
      while ($availableBits) {
        switch ($this->compressionState) {
          // Read header of compressed block
          case self::COMPRESSION_STATE_HEADER:
            // Make sure we have enough bits available
            if ($availableBits < 3)
              break (2);
            
            // Read the header
            if (($this->bitBufferLength < 3) && !$this->fillBufferedBits (3))
              break (2);
            
            $compressionHeader = $this->bitBuffer & 0x07;
            $this->bitBuffer >>= 3;
            $this->bitBufferLength -= 3;
            $availableBits -= 3;
            
            // Check if this is the last block
            $this->deflateLast = (($compressionHeader & 0x01) == 0x01);
            
            // Check type of next block
            $blockType = (($compressionHeader & 0x06) >> 1);
            
            // Not compressed at all
            if ($blockType == 0x00) {
              // Update the state
              $this->compressionState = self::COMPRESSION_STATE_IMAGE;
              
              // Clear the bit-buffer
              $this->cleanBufferedBits ();
              
              continue (2);
            // Compressed using fixed codes
            } elseif ($blockType == 0x01) {
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
              $this->compressionState = self::COMPRESSION_STATE_DATA;
              
              continue (2);
            // Compressed using dynamic codes
            } elseif ($blockType == 0x02) {
              // Update the state
              $this->compressionState = self::COMPRESSION_STATE_TABLE;
              
              // Reset just to be sure
              $this->compressedDynamicLengths = null;
            
            // Invalid type
            } else
              $this->raiseCompressionError ('Invalid block-type on deflate');
            
          // Read dynamic huffman table
          case self::COMPRESSION_STATE_TABLE:
            if ($this->compressedDynamicLengths === null) {
              // Check if there are enough bits
              if ($availableBits < 14)
                break (2);
              
              // Retrive the table
              if (($this->bitBufferLength < 14) && !$this->fillBufferedBits (14))
                $this->raiseCompressionError ('This should not happen at all');
              
              if (($this->compressedDynamicLiterals = ($this->bitBuffer & 0x1F)) > 29)
                $this->raiseCompressionError ('Too many length symbols');
              
              $this->bitBuffer >>= 5;
              
              if (($this->compressedDynamicDistances = ($this->bitBuffer & 0x1F)) > 31)
                $this->raiseCompressionError ('Too many distance symbols');
              
              $this->bitBuffer >>= 5;
              $this->compressedDynamicLengths = ($this->bitBuffer & 0x0F);
              $this->bitBuffer >>= 4;
              $this->bitBufferLength -= 14;
              $availableBits -= 14;
              
              // Decrease number of available bits
              # $availableBits -= 14;
            }
            
            // Check if we may read dynamic code-lengths
            if ($availableBits < (($this->compressedDynamicLengths + 4) * 3))
              break (2);
            
            // Read dynamic code-lengths
            static $map = [ 16, 17, 18, 0, 8, 7, 9, 6, 10, 5, 11, 4, 12, 3, 13, 2, 14, 1, 15 ];
            
            $this->compressedCodeLengths = array_fill (0, 258 + $this->compressedDynamicLiterals + $this->compressedDynamicDistances, 0);
            
            for ($i = 0; $i < $this->compressedDynamicLengths + 4; $i++) {
              if (($this->bitBufferLength < 3) && !$this->fillBufferedBits (3))
                $this->raiseCompressionError ('This should not happen at all');
              
              $this->compressedCodeLengths [$map [$i]] = ($this->bitBuffer & 7);
              $this->bitBuffer >>= 3;
              $this->bitBufferLength -= 3;
            }
            
            for ($i = $this->compressedDynamicLengths + 4; $i < 19; $i++)
              $this->compressedCodeLengths [$map [$i]] = 0;
            
            $availableBits -= (($this->compressedDynamicLengths + 4) * 3);
            
            $this->compressedCodeLength = 7;
            $this->deflateTable = array_fill (0, $this::COMPRESSION_DEFLATE_TABLE_SIZE * 3, 0);
            $this->deflateTableOffset = 0;
            $hufts = 0;
            
            if (!$this->buildHuffmanTable (19, $this->compressedCodeLengths, 0, 19, 19, null, null, $this->deflateTableOffset, $this->compressedCodeLength, $this->deflateTable, $hufts))
              $this->raiseCompressionError ('Could not generate table');
            
            // Update the state
            $this->compressionState = self::COMPRESSION_STATE_TREE;
            $this->compressedTreeIndex = 0;
            
          // Read huffman-tree from buffer
          case self::COMPRESSION_STATE_TREE:
            if ($this->compressedTreeIndex < count ($this->compressedCodeLengths))
              for (; $this->compressedTreeIndex < count ($this->compressedCodeLengths); $this->compressedTreeIndex++) {
                // Check if there are enough bits on the buffer
                if ($availableBits < $this->compressedCodeLength)
                  break (3);
                
                // Read the actual length of the next item
                if (($this->bitBufferLength < $this->compressedCodeLength) && !$this->fillBufferedBits ($this->compressedCodeLength))
                  $this->raiseCompressionError ('This should not happen at all');
                
                $actualLength = $this->deflateTable [$this->deflateTableOffset + ($this->bitBuffer & ((1 << $this->compressedCodeLength) - 1)) * 3 + 1];
                
                if ($availableBits < $actualLength)
                  break (3);
                
                // Read the code-length
                if (($this->bitBufferLength < $actualLength) && !$this->fillBufferedBits ($actualLength))
                  $this->raiseCompressionError ('This should not happen at all');
                
                $codeLength = $this->deflateTable [$this->deflateTableOffset + ($this->bitBuffer & ((1 << $actualLength) - 1)) * 3 + 2];
                
                // Post-Process the code-length
                if ($codeLength < 16) {
                  // Store the code
                  $this->compressedCodeLengths [$this->compressedTreeIndex] = $codeLength;
                  
                  // Make sure the bit-buffer is cleared
                  $this->bitBuffer >>= $actualLength;
                  $this->bitBufferLength -= $actualLength;
                  $availableBits -= $actualLength;
                  
                  // Continue with next one
                  continue;
                }
                
                // Determine how many bits we need
                $i = ($codeLength == 18 ? 7 : $codeLength - 14);
                
                if ($availableBits < $actualLength + $i)
                  break (3);
                
                // Make sure the bit-buffer is cleared 
                $this->bitBuffer >>= $actualLength;
                $this->bitBufferLength -= $actualLength;
                $availableBits -= $actualLength;
                
                // Retrive the additional bits
                if (($this->bitBufferLength < $i) && !$this->fillBufferedBits ($i))
                  $this->raiseCompressionError ('This should not happen at all');
                
                $j = ($codeLength == 18 ? 11 : 3) + ($this->bitBuffer & ((1 << $i) - 1));
                $this->bitBuffer >>= $i;
                $this->bitBufferLength -= $i;
                $availableBits -= $i;
                
                if (($this->compressedTreeIndex + $j > count ($this->compressedCodeLengths)) || (($codeLength == 16) && ($this->compressedTreeIndex < 1)))
                  $this->raiseCompressionError ('Invalid bit-length repeat');
                
                // Determine the new code-length
                $codeLength = ($codeLength == 16 ? $this->compressedCodeLengths [$this->compressedTreeIndex - 1] : 0);
                
                do {
                  $this->compressedCodeLengths [$this->compressedTreeIndex++] = $codeLength;
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
              $this->raiseCompressionError ('Could not build literal/length tree');
            
            if (!$this->buildHuffmanTable (288, $this->compressedCodeLengths, 257 + $this->compressedDynamicLiterals, 1 + $this->compressedDynamicDistances, 0, self::$cpdist, self::$cpdext, $this->deflateDistanceOffset, $this->deflateDistanceBits, $this->deflateTable, $hufts))
              $this->raiseCompressionError ('Could not build distance tree');
            
            // Move to DATA-state
            $this->deflateTableOffset = $this->deflateLiteralOffset;
            $this->deflateCodeLength = $this->deflateLiteralBits;
            $this->compressionState = self::COMPRESSION_STATE_DATA;
            
          // Read compressed data from buffer
          case self::COMPRESSION_STATE_DATA:
            // Check if there are enough bits on the buffer
            while ($availableBits >= $this->deflateCodeLength) {
              // Read bits from buffer
              if (($this->bitBufferLength < $this->deflateCodeLength) && !$this->fillBufferedBits ($this->deflateCodeLength))
                $this->raiseCompressionError ('This should not happen at all');
              
              $tableIndex = ($this->deflateTableOffset + ($this->bitBuffer & ((1 << $this->deflateCodeLength) - 1))) * 3;
              
              // Check type of next element
              $dataType = $this->deflateTable [$tableIndex];
              
              // Peek a literal from the table
              if ($dataType == 0) {
                // Append to uncompressed buffer
                if (!$this->raiseEvents || ($this->uncompressedBufferLength - $this->uncompressedBufferOffset > $this::UNCOMPRESSED_FLUSH_WATERMARK)) {
                  $this->uncompressedBuffer .= $this->deflateTable [$tableIndex + 2];
                  $this->uncompressedBufferLength++;
                } else
                  $this->processUncompressed ($this->deflateTable [$tableIndex + 2], 1);
                
                // Remove the bits from buffer
                $this->bitBuffer >>= $this->deflateTable [$tableIndex + 1];
                $this->bitBufferLength -= $this->deflateTable [$tableIndex + 1];
                $availableBits -= $this->deflateTable [$tableIndex + 1];
                
                // Reset to start again
                $this->deflateTableOffset = $this->deflateLiteralOffset;
                $this->deflateCodeLength = $this->deflateLiteralBits;
                
                continue;
              // Read length and distance
              } elseif (($dataType & 0x10) == 0x10) {
                // Check if there are enough bits on the buffer
                if ($availableBits < $this->deflateTable [$tableIndex + 1] + ($dataType & 0x0F))
                  break (3);
                
                // Remove the bits from buffer
                $this->bitBuffer >>= $this->deflateTable [$tableIndex + 1];
                $this->bitBufferLength -= $this->deflateTable [$tableIndex + 1];
                $availableBits -= $this->deflateTable [$tableIndex + 1];
                
                // Retrive the length
                if (($this->bitBufferLength < ($dataType & 0x0F)) && !$this->fillBufferedBits ($dataType & 0x0F))
                  $this->raiseCompressionError ('This should not happen at all');
                
                $this->deflateLength = $this->deflateTable [$tableIndex + 2] + ($this->bitBuffer & ((1 << ($dataType & 0x0F)) - 1));
                $this->bitBuffer >>= ($dataType & 0x0F);
                $this->bitBufferLength -= ($dataType & 0x0F);
                $availableBits -= ($dataType & 0x0F);
                
                // Move to distance-state
                $this->deflateTableOffset = $this->deflateDistanceOffset;
                $this->deflateCodeLength = $this->deflateDistanceBits;
                $this->compressionState = self::COMPRESSION_STATE_DATA2;
                
                break;
              // Switch over to next table
              } elseif (($dataType & 0x40) == 0x00) {
                // Remove the bits from buffer
                $this->bitBuffer >>= $this->deflateTable [$tableIndex + 1];
                $this->bitBufferLength -= $this->deflateTable [$tableIndex + 1];
                $availableBits -= $this->deflateTable [$tableIndex + 1];
                
                // Set new table-offset
                $this->deflateCodeLength = $dataType;
                $this->deflateTableOffset = ($tableIndex / 3) + $this->deflateTable [$tableIndex + 2];
                
                continue;
              // Check if the end of block was reached
              } elseif (($dataType & 0x20) == 0x20) {
                // Remove the bits from buffer
                $this->bitBuffer >>= $this->deflateTable [$tableIndex + 1];
                $this->bitBufferLength -= $this->deflateTable [$tableIndex + 1];
                $availableBits -= $this->deflateTable [$tableIndex + 1];
                
                // Flush the uncompressed buffer
                $this->flushUncompressed ();
                
                // Raise a callback for this
                $this->___callback ('compressedBlockReady', $this->deflateLast);
                
                // Leave if inflate was finished
                if ($this->deflateLast) {
                  // Clean up bit-buffer
                  $this->cleanBufferedBits ();
                  
                  $this->decompressState = self::STATE_FOOTER;
                  
                  return;
                }
                
                // Proceed to next block
                $this->compressionState = self::COMPRESSION_STATE_HEADER;
                
                continue (3);
              // Invalid compressed data
              } else
                $this->raiseCompressionError ('Invalid literal or code-length');
            }
          
          // Retrive distance-literals for deflate
          case self::COMPRESSION_STATE_DATA2:
            // Make sure there are enough bits on the buffer
            if ($availableBits < $this->deflateCodeLength)
              break (2);
            
            // Retrive the distance
            if (($this->bitBufferLength < $this->deflateCodeLength) && !$this->fillBufferedBits ($this->deflateCodeLength))
              $this->raiseCompressionError ('This should not happen at all');
            
            $tableIndex = ($this->deflateTableOffset + ($this->bitBuffer & ((1 << $this->deflateCodeLength) - 1))) * 3;
            
            // Check what to do next
            $dataType = $this->deflateTable [$tableIndex];
            
            if (($dataType & 0x10) == 0x10) {
              // Check if we have enough bits available
              if ($availableBits < $this->deflateTable [$tableIndex + 1] + ($dataType & 0x1F))
                break (2);
              
              // Truncate the buffer
              $this->bitBuffer >>= $this->deflateTable [$tableIndex + 1];
              $this->bitBufferLength -= $this->deflateTable [$tableIndex + 1];
              $availableBits -= $this->deflateTable [$tableIndex + 1];
              
              // Read the whole distance
              if (($this->bitBufferLength < ($dataType & 0x0F)) && !$this->fillBufferedBits ($dataType & 0x0F))
                $this->raiseCompressionError ('This should not happen at all');
              
              $windowDistance = $this->deflateTable [$tableIndex + 2] + ($this->bitBuffer & ((1 << ($dataType & 0x0F)) - 1));
              $this->bitBuffer >>= ($dataType & 0x0F);
              $this->bitBufferLength -= ($dataType & 0x0F);
              $availableBits -= ($dataType & 0x0F);
              
              // Copy from window to uncompressed
              $uncompressedChunk = substr ($this->uncompressedBuffer, -$windowDistance, $this->deflateLength);
              
              if ($this->deflateLength > $windowDistance) {
                $uncompressedChunk = str_repeat ($uncompressedChunk, (int)($this->deflateLength / $windowDistance));
                
                if ($this->deflateLength % $windowDistance != 0)
                  $uncompressedChunk .= substr ($this->uncompressedBuffer, -$windowDistance, $this->deflateLength % $windowDistance);
              }
              
              // Append to uncompressed buffer
              if (!$this->raiseEvents || ($this->uncompressedBufferLength + $this->deflateLength - $this->uncompressedBufferOffset > $this::UNCOMPRESSED_FLUSH_WATERMARK)) {
                $this->uncompressedBuffer .= $uncompressedChunk;
                $this->uncompressedBufferLength += $this->deflateLength;
              } else
                $this->processUncompressed ($uncompressedChunk, $this->deflateLength);
              
              unset ($uncompressedChunk);
              
              // Go back to start
              $this->deflateTableOffset = $this->deflateLiteralOffset;
              $this->deflateCodeLength = $this->deflateLiteralBits;
              $this->compressionState = self::COMPRESSION_STATE_DATA;
              
              continue (2);
            // Move to next table
            } elseif (($dataType & 0x40) == 0x00) {
              // Truncate the bit-buffer
              $this->bitBuffer >>= $this->deflateTable [$tableIndex + 1];
              $this->bitBufferLength -= $this->deflateTable [$tableIndex + 1];
              $availableBits -= $this->deflateTable [$tableIndex + 1];
              
              // Set new table offset
              $this->deflateTableOffset = ($tableIndex / 3) + $this->deflateTable [$tableIndex + 2];
              $this->deflateCodeLength = $dataType;
              
              continue (2);
            }
            
            // Raise an error if we get here
            $this->raiseCompressionError ('Invalid Distance-Code');
          
          // Read uncompressed data from buffer
          case self::COMPRESSION_STATE_IMAGE:
            // Peek the length of uncompressed payload
            $imageLength = ord ($this->compressedBuffer [0]) | (ord ($this->compressedBuffer [1]) << 8);
            
            // Check if the buffer is big enough
            if ($this->compressedBufferLength < $imageLength + 4)
              return;
            
            // Validate the length
            $imageLengthComplement = ord ($this->compressedBuffer [2]) | (ord ($this->compressedBuffer [3]) << 8);
            
            if ($imageLength != ~$imageLengthComplement)
              $this->raiseCompressionError ('Malformed image-block on deflate');
            
            // Get the uncompressed data
            $uncompressedChunk = substr ($this->compressedBuffer, 4, $imageLength);
            $this->compressedBuffer = substr ($this->compressedBuffer, $imageLength + 4);
            $this->compressedBufferLength -= $imageLength + 4;
            
            // Update our state
            $this->compressionState = self::COMPRESSION_STATE_HEADER;
            
            // Push further
            $this->processUncompressed ($uncompressedChunk, $imageLength);
            unset ($uncompressedChunk);
            
            // Try to flush the uncompressed
            $this->flushUncompressed ();
            
            // Raise a callback for this
            $this->___callback ('compressedBlockReady', $this->deflateLast);
            
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
    private function buildHuffmanTable (int $i = null, array $b, int $o, int $n, int $s, array $d = null, array $e = null, int &$t, int &$m, array &$hp, int &$hn, bool $lit = false) : bool {
      // Check wheter to reset our state
      if ($i !== null)
        $huffmanContext = array_fill (0, $i, 0);
      else
        $huffmanContext = [ ];
      
      // Count each bitlength
      $x = $c = $u = array_fill (0, self::COMPRESSION_DEFLATE_MAX_BITS + 1, 0);
      $l = $k = $j = $m;
      $g = $i = 0;
      
      unset ($u [self::COMPRESSION_DEFLATE_MAX_BITS]);
      
      for ($p = 0; $p < $n; $p++)
        if ((($blen = $b [$p + $o]) >= 0) && ($blen <= self::COMPRESSION_DEFLATE_MAX_BITS)) {
          // Increase the counter
          $c [$blen]++;
          
          // Check if this might be a minimum code-length
          if (($blen < $k) && ($blen > 0))
            $k = $j = $blen;
          
          // Check if this might be a maxium code-length
          if ($blen > $g)
            $g = $i = $blen;
        } else
          $this->raiseCompressionError ('Invalid bit-length on table');
      
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
          $this->raiseCompressionError ('');
      
      if (($y -= $c [$i]) < 0)
        $this->raiseCompressionError ('');
      
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
            if ($hn + $z > self::COMPRESSION_DEFLATE_TABLE_SIZE)
              $this->raiseCompressionError ('Table-Offset too large');
            
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
      if ($Count > 24)
        throw new \Exception ('too large: ' . $Count);
      
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
      
      throw new \Exception ($Reason);
    }
    // }}}
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @access public
     * @return Events\Promise
     **/
    public function close () : Events\Promise {
      $this->decompressState = self::STATE_DETECT;
      $this->compressedBuffer = '';
      $this->compressedBufferLength = 0;
      $this->cleanBufferedBits ();
      
      $this->___callback ('eventClosed');
      
      return Events\Promise::resolve ();
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
      // Assign the source
      $this->Source = $dataSource;
      
      // Raise the callback
      return Events\Promise::resolve ();
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param ABI\Source $dataSource
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function initStreamConsumer (ABI\Stream $dataSource) : Events\Promise {
      // Store the source
      $this->Source = $dataSource;
      
      // Return resolved promise
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
    
    // {{{ read
    /**
     * Try to read pending data from this source
     * 
     * @param int $readLength (optional)
     * 
     * @access public
     * @return string
     **/
    public function read (int $readLength = null) : ?string {
      // Check wheter size is unbound
      if ($readLength === null)
        $readLength = $this->uncompressedBufferLength - $this->uncompressedBufferOffset;
      
      // Peek the data from the buffer
      $readBuffer = substr ($this->uncompressedBuffer, $this->uncompressedBufferOffset, $readLength);
      $this->uncompressedBufferOffset += $readLength;
      
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
      
      if ($readLength < 1)
        return null;
      
      return $readBuffer;
    }
    // }}}
    
    // {{{ watchRead
    /**
     * Set/Retrive the current event-watching status
     * 
     * @param bool $setState (optional) Set the status
     * 
     * @access public
     * @return bool
     **/
    public function watchRead ($setState = null) : bool {
      if ($setState !== null) {
        if ($this->raiseEvents = $setState)
          $this->flushReadable ();
        
        return true;
      }
      
      return $this->raiseEvents;
    }
    // }}}
    
    // {{{ isWatching
    /**
     * Check if we are registered on the assigned Event-Base and watching for events
     * 
     * @param bool $setState (optional) Toogle the state
     * 
     * @access public
     * @return bool
     **/
    public function isWatching ($setState = null) : bool {
      return $this->watchRead ($setState);
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

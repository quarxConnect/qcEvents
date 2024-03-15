<?php

  /**
   * quarxConnect Events - CSV Row was read
   * Copyright (C) 2014-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  namespace quarxConnect\Events\Stream\CSV\Event;

  use ArrayAccess;
  use ArrayIterator;
  use Countable;
  use IteratorAggregate;

  use quarxConnect\Events\ABI\Event;
  
  class Row implements ArrayAccess, Countable, Event, IteratorAggregate
  {
    /**
     * Actual contents of the row that was read
     *
     * @var array
     **/
    private array $csvRow;

    // {{{ __construct
    /**
     * Create a new row-read-event
     * 
     * @param array $csvRow
     * 
     * @access friendly
     * @return void
     **/
    public function __construct (array $csvRow)
    {
      $this->csvRow = $csvRow;
    }
    // }}}

    // {{{ count
    /**
     * Retrieve the number of cells on this row
     *
     * @access public
     * @return integer
     **/
    public function count (): int
    {
      return count ($this->csvRow);
    }
    // }}}

    // {{{ getIterator
    /**
     * Create a new iterator for this row
     * 
     * @access public
     * @return ArrayIterator
     **/
    public function getIterator (): ArrayIterator
    {
      return new ArrayIterator ($this->csvRow);
    }
    // }}}

    // {{{ offsetExists
    /**
     * Check if a given cell exists on this row
     *
     * @param mixed $rowOffset
     * 
     * @access public
     * @return boolean
     **/
    public function offsetExists (mixed $rowOffset): bool
    {
      return isset ($this->csvRow [$rowOffset]);
    }

    // {{{ offsetGet
    /**
     * Retrieve the value of a cell on this row
     *
     * @param mixed $rowOffset
     *
     * @access public
     * @return mixed
     */
    public function offsetGet (mixed $rowOffset): mixed
    {
      return $this->csvRow [$rowOffset] ?? null;
    }
    // }}}

    // {{{ offsetSet
    /**
     * Change the value of a cell
     *
     * UNUSED
     *
     * @param mixed $rowOffset
     * @param mixed $cellValue
     *
     * @access public
     * @return void
     **/
    public function offsetSet (mixed $rowOffset, mixed $cellValue): void
    {
      // No-Op
    }
    // }}}

    // {{{ offsetUnset
    /**
     * Remove a cell from this row
     *
     * UNUSED
     *
     * @param mixed $rowOffset
     *
     * @access public
     * @return void
     **/
    public function offsetUnset (mixed $rowOffset): void
    {
      // No-Op
    }
    // }}}
  }

<?php

  /**
   * quarxConnect Events - DNS Label
   * Copyright (C) 2015-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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

  declare (strict_types=1);

  namespace quarxConnect\Events\Stream\DNS;

  use ArrayAccess;
  use Countable;

  class Label implements Countable, ArrayAccess
  {
    /* All parts of this label */
    private array $labelParts;

    // {{{ __construct
    /**
     * Create a new DNS-Label
     *
     * @param array $labelParts (optional)
     *
     * @access friendly
     * @return void
     **/
    public function __construct (array $labelParts = [])
    {
      $this->labelParts = $labelParts;
    }
    // }}}

    // {{{ offsetGet
    /**
     * Retrieve a single label from a given position
     *
     * @param int $offset
     *
     * @access public
     * @return string NULL if Index is out of bounds
     **/
    public function offsetGet ($offset): mixed
    {
      return ($this->labelParts [$offset] ?? null);
    }
    // }}}

    // {{{ offsetSet
    /**
     * Set a DNS-Label at a given position
     *
     * @param int $offset
     * @param mixed $value
     *
     * @access public
     * @return void
     **/
    public function offsetSet ($offset, mixed $value): void
    {
      # TODO: Validate the label

      if ($offset !== null)
        $this->labelParts [(int)$offset] = $value;
      else
        array_unshift ($this->labelParts, $value);
    }
    // }}}

    // {{{ offsetExists
    /**
     * Check if an indexed DNS-Label exists
     *
     * @param int $offset
     *
     * @access public
     * @return bool
     **/
    public function offsetExists ($offset): bool
    {
      return isset ($this->labelParts [$offset]);
    }
    // }}}

    // {{{ offsetUnset
    /**
     * Remove a DNS-Label
     *
     * @param int $offset
     *
     * @access public
     * @return void
     **/
    public function offsetUnset ($offset): void
    {
      unset ($this->labelParts [$offset]);
    }
    // }}}

    // {{{ __toString
    /**
     * Create a string from this DNS-Label
     *
     * @access friendly
     * @return string
     **/
    public function __toString (): string
    {
      return implode ('.', $this->labelParts) . '.';
    }
    // }}}

    // {{{ count
    /**
     * Retrieve the number of labels
     *
     * @access public
     * @return int
     **/
    public function count (): int
    {
      return count ($this->labelParts);
    }
    // }}}

    // {{{ getParts
    /**
     * Retrieve all parts of this label
     *
     * @access public
     * @return array
     **/
    public function getParts (): array
    {
      return $this->labelParts;
    }
    // }}}

    // {{{ getParentLabel
    /**
     * Retrieve label of parented zone
     *
     * @access public
     * @return Label
     **/
    public function getParentLabel (): Label
    {
      return new $this (array_slice ($this->labelParts, 1));
    }
    // }}}

    // {{{ isSublabelOf
    /**
     * Check if this label is a child of another label
     *
     * @param Label $parentLabel
     *
     * @access public
     * @return bool
     **/
    public function isSublabelOf (Label $parentLabel): bool
    {
      return ($parentLabel->labelParts === array_slice ($this->labelParts, -count ($parentLabel->labelParts)));
    }
    // }}}

    // {{{ subLabel
    /**
     * Truncate another label from this one and return a new instance
     *
     * @param Label $parentLabel
     *
     * @access public
     * @return Label
     **/
    public function subLabel (Label $parentLabel): Label
    {
      if (!$this->isSublabelOf ($parentLabel))
        return clone $this;

      return new $this (array_slice ($this->labelParts, 0, count ($this->labelParts) - count ($parentLabel->labelParts)));
    }
    // }}}
  }

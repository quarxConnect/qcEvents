<?php

  /**
   * quarxConnect Events - Promise Status (for Promise::allSettled())
   * Copyright (C) 2018-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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
   * along with this program. If not, see <http://www.gnu.org/licenses/>.
   **/

  declare (strict_types = 1);

  namespace quarxConnect\Events\Promise;

  use Throwable;

  class Status
  {
    public const STATUS_PENDING = 'pending';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_REJECTED = 'rejected';

    public string $status;
    public mixed $value;
    public array|null $args = null;
    public Throwable|null $reason = null;

    public function __construct (string $status = self::STATUS_PENDING, mixed $value = null, array $args = null)
    {
      $this->status = $status;

      if ($status === self::STATUS_FULFILLED)
        $this->value = $value;
      elseif ($status === self::STATUS_REJECTED)
        $this->reason = $value;

      if ($status !== self::STATUS_PENDING)
        $this->args = $args ?? [ $value ];
    }
  }
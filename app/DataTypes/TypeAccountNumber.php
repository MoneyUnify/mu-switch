<?php

namespace App\DataTypes;

class TypeAccountNumber
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public string $operator,
        public string $number,
        public string $name,
        public string $country
    )
    {
        //
    }
}

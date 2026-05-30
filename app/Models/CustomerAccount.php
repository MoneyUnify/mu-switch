<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAccount extends Model
{
    protected $fillable = ['customer_id', 'name', 'number'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}

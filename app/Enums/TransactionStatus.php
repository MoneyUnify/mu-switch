<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case SUCCESS = 'success';
    case FAILED = 'failed'; 
}

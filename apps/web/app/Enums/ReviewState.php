<?php

namespace App\Enums;

enum ReviewState: string
{
    case Candidate = 'candidate';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

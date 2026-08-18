<?php

namespace App\Enums;

enum KycLevel: string
{
    case Base = 'base';
    case RialVerified = 'rial_verified';
    case Restricted = 'restricted';
}

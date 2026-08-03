<?php

namespace App\Enums;

enum PolicyTypeEnum: string
{
    case INDIVIDUAL = 'individual';
    case FAMILY = 'family';
    case CORPORATE = 'corporate';
}

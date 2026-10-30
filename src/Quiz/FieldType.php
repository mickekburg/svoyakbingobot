<?php

declare(strict_types=1);

namespace App\Quiz;

enum FieldType: string
{
    case String = 'string';
    case Int = 'int';
    case List = 'list';
    case Image = 'image';
}

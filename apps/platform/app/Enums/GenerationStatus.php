<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Repaired = 'repaired';
    case Failed = 'failed';
}

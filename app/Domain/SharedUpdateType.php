<?php

declare(strict_types=1);

namespace App\Domain;

enum SharedUpdateType: string
{
    case DailySummary = 'DAILY_SUMMARY';
    case Progress = 'PROGRESS';
    case DischargeSummary = 'DISCHARGE_SUMMARY';
}
<?php

declare(strict_types=1);

namespace App\Domain;

enum DocumentType: string
{
    case Pos = 'POS';
    case Onl = 'ONL';
    case Svc = 'SVC';
    case Rfd = 'RFD';
    case Rtn = 'RTN';
    case Po = 'PO';
    case Trf = 'TRF';
}
<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DocumentNumberService
{
    private const DOCUMENT_TYPES = ['POS', 'ONL', 'SVC', 'RFD', 'RTN', 'PO', 'TRF'];

    public function next(string $branchId, string $docType, int $year): string
    {
        $docType = strtoupper($docType);

        if (! in_array($docType, self::DOCUMENT_TYPES, true) || $year < 2000 || $year > 9999) {
            throw new DomainException('VALIDATION_ERROR', 'Jenis atau tahun nomor dokumen tidak valid.', 422);
        }

        if (DB::transactionLevel() === 0) {
            throw new DomainException('TRANSACTION_REQUIRED', 'Nomor dokumen harus dibuat di dalam transaksi.', 500);
        }

        $branch = DB::table('branches')->where('id', $branchId)->first();

        if ($branch === null) {
            throw new DomainException('NOT_FOUND', 'Cabang tidak ditemukan.', 404);
        }

        DB::table('invoice_sequences')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'branch_id' => $branchId,
            'document_type' => $docType,
            'year' => $year,
            'last_number' => 0,
        ]);

        $sequence = DB::table('invoice_sequences')
            ->where('branch_id', $branchId)
            ->where('document_type', $docType)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            throw new DomainException('CONFLICT', 'Nomor dokumen tidak dapat dikunci.');
        }

        $next = $sequence->last_number + 1;
        DB::table('invoice_sequences')->where('id', $sequence->id)->update(['last_number' => $next]);

        return sprintf('%s-%s-%d-%06d', $branch->code, $docType, $year, $next);
    }
}

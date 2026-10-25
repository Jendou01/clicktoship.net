<?php

namespace App\Modules\Operations\Application\Overview;

use Illuminate\Support\Facades\DB;

final class Facts
{
    public function currentCustomsQuarantine()
    {
        return DB::table('customs_document_results as r')->join('customs_documents as d', fn ($j) => $j->on('d.id', '=', 'r.document_id')->on('d.merchant_id', '=', 'r.merchant_id'))->join('shipments as s', fn ($j) => $j->on('s.id', '=', 'd.shipment_id')->on('s.merchant_id', '=', 'd.merchant_id')->on('s.revision', '=', 'd.revision'))->where('r.state', 'quarantined')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('customs_document_revocations as v')->whereColumn('v.document_id', 'd.id')->whereColumn('v.merchant_id', 'd.merchant_id'));
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateConversionRequest;
use App\Http\Requests\FxQuoteRequest;
use App\Services\Fx\FxService;
use Illuminate\Http\JsonResponse;

class FxController extends Controller
{
    public function __construct(private readonly FxService $fx) {}

    public function quote(FxQuoteRequest $request): JsonResponse
    {
        $quote = $this->fx->quote($request->input('from'), $request->input('to'), $request->integer('amount'));

        return response()->json(['data' => [
            'from' => $quote->from->currency,
            'to' => $quote->gross->currency,
            'from_amount' => $quote->from->minor,
            'rate' => $quote->rate,
            'spread_bps' => $quote->spreadBps,
            'gross_amount' => $quote->gross->minor,
            'spread' => $quote->spread->minor,
            'to_amount' => $quote->net->minor,
            'to_amount_display' => $quote->net->toMajorString(),
        ]]);
    }

    public function convert(CreateConversionRequest $request): JsonResponse
    {
        $conversion = $this->fx->convert(
            $this->merchant($request),
            $request->input('from'),
            $request->input('to'),
            $request->integer('amount'),
            $request->input('reference'),
            $request->attributes->get('idempotency_key'),
        );

        return response()->json(['data' => [
            'id' => $conversion->id,
            'status' => $conversion->status,
            'from' => $conversion->from_currency,
            'to' => $conversion->to_currency,
            'from_amount' => $conversion->from_amount_minor,
            'to_amount' => $conversion->to_amount_minor,
            'spread' => $conversion->spread_minor,
            'rate' => $conversion->rate,
            'reference' => $conversion->reference,
            'created_at' => optional($conversion->created_at)->toIso8601String(),
        ]], 201);
    }
}

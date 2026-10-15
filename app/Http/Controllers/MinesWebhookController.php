<?php

namespace App\Http\Controllers;

use App\Models\IntegrationWebhookDelivery;
use App\Models\MinesConnection;
use App\Services\Mines\WebhookApplier;
use App\Services\Mines\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * POST /webhooks/mines/{connection} — signed deliveries from Dot.Mines.
 * Order: find connection → verify signature → store → dedupe → apply.
 * Always 2xx once stored, so Dot.Mines never retries a poison payload.
 */
class MinesWebhookController extends Controller
{
    public function __invoke(Request $request, int $connection, WebhookApplier $applier): JsonResponse
    {
        $minesConnection = MinesConnection::withoutGlobalScopes()->find($connection);

        if ($minesConnection === null) {
            return response()->json(['message' => 'Unknown connection.'], 404);
        }

        if ($minesConnection->webhook_secret === null || $minesConnection->webhook_secret === '') {
            return response()->json(['message' => 'This connection has no webhook secret configured.'], 503);
        }

        $rawBody = $request->getContent();
        $header = (string) $request->header(WebhookSignature::HEADER, '');

        if (! WebhookSignature::verify($header, $rawBody, $minesConnection->webhook_secret, (int) config('mines.webhook_skew_seconds', 300))) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        if ($minesConnection->status === 'disabled') {
            return response()->json(['status' => 'ignored', 'reason' => 'connection disabled'], 202);
        }

        $payload = json_decode($rawBody, true);
        $payload = is_array($payload) ? $payload : [];
        $event = (string) ($request->header('X-Mines-Event') ?? $payload['event'] ?? 'unknown');
        $deliveryId = (string) ($request->header('X-Mines-Delivery') ?? hash('sha256', $rawBody));

        $existing = IntegrationWebhookDelivery::where('mines_connection_id', $minesConnection->id)->where('delivery_id', $deliveryId)->first();

        if ($existing !== null) {
            return response()->json(['status' => 'ignored', 'reason' => 'duplicate delivery']);
        }

        $delivery = IntegrationWebhookDelivery::create([
            'mines_connection_id' => $minesConnection->id,
            'delivery_id' => $deliveryId,
            'event' => $event,
            'occurred_at' => isset($payload['occurred_at']) ? Carbon::parse($payload['occurred_at']) : null,
            'payload' => $payload,
            'status' => 'received',
        ]);

        try {
            $status = $applier->apply($minesConnection, $delivery);
            $delivery->update(['status' => $status]);
        } catch (Throwable $e) {
            $delivery->update(['status' => 'failed', 'error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500)]);

            return response()->json(['status' => 'failed']);
        }

        return response()->json(['status' => $status]);
    }
}

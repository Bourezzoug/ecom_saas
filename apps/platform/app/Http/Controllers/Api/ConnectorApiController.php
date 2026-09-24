<?php

namespace App\Http\Controllers\Api;

use App\Domain\Publishing\ConnectionService;
use App\Http\Controllers\Controller;
use App\Models\WpConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints the WordPress plugin calls with its connection key (Sanctum).
 */
class ConnectorApiController extends Controller
{
    public function __construct(private readonly ConnectionService $connections) {}

    public function handshake(Request $request): JsonResponse
    {
        $connection = $this->connection($request);

        $data = $request->validate([
            'site_url' => ['required', 'url:http,https', 'max:255'],
            'versions' => ['array'],
            'versions.*' => ['nullable', 'string', 'max:40'],
        ]);

        return response()->json($this->connections->handshake($connection, $data));
    }

    public function status(Request $request): JsonResponse
    {
        $connection = $this->connection($request);

        return response()->json([
            'status' => $connection->status,
            'project' => $connection->project->name,
            'site_url' => $connection->site_url,
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->connections->revoke($this->connection($request));

        return response()->json(['ok' => true]);
    }

    private function connection(Request $request): WpConnection
    {
        // Sanctum resolves the token's owner: for connector keys that is a WpConnection.
        $connection = auth('sanctum')->user();

        abort_unless(
            $connection instanceof WpConnection && $connection->tokenCan('connector') && $connection->status !== 'revoked',
            403,
            'This connection key is not valid.',
        );

        return $connection;
    }
}

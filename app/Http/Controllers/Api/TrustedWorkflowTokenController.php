<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TrustedWorkflows\TrustedWorkflowExchangeDenied;
use App\Services\TrustedWorkflows\TrustedWorkflowTokenExchange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class TrustedWorkflowTokenController extends Controller
{
    #[OA\Post(
        summary: 'Exchange a CI OIDC token',
        description: 'Exchange the OIDC token of a CI job (for example GitHub Actions with `id-token: write`) for a short-lived API token. '
            .'A trusted workflow of a team must match the token issuer, audience, and claims. '
            .'The API token has the abilities and scopes of that trusted workflow and expires after its token lifetime. '
            .'Each OIDC token can be exchanged once. No API token is needed for this request.',
        path: '/auth/oidc/token',
        operationId: 'exchange-oidc-token',
        security: [],
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['token'],
                properties: [
                    new OA\Property(property: 'token', type: 'string', description: 'The OIDC token (JWT) issued to the CI job.'),
                    new OA\Property(property: 'rule', type: 'string', description: 'UUID of the trusted workflow to use, when more than one matches.'),
                ],
                type: 'object',
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'A short-lived API token.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'token', type: 'string', example: '42|AbCdEf...'),
                        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'abilities', type: 'array', items: new OA\Items(type: 'string'), example: ['read', 'deploy']),
                        new OA\Property(
                            property: 'scopes',
                            type: 'array',
                            nullable: true,
                            description: 'Projects, environments, or resources the token is limited to. Null for the whole team.',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'scope', type: 'string', enum: ['project', 'environment', 'resource']),
                                    new OA\Property(property: 'uuid', type: 'string'),
                                ],
                            ),
                        ),
                        new OA\Property(property: 'trusted_workflow', type: 'string', description: 'UUID of the trusted workflow that matched.'),
                    ]
                )),
            new OA\Response(
                response: 403,
                description: 'No trusted workflow accepts this token, or the API is disabled for this address.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Token exchange denied.'),
                    ]
                )),
            new OA\Response(
                response: 409,
                description: 'More than one trusted workflow matches. Send the UUID of one in `rule`.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
            new OA\Response(
                response: 429,
                ref: '#/components/responses/429',
            ),
            new OA\Response(
                response: 503,
                description: 'The token issuer could not be reached.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )),
        ]
    )]
    public function exchange(Request $request, TrustedWorkflowTokenExchange $exchange): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:16384'],
            'rule' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        try {
            $result = $exchange->exchange($request->string('token')->toString(), $request->input('rule'));
        } catch (TrustedWorkflowExchangeDenied $denied) {
            auditLog('api.trusted_workflow.exchange_denied', [
                'team_id' => $denied->context['team_id'] ?? null,
                'reason' => $denied->reason,
                'detail' => mb_substr($denied->detail, 0, 500),
                ...$denied->context,
            ], 'warning');

            return response()->json(['message' => match ($denied->status) {
                409 => 'More than one trusted workflow matches this token. Send the UUID of one in "rule".',
                503 => 'The token issuer could not be reached. Try again later.',
                default => 'Token exchange denied.',
            }], $denied->status);
        }

        $rule = $result['trusted_workflow'];
        auditLog('api.trusted_workflow.token_issued', [
            'team_id' => $rule->team_id,
            'trusted_workflow_uuid' => $rule->uuid,
            'trusted_workflow_name' => $rule->name,
            'issued_token_id' => $result['token_id'],
            'abilities' => $result['abilities'],
            'scopes' => $result['scopes'],
            'expires_at' => $result['expires_at'],
            'claims' => $result['claims'],
            'owner_id' => $rule->user_id,
        ]);

        return response()->json([
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'expires_at' => $result['expires_at'],
            'abilities' => $result['abilities'],
            'scopes' => $result['scopes'],
            'trusted_workflow' => $rule->uuid,
        ]);
    }
}

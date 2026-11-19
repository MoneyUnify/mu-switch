<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An MCP tool that runs a MoneyUnify API action. It calls the same controller
 * action as the REST API, with the same validation, routing, fallback,
 * limits and idempotency, so an agent and an integration behave the same.
 */
abstract class SwitchTool extends Tool
{
    /**
     * Run a REST API action as the given account and return its result.
     *
     * @param  array{0: class-string, 1: string}  $action
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $parameters  route parameters, e.g. ['merchant' => '12']
     */
    protected function forward(array $action, array $input, ?User $account, array $parameters = [], ?string $idempotencyKey = null): Response|ResponseFactory
    {
        if (! $account) {
            return Response::error('Not signed in.');
        }

        $http = HttpRequest::create('/mcp', 'POST', array_filter($input, fn ($value): bool => $value !== null));
        $http->headers->set('Accept', 'application/json');
        $http->setUserResolver(fn () => $account);

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $http->headers->set('Idempotency-Key', $idempotencyKey);
        }

        try {
            $response = app()->call([app($action[0]), $action[1]], ['request' => $http, ...$parameters]);
        } catch (ValidationException $e) {
            return Response::error('Invalid input: '.implode(' ', $e->validator->errors()->all()));
        } catch (ModelNotFoundException|NotFoundHttpException) {
            return Response::error('Not found.');
        }

        if (! $response instanceof JsonResponse) {
            return Response::error('The request could not be completed.');
        }

        return self::result($response);
    }

    /**
     * An API response as a tool result: its data on success, its message on failure.
     */
    protected static function result(JsonResponse $response): Response|ResponseFactory
    {
        $body = (array) $response->getData(true);

        if ($response->getStatusCode() >= 400) {
            return Response::error((string) ($body['message'] ?? 'The request failed.'));
        }

        return Response::structured(array_filter([
            'status' => $body['status'] ?? 'success',
            'message' => $body['message'] ?? null,
            'data' => $body['data'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== []));
    }
}

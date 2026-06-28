<?php

namespace App\Http\Middleware;

use App\Models\ApiLog;
use App\Support\RequestContext;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LogApiRequests
{
    /**
     * Header names whose values must never be persisted.
     *
     * @var list<string>
     */
    private const REDACTED_HEADERS = ['authorization', 'cookie', 'set-cookie', 'x-xsrf-token', 'php-auth-pw'];

    /**
     * Request body keys whose values must never be persisted.
     *
     * @var list<string>
     */
    private const REDACTED_FIELDS = ['password', 'password_confirmation', 'api_key', 'secret', 'token', 'access_token'];

    /**
     * Maximum number of characters of a response body to persist.
     */
    private const MAX_BODY_LENGTH = 20000;

    /**
     * Handle an incoming request and persist a full record of it.
     *
     * The request snapshot is captured before the pipeline runs so it reflects
     * the original input. If the pipeline throws, the exception is reported and
     * rendered here so we can persist both the final response and the trace.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        // Correlate this request with every MNO call it triggers.
        $requestId = (string) Str::uuid();
        RequestContext::set($requestId);

        $snapshot = [
            'request_id' => $requestId,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_headers' => $this->redactHeaders($request->headers->all()),
            'request_body' => $this->redactBody($request->except('user')),
        ];

        $exception = null;

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $handler = app(ExceptionHandler::class);
            $handler->report($e);
            $response = $handler->render($request, $e);
            $exception = $e;
        }

        $this->persist($request, $snapshot, $response, $exception, $startedAt);
        RequestContext::clear();

        return $response;
    }

    /**
     * Persist the API log entry. Logging failures must never break the request.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function persist(Request $request, array $snapshot, Response $response, ?Throwable $exception, float $startedAt): void
    {
        // Exceptions thrown inside the routing pipeline are rendered to a
        // response before they reach this middleware, so fall back to the one
        // stashed on the request by the exception handler's render hook.
        $exception ??= $request->attributes->get('api_log_exception');

        try {
            ApiLog::create([
                ...$snapshot,
                'user_id' => $request->user()?->id,
                'route' => $request->route()?->getName() ?? optional($request->route())->uri(),
                'response_status' => $response->getStatusCode(),
                'response_body' => $this->responseBody($response),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'exception_class' => $exception ? $exception::class : null,
                'exception_message' => $exception?->getMessage(),
                'exception_trace' => $exception?->getTraceAsString(),
            ]);
        } catch (Throwable $logError) {
            Log::error('Failed to persist API log', [
                'error' => $logError->getMessage(),
                'path' => $snapshot['url'] ?? null,
            ]);
        }
    }

    /**
     * Redact sensitive header values.
     *
     * @param  array<string, array<int, string|null>>  $headers
     * @return array<string, array<int, string|null>>
     */
    private function redactHeaders(array $headers): array
    {
        foreach ($headers as $name => $values) {
            if (in_array(strtolower($name), self::REDACTED_HEADERS, true)) {
                $headers[$name] = ['[REDACTED]'];
            }
        }

        return $headers;
    }

    /**
     * Redact sensitive request body fields.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function redactBody(array $body): array
    {
        foreach ($body as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_FIELDS, true)) {
                $body[$key] = '[REDACTED]';
            }
        }

        return $body;
    }

    /**
     * Extract a persistable (truncated) representation of the response body.
     */
    private function responseBody(Response $response): ?string
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return null;
        }

        $content = $response->getContent();

        if ($content === false || $content === '') {
            return null;
        }

        return mb_substr($content, 0, self::MAX_BODY_LENGTH);
    }
}

<?php

namespace Spatie\LaravelRay\Watchers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Spatie\LaravelRay\Ray;
use Spatie\Ray\ArgumentConverter;
use Spatie\Ray\Payloads\TablePayload;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class RequestWatcher extends Watcher
{
    public function register(): void
    {
        Event::listen(RequestHandled::class, function (RequestHandled $event) {
            if (!$this->enabled()) {
                return;
            }

            $this->handleRequest($event->request, $event->response);
        });
    }

    protected function handleRequest(Request $request, Response $response)
    {
        $startTime = defined('LARAVEL_START')
            ? LARAVEL_START
            : $request->server('REQUEST_TIME_FLOAT');

        $headers = collect($request->headers->all())
            ->map(function (array $header) {
                return $header[0];
            })
            ->toArray();

        $session = $request->hasSession()
            ? $request->session()->all()
            : [];

        $payload = new TablePayload([
            'ip_address' => $request->ip(),
            'uri' => str_replace($request->root(), '', $request->fullUrl()) ?: '/',
            'method' => $request->method(),
            'controller_action' => optional($request->route())->getActionName(),
            'middleware' => array_values(optional($request->route())->gatherMiddleware() ?? []),
            'headers' => $headers,
            'payload' => $this->payload($request),
            'session' => $session,
            'response_status' => $response->getStatusCode(),
            'response' => $this->response($response),
            'duration' => $startTime ? floor((microtime(true) - $startTime) * 1000) : null,
            'memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
        ], 'Request');

        app(Ray::class)->sendRequest($payload);
    }

    private function payload(Request $request)
    {
        $files = $request->files->all();

        array_walk_recursive($files, function (&$file) {
            $file = [
                'name' => $file->getClientOriginalName(),
                'size' => $file->isFile() ? ($file->getSize() / 1000) . 'KB' : '0',
            ];
        });

        return array_replace_recursive($request->input(), $files);
    }

    protected function response(Response $response)
    {
        $content = $response->getContent();

        if (is_string($content)) {
            if (is_array(json_decode($content, true)) &&
                json_last_error() === JSON_ERROR_NONE) {
                return json_decode($content, true);
            }

            if (Str::startsWith(strtolower($response->headers->get('Content-Type')), 'text/plain')) {
                return $content;
            }
        }

        if ($response instanceof RedirectResponse) {
            return 'Redirected to ' . $response->getTargetUrl();
        }

        if ($response instanceof IlluminateResponse && $response->getOriginalContent() instanceof View) {
            return [
                'view' => $response->getOriginalContent()->getPath(),
                'data' => $this->extractDataFromView($response->getOriginalContent()),
            ];
        }

        return 'HTML Response';
    }

    protected function extractDataFromView($view)
    {
        return collect($view->getData())
            ->map(function ($value) {
                if ($value instanceof Model) {
                    return $value->toArray();
                }

                if (is_object($value)) {
                    return [
                        'class' => get_class($value),
                        'properties' => json_decode(json_encode($value), true),
                    ];
                }

                return json_decode(json_encode($value), true);
            })
            ->toArray();
    }
}

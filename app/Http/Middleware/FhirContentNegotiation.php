<?php

namespace Modules\FHIR\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\FHIR\FhirResponse\FhirResponseFactory;

class FhirContentNegotiation
{
    protected array $validContentTypes = ['application/fhir+json', 'application/json'];

    public function __construct(protected FhirResponseFactory $responseFactory) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
            $ct = $request->header('Content-Type');
            if ($ct && !in_array(explode(';', $ct)[0], $this->validContentTypes)) {
                return $this->responseFactory->unsupportedMediaType();
            }
        }

        $accept = $request->header('Accept');
        if ($accept && $accept !== '*/*' && !in_array($accept, $this->validContentTypes)) {
            $valid = false;
            foreach (explode(',', $accept) as $a) {
                $a = trim(explode(';', $a)[0]);
                if (in_array($a, $this->validContentTypes) || $a === '*/*') {
                    $valid = true;
                    break;
                }
            }
            if (!$valid) {
                return $this->responseFactory->notAcceptable();
            }
        }

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->headers->set('Content-Type', 'application/fhir+json');
        }

        return $response;
    }
}

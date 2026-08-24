<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Exception\MethodNotAllowed;
use App\Http\Exception\RouteNotFound;

/**
 * Minimal router. `{name}` placeholders match one path segment and reach the
 * controller through Request::pathParameter().
 */
final class Router
{
    /** @var list<array{method: string, regex: string, names: list<string>, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): self
    {
        [$regex, $names] = self::compile($pattern);

        $this->routes[] = [
            'method' => \strtoupper($method),
            'regex' => $regex,
            'names' => $names,
            'handler' => $handler,
        ];

        return $this;
    }

    /**
     * @throws RouteNotFound
     * @throws MethodNotAllowed
     */
    public function dispatch(Request $request): JsonResponse
    {
        $path = self::normalisePath($request->path);

        /** @var list<string> $allowedForPath */
        $allowedForPath = [];

        foreach ($this->routes as $route) {
            if (\preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $request->method) {
                $allowedForPath[] = $route['method'];
                continue;
            }

            $parameters = [];
            foreach ($route['names'] as $name) {
                $parameters[$name] = $matches[$name];
            }

            return ($route['handler'])($request->withPathParameters($parameters));
        }

        if ($allowedForPath !== []) {
            throw MethodNotAllowed::for($request->method, $path, \array_values(\array_unique($allowedForPath)));
        }

        throw RouteNotFound::for($request->method, $path);
    }

    /**
     * Splits on placeholders so literal segments can be quoted and placeholders
     * become named capture groups.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function compile(string $pattern): array
    {
        $placeholder = '/(\{[a-z_][a-z0-9_]*\})/i';

        $parts = \preg_split(
            $placeholder,
            self::normalisePath($pattern),
            -1,
            \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY,
        );
        \assert(\is_array($parts));

        $names = [];
        $regex = '';

        foreach ($parts as $part) {
            if (\preg_match('/^\{([a-z_][a-z0-9_]*)\}$/i', $part, $matches) === 1) {
                $names[] = $matches[1];
                $regex .= \sprintf('(?<%s>[^/]+)', $matches[1]);

                continue;
            }

            $regex .= \preg_quote($part, '#');
        }

        return ['#^' . $regex . '$#', $names];
    }

    private static function normalisePath(string $path): string
    {
        $path = '/' . \trim($path, '/');

        return $path === '/' ? '/' : \rtrim($path, '/');
    }
}

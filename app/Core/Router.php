<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Routeur à motifs : /equipment/{id} (id numérique), /locale/{code} (segment libre).
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, handler: array{0: class-string, 1: string}, options: array}> */
    private array $routes = [];

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param array{auth?: bool, permission?: string} $options
     */
    public function get(string $pattern, array $handler, array $options = []): void
    {
        $this->add('GET', $pattern, $handler, $options);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function post(string $pattern, array $handler, array $options = []): void
    {
        $this->add('POST', $pattern, $handler, $options);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function add(string $method, string $pattern, array $handler, array $options = []): void
    {
        $regex = preg_replace_callback(
            '#\{(\w+)\}#',
            static fn (array $m): string => $m[1] === 'id' || str_ends_with($m[1], 'Id')
                ? '(?P<' . $m[1] . '>\d+)'
                : '(?P<' . $m[1] . '>[\w-]+)',
            $pattern
        );
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'options' => $options + ['auth' => true],
        ];
    }

    /**
     * @return array{handler: array{0: class-string, 1: string}, options: array, params: array<string, string>}
     * @throws HttpException 404 ou 405
     */
    public function match(string $method, string $path): array
    {
        $allowed = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $allowed = true;
            if ($route['method'] !== $method && !($method === 'HEAD' && $route['method'] === 'GET')) {
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            return ['handler' => $route['handler'], 'options' => $route['options'], 'params' => $params];
        }

        throw $allowed ? new HttpException(405, 'errors.method_not_allowed') : HttpException::notFound();
    }
}

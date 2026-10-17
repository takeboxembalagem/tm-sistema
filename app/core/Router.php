<?php

declare(strict_types=1);

final class Router
{
    /** @var array<string, list<array{regex:string,handler:callable,params:list<string>}>> */
    private array $routes = ['GET' => [], 'POST' => []];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][] = $this->compile($path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][] = $this->compile($path, $handler);
    }

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = $this->normalize(request_path());

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                $params = [];
                foreach ($route['params'] as $name) {
                    $params[] = (int) ($matches[$name] ?? 0);
                }
                $route['handler'](...$params);
                return;
            }
        }

        http_response_code(404);
        if (Auth::check()) {
            View::render('pages/not_found', [
                'title' => 'Página não encontrada',
                'page' => '',
            ]);
        } else {
            echo 'Página não encontrada.';
        }
    }

    private function compile(string $path, callable $handler): array
    {
        $normalized = $this->normalize($path);
        $params = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '(?P<' . $m[1] . '>[0-9]+)';
            },
            $normalized
        );

        return [
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'params' => $params,
        ];
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}

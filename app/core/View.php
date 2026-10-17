<?php

declare(strict_types=1);

final class View
{
    public static function render(string $view, array $data = [], string $layout = 'main'): void
    {
        $viewFile = dirname(__DIR__) . '/views/' . $view . '.php';
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo 'View não encontrada.';
            return;
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        if ($layout === '') {
            echo $content;
            return;
        }

        $layoutFile = dirname(__DIR__) . '/views/layouts/' . $layout . '.php';
        require $layoutFile;
    }
}

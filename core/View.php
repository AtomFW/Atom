<?php

namespace Atom\core;

class View
{
    public string $title = '';

    public function renderView($view, array $params)
    {
        $layoutName = Atom::$app->layout;
        if (Atom::$app->controller) {
            $layoutName = Atom::$app->controller->layout;
        }
        $viewContent = $this->renderViewOnly($view, $params);
        ob_start();
        include_once Atom::$ROOT_DIR."/views/layouts/$layoutName.php";
        $layoutContent = ob_get_clean();
        return str_replace('{{content}}', $viewContent, $layoutContent);
    }

    public function renderViewOnly($view, array $params)
    {
        foreach ($params as $key => $value) {
            $$key = $value;
        }
        ob_start();
        include_once Atom::$ROOT_DIR."/views/$view.php";
        return ob_get_clean();
    }
}

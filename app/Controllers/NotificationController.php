<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\NotificationService;

/**
 * Centre de notifications (cloche de la barre supérieure) : points d'attention du périmètre,
 * chacun ouvrant la fenêtre de détail correspondante (tableau filtrable, exports).
 */
final class NotificationController extends Controller
{
    public function __construct(ControllerContext $ctx, private readonly NotificationService $notifications)
    {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $items = $this->notifications->items($this->user()->can('parts.view'));

        return $this->json([
            'count' => count(array_filter($items, static fn (array $i) => $i['level'] === 'bad')),
            'total' => count($items),
            'items' => $items,
        ]);
    }
}

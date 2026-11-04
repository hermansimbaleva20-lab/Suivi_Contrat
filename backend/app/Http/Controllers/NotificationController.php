<?php

namespace App\Http\Controllers;

use App\Support\Ctx;
use Illuminate\Support\Facades\DB;

/** Notifications du compte connecté, dans le projet courant. */
class NotificationController extends Controller
{
    private function mine()
    {
        return DB::table('user_notifications')->where('project_id', Ctx::projectId())->where('user_id', Ctx::user()->id);
    }

    public function index()
    {
        $rows = $this->mine()->orderByRaw('read_at IS NULL DESC')->orderByDesc('id')->limit(30)->get()->map(fn ($n) => [
            'id' => $n->id, 'kind' => $n->kind, 'title' => $n->title, 'body' => (string) $n->body, 'link' => $n->link,
            'createdAt' => substr((string) $n->created_at, 0, 16), 'isRead' => $n->read_at !== null,
        ])->values();
        return ['notifications' => $rows, 'unread' => $this->mine()->whereNull('read_at')->count()];
    }

    public function read(int $id)
    {
        $n = $this->mine()->where('id', $id)->update(['read_at' => DB::raw('COALESCE(read_at, NOW())'), 'updated_at' => now()]);
        abort_unless($n, 404, 'Notification introuvable.');
        return ['message' => 'Notification lue.'];
    }

    public function readAll()
    {
        $this->mine()->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        return ['message' => 'Toutes les notifications sont marquées comme lues.'];
    }
}

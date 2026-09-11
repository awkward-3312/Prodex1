<?php

namespace App\Services\Mobile;

use App\Models\Client;

class MobilePosClientSearchService
{
    public function search(?string $search = null, ?int $page = null, ?int $perPage = null): array
    {
        $page = max(1, (int) ($page ?: 1));
        $perPage = min(30, max(1, (int) ($perPage ?: 20)));

        $query = Client::query()
            ->whereNull('deleted_at')
            ->select(['id', 'name', 'phone', 'tax_number'])
            ->orderBy('name')
            ->orderBy('id');

        if ($search !== null && $search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('tax_number', 'like', $like);
            });
        }

        $total = (int) (clone $query)->count();
        $clients = $query->forPage($page, $perPage)->get()
            ->map(fn (Client $client) => [
                'id' => (int) $client->id,
                'name' => (string) $client->name,
                'phone' => $client->phone,
                'tax_number' => $client->tax_number,
            ])
            ->values()
            ->all();

        return [
            'items' => $clients,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'has_more' => ($page * $perPage) < $total,
            ],
        ];
    }
}

<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class GroupPagination
{
    public static function paginate(Request $request, Builder $query, string $key, int $total, int $perPage = 50): LengthAwarePaginator
    {
        $pageName = 'page_'.$key;
        $page = min(Paginator::resolveCurrentPage($pageName), max(1, (int) ceil($total / $perPage)));

        return $query->paginate($perPage, ['*'], $pageName, $page, $total)->appends($request->except('page'));
    }
}

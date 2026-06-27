<?php

namespace App\Http\Controllers\Prezet;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Prezet\Prezet\Data\DocumentData;
use Prezet\Prezet\Models\Document;
use Prezet\Prezet\Prezet;

class IndexController
{
    public function __invoke(Request $request): View
    {
        $category = $request->input('category');
        $tag = $request->input('tag');

        $query = app(Document::class)::where('draft', false);

        if ($category) {
            $query->where('category', $category);
        }

        if ($tag) {
            $query->whereHas('tags', function ($q) use ($tag) {
                $q->where('name', $tag);
            });
        }

        $nav = Prezet::getSummary();
        $docs = $query->orderBy('created_at', 'desc')
            ->paginate(4);

        $docsData = $docs->map(fn (Document $doc) => app(DocumentData::class)::fromModel($doc));

        return view('prezet.index', [
            'nav' => $nav,
            'articles' => $docsData,
            'paginator' => $docs,
            'currentTag' => request()->query('tag'),
            'currentCategory' => request()->query('category'),
        ]);
    }
}

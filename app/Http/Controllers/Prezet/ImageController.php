<?php

namespace App\Http\Controllers\Prezet;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Prezet\Prezet\Prezet;

class ImageController
{
    public function __invoke(Request $request, string $path): Response
    {
        $file = Prezet::getImage($path);
        $size = strlen($file);

        return response($file, 200, [
            'Content-Type' => match (pathinfo($path, PATHINFO_EXTENSION)) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                default => 'image/webp'
            },
            'Content-Length' => $size,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }
}

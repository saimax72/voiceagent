<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

final class FileController
{
    /** Download an uploaded knowledge document (owner only). */
    public function document(Request $request, string $id): Response
    {
        $source = DB::instance()->fetch('SELECT * FROM knowledge_sources WHERE id = ? AND tenant_id = ? AND type = \'file\'', [(int) $id, tenant_id()]);
        if (!$source || empty($source['file_path'])) {
            abort(404);
        }
        $path = APP_ROOT . '/storage/documents/' . $source['file_path'];
        if (!is_file($path)) {
            abort(404, 'File no longer exists.');
        }
        return Response::file($path, (string) ($source['file_name'] ?: basename($path)), (string) ($source['mime'] ?: 'application/octet-stream'));
    }
}

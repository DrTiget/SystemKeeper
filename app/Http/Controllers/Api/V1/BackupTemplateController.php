<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BackupTemplateController extends Controller
{
    /**
     * Шаблоны команды перед бекапом: `{key, group, title, description, command}`.
     */
    public function preCommands(): JsonResponse
    {
        return response()->json(['data' => config('backup.pre_command_templates')]);
    }
}
